<?php

use App\Actions\Budget\DeleteTransaction;
use App\Actions\Month\MarkLineDone;
use App\Actions\Month\SaveFixedLine;
use App\Actions\Month\SetLineThisMonth;
use App\Actions\Month\WatchCategory;
use App\Actions\Budget\RestoreTransaction;
use App\Actions\Budget\UpdateTransaction;
use App\Enums\DueState;
use App\Enums\LineType;
use App\Enums\Pace;
use App\Models\Category;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\MonthLine;
use App\Services\Data\MonthView;
use App\Services\Data\WatchState;
use App\Services\MonthService;
use App\Services\PeriodCloser;
use App\Services\Watching;
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

        $previous = ['id' => $transaction->id, 'category' => $transaction->category_id, 'amount' => $transaction->amount, 'date' => $transaction->occurred_on->toDateString(), 'note' => $transaction->note];

        try {
            app(UpdateTransaction::class)->handle($user, $transaction, (int) $validated['category'], $amount, CarbonImmutable::parse($validated['date']), $validated['note']);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => $this->firstMessages($exception->errors())];
        }

        $this->refresh();
        $this->dispatch('budget-updated');

        // A corrected spending that no longer matches the search or the category leaves the list: say so.
        $stillListed = ! $this->isFiltered() || collect($this->days)->contains(fn (array $day): bool => collect($day['rows'])->contains(fn (array $row): bool => ($row['id'] ?? null) === $transactionId));
        $this->dispatch('app-toast', title: __('Spending updated.'), subtitle: $stillListed ? null : __('It no longer matches the search, so it left the list.'), undo: 'undo-transaction-edit', params: $previous);

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
     * "Elintéztem" / "Félretettem" from the fixed line sheet; the numbers stay, a goal grows on "Félretettem".
     */
    public function markDone(int $budgetLineId): void
    {
        $line = $this->line($budgetLineId);
        $toGoal = resolve(MarkLineDone::class)->mark($this->user(), $this->period, $budgetLineId);

        $this->changed();
        $this->dispatch('app-toast',
            title: $line->isSavings() ? __(':name set aside', ['name' => $line->name]) : __(':name done', ['name' => $line->name]),
            subtitle: $toGoal !== null ? __('Goal: +:amount', ['amount' => money($toGoal)]) : __('The numbers did not change.'),
            undo: 'month-undo-done',
            params: ['budgetLineId' => $budgetLineId],
        );
    }

    #[On('month-undo-done')]
    public function undoDone(int $budgetLineId): void
    {
        resolve(MarkLineDone::class)->undo($this->user(), $this->period, $budgetLineId);
        $this->changed();
    }

    /**
     * "Ebben a hónapban nem lesz", or back.
     */
    public function skipThisMonth(int $budgetLineId, bool $skipped = true): void
    {
        $line = $this->line($budgetLineId);
        resolve(SetLineThisMonth::class)->skip($this->user(), $this->period, $budgetLineId, $skipped);
        $this->changed();

        $this->dispatch('app-toast',
            title: $skipped ? __(':name: not this month', ['name' => $line->name]) : __(':name counts again this month', ['name' => $line->name]),
            subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]),
            undo: 'month-undo-skip',
            params: ['budgetLineId' => $budgetLineId, 'skipped' => ! $skipped],
        );
    }

    #[On('month-undo-skip')]
    public function undoSkip(int $budgetLineId, bool $skipped): void
    {
        resolve(SetLineThisMonth::class)->skip($this->user(), $this->period, $budgetLineId, $skipped);
        $this->changed();
    }

    /**
     * "Ebben a hónapban más összeg": counted with another amount this month only.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function amountThisMonth(int $budgetLineId, string $value): array
    {
        $line = $this->line($budgetLineId);
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null || $amount <= 0) {
            return ['ok' => false, 'error' => __('Enter an amount.')];
        }

        resolve(SetLineThisMonth::class)->amount($this->user(), $this->period, $budgetLineId, $amount);
        $this->changed();
        $this->dispatch('app-toast',
            title: __(':name: :amount this month', ['name' => $line->name, 'amount' => money($amount)]),
            subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]),
            undo: 'month-undo-amount',
            params: ['budgetLineId' => $budgetLineId],
        );

        return ['ok' => true, 'error' => null];
    }

    #[On('month-undo-amount')]
    public function undoAmount(int $budgetLineId): void
    {
        resolve(SetLineThisMonth::class)->amount($this->user(), $this->period, $budgetLineId, null);
        $this->changed();
    }

    /**
     * Create or change a fixed line for every month from this one on.
     *
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function saveLine(?int $budgetLineId, array $form): array
    {
        $user = $this->user();
        $amount = is_string($form['amount'] ?? null) ? Money::parse($form['amount'], $user->settings()->currency) : null;
        $type = LineType::tryFrom(is_string($form['type'] ?? null) ? $form['type'] : '') ?? LineType::Fixed;
        $dueDay = is_numeric($form['due_day'] ?? null) ? (int) $form['due_day'] : null;
        $goalId = is_numeric($form['goal_id'] ?? null) ? (int) $form['goal_id'] : null;
        $line = $budgetLineId !== null ? $user->budgetLines()->with('category')->findOrFail($budgetLineId) : null;

        try {
            resolve(SaveFixedLine::class)->save($user, [
                'name' => is_string($form['name'] ?? null) ? $form['name'] : '',
                'type' => $type,
                'amount' => $amount ?? 0,
                'due_day' => $dueDay,
                'manual_pay' => (bool) ($form['manual_pay'] ?? false),
                'goal_id' => $goalId,
            ], $line);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => $this->firstMessages($exception->errors())];
        }

        $this->changed();
        $this->dispatch('app-toast', title: $line ? __('Saved for every month') : __('Added to the fixed expenses'), subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]));

        return ['ok' => true, 'errors' => []];
    }

    public function deleteLine(int $budgetLineId): void
    {
        $line = $this->line($budgetLineId);
        resolve(SaveFixedLine::class)->delete($this->user(), $budgetLineId);
        $this->changed();
        $this->dispatch('app-toast', title: __(':name removed', ['name' => $line->name]), subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]), icon: 'delete');
    }

    /**
     * The monthly income, from this month on (Bevétel in Your month).
     *
     * @return array{ok: bool, error: string|null}
     */
    public function setIncome(string $value): array
    {
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null || $amount < 1) {
            return ['ok' => false, 'error' => __('Enter your monthly income.')];
        }

        $this->user()->settings()->update(['income' => $amount]);

        if ($this->period->isOpen()) {
            $this->period->update(['income_planned' => $amount]);
        }

        $this->changed();
        $this->dispatch('app-toast', title: __('Income saved'), subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]));

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function saveWatch(int $categoryId, string $value): array
    {
        $amount = Money::parse($value, $this->user()->settings()->currency) ?? 0;

        try {
            $watch = resolve(WatchCategory::class)->watch($this->user(), $categoryId, $amount);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'error' => collect($exception->errors())->flatten()->first()];
        }

        $this->changed();
        $this->dispatch('app-toast',
            title: __('Watching :category · :amount a month', ['category' => $watch->category->name ?? '', 'amount' => money($watch->amount)]),
            undo: 'month-undo-watch',
            params: ['categoryId' => $categoryId],
        );

        return ['ok' => true, 'error' => null];
    }

    #[On('month-undo-watch')]
    public function unwatch(int $categoryId): void
    {
        resolve(WatchCategory::class)->unwatch($this->user(), $categoryId);
        $this->changed();
    }

    private function changed(): void
    {
        $this->refresh();
        $this->dispatch('budget-updated');
    }

    private function line(int $budgetLineId): MonthLine
    {
        foreach ($this->month->lines as $line) {
            if ($line->lineId === $budgetLineId) {
                return $line;
            }
        }

        abort(404);
    }

    public function showPeriod(int $periodId): void
    {
        if ($this->user()->periods()->whereKey($periodId)->exists()) {
            $this->periodId = $periodId;
            $this->periodNotFound = false;
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
        unset($this->period, $this->days, $this->month, $this->watched, $this->whereWent, $this->neighbours, $this->filterCategories, $this->editCategories, $this->watchCandidates, $this->goals);
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function month(): MonthView
    {
        return resolve(MonthService::class)->view($this->user(), $this->period, app(PeriodService::class)->today($this->user()->settings()));
    }

    /**
     * @return list<WatchState>
     */
    #[Computed]
    public function watched(): array
    {
        $watched = [];

        foreach ($this->user()->watchedCategories()->with('category')->orderBy('id')->get() as $watch) {
            $watched[$watch->category_id] = ['name' => $watch->category->name ?? '', 'amount' => $watch->amount];
        }

        return resolve(Watching::class)->states($watched, $this->month->spentByCategory);
    }

    /**
     * "Hova ment?": spending by category, the biggest first, with its share.
     *
     * @return list<array{id: int, name: string, icon: string, amount: int, percent: int}>
     */
    #[Computed]
    public function whereWent(): array
    {
        $spent = $this->month->spentByCategory;
        $total = array_sum($spent);
        arsort($spent);
        $categories = $this->user()->categories()->withTrashed()->whereIn('id', array_keys($spent))->get()->keyBy('id');
        $rows = [];

        foreach ($spent as $categoryId => $amount) {
            $category = $categories->get($categoryId);
            $rows[] = [
                'id' => $categoryId,
                'name' => $category->name ?? '',
                'icon' => Icons::forCategory($category?->icon),
                'amount' => $amount,
                'percent' => $total > 0 ? (int) round($amount * 100 / $total) : 0,
            ];
        }

        return $rows;
    }

    /**
     * Spending categories that can be watched (not watched yet).
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    #[Computed]
    public function watchCandidates(): \Illuminate\Support\Collection
    {
        $watched = $this->user()->watchedCategories()->pluck('category_id');

        return $this->user()->categories()->where('type', LineType::Variable)->whereNotIn('id', $watched)->orderBy('sort')->orderBy('name')->get()->toBase();
    }

    /**
     * Goals a savings line can be tied to.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Pocket>
     */
    #[Computed]
    public function goals(): \Illuminate\Support\Collection
    {
        return $this->user()->pockets()->goals()->orderBy('sort')->orderBy('name')->get()->toBase();
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
                // Skipped lines count as 0, so they are not among what the fixed lines take.
                $fixed = array_values(array_filter($this->month->lines, fn (MonthLine $line): bool => ! $line->skipped));
                $days[$key] ??= ['date' => $period->starts_on, 'total' => 0, 'payday' => true, 'fromPocket' => false, 'rows' => []];
                $days[$key]['payday'] = true;
                $days[$key]['rows'][] = ['kind' => 'income', 'amount' => $period->income()];

                foreach ($period->extraIncomes()->get() as $extra) {
                    $extraKey = $extra->occurred_on->toDateString();
                    $days[$extraKey] ??= ['date' => $extra->occurred_on, 'total' => 0, 'payday' => false, 'fromPocket' => false, 'rows' => []];
                    $days[$extraKey]['rows'][] = ['kind' => 'extra-income', 'amount' => $extra->amount, 'note' => $extra->note];
                }
                if ($fixed !== []) {
                    $days[$key]['rows'][] = ['kind' => 'fixed', 'count' => count($fixed), 'amount' => array_sum(array_map(fn (MonthLine $line): int => $line->counted(), $fixed))];
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
    $month = $this->month;
    $state = $month->state;
    $today = $month->today;
    $closeRecord = $period->isOpen() ? null : $period->close()->first();
    $hasSpending = collect($this->days)->contains(fn ($day) => collect($day['rows'])->contains('kind', 'spending'));
    $filtered = $this->isFiltered();
    $currency = user_currency();
    $previousId = $this->neighbours['previous'];
    $nextId = $this->neighbours['next'];
    $navClasses = 'aria-disabled:cursor-default aria-disabled:bg-transparent aria-disabled:text-muted aria-disabled:ring-1 aria-disabled:ring-inset aria-disabled:ring-line aria-disabled:active:scale-100';
    $paceClass = match ($state->pace) {
        Pace::Good => 'bg-accent-tint text-accent-strong',
        Pace::Fast => 'bg-surface-2 text-ink',
        Pace::SlowDown => 'bg-warn-tint text-warn-text',
    };
    $howRows = [
        [__('This month’s money'), money($state->spendable)],
        [__('Spent so far'), $state->spent > 0 ? '−'.money($state->spent) : money(0)],
        [__('Left this month'), money($state->remaining)],
        [__('Days left, today included'), trans_choice('{1} :count day|[2,*] :count days', $state->daysLeft, ['count' => $state->daysLeft])],
        [__('Daily allowance'), money($state->dailyAllowance)],
    ];
    // Each fixed line's subtitle and the form its sheet opens with.
    $lineStates = [];
    $lineForms = [];
    $lineModels = auth()->user()->budgetLines()->whereIn('id', array_map(fn ($line) => $line->lineId, $month->lines))->get()->keyBy('id');
    foreach ($month->lines as $line) {
        $day = $line->dueOn ? Dates::short($line->dueOn) : null;
        $lineStates[$line->lineId] = match ($line->state) {
            DueState::Skipped => __('not this month'),
            DueState::NoDay => __('no day'),
            DueState::Upcoming => $line->manualPay ? __('paid by hand · :date', ['date' => $day]) : (string) $day,
            DueState::DueToday => __(':date · goes out today', ['date' => $day]),
            DueState::Deducted => __(':date · taken off (the day has passed)', ['date' => $day]),
            DueState::ToDo, DueState::Overdue => $line->isSavings() ? __(':date · not set aside yet', ['date' => $day]) : __('paid by hand · :date · not done yet', ['date' => $day]),
            DueState::Done => $line->isSavings() ? __(':date · set aside', ['date' => $day ?? '']) : __('paid by hand · :date · done', ['date' => $day ?? '']),
            default => '',
        };
        $model = $lineModels->get($line->lineId);
        $lineForms[$line->lineId] = [
            'id' => $line->lineId,
            'name' => $line->name,
            'type' => $line->type->value,
            'amount' => \App\Support\Money::toInput($model->amount ?? $line->amount, $currency),
            'thisMonth' => \App\Support\Money::toInput($line->amount, $currency),
            'differs' => $model !== null && $model->amount !== $line->amount,
            'due_day' => $model?->due_day,
            'manual_pay' => $line->manualPay,
            'goal_id' => $line->goalId,
            'skipped' => $line->skipped,
            'done' => $line->state === DueState::Done,
            'savings' => $line->isSavings(),
            'loanUrl' => $line->type === LineType::Loan ? route('loan', $line->lineId) : null,
        ];
    }
@endphp

<div @if (! $period->isOpen()) data-hides-record-button @endif x-data="monthPage({
        decimals: {{ $currency->decimals() }},
        locale: @js(str_replace('_', '-', app()->getLocale())),
        today: @js($today->toDateString()),
        failed: @js(__('Could not save. Check your connection and try again.')),
        categoryNames: @js($period->isOpen() ? $this->editCategories->pluck('name', 'id') : []),
        symbol: @js($currency->symbol()),
    })">
    @if ($periodNotFound)
        <x-ui.callout tone="warn" class="mx-4 mt-3" role="status" data-test="period-not-found">{{ __('That month was not found, so the current one is shown.') }}</x-ui.callout>
    @endif

    <div class="flex items-end justify-between gap-2 px-6 pt-3.5">
        <div class="min-w-0">
            <h1 class="truncate text-[30px] font-semibold tracking-[-0.03em]">{{ Dates::monthName($period->nameDate()) }}</h1>
            <div class="num mt-0.5 text-[13px] text-muted">
                {{ Dates::range($period->starts_on, $period->ends_on) }} ·
                @if (! $period->isOpen())
                    {{ __('closed') }}
                @elseif ($period->contains($today))
                    {{ trans_choice('{1} :count day left|[2,*] :count days left', $state->daysLeft, ['count' => $state->daysLeft]) }}
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

    {{-- The month in one card: what it still has (or what was left), what went, the daily allowance. --}}
    <x-ui.card class="mx-4 mb-1 mt-[18px] px-[18px] py-4" data-test="month-summary">
        @if ($period->isOpen())
            <div class="flex items-center gap-1 text-[13px] text-muted">
                <span>{{ __('Left this month') }}</span>
                <x-explainer topic="month-left" :rows="$howRows" />
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <x-ui.amount :value="$state->remaining" size="lg" :tone="$state->remaining < 0 ? 'warn' : null" class="[&>span:first-child]:text-[34px]" x-data x-number-change />
                <span class="inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[13px] font-semibold {{ $paceClass }}" data-test="pace">{{ $state->pace->label() }}</span>
            </div>
            <div class="num mt-3 flex items-baseline justify-between gap-3 text-[13px] text-muted">
                <span>{!! __('Spent :amount of :total', ['amount' => '<b class="font-semibold text-ink">'.e(money($state->spent)).'</b>', 'total' => e(money($state->spendable))]) !!}</span>
            </div>
            <x-ui.bar :label="__('Spent this month')" :value="$state->spendable > 0 ? min(1, $state->spent / $state->spendable) : 0" :tone="$state->spent > $state->spendable ? 'warn' : 'accent'" class="mt-2" />
            <div class="num mt-2 text-[13px] text-muted" data-test="month-allowance">{{ __('Daily allowance :amount · left today :left', ['amount' => money($state->dailyAllowance), 'left' => money(max(0, $state->todayLeft))]) }}</div>

            @if (! app(PeriodCloser::class)->canClose(auth()->user(), $period))
                <div class="mt-3 flex items-center gap-1.5 text-[13px] text-muted" data-test="close-not-yet">
                    <x-ui.icon name="schedule" :size="16" />{{ __('You can close the month from :date.', ['date' => Dates::short(app(PeriodCloser::class)->closableFrom($period))]) }}
                </div>
            @else
                <x-ui.button variant="tonal" size="md" icon="task_alt" :href="route('close', $period)" wire:navigate class="mt-4 w-full" data-test="start-close">{{ __('Close the month') }}</x-ui.button>
            @endif
        @elseif ($closeRecord)
            <div class="text-[13px] text-muted">{{ $closeRecord->leftover >= 0 ? __('Left over') : __('Overspent') }}</div>
            <x-ui.amount :value="$closeRecord->leftover" size="lg" :tone="$closeRecord->leftover < 0 ? 'warn' : null" class="mt-1 [&>span:first-child]:text-[34px]" />
            <div class="mt-2 text-[13px] text-muted" data-test="close-decision">
                @switch($closeRecord->decision)
                    @case(\App\Enums\CloseDecision::Save)
                        {{ __('Set aside: :amount', ['amount' => money($closeRecord->to_goal)]) }}@if ($closeRecord->carried_over > 0) · {{ __('carried over: :amount', ['amount' => money($closeRecord->carried_over)]) }}@endif
                        @break
                    @case(\App\Enums\CloseDecision::Carry)
                        {{ __('Carried into the next month.') }}
                        @break
                    @case(\App\Enums\CloseDecision::Deduct)
                        {{ __('Taken off the next month.') }}
                        @break
                    @case(\App\Enums\CloseDecision::Covered)
                        {{ __('Covered from elsewhere.') }}
                        @break
                    @case(\App\Enums\CloseDecision::FromGoal)
                        {{ __('Covered from a goal.') }}
                        @break
                    @default
                        {{ __('Only noted.') }}
                @endswitch
            </div>
            <livewire:reopen-closing :period-id="$period->id" :key="'reopen-'.$period->id" />
        @endif
    </x-ui.card>

    {{-- Watched categories: only watched, the month's money does not change because of them. --}}
    @if ($period->isOpen())
        <section class="mx-4 mt-3" data-test="watched">
            <div class="flex items-baseline justify-between px-1.5 pb-2 pt-3">
                <h2 class="text-[13px] font-semibold text-ink-2">{{ __('Watched categories') }}</h2>
                @unless (auth()->user()->hasPlusz())
                    <span class="num text-xs text-muted">{{ count($this->watched) }}/{{ \App\Services\Watching::BASE_LIMIT }}</span>
                @endunless
            </div>
            <x-ui.card class="px-4 py-1">
                @forelse ($this->watched as $watch)
                    @php
                        $ringPercent = min(100, $watch->percent());
                        $circumference = 2 * M_PI * 19.5;
                    @endphp
                    <button type="button" x-on:click="openWatch({{ $watch->categoryId }}, @js($watch->name), @js(\App\Support\Money::toInput($watch->amount, $currency)))" wire:key="watch-{{ $watch->categoryId }}"
                            @class(['focus-ring flex min-h-16 w-full items-center gap-3 py-2 text-left', 'border-b border-line' => ! $loop->last]) data-test="watch-row">
                        <svg class="size-11 shrink-0 -rotate-90" viewBox="0 0 44 44" aria-hidden="true">
                            <circle cx="22" cy="22" r="19.5" fill="none" stroke-width="5" class="stroke-surface-2" />
                            <circle cx="22" cy="22" r="19.5" fill="none" stroke-width="5" stroke-linecap="round" @class(['stroke-warn' => $watch->isOver() || $watch->isNearing(), 'stroke-accent' => ! $watch->isOver() && ! $watch->isNearing()])
                                    stroke-dasharray="{{ round($circumference * $ringPercent / 100, 2) }} {{ round($circumference, 2) }}" />
                        </svg>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-[15px] font-medium">{{ $watch->name }}</span>
                            <span class="num text-[13px] text-muted">
                                {{ money_number($watch->spent) }} / {{ money($watch->amount) }} ·
                                @if ($watch->isOver())
                                    <span class="font-semibold text-warn-text">{{ __(':amount more', ['amount' => money(-$watch->left())]) }}</span>
                                @else
                                    {{ __('left :amount', ['amount' => money($watch->left())]) }}
                                @endif
                            </span>
                        </span>
                        <span class="num text-[13px] font-semibold text-ink-2">{{ $watch->percent() }}%</span>
                    </button>
                @empty
                    <div class="flex items-center justify-between gap-3 py-3">
                        <span class="text-[15px] text-ink-2">{{ __('Watch a category? E.g. eating out.') }}</span>
                        <x-ui.button size="sm" variant="tonal" x-on:click="openWatch(null)" data-test="watch-first">{{ __('Set it up') }}</x-ui.button>
                    </div>
                @endforelse
            </x-ui.card>
            @if ($this->watched !== [])
                <button type="button" x-on:click="openWatch(null)" class="focus-ring mt-1 flex min-h-11 items-center gap-2 px-1.5 text-sm font-semibold text-accent-strong" data-test="watch-add">
                    <x-ui.icon name="add" :size="20" />{{ __('Watch a category') }}
                </button>
            @endif
            <p class="px-1.5 text-xs text-muted">{{ __('Only watched: the daily allowance and what is left do not change.') }}</p>
        </section>
    @endif

    {{-- Where it went --}}
    @if ($this->whereWent !== [])
        <section class="mx-4 mt-3" data-test="where-went">
            <h2 class="px-1.5 pb-2 pt-3 text-[13px] font-semibold text-ink-2">{{ __('Where did it go?') }}</h2>
            <x-ui.card class="px-4 py-1">
                @foreach ($this->whereWent as $row)
                    <button type="button" wire:click="$set('categoryId', {{ $categoryId === $row['id'] ? 'null' : $row['id'] }})" wire:key="went-{{ $row['id'] }}"
                            @class(['focus-ring w-full py-2.5 text-left', 'border-b border-line' => ! $loop->last])>
                        <span class="grid grid-cols-[36px_minmax(0,1fr)_auto_44px] items-center gap-3">
                            <x-ui.icon-tile :icon="$row['icon']" :size="36" />
                            <span class="truncate text-[15px]">{{ $row['name'] }}</span>
                            <x-ui.amount size="sm" :value="$row['amount']" class="text-[15px] font-medium" />
                            <span class="num text-right text-[13px] text-muted">{{ $row['percent'] }}%</span>
                        </span>
                        <span class="mt-1.5 ml-12 block h-1 rounded-full bg-surface-2"><span class="block h-1 rounded-full bg-ink-2/50" style="width: {{ $row['percent'] }}%"></span></span>
                    </button>
                @endforeach
            </x-ui.card>
        </section>
    @endif

    @if ($period->isOpen() && auth()->user()->hasPlusz())
        @foreach (resolve(\App\Services\LoanOverview::class)->saving(auth()->user(), $today) as $loanSummary)
            <x-loan-progress :summary="$loanSummary" class="mx-4 mt-3" wire:key="loan-{{ $loanSummary->loan->id }}" />
        @endforeach
    @endif

    {{-- Your month: income − fixed lines (savings too) = the month's money, and every fixed line with its state. --}}
    @php
        $fixedOnly = array_sum(array_map(fn ($line) => $line->isSavings() ? 0 : $line->counted(), $month->lines));
        $savingsOnly = $month->fixedTotal - $fixedOnly;
        $summary = $month->fixedSummary;
    @endphp
    <section class="mx-4 mt-3" data-test="your-month">
        <h2 class="px-1.5 pb-2 pt-3 text-[13px] font-semibold text-ink-2">{{ __('Your month') }}</h2>
        <x-ui.card class="px-4 py-3">
            <dl class="num text-[15px]">
                @if ($period->isOpen())
                    <button type="button" class="focus-ring -mx-1 flex w-[calc(100%+0.5rem)] justify-between rounded-lg px-1 py-1" x-on:click="openIncome(@js(\App\Support\Money::toInput($month->income, $currency)))" data-test="edit-income"><dt class="text-ink-2">{{ __('Income') }}</dt><dd class="flex items-center gap-1">{{ money($month->income) }}<x-ui.icon name="edit" :size="16" class="text-faint" /></dd></button>
                @else
                    <div class="flex justify-between py-1"><dt class="text-ink-2">{{ __('Income') }}</dt><dd>{{ money($month->income) }}</dd></div>
                @endif
                @if ($month->extraIncome > 0)
                    <div class="flex justify-between py-1"><dt class="text-ink-2">{{ __('Extra income') }}</dt><dd>+{{ money($month->extraIncome) }}</dd></div>
                @endif
                @if ($period->carried_over !== 0)
                    <div class="flex justify-between py-1"><dt class="text-ink-2">{{ $period->carried_over > 0 ? __('Carried over') : __('Deducted from last month') }}</dt><dd>{{ $period->carried_over > 0 ? '+' : '−' }}{{ money(abs($period->carried_over)) }}</dd></div>
                @endif
                <div class="flex justify-between py-1"><dt class="text-ink-2">{{ __('Fixed expenses') }}</dt><dd>−{{ money($fixedOnly) }}</dd></div>
                @if ($savingsOnly > 0)
                    <div class="flex justify-between py-1"><dt class="text-ink-2">{{ __('Savings line') }}</dt><dd>−{{ money($savingsOnly) }}</dd></div>
                @endif
                <div class="mt-1 flex justify-between border-t border-line pt-2 font-semibold"><dt>{{ __('Money for the month') }}</dt><dd data-test="spendable">{{ money($state->spendable) }}</dd></div>
            </dl>

            @if ($summary['withDay'] > 0)
                <div class="mt-3 border-t border-line pt-3">
                    <div class="flex items-baseline justify-between gap-2 text-[13px]">
                        <span class="font-semibold text-ink" data-test="fixed-count">{{ __('Fixed expenses: :settled of :total gone out', ['settled' => $summary['settled'], 'total' => \App\Support\Hungarian::withSuffix($summary['withDay'], 'ból', 'ből')]) }}</span>
                    </div>
                    <div class="mt-1.5 grid gap-1" style="grid-template-columns: repeat({{ $summary['withDay'] }}, minmax(0, 1fr))" aria-hidden="true">
                        @for ($i = 0; $i < $summary['withDay']; $i++)
                            <i @class(['block h-1.5 rounded-full', 'bg-accent' => $i < $summary['settled'], 'bg-surface-2' => $i >= $summary['settled']])></i>
                        @endfor
                    </div>
                    @if ($summary['noDay'] > 0)
                        <p class="mt-1.5 text-xs text-muted">{{ trans_choice('{1} :count has no day|[2,*] :count have no day', $summary['noDay'], ['count' => \App\Support\Hungarian::withSuffix($summary['noDay'], 'nak', 'nek')]) }}</p>
                    @endif
                </div>
            @endif

            <div class="mt-2 border-t border-line">
                @foreach ($month->lines as $line)
                    <button type="button" wire:key="line-{{ $line->lineId }}" @if ($period->isOpen()) x-on:click="openLine(@js($lineForms[$line->lineId]))" @endif
                            @class(['focus-ring grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-2.5 text-left', 'border-b border-line' => ! $loop->last, 'opacity-60' => $line->skipped]) data-test="fixed-line">
                        <x-ui.icon-tile :icon="$line->isSavings() ? 'savings' : ($line->type === LineType::Loan ? 'account_balance' : 'receipt_long')" :size="36" />
                        <span class="min-w-0">
                            <span class="block truncate text-[15px]">{{ $line->name }}</span>
                            <span class="mt-0.5 block truncate text-xs text-muted">{{ $lineStates[$line->lineId] }}</span>
                        </span>
                        <x-ui.amount size="sm" :value="$line->counted()" class="text-[15px] font-medium" />
                    </button>
                @endforeach
            </div>
            @if ($period->isOpen())
                <button type="button" x-on:click="openLine(null)" class="focus-ring mt-1 flex min-h-11 items-center gap-2 text-sm font-semibold text-accent-strong" data-test="add-fixed-line">
                    <x-ui.icon name="add" :size="20" />{{ __('Fixed expense') }}
                </button>
            @endif
        </x-ui.card>
    </section>

    {{--
        Search and category filter. The placeholder stays 15px on touch screens too, where the typed
        text grows to 16px (app.css) so iOS does not zoom into the field on focus.
    --}}
    <div class="mx-4 mt-4" data-test="month-filters">
        <label class="flex h-11 items-center gap-2 rounded-[14px] bg-surface px-3.5 focus-within:ring-2 focus-within:ring-accent">
            <x-ui.icon name="search" :size="20" class="text-muted" />
            <input type="search" wire:model.live.debounce.400ms="search" maxlength="100" enterkeyhint="search" autocomplete="off"
                   placeholder="{{ __('Search notes or amounts') }}" aria-label="{{ __('Search notes or amounts') }}"
                   class="min-w-0 flex-1 bg-transparent text-[15px] text-ink outline-none placeholder:text-[15px] placeholder:text-muted" data-test="month-search">
        </label>
        {{-- "Every month" (while searching) and the category filter in one scrolling row, faded at the right edge. --}}
        @if (trim($search) !== '' || ($this->filterCategories->isNotEmpty() && ! $this->searchesAllMonths()))
            <div class="no-scrollbar -mx-4 mt-2 flex gap-2 overflow-x-auto px-4 [mask-image:linear-gradient(to_right,#000_calc(100%-28px),transparent)]" role="group" aria-label="{{ __('Filter by category') }}" data-test="category-filter">
                @if (trim($search) !== '')
                    <x-ui.choice :selected="$searchAll" wire:click="$toggle('searchAll')" class="flex shrink-0 items-center gap-1.5 rounded-control px-3.5 text-[13px]" data-test="search-all">
                        <x-ui.icon name="calendar_month" :size="18" />{{ __('Every month') }}
                    </x-ui.choice>
                @endif
                @if (! $this->searchesAllMonths())
                    <x-ui.choice :selected="$categoryId === null" wire:click="$set('categoryId', null)" class="shrink-0 rounded-control px-3.5 text-[13px]">{{ __('All') }}</x-ui.choice>
                    @foreach ($this->filterCategories as $filterCategory)
                        <x-ui.choice :selected="$categoryId === $filterCategory->id" wire:key="filter-{{ $filterCategory->id }}" wire:click="$set('categoryId', {{ $categoryId === $filterCategory->id ? 'null' : $filterCategory->id }})" class="shrink-0 rounded-control px-3.5 text-[13px]" data-test="filter-{{ $filterCategory->id }}">{{ $filterCategory->name }}</x-ui.choice>
                    @endforeach
                @endif
                <span class="w-4 shrink-0" aria-hidden="true"></span>
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
                    @elseif ($row['kind'] === 'extra-income')
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border]) data-test="extra-income">
                            <x-ui.icon-tile icon="payments" tone="accent" :size="36" />
                            <span class="min-w-0"><span class="block truncate text-[15px]">{{ __('Got money') }}</span>@if ($row['note'])<span class="mt-0.5 block truncate text-xs text-muted">{{ $row['note'] }}</span>@endif</span>
                            <x-ui.amount size="sm" :value="$row['amount']" signed class="text-[15px] font-medium text-accent" />
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
        <x-ui.card class="mx-4 mt-4 px-4" data-test="no-results">
            <x-ui.empty-state compact icon="search" :title="__('No matching spending')">
                {{ $this->searchesAllMonths() ? __('No spending in any month matches the search.') : __('Nothing in this month matches the search or the category.') }}
                <x-slot:actions>
                    <x-ui.button size="sm" variant="tonal" wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif (! $hasSpending)
        <x-ui.empty-state icon="event_note" :title="__('Clean slate')" class="mx-4 mt-4" data-test="empty-state">
            {{ $period->isOpen() ? __('Your daily spending and the “didn’t spend” days land here. Record the first one with the + button.') : __('No spending or “didn’t spend” day was recorded in this period.') }}
        </x-ui.empty-state>
    @endif

    {{-- A spending: correct it (open period) or just look at it (closed period). --}}
    {{-- Short sheet (grows with its content); the big amount itself opens the numpad, so it is not repeated as a row. --}}
    <x-ui.sheet show="edit" close="closeEdit()" title="editTitle()" :full="false" data-test="transaction-sheet">
        @if ($period->isOpen())
            <button type="button" x-show="! edit?.readonly && ! edit?.pocketArchived" x-on:click="focus('amount', @js(__('Amount')))"
                    class="focus-ring mx-auto flex items-center gap-2 rounded-group px-4 py-3" :class="active === 'amount' && 'bg-accent/10'"
                    aria-label="{{ __('Amount') }}" data-test="edit-amount">
                <x-ui.amount bind="display('amount')" size="xl" />
                <x-ui.icon name="edit" :size="20" class="text-faint" />
            </button>
        @endif
        <div class="py-3 text-center" @if ($period->isOpen()) x-show="edit?.readonly || edit?.pocketArchived" @endif>
            <x-ui.amount bind="display('amount')" size="xl" />
        </div>
        <div class="mb-1 text-center text-xs text-danger" x-show="errors.amount" x-text="errors.amount" data-test="edit-error-amount"></div>
        <p class="mx-auto mb-2 max-w-xs text-center text-xs leading-snug text-muted" x-show="edit?.pocket && ! edit?.pocketArchived"
           x-text="@js(__('Paid from the :pocket pocket. A new amount takes the difference from the pocket or puts it back.')).replace(':pocket', edit?.pocket ?? '')"></p>
        <p class="mx-auto mb-2 max-w-xs text-center text-xs leading-snug text-muted" x-show="edit?.pocket && edit?.pocketArchived"
           x-text="@js(__('Paid from the archived :pocket pocket, so its amount cannot change.')).replace(':pocket', edit?.pocket ?? '')"></p>

        @if ($period->isOpen())
            <div x-show="! edit?.readonly">
            <x-ui.form-group>
                <x-ui.select-row :label="__('Category')" x-model.number="form.category" data-test="edit-category">
                    @foreach ($this->editCategories as $editCategory)<option value="{{ $editCategory->id }}">{{ $editCategory->trashed() ? __(':name, removed from the plan', ['name' => $editCategory->name]) : $editCategory->name }}</option>@endforeach
                </x-ui.select-row>
                <label class="relative flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5 focus-within:bg-accent/10">
                    <span class="text-[15px]">{{ __('Date') }}</span>
                    <span class="num text-[15px] text-ink-2" x-text="dateLabel(form.date)"></span>
                    <input type="date" x-model="form.date" :max="today" class="absolute inset-0 opacity-0" aria-label="{{ __('Date') }}" data-test="edit-date">
                </label>
                <x-ui.text-row :label="__('Note')" x-model="form.note" maxlength="255" :placeholder="__('Optional')" data-test="edit-note" />
            </x-ui.form-group>
            <p class="px-1.5 pt-2 text-xs text-danger" x-show="otherError()" x-text="otherError()"></p>
            <x-ui.button variant="danger" size="md" icon="delete" x-on:click="remove()" ::disabled="saving" class="mt-6 w-full" data-test="delete-transaction">{{ __('Delete entry') }}</x-ui.button>
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

    {{-- A fixed line: this month only (not this month / another amount / done), and for every month. --}}
    <x-ui.sheet show="line !== null" close="closeLine()" title="lineTitle()" data-test="line-sheet">
        <template x-if="line && line.id">
            <div>
                <x-ui.form-group :title="__('This month')">
                    <button type="button" x-show="! line.skipped" x-on:click="$wire.skipThisMonth(line.id, true); closeLine()" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px]" data-test="skip-this-month">
                        <span>{{ __('Not this month') }}</span><x-ui.icon name="chevron_right" class="text-faint" />
                    </button>
                    <button type="button" x-show="line.skipped" x-on:click="$wire.skipThisMonth(line.id, false); closeLine()" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px]" data-test="unskip-this-month">
                        <span>{{ __('It counts this month after all') }}</span><x-ui.icon name="chevron_right" class="text-faint" />
                    </button>
                    <button type="button" x-show="! line.skipped" x-on:click="openThisMonth()" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px]" data-test="amount-this-month">
                        <span>{{ __('Another amount this month') }}</span><span class="num text-ink-2" x-text="line.differs ? display('thisMonth') + ' ' + symbol : ''"></span>
                    </button>
                    <button type="button" x-show="line.manual_pay && ! line.skipped && ! line.done" x-on:click="$wire.markDone(line.id); closeLine()" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px] font-semibold text-accent-strong" data-test="line-done"
                            x-text="line.savings ? @js(__('Set aside')) : @js(__('Mark as done'))"></button>
                </x-ui.form-group>
                <template x-if="line.loanUrl">
                    <x-ui.form-group class="mt-4">
                        <a :href="line.loanUrl" wire:navigate class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px]" data-test="loan-planner-link">
                            <span>{{ __('When will I be loan-free?') }}</span>
                            <span class="flex items-center gap-1.5"><span class="rounded-full bg-plum-tint px-2 py-0.5 text-[11px] font-semibold text-plum">Plusz</span><x-ui.icon name="chevron_right" class="text-faint" /></span>
                        </a>
                    </x-ui.form-group>
                </template>
            </div>
        </template>

        <x-ui.form-group :title="__('Every month')" class="mt-4">
            <x-ui.text-row :label="__('Name')" x-model="lineForm.name" maxlength="60" data-test="line-name" />
            <x-ui.amount-row name="amount" :label="__('Amount')" data-test="field-amount" />
            <x-ui.select-row :label="__('Type')" x-model="lineForm.type" data-test="line-type">
                <option value="fixed">{{ __('Fixed expense') }}</option>
                <option value="loan">{{ __('Loan installment') }}</option>
                <option value="sinking">{{ __('Savings line') }}</option>
            </x-ui.select-row>
            <div x-show="lineForm.type === 'sinking'">
                <x-ui.select-row :label="__('Goal')" x-model="lineForm.goal_id" data-test="line-goal">
                    <option value="">{{ __('No goal') }}</option>
                    @foreach ($this->goals as $goal)<option value="{{ $goal->id }}">{{ $goal->name }}</option>@endforeach
                </x-ui.select-row>
            </div>
            <x-ui.select-row :label="__('Which day does it go out?')" x-model="lineForm.due_day" data-test="line-day">
                <option value="">{{ __('No day') }}</option>
                @for ($d = 1; $d <= 31; $d++)<option value="{{ $d }}">{{ __(':day.', ['day' => $d]) }}</option>@endfor
            </x-ui.select-row>
            <div x-show="lineForm.type !== 'sinking'">
                <x-ui.switch-row model="lineForm.manual_pay" :label="__('I pay it by hand')" :hint="__('On its day an “Elintéztem” button shows. It changes no number.')" />
            </div>
        </x-ui.form-group>
        <p class="px-1.5 pt-2 text-xs text-danger" x-show="lineError()" x-text="lineError()"></p>
        <p class="px-1.5 pt-2 text-xs text-muted">{{ __('Changes count from this month on, and can be undone.') }}</p>

        <x-slot:danger>
            <x-ui.button variant="danger" size="md" icon="delete" x-show="line && line.id" x-on:click="removeLine()" class="w-full" data-test="delete-line">{{ __('Remove from the fixed expenses') }}</x-ui.button>
        </x-slot:danger>
        <x-slot:footer>
            <x-ui.button x-on:click="saveLine()" ::disabled="saving" class="w-full" data-test="save-line">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    <x-ui.numpad-sheet show="incomeOpen" close="incomeOpen = false" :label="__('Monthly income')" done="saveIncome()" display="display('income')" :decimal="$currency->decimals() > 0" error="incomeError" :intro="\Illuminate\Support\Js::from(__('From this month on.'))->toHtml()" data-test="income-sheet" />

    <x-ui.numpad-sheet show="thisMonthOpen" close="thisMonthOpen = false" title="lineTitle()" done="saveThisMonth()" display="display('thisMonth')" :decimal="$currency->decimals() > 0" error="thisMonthError" :intro="\Illuminate\Support\Js::from(__('Only this month. The difference changes what is left.'))->toHtml()" data-test="this-month-sheet" />

    {{-- Watch a category: pick it, then the monthly amount. --}}
    <x-ui.sheet show="watchOpen" close="watchOpen = false" :label="__('Watch a category')" :full="false" data-test="watch-sheet">
        <template x-if="watch.id === null">
            <div>
                @if (! auth()->user()->hasPlusz() && count($this->watched) >= \App\Services\Watching::BASE_LIMIT)
                    <div class="flex flex-col items-center px-2 py-4 text-center" data-test="watch-limit">
                        <x-morzsa mood="nyugodt" :size="72" />
                        <p class="mt-3 text-[17px] font-semibold">{{ __('Two categories can be watched') }}</p>
                        <p class="mt-1 text-[15px] text-muted">{{ __('The base plan watches two categories. With Plusz, any number.') }}</p>
                    </div>
                @else
                    <p class="px-1 pb-2 text-[13px] font-medium text-muted">{{ __('Which category?') }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->watchCandidates as $candidate)
                            <button type="button" x-on:click="pickWatch({{ $candidate->id }}, @js($candidate->name))" class="focus-ring flex min-h-11 items-center rounded-control border border-control-border bg-surface-2 px-3 text-left text-[15px] font-medium" wire:key="cand-{{ $candidate->id }}">{{ $candidate->name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </template>
        <template x-if="watch.id !== null">
            <div>
                <p class="text-center text-[13px] text-muted">{{ __('How much do you mean to spend on it a month?') }}</p>
                <x-ui.amount-display display="display('watch')" class="py-4" />
                <p class="mb-2 text-center text-xs text-danger" x-show="watchError" x-text="watchError"></p>
                <x-ui.numpad :decimal="$currency->decimals() > 0" enter="saveWatch()" />
                <p class="mt-3 text-center text-xs text-muted">{{ __('Only watched: the daily allowance and what is left do not change. I speak up at 80%.') }}</p>
            </div>
        </template>
        <x-slot:danger>
            <x-ui.button variant="danger" size="md" icon="close" x-show="watch.id !== null && watch.existing" x-on:click="$wire.unwatch(watch.id); watchOpen = false" class="w-full" data-test="unwatch">{{ __('Stop watching') }}</x-ui.button>
        </x-slot:danger>
        <x-slot:footer>
            <x-ui.button x-show="watch.id !== null" x-on:click="saveWatch()" class="w-full" data-test="save-watch">{{ __('Watch it') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>

@script
<script>
    Alpine.data('monthPage', ({ decimals, locale, today, failed, categoryNames, symbol }) => ({
        ...window.amountFields({ decimals, locale, fields: { amount: '', thisMonth: '', watch: '', income: '' }, rules: { amount: { maxWhole: 9 }, thisMonth: { maxWhole: 9 }, watch: { maxWhole: 9 }, income: { maxWhole: 9 } } }),
        incomeOpen: false,
        incomeError: null,

        openIncome(value) {
            this.editAmount('income', value, '')
            this.incomeError = null
            this.incomeOpen = true
        },
        async saveIncome() {
            const result = await $wire.setIncome(this.fields.income || '0')
            this.incomeError = result.error
            if (result.ok) this.incomeOpen = false
        },
        symbol,
        line: null,
        lineForm: { name: '', type: 'fixed', due_day: '', manual_pay: false, goal_id: '' },
        lineErrors: {},
        thisMonthOpen: false,
        thisMonthError: null,
        watchOpen: false,
        watch: { id: null, name: '', existing: false },
        watchError: null,

        openLine(row) {
            this.line = row ?? { id: null }
            this.lineForm = row
                ? { name: row.name, type: row.type, due_day: row.due_day === null ? '' : String(row.due_day), manual_pay: row.manual_pay, goal_id: row.goal_id === null ? '' : String(row.goal_id) }
                : { name: '', type: 'fixed', due_day: '', manual_pay: false, goal_id: '' }
            this.fields.amount = row ? row.amount : ''
            this.fields.thisMonth = row ? row.thisMonth : ''
            this.active = null
            this.lineErrors = {}
        },
        closeLine() {
            this.active = null
            this.line = null
        },
        lineTitle() {
            return this.line?.id ? this.line.name : @js(__('New fixed expense'))
        },
        lineError() {
            return Object.values(this.lineErrors).filter(Boolean).join(' ')
        },
        async saveLine() {
            if (this.saving) return
            this.saving = true
            this.active = null
            try {
                const result = await $wire.saveLine(this.line?.id ?? null, { ...this.lineForm, amount: this.fields.amount || '' })
                this.lineErrors = result?.errors ?? {}
                if (result?.ok) this.line = null
            } catch (e) {
                this.lineErrors = { form: failed }
            } finally {
                this.saving = false
            }
        },
        async removeLine() {
            const id = this.line?.id
            if (! id) return
            this.closeLine()
            await $wire.deleteLine(id)
        },
        openThisMonth() {
            this.editAmount('thisMonth', this.fields.thisMonth, this.line?.name ?? '')
            this.thisMonthError = null
            this.thisMonthOpen = true
        },
        async saveThisMonth() {
            const result = await $wire.amountThisMonth(this.line.id, this.fields.thisMonth || '0')
            this.thisMonthError = result.error
            if (result.ok) {
                this.thisMonthOpen = false
                this.line = null
            }
        },
        openWatch(id, name = '', amount = '') {
            this.watch = { id, name, existing: id !== null }
            this.fields.watch = amount
            this.active = id !== null ? 'watch' : null
            this.amountFresh = id !== null
            this.watchError = null
            this.watchOpen = true
        },
        pickWatch(id, name) {
            this.watch = { id, name, existing: false }
            this.fields.watch = ''
            this.active = 'watch'
        },
        async saveWatch() {
            const result = await $wire.saveWatch(this.watch.id, this.fields.watch || '0')
            this.watchError = result.error
            if (result.ok) this.watchOpen = false
        },
        edit: null,
        form: { category: null, date: today, note: '' },
        errors: {},
        saving: false,
        today,

        openEdit(row) {
            this.edit = row
            this.form = { category: row.category, date: row.date, note: row.note ?? '' }
            this.fields.amount = row.amount
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
