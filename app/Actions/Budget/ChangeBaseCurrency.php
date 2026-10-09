<?php

namespace App\Actions\Budget;

use App\Enums\Currency;
use App\Models\CurrencyConversion;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\Data\CurrencyChangePreview;
use App\Services\ExchangeRates\ExchangeRateSource;
use App\Services\ExchangeRates\ExchangeRatesUnavailable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the user's base currency and convert every stored money amount with it.
 *
 * Going to a currency the user has not come from converts at today's MNB rate and records,
 * per money cell, the value before and after. Going back to a currency the user came from
 * undoes those conversions, newest first: a cell that still holds the "after" value gets its
 * "before" value back exactly, anything added or changed since is converted back with the
 * inverse of the rate used then. So HUF → EUR → HUF gives back the very same forints, no
 * matter how often it is repeated, and today's rate never touches a restore.
 */
final readonly class ChangeBaseCurrency
{
    /**
     * Integer money columns in the base currency, by table. Every table has user_id.
     *
     * @var array<string, list<string>>
     */
    private const array COLUMNS = [
        'budget_settings' => ['income', 'reserve_fixed'],
        'budget_lines' => ['amount', 'amount_avg', 'amount_max'],
        'periods' => ['income_planned', 'income_actual'],
        'period_line_statuses' => ['amount_actual'],
        'period_closes' => ['planned_total', 'actual_total', 'leftover', 'to_reserve', 'to_invest', 'from_reserve'],
        'transactions' => ['amount'],
        'pockets' => ['balance', 'target_amount', 'prepay_step'],
        'pocket_movements' => ['amount'],
        'loans' => ['principal_balance', 'installment', 'insurance'],
        'loan_events' => ['amount', 'principal_after', 'installment_after'],
    ];

    /**
     * Money inside JSON columns: dotted paths, "*" for every item of a list.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array JSON_COLUMNS = [
        'periods' => [
            'plan_snapshot' => ['*.amount', '*.amount_avg', '*.amount_max', '*.planned'],
        ],
        'period_closes' => [
            'breakdown' => [
                'income_planned', 'income_actual', 'top_ups',
                'categories.*.planned', 'categories.*.actual', 'categories.*.diff',
                'allocation.to_reserve', 'allocation.to_surplus', 'allocation.from_reserve', 'allocation.uncovered',
                'pocket_deposits.*.amount',
                'prepay_ready.*.balance', 'prepay_ready.*.step',
            ],
        ],
    ];

    /** An example amount when the user has no income set: 100 units of the currency. */
    private const int EXAMPLE_MAJOR = 100;

    public function __construct(
        private ExchangeRateSource $rates,
        private CurrencyConverter $converter,
    ) {}

    /**
     * What switching to $to would do. Only a new conversion needs MNB; a restore does not.
     *
     * @throws ExchangeRatesUnavailable when MNB cannot be reached for a new conversion
     */
    public function preview(User $user, Currency $to): CurrencyChangePreview
    {
        $settings = $user->settings();
        $from = $settings->currency;
        $undo = $this->conversionsToUndo($user, $from, $to);
        $exampleBefore = $settings->income > 0 ? $settings->income : self::EXAMPLE_MAJOR * $from->minorPerMajor();

        if ($undo !== []) {
            $after = $exampleBefore;

            foreach ($undo as $conversion) {
                $entry = $settings->income > 0 ? $this->entries($conversion, 'budget_settings', $settings->id)['income'] ?? null : null;
                $restored = $this->restoredCell('budget_settings', 'income', $after, $entry, $this->inverse($conversion), null);
                $after = is_int($restored) ? $restored : $after;
            }

            $rates = [];

            foreach ($undo as $conversion) {
                foreach ([[$conversion->from_currency, $conversion->from_rate], [$conversion->to_currency, $conversion->to_rate]] as [$currency, $rate]) {
                    if ($currency !== Currency::HUF) {
                        $rates[] = ['date' => $conversion->rate_date->toDateString(), 'currency' => $currency->value, 'huf_per' => $rate];
                    }
                }
            }

            return new CurrencyChangePreview(
                from: $from,
                to: $to,
                restores: true,
                hufPerFrom: '',
                hufPerTo: '',
                rateDate: '',
                rates: $rates,
                exampleBefore: $exampleBefore,
                exampleAfter: $after,
                linesWithOriginal: $this->linesWithOriginal($user, $to),
            );
        }

        $quote = $this->rates->latest();
        $hufPerFrom = $quote->hufPer($from);
        $hufPerTo = $quote->hufPer($to);

        $rates = [];

        foreach ([[$from, $hufPerFrom], [$to, $hufPerTo]] as [$currency, $rate]) {
            if ($currency !== Currency::HUF) {
                $rates[] = ['date' => $quote->date, 'currency' => $currency->value, 'huf_per' => $rate];
            }
        }

        return new CurrencyChangePreview(
            from: $from,
            to: $to,
            restores: false,
            hufPerFrom: $hufPerFrom,
            hufPerTo: $hufPerTo,
            rateDate: $quote->date,
            rates: $rates,
            exampleBefore: $exampleBefore,
            exampleAfter: $this->converter->convert($exampleBefore, $from, $to, $hufPerFrom, $hufPerTo),
            linesWithOriginal: $this->linesWithOriginal($user, $to),
        );
    }

    /**
     * Plan lines with an original amount in $to: they take that amount instead of a converted one.
     */
    private function linesWithOriginal(User $user, Currency $to): int
    {
        return $user->budgetLines()->where('orig_currency', $to->value)->whereNotNull('orig_amount')->count();
    }

    /**
     * Carry out a confirmed preview, all in one database transaction.
     *
     * @throws ValidationException when the settings changed since the preview
     */
    public function handle(User $user, CurrencyChangePreview $preview): void
    {
        DB::transaction(function () use ($user, $preview): void {
            $settings = $user->settings();
            $settings->newQuery()->whereKey($settings->id)->lockForUpdate()->first();
            $settings->refresh();

            $undo = $this->conversionsToUndo($user, $settings->currency, $preview->to);

            if ($settings->currency !== $preview->from || $preview->from === $preview->to || ($undo !== []) !== $preview->restores) {
                throw ValidationException::withMessages(['currency' => __('The base currency changed in the meantime. Please try again.')]);
            }

            if ($preview->restores) {
                foreach ($undo as $conversion) {
                    $this->undo($user, $conversion);
                }
            } else {
                if ($user->currencyConversions()->latest('id')->first()?->to_currency !== $settings->currency) {
                    // A stale chain (or none): nothing earlier can be restored any more.
                    $user->currencyConversions()->delete();
                }

                $this->convert($user, $preview);
            }

            $settings->update(['currency' => $preview->to]);
        });
    }

    /**
     * The conversions to undo, newest first, to get back to $to; empty when $to is new.
     *
     * @return list<CurrencyConversion>
     */
    private function conversionsToUndo(User $user, Currency $current, Currency $to): array
    {
        $chain = $user->currencyConversions()->orderByDesc('id')->get();

        // The newest conversion always ends in the current currency; if not, the chain is stale
        // (handle() then drops it) and nothing can be restored from it.
        if ($chain->isNotEmpty() && $chain->first()->to_currency !== $current) {
            return [];
        }

        $undo = [];

        foreach ($chain as $conversion) {
            $undo[] = $conversion;

            if ($conversion->from_currency === $to) {
                return $undo;
            }
        }

        return [];
    }

    private function convert(User $user, CurrencyChangePreview $preview): void
    {
        $conversion = $user->currencyConversions()->create([
            'from_currency' => $preview->from,
            'to_currency' => $preview->to,
            'from_rate' => $preview->hufPerFrom,
            'to_rate' => $preview->hufPerTo,
            'rate_date' => $preview->rateDate,
        ]);

        $convert = fn (int $amount): int => $this->converter->convert($amount, $preview->from, $preview->to, $preview->hufPerFrom, $preview->hufPerTo);
        $entries = [];

        foreach ($this->rows($user) as $table => $rows) {
            foreach ($rows as $row) {
                $id = $this->toInt($row['id'] ?? null) ?? 0;
                $changes = [];

                foreach ($this->cells($table, $row) as $column => $before) {
                    $after = $this->originalAmount($table, $column, $row, $preview->to) ?? $this->convertCell($table, $column, $before, $convert);

                    if (! $this->same($before, $after)) {
                        $changes[$column] = $after;
                        $entries[] = [
                            'currency_conversion_id' => $conversion->id,
                            'table_name' => $table,
                            'row_id' => $id,
                            'column_name' => $column,
                            'before' => $this->encode($before),
                            'after' => $this->encode($after),
                        ];
                    }
                }

                $this->write($table, $id, $changes);
            }
        }

        foreach (array_chunk($entries, 500) as $chunk) {
            DB::table('currency_conversion_values')->insert($chunk);
        }
    }

    private function undo(User $user, CurrencyConversion $conversion): void
    {
        $inverse = $this->inverse($conversion);
        $entries = $this->allEntries($conversion);

        foreach ($this->rows($user) as $table => $rows) {
            foreach ($rows as $row) {
                $id = $this->toInt($row['id'] ?? null) ?? 0;
                $changes = [];

                foreach ($this->cells($table, $row) as $column => $current) {
                    $original = $this->originalAmount($table, $column, $row, $conversion->from_currency);
                    $value = $this->restoredCell($table, $column, $current, $entries[$table][$id][$column] ?? null, $inverse, $original);

                    if (! $this->same($current, $value)) {
                        $changes[$column] = $value;
                    }
                }

                $this->write($table, $id, $changes);
            }
        }

        $conversion->delete();
    }

    /**
     * Undoing one cell: its "before" value when it still holds the conversion's "after" value;
     * otherwise (added or changed since) the plan line's original amount in that currency if it
     * has one, or the current value converted back with the inverse rate.
     *
     * @param  int|array<array-key, mixed>  $current
     * @param  array{before: string, after: string}|null  $entry
     * @param  Closure(int): int  $inverse
     * @return int|array<array-key, mixed>
     */
    private function restoredCell(string $table, string $column, int|array $current, ?array $entry, Closure $inverse, ?int $original): int|array
    {
        if ($entry !== null && $this->same($current, $this->decode($table, $column, $entry['after']))) {
            return $this->decode($table, $column, $entry['before']);
        }

        return $original ?? $this->convertCell($table, $column, $current, $inverse);
    }

    /**
     * A plan line whose original (foreign) amount is in the target currency takes exactly that.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function originalAmount(string $table, string $column, array $row, Currency $target): ?int
    {
        if ($table !== 'budget_lines' || $column !== 'amount' || ($row['orig_currency'] ?? null) !== $target->value) {
            return null;
        }

        return $this->toInt($row['orig_amount'] ?? null);
    }

    /**
     * @param  int|array<array-key, mixed>  $value
     * @param  Closure(int): int  $convert
     * @return int|array<array-key, mixed>
     */
    private function convertCell(string $table, string $column, int|array $value, Closure $convert): int|array
    {
        if (is_int($value)) {
            return $convert($value);
        }

        foreach (self::JSON_COLUMNS[$table][$column] ?? [] as $path) {
            $value = $this->mapPath($value, explode('.', $path), $convert);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $segments
     * @param  Closure(int): int  $convert
     * @return array<array-key, mixed>
     */
    private function mapPath(array $data, array $segments, Closure $convert): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $data;
        }

        foreach ($segment === '*' ? array_keys($data) : [$segment] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($segments === [] && is_int($data[$key])) {
                $data[$key] = $convert($data[$key]);
            } elseif ($segments !== [] && is_array($data[$key])) {
                $data[$key] = $this->mapPath($data[$key], $segments, $convert);
            }
        }

        return $data;
    }

    /**
     * @return Closure(int): int
     */
    private function inverse(CurrencyConversion $conversion): Closure
    {
        return fn (int $amount): int => $this->converter->convert($amount, $conversion->to_currency, $conversion->from_currency, $conversion->to_rate, $conversion->from_rate);
    }

    /**
     * Every row of the user that holds money (archived pockets included), with its id and
     * money columns. Read without model scopes or events: only the amounts change.
     *
     * @return array<string, array<int, array<array-key, mixed>>>
     */
    private function rows(User $user): array
    {
        $rows = [];

        foreach (array_keys(self::COLUMNS) as $table) {
            $columns = ['id', ...$this->moneyColumns($table), ...($table === 'budget_lines' ? ['orig_amount', 'orig_currency'] : [])];

            $rows[$table] = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get($columns)
                ->map(fn (object $row): array => (array) $row)
                ->values()
                ->all();
        }

        return $rows;
    }

    /**
     * The row's money cells that hold a value: integers, or decoded JSON.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, int|array<array-key, mixed>>
     */
    private function cells(string $table, array $row): array
    {
        $cells = [];

        foreach (self::COLUMNS[$table] as $column) {
            $value = $this->toInt($row[$column] ?? null);

            if ($value !== null) {
                $cells[$column] = $value;
            }
        }

        foreach (array_keys(self::JSON_COLUMNS[$table] ?? []) as $column) {
            $raw = $row[$column] ?? null;
            $value = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($value)) {
                $cells[$column] = $value;
            }
        }

        return $cells;
    }

    /**
     * @return list<string>
     */
    private function moneyColumns(string $table): array
    {
        return [...self::COLUMNS[$table], ...array_keys(self::JSON_COLUMNS[$table] ?? [])];
    }

    /**
     * @param  array<string, int|array<array-key, mixed>>  $changes
     */
    private function write(string $table, int $id, array $changes): void
    {
        if ($changes !== []) {
            DB::table($table)->where('id', $id)->update(array_map(fn (int|array $value): int|string => is_int($value) ? $value : $this->encode($value), $changes));
        }
    }

    /**
     * @return array<string, array<int, array<string, array{before: string, after: string}>>>
     */
    private function allEntries(CurrencyConversion $conversion): array
    {
        $entries = [];

        foreach (DB::table('currency_conversion_values')->where('currency_conversion_id', $conversion->id)->get() as $entry) {
            $entries[$this->toStr($entry->table_name)][$this->toInt($entry->row_id) ?? 0][$this->toStr($entry->column_name)] = [
                'before' => $this->toStr($entry->before),
                'after' => $this->toStr($entry->after),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, array{before: string, after: string}>
     */
    private function entries(CurrencyConversion $conversion, string $table, int $rowId): array
    {
        return $this->allEntries($conversion)[$table][$rowId] ?? [];
    }

    /**
     * @param  int|array<array-key, mixed>  $value
     */
    private function encode(int|array $value): string
    {
        return is_int($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @return int|array<array-key, mixed>
     */
    private function decode(string $table, string $column, string $stored): int|array
    {
        if (! isset(self::JSON_COLUMNS[$table][$column])) {
            return (int) $stored;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Equal values; JSON objects compare regardless of key order (the database may reorder them).
     *
     * @param  int|array<array-key, mixed>  $a
     * @param  int|array<array-key, mixed>  $b
     */
    private function same(int|array $a, int|array $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalize(...), $value);
    }

    private function toInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    private function toStr(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
