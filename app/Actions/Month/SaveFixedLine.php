<?php

namespace App\Actions\Month;

use App\Actions\Budget\DeletePlanLine;
use App\Actions\Budget\SavePlanLine;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A 2.0 fixed line from the Month screen's sheet: a fixed expense, a loan installment or a savings
 * line (tied to a goal or not). Saving it changes every month from the current one on.
 */
final readonly class SaveFixedLine
{
    public const array TYPES = [LineType::Fixed, LineType::Loan, LineType::Sinking];

    public function __construct(private SavePlanLine $savePlanLine, private DeletePlanLine $deletePlanLine) {}

    /**
     * @param  array{name: string, type: LineType, amount: int, due_day: int|null, manual_pay: bool, goal_id?: int|null, active_to?: string|null}  $data
     */
    public function save(User $user, array $data, ?BudgetLine $line = null): BudgetLine
    {
        $name = trim($data['name']);
        $errors = [];

        if ($name === '') {
            $errors['name'] = __('Give it a name.');
        }

        if ($data['amount'] < 1) {
            $errors['amount'] = __('Enter an amount.');
        }

        if (! in_array($data['type'], self::TYPES, true)) {
            $errors['type'] = __('Choose a type.');
        }

        if ($data['due_day'] !== null && ($data['due_day'] < 1 || $data['due_day'] > 31)) {
            $errors['due_day'] = __('Choose a day between 1 and 31.');
        }

        $goalId = $data['type'] === LineType::Sinking ? ($data['goal_id'] ?? null) : null;

        if ($goalId !== null && ! $user->pockets()->whereKey($goalId)->exists()) {
            $errors['goal_id'] = __('Choose one of your goals.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->savePlanLine->handle($user, [
            'name' => $name,
            'type' => $data['type'],
            'icon' => $line?->category?->icon,
            'amount' => $data['amount'],
            'due_day' => $data['due_day'],
            'manual_pay' => $data['type'] === LineType::Sinking ? true : $data['manual_pay'],
            'pocket_id' => $goalId,
            'loan_id' => $data['type'] === LineType::Loan ? $line?->loan_id : null,
            'active_from' => $line?->active_from?->toDateString(),
            'active_to' => $data['active_to'] ?? $line?->active_to?->toDateString(),
            'note' => $line?->note,
        ], $line);
    }

    public function delete(User $user, int $budgetLineId): void
    {
        $this->deletePlanLine->handle($user->budgetLines()->findOrFail($budgetLineId));
    }
}
