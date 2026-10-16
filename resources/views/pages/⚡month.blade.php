<?php

use App\Actions\Budget\ChangeFixedItemStatus;
use App\Actions\Budget\DeleteTransaction;
use App\Actions\Budget\RestoreTransaction;
use App\Actions\Budget\UpdateTransaction;
use App\Enums\LineStatus;
use App\Enums\LineType;
use App\Models\Category;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\ClosePreview;
use App\Services\Data\FixedItem;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Support\Dates;
use App\Support\Icons;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Month')] class extends Component {
    /** Largest amount accepted in one entry, in major units (as on the entry sheet). */
    private const int MAX_MAJOR = 1_000_000_000;

    /** How many matches a search over every month lists at most, newest first. */
    public const int SEARCH_ALL_LIMIT = 100;

    #[Url(as: 'periodus')]
    public ?int $periodId = null;

    #[Url(as: 'kategoria')]
    public ?int $categoryId = null;

    /** Free text matched against notes, category names and amounts. */
    public string $search = '';

    /** Search the spending of every month, not only the one shown. */
    public bool $searchAll = false;

    /** The requested ?periodus= was not the user's: the current month is shown instead. */
    public bool $periodNotFound = false;

    /**
     * Deleted spending that the toast's undo can still bring back, by undo token.
     *
     * @var array<string, array{category_id: int, amount: int, occurred_on: string, note: string|null, source: string, client_uuid: string|null, account_id: int|null, external_ref: string|null, pocket_id: int|null, pocket_note: string|null}>
     */
    #[Locked]
    public array $deleted = [];

    /**
     * The fixed items' order when the page was opened, so a ticked item does not jump away.
     *
     * @var list<int>
     */
    #[Locked]
    public array $fixedOrder = [];

    public function mount(): void
    {
        if ($this->periodId === null || ! $this->user()->periods()->whereKey($this->periodId)->exists()) {
            $this->periodNotFound = $this->periodId !== null;
            $this->periodId = app(PeriodService::class)->current($this->user())->id;
        }
    }

    /**
     * Save the corrected spending from the edit sheet. The amount is the typed numpad string.
     *
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function updateTransaction(int $transactionId, array $form): array
    {
        $user = $this->user();
        $transaction = $user->transactions()->find($transactionId);

        if (! $transaction instanceof Transaction) {
            return ['ok' => false, 'errors' => ['transaction' => __('This spending was not found.')]];
        }

        $currency = $user->settings()->currency;
        $amount = is_string($form['amount'] ?? null) ? Money::parse($form['amount'], $currency) : null;

        $validator = Validator::make([
            'category' => $form['category'] ?? null,
            'date' => $form['date'] ?? null,
            'note' => $form['note'] ?? null,
        ], [
            'category' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.app(PeriodService::class)->today($user->settings())->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['date.before_or_equal' => __('You cannot record spending on a future day.')]);

        $errors = $validator->fails() ? $this->firstMessages($validator->errors()->toArray()) : [];

        if ($amount === null || $amount > self::MAX_MAJOR * $currency->minorPerMajor()) {
            $errors['amount'] = __('Enter a valid amount.');
        } elseif ($amount < 1) {
            $errors['amount'] = __('The amount must be greater than zero.');
        }

        if ($errors !== [] || $amount === null) {
            return ['ok' => false, 'errors' => $errors];
        }

        /** @var array{category: int|string, date: string, note: ?string} $validated */
        $validated = $validator->validated();

        try {
            app(UpdateTransaction::class)->handle($user, $transaction, (int) $validated['category'], $amount, CarbonImmutable::parse($validated['date']), $validated['note']);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => $this->firstMessages($exception->errors())];
        }

        $this->refresh();
        $this->dispatch('budget-updated');

        // A corrected spending that no longer matches the search or the category leaves the list: say so.
        $stillListed = ! $this->isFiltered() || collect($this->days)->contains(fn (array $day): bool => collect($day['rows'])->contains(fn (array $row): bool => ($row['id'] ?? null) === $transactionId));
        $this->dispatch('app-toast', title: __('Spending updated.'), subtitle: $stillListed ? null : __('It no longer matches the search, so it left the list.'));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Delete a spending; the toast's undo brings it back as it was.
     */
    public function delete(int $transactionId): void
    {
        $transaction = $this->user()->transactions()->find($transactionId);

        if (! $transaction instanceof Transaction) {
            return;
        }

        try {
            $snapshot = app(DeleteTransaction::class)->handle($this->user(), $transaction);
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: $this->firstMessages($exception->errors())[array_key_first($exception->errors())] ?? '', icon: 'error');

            return;
        }

        $token = (string) Str::uuid();
        $this->deleted = [...array_slice($this->deleted, -4, null, true), $token => $snapshot];
        $this->refresh();

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: __('Entry removed.'),
            subtitle: $snapshot['pocket_id'] !== null ? __('The money went back into the pocket.') : null,
            icon: 'delete',
            undo: 'month-restore-transaction',
            params: ['token' => $token],
        );
    }

    #[On('month-restore-transaction')]
    public function restoreTransaction(string $token): void
    {
        $snapshot = $this->deleted[$token] ?? null;

        if ($snapshot === null) {
            return;
        }

        try {
            app(RestoreTransaction::class)->handle($this->user(), $snapshot);
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: __('The spending could not be restored.'), subtitle: $this->firstMessages($exception->errors())[array_key_first($exception->errors())] ?? null, icon: 'error');

            return;
        }

        unset($this->deleted[$token]);
        $this->refresh();

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Spending restored.'), icon: 'undo');
    }

    /**
     * Tick a fixed item off for the period, or untick it; the toast offers the way back.
     */
    public function togglePaid(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->toggle($this->user(), $this->period, $this->overview->fixedItems, $budgetLineId));
    }

    /**
     * Skip a plain fixed item this period: it counts as 0.
     */
    public function skipLine(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->set($this->user(), $this->period, $this->overview->fixedItems, $budgetLineId, LineStatus::Skipped));
    }

    /**
     * A skipped item comes up after all: back to due.
     */
    public function unskipLine(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->set($this->user(), $this->period, $this->overview->fixedItems, $budgetLineId, LineStatus::Open));
    }

    #[On(ChangeFixedItemStatus::UNDO_EVENT)]
    public function undoFixedItem(int $budgetLineId, string $status): void
    {
        if (resolve(ChangeFixedItemStatus::class)->undo($this->user(), $this->period, $this->overview->fixedItems, $budgetLineId, $status)) {
            $this->refresh();
            $this->dispatch('budget-updated');
        }
    }

    /**
     * @param  array{title: string, subtitle: string|null, icon: string, undo: string, params: array{budgetLineId: int, status: string}}|null  $toast
     */
    private function fixedItemChanged(?array $toast): void
    {
        if ($toast === null) {
            return;
        }

        $this->refresh();
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', ...$toast);
    }

    public function showPeriod(int $periodId): void
    {
        if ($this->user()->periods()->whereKey($periodId)->exists()) {
            $this->periodId = $periodId;
            $this->periodNotFound = false;
            $this->fixedOrder = [];
            $this->refresh();
        }
    }

    public function clearFilters(): void
    {
        $this->categoryId = null;
        $this->search = '';
        $this->searchAll = false;
    }

    /**
     * Whether the list shows search results from every month instead of the month shown.
     */
    public function searchesAllMonths(): bool
    {
        return $this->searchAll && trim($this->search) !== '';
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        unset($this->period, $this->days, $this->overview, $this->neighbours, $this->closePreview, $this->fixedItems, $this->filterCategories, $this->editCategories);
    }

    /**
     * The month against its plan: how far "if you spend every budget in full" is from the
     * planned leftover, and why.
     */
    #[Computed]
    public function deviation(): \App\Services\Data\PlanDeviation
    {
        return $this->overview->deviation;
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function overview(): \App\Services\Data\Overview
    {
        $today = app(PeriodService::class)->today($this->user()->settings());

        return app(OverviewService::class)->forPeriod($this->user(), $this->period, $today);
    }

    /**
     * What the closing wizard would show if the period were closed today.
     */
    #[Computed]
    public function closePreview(): ClosePreview
    {
        return app(PeriodCloser::class)->preview($this->user(), $this->period);
    }

    /**
     * The fixed items in the order they had when the page was opened.
     *
     * @return list<FixedItem>
     */
    #[Computed]
    public function fixedItems(): array
    {
        $items = $this->overview->fixedItems;
        $ids = array_values(array_filter(array_map(fn (FixedItem $item): ?int => $item->line->lineId, $items), fn (?int $id): bool => $id !== null));

        if ($this->fixedOrder === []) {
            $this->fixedOrder = $ids;
        } else {
            $this->fixedOrder = [...array_values(array_intersect($this->fixedOrder, $ids)), ...array_values(array_diff($ids, $this->fixedOrder))];
        }

        $position = array_flip($this->fixedOrder);
        usort($items, fn (FixedItem $a, FixedItem $b): int => ($position[$a->line->lineId ?? -1] ?? PHP_INT_MAX) <=> ($position[$b->line->lineId ?? -1] ?? PHP_INT_MAX));

        return $items;
    }

    /**
     * The closed period's variable categories, planned against actual, from the closing record,
     * and the fixed items marked as not paid that period.
     *
     * @return list<array{category_id: int, name: string, planned: int, actual: int, diff: int, deviates: bool, skipped: bool}>
     */
    #[Computed]
    public function closedBreakdown(): array
    {
        if ($this->period->isOpen()) {
            return [];
        }

        $categories = $this->period->close()->first()->breakdown['categories'] ?? [];
        $rows = [];

        foreach (is_array($categories) ? $categories : [] as $row) {
            if (is_array($row) && (($row['type'] ?? null) === LineType::Variable->value || ($row['skipped'] ?? false) === true)) {
                $rows[] = [
                    'category_id' => (int) ($row['category_id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'planned' => (int) ($row['planned'] ?? 0),
                    'actual' => (int) ($row['actual'] ?? 0),
                    'diff' => (int) ($row['diff'] ?? 0),
                    'deviates' => (bool) ($row['deviates'] ?? false),
                    'skipped' => (bool) ($row['skipped'] ?? false),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{previous: int|null, next: int|null}
     */
    #[Computed]
    public function neighbours(): array
    {
        $periods = $this->user()->periods();

        return [
            'previous' => (clone $periods)->whereDate('starts_on', '<', $this->period->starts_on->toDateString())->orderByDesc('starts_on')->value('id'),
            'next' => (clone $periods)->whereDate('starts_on', '>', $this->period->starts_on->toDateString())->orderBy('starts_on')->value('id'),
        ];
    }

    public function isFiltered(): bool
    {
        return $this->categoryId !== null || trim($this->search) !== '';
    }

    /**
     * Rows grouped by day, newest first: spending, money moved between pockets and the budget,
     * "didn't spend" marks and the payday. The day's total is what came out of the budget:
     * spending paid from a pocket is listed but not added. A category filter or a search shows
     * the matching spending only; a search over every month shows the newest matches of all.
     *
     * @return list<array{date: CarbonImmutable, total: int, payday: bool, fromPocket: bool, rows: list<array<string, mixed>>}>
     */
    #[Computed]
    public function days(): array
    {
        $period = $this->period;
        $today = app(PeriodService::class)->today($this->user()->settings());
        $currency = $this->user()->settings()->currency;
        $search = trim($this->search);
        $days = [];

        $allMonths = $this->searchesAllMonths();
        $transactions = ($allMonths ? $this->user()->transactions()->with('period')->limit(self::SEARCH_ALL_LIMIT) : $period->transactions())->with(['category', 'pocket'])
            ->when($this->categoryId, fn ($query, int $categoryId) => $query->where('category_id', $categoryId))
            ->when($search !== '', function ($query) use ($search, $currency): void {
                $searchAmount = $this->searchAmount($search, $currency);
                $query->where(function ($query) use ($search, $searchAmount): void {
                    $query->where('note', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'));

                    if ($searchAmount !== null) {
                        $query->orWhere('amount', $searchAmount);
                    }
                });
            })
            ->orderByDesc('occurred_on')->orderByDesc('id')->get();

        foreach ($transactions as $transaction) {
            $key = $transaction->occurred_on->toDateString();
            $days[$key] ??= ['date' => $transaction->occurred_on, 'total' => 0, 'payday' => false, 'fromPocket' => false, 'rows' => []];
            if ($transaction->pocket_id === null) {
                $days[$key]['total'] += $transaction->amount;
            } else {
                $days[$key]['fromPocket'] = true;
            }
            $days[$key]['rows'][] = [
                'kind' => 'spending',
                'id' => $transaction->id,
                'icon' => Icons::forCategory($transaction->category?->icon),
                'title' => $transaction->category?->name ?? '',
                'note' => $transaction->note,
                'amount' => $transaction->amount,
                'pocket' => $transaction->pocket?->name,
                'edit' => [
                    'id' => $transaction->id,
                    'title' => $transaction->category?->name ?? '',
                    'amount' => str_replace('.', ',', Money::toInput($transaction->amount, $currency)),
                    'category' => $transaction->category_id,
                    'date' => $key,
                    'note' => $transaction->note ?? '',
                    'pocket' => $transaction->pocket?->name,
                    'pocketArchived' => $transaction->pocket?->trashed() ?? false,
                    // A result from another, closed month can only be looked at.
                    'readonly' => $allMonths && ! ($transaction->period?->isOpen() ?? false),
                ],
            ];
        }

        if (! $this->isFiltered()) {
            $marks = $this->user()->dayMarks()
                ->whereDate('date', '>=', $period->starts_on->toDateString())
                ->whereDate('date', '<=', $period->ends_on->toDateString())
                ->get();

            foreach ($marks as $mark) {
                $key = $mark->date->toDateString();
                $days[$key] ??= ['date' => $mark->date, 'total' => 0, 'payday' => false, 'fromPocket' => false, 'rows' => []];
                $days[$key]['rows'][] = ['kind' => 'no-spend', 'markedAt' => $mark->created_at?->setTimezone($this->user()->settings()->timezone)->format('H:i')];
            }

            // Money moved between a pocket and this month's budget, as the pocket sheet recorded it.
            $moves = \App\Models\PocketMovement::query()->with('pocket')
                ->where('period_id', $period->id)->where('to_budget', true)
                ->orderByDesc('id')->get();

            foreach ($moves as $move) {
                $key = $move->occurred_on->toDateString();
                $days[$key] ??= ['date' => $move->occurred_on, 'total' => 0, 'payday' => false, 'fromPocket' => false, 'rows' => []];
                $days[$key]['rows'][] = ['kind' => $move->amount > 0 ? 'to-pocket' : 'from-pocket', 'pocket' => $move->pocket->name ?? '', 'amount' => abs($move->amount), 'note' => $move->note];
            }

            if (! $period->starts_on->greaterThan($today)) {
                $key = $period->starts_on->toDateString();
                // Skipped items count as 0, so they are not among what the fixed items take.
                $fixed = array_values(array_filter($this->overview->fixedItems, fn (FixedItem $item): bool => ! $item->isSkipped()));
                $days[$key] ??= ['date' => $period->starts_on, 'total' => 0, 'payday' => true, 'fromPocket' => false, 'rows' => []];
                $days[$key]['payday'] = true;
                $days[$key]['rows'][] = ['kind' => 'income', 'amount' => $period->income()];
                if ($fixed !== []) {
                    $days[$key]['rows'][] = ['kind' => 'fixed', 'count' => count($fixed), 'amount' => array_sum(array_map(fn (FixedItem $item): int => $item->line->counted(), $fixed))];
                }
            }
        }

        krsort($days);

        return array_values($days);
    }

    #[Computed]
    public function category(): ?Category
    {
        return $this->categoryId === null ? null : $this->user()->categories()->withTrashed()->find($this->categoryId);
    }

    /**
     * Filter chips: the categories with spending in this period, and the one filtered by.
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    #[Computed]
    public function filterCategories(): \Illuminate\Support\Collection
    {
        $ids = $this->period->transactions()->distinct()->pluck('category_id')->push($this->categoryId)->filter()->unique();

        return $this->user()->categories()->withTrashed()->whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get()->toBase();
    }

    /**
     * Categories a spending can be moved to: the variable ones, plus those already used in
     * this period (so an entry's own category is always listed).
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    #[Computed]
    public function editCategories(): \Illuminate\Support\Collection
    {
        $used = $this->period->transactions()->distinct()->pluck('category_id');

        return $this->user()->categories()->withTrashed()
            ->where(fn ($query) => $query->where(fn ($live) => $live->whereNull('deleted_at')->where('type', LineType::Variable))->orWhereIn('id', $used))
            ->orderBy('sort')->orderBy('name')->get()->toBase();
    }

    /**
     * The search text as an amount in minor units, if it looks like one ("12 500", "12,500", "24,99").
     */
    private function searchAmount(string $search, \App\Enums\Currency $currency): ?int
    {
        $compact = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $search) ?? '';

        if ($currency->decimals() === 0) {
            $compact = str_replace([',', '.'], '', $compact);
        }

        return Money::parse($compact, $currency);
    }

    /**
     * The first message of each field, as the sheets show one line per field.
     *
     * @param  array<string, array<int, string>>  $messages
     * @return array<string, string>
     */
    private function firstMessages(array $messages): array
    {
        return array_map(fn (array $fieldMessages): string => $fieldMessages[0] ?? '', $messages);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $period = $this->period;
    $overview = $this->overview;
    $forecast = $overview->forecast;
    $today = $overview->today;
    $closeRecord = $period->isOpen() ? null : $period->close()->first();
    $hasSpending = collect($this->days)->contains(fn ($day) => collect($day['rows'])->contains('kind', 'spending'));
    $filtered = $this->isFiltered();
    $currency = user_currency();
    $previousId = $this->neighbours['previous'];
    $nextId = $this->neighbours['next'];
    $navClasses = 'aria-disabled:cursor-default aria-disabled:bg-transparent aria-disabled:text-muted aria-disabled:ring-1 aria-disabled:ring-inset aria-disabled:ring-line aria-disabled:active:scale-100';
@endphp

<div @if (! $period->isOpen()) data-hides-record-button @endif x-data="monthPage({
        decimals: {{ $currency->decimals() }},
        locale: @js(str_replace('_', '-', app()->getLocale())),
        today: @js($today->toDateString()),
        failed: @js(__('Could not save. Check your connection and try again.')),
        categoryNames: @js($period->isOpen() ? $this->editCategories->pluck('name', 'id') : []),
    })">
    @if ($periodNotFound)
        <div class="mx-4 mt-3 flex items-start gap-2 rounded-[18px] bg-warn/12 px-4 py-3 text-[13px] text-ink-2" role="status" data-test="period-not-found">
            <x-ui.icon name="info" :size="18" class="text-warn" />
            <span>{{ __('That month was not found, so the current one is shown.') }}</span>
        </div>
    @endif

    <div class="flex items-end justify-between gap-2 px-6 pt-3.5">
        <div class="min-w-0">
            <h1 class="truncate text-[30px] font-semibold tracking-[-0.03em]">{{ Dates::monthName($period->nameDate()) }}</h1>
            <div class="num mt-0.5 text-[13px] text-muted">
                {{ Dates::range($period->starts_on, $period->ends_on) }} ·
                @if (! $period->isOpen())
                    {{ __('closed') }}
                @elseif ($period->contains($today))
                    {{ trans_choice('{1} :count day left|[2,*] :count days left', $forecast->remainingDays, ['count' => $forecast->remainingDays]) }}
                @else
                    {{ __('open') }}
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            @foreach ([['chevron_left', __('Previous period'), $previousId, 'previous-period'], ['chevron_right', __('Next period'), $nextId, 'next-period']] as [$navIcon, $navLabel, $navId, $navTest])
                @if ($navId === null)
                    <x-ui.icon-button :icon="$navIcon" :label="$navLabel" aria-disabled="true" :class="$navClasses" :data-test="$navTest" />
                @else
                    <x-ui.icon-button :icon="$navIcon" :label="$navLabel" wire:click="showPeriod({{ $navId }})" :data-test="$navTest" />
                @endif
            @endforeach
        </div>
    </div>

    <x-ui.card class="mx-4 mb-1 mt-[18px] rounded-[22px] px-[18px] py-4">
        @if ($period->isOpen())
            @php
                $closeLeftover = $this->closePreview->leftover();
                $deviation = $this->deviation;
                $deviationAmount = $deviation->amount();
            @endphp
            <div class="mb-3.5 border-b border-line pb-3.5" data-test="close-now">
                <div class="text-[13px] text-muted">{{ __('If the month ended today') }}</div>
                <x-ui.amount :value="$closeLeftover" size="lg" :tone="$closeLeftover >= 0 ? 'accent' : 'danger'" class="mt-1 [&>span:first-child]:text-[26px]" />
                <div class="mt-1.5 text-xs text-pretty text-muted">{{ __('Based on your spending so far: what you have not spent yet counts as leftover.') }}</div>
            </div>
            {{-- ForecastService::deviation(): "if you spend every budget in full" against the planned leftover. --}}
            <div class="mb-3.5 border-b border-line pb-3.5" data-test="plan-deviation">
                <div @class(['num text-[15px] font-semibold', 'text-accent-strong' => $deviationAmount > 0, 'text-danger' => $deviationAmount < 0, 'text-ink' => $deviationAmount === 0]) data-test="plan-deviation-verdict">
                    @if ($deviationAmount > 0)
                        {{ __(':amount better than the plan', ['amount' => money($deviationAmount)]) }}
                    @elseif ($deviationAmount < 0)
                        {{ __(':amount over the plan', ['amount' => money(-$deviationAmount)]) }}
                    @else
                        {{ __('You are on plan') }}
                    @endif
                </div>
                @if ($deviation->items !== [])
                    <ul class="num mt-1.5 text-[13px]" aria-label="{{ __('Why it differs from the plan') }}" data-test="plan-deviation-items">
                        @foreach ($deviation->items as $deviationItem)
                            <li class="flex justify-between gap-3 py-0.5">
                                <span class="min-w-0 truncate text-muted">{{ $deviationItem->label() }}</span>
                                <span @class(['shrink-0', 'text-danger' => $deviationItem->reason === \App\Enums\DeviationReason::Overspent, 'text-ink-2' => $deviationItem->reason !== \App\Enums\DeviationReason::Overspent])>{{ $deviationItem->amount > 0 ? '+'.money($deviationItem->amount) : '−'.money(-$deviationItem->amount) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-1 text-xs text-pretty text-muted">{{ __('Only going over a budget, a skipped fixed item or a pocket move changes this.') }}</div>
                @endif
            </div>
        @endif

        <div class="num flex items-baseline justify-between text-[13px] text-muted">
            <span>{{ $period->isOpen() ? __('Variable spending so far') : __('Variable spending total') }}</span>
            <span>{{ __('budget :amount', ['amount' => money($forecast->variablePlanned)]) }}</span>
        </div>
        <x-ui.amount :value="$forecast->variableSpent" class="mt-1" />
        <x-ui.bar :label="$period->isOpen() ? __('Variable spending so far') : __('Variable spending total')" :value="$forecast->variablePlanned > 0 ? $forecast->variableSpent / $forecast->variablePlanned : 0" :tone="$forecast->variableSpent > $forecast->variablePlanned ? 'danger' : ($forecast->variablePlanned > 0 && $forecast->variableSpent / $forecast->variablePlanned >= 0.8 ? 'warn' : 'accent')" class="mt-2.5" />
        @if (! $period->isOpen() && $forecast->variablePlanned > 0)
            <div @class(['num mt-1.5 text-[13px] font-medium', 'text-danger' => $forecast->variableSpent > $forecast->variablePlanned, 'text-muted' => $forecast->variableSpent <= $forecast->variablePlanned]) data-test="variable-verdict">
                @if ($forecast->variableSpent > $forecast->variablePlanned)
                    {{ __(':amount over the budget', ['amount' => money($forecast->variableSpent - $forecast->variablePlanned)]) }}
                @elseif ($forecast->variableSpent < $forecast->variablePlanned)
                    {{ __(':amount under the budget', ['amount' => money($forecast->variablePlanned - $forecast->variableSpent)]) }}
                @else
                    {{ __('Exactly on budget') }}
                @endif
            </div>
        @endif

        @if ($period->isOpen() && ! app(\App\Services\PeriodCloser::class)->canClose(auth()->user(), $period))
            <div class="mt-3.5 flex h-12 items-center justify-center gap-2 rounded-2xl border border-dashed border-line text-[13px] text-muted" data-test="close-not-yet">
                <x-ui.icon name="schedule" :size="18" />{{ __('You can close the month from :date.', ['date' => Dates::short(app(\App\Services\PeriodCloser::class)->closableFrom($period))]) }}
            </div>
        @elseif ($period->isOpen())
            <a href="{{ route('close', $period) }}" wire:navigate class="mt-3.5 flex h-12 items-center justify-center gap-2 rounded-2xl bg-surface-2 text-[15px] font-semibold" data-test="start-close">
                <x-ui.icon name="task_alt" :size="20" class="text-accent" />{{ __('Close the month') }}
            </a>
        @elseif ($closeRecord)
            <a href="{{ route('close', $period) }}" wire:navigate class="mt-3.5 grid grid-cols-3 gap-2 rounded-2xl bg-surface-2 px-3 py-2.5 text-xs" data-test="close-summary">
                <span><span class="block text-muted">{{ __('Leftover') }}</span><span class="num block text-sm font-semibold">{{ money($closeRecord->leftover) }}</span></span>
                <span><span class="block text-muted">{{ __('To the reserve') }}</span><span class="num block text-sm">{{ money($closeRecord->to_reserve) }}</span></span>
                <span><span class="block text-muted">{{ __('Rest') }}</span><span class="num block text-sm">{{ money($closeRecord->to_invest) }}</span></span>
            </a>
            <livewire:reopen-closing :period-id="$period->id" :key="'reopen-'.$period->id" />
        @endif
    </x-ui.card>

    {{-- Closed month: plan against actual per variable category, from the closing record. --}}
    @if ($this->closedBreakdown !== [])
        <div class="mx-4 mt-3 rounded-[22px] bg-surface px-2 py-1.5" data-test="closed-breakdown">
            <table class="num w-full table-fixed border-separate border-spacing-0 text-sm">
                <caption class="sr-only">{{ __('Plan against actual, by category') }}</caption>
                <colgroup><col><col class="w-16"><col class="w-16"><col class="w-[70px]"></colgroup>
                <thead>
                    <tr class="text-xs font-semibold text-muted">
                        <th scope="col" class="px-2.5 pb-1.5 pt-2.5 text-left font-semibold">{{ __('Category') }}</th>
                        <th scope="col" class="pb-1.5 pl-1.5 pt-2.5 text-right font-semibold">{{ __('Plan') }}</th>
                        <th scope="col" class="pb-1.5 pl-1.5 pt-2.5 text-right font-semibold">{{ __('Actual') }}</th>
                        <th scope="col" class="pb-1.5 pl-1.5 pr-2.5 pt-2.5 text-right font-semibold">{{ __('Diff.') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->closedBreakdown as $row)
                        @php
                            // Going over a budget is red, with words; a skipped fixed item just says so.
                            $over = $row['diff'] > 0;
                            $cell = $over ? 'bg-danger/10' : ($row['diff'] < 0 && $row['deviates'] && ! $row['skipped'] ? 'bg-accent/8' : '');
                        @endphp
                        <tr wire:key="closed-row-{{ $row['category_id'] }}" @if ($over) data-test="over-budget-row" @endif>
                            <th scope="row" @class(['rounded-l-[14px] px-2.5 py-[11px] text-left align-top font-normal', $cell])>
                                <span class="block truncate">{{ $row['name'] }}</span>
                                @if ($row['skipped'])
                                    <span class="block text-xs text-muted" data-test="skipped-row">{{ __('not paid') }}</span>
                                @elseif ($row['diff'] !== 0)
                                    <span @class(['block text-xs leading-snug', 'text-danger' => $over, 'text-ink-2' => ! $over]) data-test="closed-row-verdict">{{ $over ? __('over the budget by :amount', ['amount' => money($row['diff'])]) : __('under the budget by :amount', ['amount' => money(-$row['diff'])]) }}</span>
                                @endif
                            </th>
                            <td @class(['py-[11px] pl-1.5 text-right align-top text-muted', $cell])>{{ money_number($row['planned']) }}</td>
                            <td @class(['py-[11px] pl-1.5 text-right align-top', $cell])>{{ money_number($row['actual']) }}</td>
                            <td @class(['rounded-r-[14px] py-[11px] pl-1.5 pr-2.5 text-right align-top font-semibold', $cell, 'text-danger' => $over, 'text-accent' => $row['diff'] < 0, 'text-muted' => $row['diff'] === 0])>
                                {{ $over ? '+' : '' }}{{ $row['diff'] === 0 ? '0' : money_number($row['diff']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{--
        Open month: the fixed items with their tick, in a stable order while the page is open.
        Collapsed to one summary row so the spending list stays near the top; the open state is
        remembered for the browser session.
    --}}
    @if ($period->isOpen() && $this->fixedItems !== [])
        @php
            $fixedDone = count(array_filter($this->fixedItems, fn ($item) => $item->paid));
            $fixedDue = count(array_filter($this->fixedItems, fn ($item) => $item->isOverdue($today)));
            $fixedSkipped = count(array_filter($this->fixedItems, fn ($item) => $item->isSkipped()));
            $fixedLeft = count($this->fixedItems) - $fixedDone - $fixedSkipped;
        @endphp
        <x-ui.card class="mx-4 mt-3 px-[18px]" data-test="fixed-items"
                   x-data="{
                        open: false,
                        init() { try { this.open = sessionStorage.getItem('month-fixed-open') === '1' } catch (e) {} },
                        toggle() {
                            this.open = ! this.open
                            try { sessionStorage.setItem('month-fixed-open', this.open ? '1' : '0') } catch (e) {}
                        },
                   }">
            <button type="button" x-on:click="toggle()" :aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-controls="month-fixed-list"
                    class="focus-ring flex min-h-[56px] w-full items-center justify-between gap-3 rounded-xl py-2 text-left" data-test="fixed-summary">
                <span class="min-w-0">
                    <span class="block text-[15px] font-semibold">{{ __('Fixed items') }}</span>
                    <span class="num mt-0.5 block text-[13px] text-muted">
                        {{ __(':count done', ['count' => $fixedDone]) }}@if ($fixedSkipped > 0)<span data-test="fixed-skipped"> · {{ __(':count skipped', ['count' => $fixedSkipped]) }}</span>@endif@if ($fixedLeft > 0) · {{ __(':count left', ['count' => $fixedLeft]) }}@endif@if ($fixedDue > 0)<span class="font-medium text-warn" data-test="fixed-due"> · {{ __(':count due', ['count' => $fixedDue]) }}</span>@endif
                    </span>
                </span>
                <x-ui.icon name="expand_more" :size="22" class="text-muted transition-transform" x-bind:class="open && 'rotate-180'" />
            </button>
            <div id="month-fixed-list" x-show="open" x-cloak class="border-t border-line pb-2" data-test="fixed-list">
            @foreach ($this->fixedItems as $item)
                <x-fixed-item-row :item="$item" :today="$today" @class(['border-b border-line' => ! $loop->last]) wire:key="fixed-{{ $item->line->lineId }}" />
            @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Search and category filter --}}
    <div class="mx-4 mt-4" data-test="month-filters">
        <label class="flex h-11 items-center gap-2 rounded-[14px] bg-surface px-3.5 focus-within:ring-2 focus-within:ring-accent">
            <x-ui.icon name="search" :size="20" class="text-muted" />
            <input type="search" wire:model.live.debounce.400ms="search" maxlength="100" enterkeyhint="search" autocomplete="off"
                   placeholder="{{ __('Search notes or amounts') }}" aria-label="{{ __('Search notes or amounts') }}"
                   class="min-w-0 flex-1 bg-transparent text-[15px] text-ink outline-none placeholder:text-muted" data-test="month-search">
        </label>
        @if (trim($search) !== '')
            <label class="mt-2 flex min-h-11 items-center gap-2.5 px-1.5 text-[13px] text-ink-2" data-test="search-all">
                <input type="checkbox" wire:model.live="searchAll" class="size-[18px] accent-[var(--app-accent)]">
                {{ __('Search in every month') }}
            </label>
        @endif
        @if ($this->filterCategories->isNotEmpty() && ! $this->searchesAllMonths())
            <div class="no-scrollbar -mx-4 mt-2 flex gap-2 overflow-x-auto px-4" role="group" aria-label="{{ __('Filter by category') }}" data-test="category-filter">
                <x-ui.choice :selected="$categoryId === null" wire:click="$set('categoryId', null)" class="shrink-0 rounded-xl px-3.5 text-[13px]">{{ __('All') }}</x-ui.choice>
                @foreach ($this->filterCategories as $filterCategory)
                    <x-ui.choice :selected="$categoryId === $filterCategory->id" wire:key="filter-{{ $filterCategory->id }}" wire:click="$set('categoryId', {{ $categoryId === $filterCategory->id ? 'null' : $filterCategory->id }})" class="shrink-0 rounded-xl px-3.5 text-[13px]" data-test="filter-{{ $filterCategory->id }}">{{ $filterCategory->name }}</x-ui.choice>
                @endforeach
            </div>
        @endif
    </div>

    @foreach ($this->days as $day)
        <div class="px-4" wire:key="day-{{ $day['date']->toDateString() }}">
            <div class="num flex justify-between gap-3 px-1.5 pb-2 pt-[18px] text-[13px]">
                <span class="min-w-0 font-semibold text-ink-2">{{ Dates::day($day['date'], $today) }}@if ($day['payday']) · {{ __('Payday') }}@endif</span>
                @if ($day['fromPocket'])
                    <span class="shrink-0 whitespace-nowrap text-muted" data-test="day-total">{{ __(':amount from the budget', ['amount' => money($day['total'])]) }}</span>
                @elseif ($day['total'] > 0 || ! $day['payday'])
                    <span class="shrink-0 whitespace-nowrap text-muted" data-test="day-total">{{ money($day['total']) }}</span>
                @endif
            </div>
            <div class="rounded-[20px] bg-surface px-4">
                @foreach ($day['rows'] as $row)
                    @php $border = ! $loop->last; @endphp
                    @if ($row['kind'] === 'spending')
                        <button type="button" x-on:click="openEdit(@js($row['edit']))" wire:key="transaction-{{ $row['id'] }}"
                                @class(['focus-ring grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px] text-left', 'border-b border-line' => $border]) data-test="transaction">
                            <x-ui.icon-tile :icon="$row['icon']" :size="36" />
                            <span class="min-w-0"><span class="block truncate text-[15px]">{{ $row['title'] }}</span>@if ($row['pocket'])<span class="mt-0.5 block truncate text-xs text-accent-strong">{{ __('paid from :pocket', ['pocket' => $row['pocket']]) }}@if ($row['note']) · {{ $row['note'] }}@endif</span>@elseif ($row['note'])<span class="mt-0.5 block truncate text-xs text-muted">{{ $row['note'] }}</span>@endif</span>
                            <x-ui.amount size="sm" :value="$row['amount']" @class(['text-[15px] font-medium', 'text-ink-2' => $row['pocket']]) />
                        </button>
                    @elseif ($row['kind'] === 'no-spend')
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="do_not_disturb_on" tone="accent" :size="36" />
                            <span><span class="block text-[15px] text-ink-2">{{ __("Didn't spend") }}</span>@if ($row['markedAt'])<span class="mt-0.5 block text-xs text-muted">{{ __('marked at :time', ['time' => $row['markedAt']]) }}</span>@endif</span>
                            <span></span>
                        </div>
                    @elseif (in_array($row['kind'], ['to-pocket', 'from-pocket'], true))
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border]) data-test="pocket-move">
                            <x-ui.icon-tile icon="savings" tone="accent" :size="36" />
                            <span class="min-w-0"><span class="block truncate text-[15px]">{{ $row['kind'] === 'to-pocket' ? __('Put into pockets') : __('From pockets into the budget') }}</span><span class="mt-0.5 block truncate text-xs text-muted">{{ $row['pocket'] }}@if ($row['note']) · {{ $row['note'] }}@endif</span></span>
                            <span @class(['num whitespace-nowrap text-[15px] font-medium', 'text-accent-strong' => $row['kind'] === 'from-pocket'])>{{ $row['kind'] === 'from-pocket' ? '+' : '−' }}{{ money($row['amount']) }}</span>
                        </div>
                    @elseif ($row['kind'] === 'income')
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="payments" tone="accent" :size="36" />
                            <span class="text-[15px]">{{ __('Salary') }}</span>
                            <x-ui.amount size="sm" :value="$row['amount']" signed class="text-[15px] font-medium text-accent" />
                        </div>
                    @else
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="autorenew" tone="muted" :size="36" />
                            <span><span class="block text-[15px]">{{ __('Fixed items') }}</span><span class="mt-0.5 block text-xs text-muted">{{ trans_choice('{1} :count item, counted at its planned amount|[2,*] :count items, counted at their planned amount', $row['count'], ['count' => $row['count']]) }}</span></span>
                            <x-ui.amount size="sm" :value="$row['amount']" class="text-[15px] font-medium" />
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach

    @if (! $hasSpending && $filtered)
        <div class="flex flex-col items-center gap-2.5 px-10 pb-6 pt-12 text-center" data-test="no-results">
            <x-ui.icon-tile icon="receipt_long" tone="muted" :size="56" class="!bg-surface" />
            <div class="mt-1 text-lg font-semibold">{{ __('No matching spending') }}</div>
            <div class="text-sm leading-normal text-pretty text-muted">{{ $this->searchesAllMonths() ? __('No spending in any month matches the search.') : __('Nothing in this month matches the search or the category.') }}</div>
            <x-ui.button size="md" variant="secondary" wire:click="clearFilters" class="mt-2" data-test="clear-filters">{{ __('Clear filters') }}</x-ui.button>
        </div>
    @elseif (! $hasSpending)
        <div class="flex flex-col items-center gap-2.5 px-10 pb-6 pt-12 text-center" data-test="empty-state">
            <x-ui.icon-tile icon="event_note" tone="muted" :size="56" class="!bg-surface" />
            <div class="mt-1 text-lg font-semibold">{{ __('Clean slate') }}</div>
            <div class="text-sm leading-normal text-pretty text-muted">{{ $period->isOpen() ? __('Your daily spending and the “didn’t spend” days land here. Record the first one with the + button.') : __('No spending or “didn’t spend” day was recorded in this period.') }}</div>
        </div>
    @endif

    {{-- A spending: correct it (open period) or just look at it (closed period). --}}
    <x-ui.sheet show="edit" close="closeEdit()" title="editTitle()" data-test="transaction-sheet">
        <div class="py-3 text-center" :class="! filled('amount') && 'text-muted'">
            <x-ui.amount bind="display('amount')" size="xl" />
        </div>
        <div class="mb-1 text-center text-xs text-danger" x-show="errors.amount" x-text="errors.amount" data-test="edit-error-amount"></div>
        <p class="mx-auto mb-2 max-w-xs text-center text-xs leading-snug text-accent" x-show="edit?.pocket && ! edit?.pocketArchived"
           x-text="@js(__('Paid from the :pocket pocket. A new amount takes the difference from the pocket or puts it back.')).replace(':pocket', edit?.pocket ?? '')"></p>
        <p class="mx-auto mb-2 max-w-xs text-center text-xs leading-snug text-muted" x-show="edit?.pocket && edit?.pocketArchived"
           x-text="@js(__('Paid from the archived :pocket pocket, so its amount cannot change.')).replace(':pocket', edit?.pocket ?? '')"></p>

        @if ($period->isOpen())
            <div x-show="! edit?.readonly">
            <x-ui.form-group>
                <x-ui.amount-row name="amount" :label="__('Amount')" fallback="0" x-show="! edit?.pocketArchived" data-test="edit-amount" />
                <x-ui.select-row :label="__('Category')" x-model.number="form.category" data-test="edit-category">
                    @foreach ($this->editCategories as $editCategory)<option value="{{ $editCategory->id }}">{{ $editCategory->name }}</option>@endforeach
                </x-ui.select-row>
                <label class="relative flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5 focus-within:bg-accent/10">
                    <span class="text-[15px]">{{ __('Date') }}</span>
                    <span class="num text-[15px] text-ink-2" x-text="dateLabel(form.date)"></span>
                    <input type="date" x-model="form.date" :max="today" class="absolute inset-0 opacity-0" aria-label="{{ __('Date') }}" data-test="edit-date">
                </label>
                <x-ui.text-row :label="__('Note')" x-model="form.note" maxlength="255" :placeholder="__('Optional')" data-test="edit-note" />
            </x-ui.form-group>
            <p class="px-1.5 pt-2 text-xs text-danger" x-show="otherError()" x-text="otherError()"></p>
            <div class="mt-4 flex justify-center">
                <button type="button" x-on:click="remove()" :disabled="saving" class="focus-ring flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-medium text-danger" data-test="delete-transaction"><x-ui.icon name="delete" :size="18" />{{ __('Delete entry') }}</button>
            </div>
            </div>
        @endif
        <div @if ($period->isOpen()) x-show="edit?.readonly" @endif>
            <div class="pb-2 text-center text-sm text-muted">
                <span x-text="dateLabel(edit?.date)"></span><span x-show="edit?.note"> · </span><span x-text="edit?.note"></span>
            </div>
            <p class="px-6 pb-2 text-center text-xs text-muted">{{ __('The month is closed, so its spending cannot change.') }}</p>
        </div>

        <x-slot:footer>
            @if ($period->isOpen())
                <x-ui.button x-show="! edit?.readonly" x-on:click="save()" ::disabled="saving" class="w-full" data-test="save-transaction">{{ __('Save') }}</x-ui.button>
                <x-ui.button variant="secondary" x-show="edit?.readonly" x-on:click="closeEdit()" class="w-full">{{ __('Close') }}</x-ui.button>
            @else
                <x-ui.button variant="secondary" x-on:click="closeEdit()" class="w-full">{{ __('Close') }}</x-ui.button>
            @endif
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    <x-fixed-item-sheet />
</div>

@script
<script>
    Alpine.data('monthPage', ({ decimals, locale, today, failed, categoryNames }) => ({
        ...window.amountFields({ decimals, locale, fields: { amount: '' }, rules: { amount: { maxWhole: 9 } } }),
        edit: null,
        form: { category: null, date: today, note: '' },
        errors: {},
        saving: false,
        today,

        openEdit(row) {
            this.edit = row
            this.form = { category: row.category, date: row.date, note: row.note ?? '' }
            this.fields = { amount: row.amount }
            this.active = null
            this.errors = {}
        },

        /** The sheet title follows the category picked in the form, before it is saved. */
        editTitle() {
            return categoryNames[this.form.category] ?? this.edit?.title ?? ''
        },

        closeEdit() {
            this.active = null
            this.edit = null
        },

        dateLabel(value) {
            if (! value) return ''
            return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(value + 'T12:00:00'))
        },

        otherError() {
            const { amount, ...rest } = this.errors
            return Object.values(rest).filter(Boolean).join(' ')
        },

        async save() {
            if (this.saving || ! this.edit) return
            this.saving = true
            this.active = null
            try {
                const result = await $wire.updateTransaction(this.edit.id, { amount: this.fields.amount || '', category: this.form.category, date: this.form.date, note: this.form.note || null })
                this.errors = result?.errors ?? {}
                if (result?.ok) this.edit = null
            } catch (e) {
                this.errors = { form: failed }
            } finally {
                this.saving = false
            }
        },

        async remove() {
            if (this.saving || ! this.edit) return
            const id = this.edit.id
            this.closeEdit()
            await $wire.delete(id)
        },
    }))
</script>
@endscript
