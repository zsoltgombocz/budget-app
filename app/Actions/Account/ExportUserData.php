<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Everything stored about a user, as a ZIP of CSV files (one per table). Secrets that only
 * matter for signing in (password hash, two-factor secrets, passkey keys, push keys) stay out.
 */
final class ExportUserData
{
    /** Tables that are not user data even if they have a user_id column. */
    private const array SKIP_TABLES = ['pulse_aggregates', 'pulse_entries', 'pulse_values', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'migrations'];

    /** @var array<string, list<string>> */
    private const array SECRET_COLUMNS = [
        'users' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        'passkeys' => ['credential', 'credential_id'],
        'push_subscriptions' => ['public_key', 'auth_token'],
        'sessions' => ['payload'],
    ];

    /**
     * Child tables without a user_id: table => [foreign key, parent table].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array CHILD_TABLES = [
        'subscription_items' => ['subscription_id', 'subscriptions'],
        'currency_conversion_values' => ['currency_conversion_id', 'currency_conversions'],
    ];

    /**
     * Writes the ZIP to a temporary file and returns its path.
     */
    public function handle(User $user): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($this->tables($user) as $table => $rows) {
            $zip->addFromString($table.'.csv', $this->csv($table, $rows));
        }

        $zip->addFromString('OLVASSEL.txt', $this->readme($user));

        $zip->close();

        return $path;
    }

    /**
     * What the files are, so the export is understandable without the app.
     */
    private function readme(User $user): string
    {
        $currency = $user->settings()->currency;

        return implode("\r\n", [
            'MoneySight – az adataid ('.now()->format('Y-m-d H:i').')',
            '',
            'Minden táblázat egy CSV fájl (pontosvesszővel elválasztva, UTF-8). Minden sor a te adatod.',
            'Összegek: egész számok a pénznem legkisebb egységében ('.$currency->value.', 1 '.$currency->symbol().' = '.$currency->minorPerMajor().' egység).',
            '',
            'transactions.csv – a rögzített költéseid (category_id: a categories.csv sorai)',
            'categories.csv – a kategóriáid',
            'budget_lines.csv – a terved sorai (fix tételek, keretek, félretételek)',
            'periods.csv, period_closes.csv – a hónapjaid és a zárások',
            'pockets.csv, pocket_movements.csv – a perselyek és a mozgásaik',
            'loans.csv, loan_events.csv – a hiteleid és az előtörlesztések',
            'budget_settings.csv – a beállításaid',
            'users.csv – a fiókod (a belépési titkok, pl. kulcsok, nincsenek benne)',
            'a többi fájl: értesítések, munkamenetek, előfizetés, sorozat és mérföldkövek',
        ])."\r\n";
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function tables(User $user): array
    {
        $tables = ['users' => $this->rows('users', 'id', [$user->id])];
        $ids = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! is_string($table) || in_array($table, self::SKIP_TABLES, true) || $table === 'users' || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            $tables[$table] = $this->rows($table, 'user_id', [$user->id]);
            $ids[$table] = array_values(array_filter(array_map(fn (array $row): mixed => $row['id'] ?? null, $tables[$table])));
        }

        foreach (self::CHILD_TABLES as $table => [$column, $parent]) {
            if (Schema::hasTable($table) && isset($ids[$parent])) {
                $tables[$table] = $this->rows($table, $column, $ids[$parent]);
            }
        }

        if (Schema::hasTable('push_subscriptions')) {
            $rows = DB::table('push_subscriptions')->where('subscribable_type', $user->getMorphClass())->where('subscribable_id', $user->id)->get()->all();
            $tables['push_subscriptions'] = array_values(array_map(fn (object $row): array => $this->withoutSecrets('push_subscriptions', $this->toArray($row)), $rows));
        }

        ksort($tables);

        return $tables;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $query = DB::table($table)->whereIn($column, $values);

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        return array_values(array_map(fn (object $row): array => $this->withoutSecrets($table, $this->toArray($row)), $query->get()->all()));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $row): array
    {
        $values = [];

        foreach (get_object_vars($row) as $column => $value) {
            $values[(string) $column] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withoutSecrets(string $table, array $row): array
    {
        return array_diff_key($row, array_flip(self::SECRET_COLUMNS[$table] ?? []));
    }

    /**
     * Semicolon separated with a BOM, so Excel opens the accents right.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(string $table, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a memory stream.');
        }

        $columns = $rows === []
            ? array_values(array_filter(Schema::getColumnListing($table), fn (mixed $column): bool => is_string($column) && ! in_array($column, self::SECRET_COLUMNS[$table] ?? [], true)))
            : array_keys($rows[0]);
        fputcsv($handle, $columns, ';', escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_values($row)), ';', escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }
}
