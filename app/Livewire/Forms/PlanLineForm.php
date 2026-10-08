<?php

namespace App\Livewire\Forms;

use App\Enums\CalcMode;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Support\Icons;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Form;

class PlanLineForm extends Form
{
    public ?int $lineId = null;

    public string $name = '';

    public string $type = 'variable';

    public string $icon = '';

    public string $color = '';

    public bool $isQuickEntry = true;

    public string $amount = '';

    public string $amountAvg = '';

    public string $amountMax = '';

    public string $calcMode = 'fixed';

    public ?int $dueDay = null;

    public ?int $pocketId = null;

    public ?int $loanId = null;

    public string $origAmount = '';

    public string $origCurrency = '';

    public ?string $activeFrom = null;

    public ?string $activeTo = null;

    public string $note = '';

    public function fillFrom(BudgetLine $line, Currency $currency): void
    {
        $category = $line->category;

        $this->lineId = $line->id;
        $this->name = $category->name ?? '';
        $this->type = $category?->type->value ?? LineType::Fixed->value;
        $this->icon = $category->icon ?? '';
        $this->color = $category->color ?? '';
        $this->isQuickEntry = $category->is_quick_entry ?? false;
        $this->amount = Money::toInput($line->amount, $currency);
        $this->amountAvg = Money::toInput($line->amount_avg, $currency);
        $this->amountMax = Money::toInput($line->amount_max, $currency);
        $this->calcMode = $line->calc_mode->value;
        $this->dueDay = $line->due_day;
        $this->pocketId = $line->pocket_id;
        $this->loanId = $line->loan_id;
        $this->origCurrency = $line->orig_currency ?? '';
        $this->origAmount = $line->orig_currency !== null && Currency::tryFrom($line->orig_currency) !== null
            ? Money::toInput($line->orig_amount, Currency::from($line->orig_currency))
            : '';
        $this->activeFrom = $line->active_from?->toDateString();
        $this->activeTo = $line->active_to?->toDateString();
        $this->note = $line->note ?? '';
    }

    public function startNew(LineType $type): void
    {
        $this->reset();
        $this->type = $type->value;
        $this->isQuickEntry = $type === LineType::Variable;
    }

    /**
     * Validate and convert to the SavePlanLine payload.
     *
     * @return array{name: string, type: LineType, icon: string|null, color: string|null, is_quick_entry: bool, amount: int, amount_avg: int|null, amount_max: int|null, calc_mode: CalcMode, due_day: int|null, pocket_id: int|null, loan_id: int|null, orig_amount: int|null, orig_currency: string|null, active_from: string|null, active_to: string|null, note: string|null}
     */
    public function payload(Currency $currency, int $userId): array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(LineType::class)],
            'calcMode' => ['required', Rule::enum(CalcMode::class)],
            'amount' => ['required', $this->moneyRule($currency)],
            'amountAvg' => ['nullable', $this->moneyRule($currency)],
            'amountMax' => ['nullable', $this->moneyRule($currency)],
            'dueDay' => ['nullable', 'integer', 'between:1,31'],
            'pocketId' => ['nullable', 'integer', Rule::exists('pockets', 'id')->where('user_id', $userId)->whereNull('deleted_at')],
            'loanId' => ['nullable', 'integer', Rule::exists('loans', 'id')->where('user_id', $userId)],
            'origCurrency' => ['nullable', Rule::enum(Currency::class)],
            'origAmount' => ['nullable', 'required_with:origCurrency', $this->moneyRule(Currency::tryFrom($this->origCurrency) ?? $currency)],
            'activeFrom' => ['nullable', 'date'],
            'activeTo' => ['nullable', 'date', 'after_or_equal:activeFrom'],
            'note' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', Rule::in(['', ...Icons::CATEGORY])],
            'color' => ['nullable', 'string', 'max:20', 'alpha'],
        ]);

        $origCurrency = Currency::tryFrom($this->origCurrency);
        $type = LineType::from($this->type);
        $avg = $this->parse($this->amountAvg, $currency);
        $max = $this->parse($this->amountMax, $currency);
        $calcMode = CalcMode::from($this->calcMode);

        if ($type === LineType::Variable && $calcMode === CalcMode::Fixed && ($avg !== null || $max !== null)) {
            $calcMode = $avg !== null ? CalcMode::Avg : CalcMode::Max;
        }

        return [
            'name' => trim($this->name),
            'type' => $type,
            'icon' => $this->icon !== '' ? $this->icon : null,
            'color' => $this->color !== '' ? $this->color : null,
            'is_quick_entry' => $this->isQuickEntry,
            'amount' => (int) $this->parse($this->amount, $currency),
            'amount_avg' => $avg,
            'amount_max' => $max,
            'calc_mode' => $calcMode,
            'due_day' => $this->dueDay,
            'pocket_id' => $this->pocketId,
            'loan_id' => $this->loanId,
            'orig_amount' => $origCurrency instanceof Currency ? $this->parse($this->origAmount, $origCurrency) : null,
            'orig_currency' => $origCurrency?->value,
            'active_from' => $this->activeFrom ?: null,
            'active_to' => $this->activeTo ?: null,
            'note' => $this->note !== '' ? $this->note : null,
        ];
    }

    private function parse(string $value, Currency $currency): ?int
    {
        return $value === '' ? null : Money::parse($value, $currency);
    }

    private function moneyRule(Currency $currency): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($currency): void {
            if ($value !== null && $value !== '' && (! is_scalar($value) || Money::parse((string) $value, $currency) === null)) {
                $fail(__('Enter a valid amount.'));
            }
        };
    }
}
