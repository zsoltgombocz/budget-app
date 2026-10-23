<?php

namespace App\Actions\Month;

use App\Enums\LineType;
use App\Enums\PocketMovementType;
use App\Models\Pocket;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goals (2.0): a name, an optional amount and deadline, what is on it now, and the monthly savings
 * line that feeds it ("Félretétel · Nyaralás"). A goal only grows by what the user does.
 */
final readonly class SaveGoal
{
    public function __construct(private SaveFixedLine $saveFixedLine, private PeriodService $periods) {}

    /**
     * @param  array{name: string, target: int|null, target_month: string|null, monthly: int, starting: int}  $data
     */
    public function create(User $user, array $data): Pocket
    {
        $this->validate($data);

        return DB::transaction(function () use ($user, $data): Pocket {
            $goal = $user->pockets()->create([
                'name' => trim($data['name']),
                'target_amount' => $data['target'],
                'target_month' => $this->month($data['target_month']),
                'balance' => 0,
                'sort' => $user->pockets()->withTrashed()->count() + 1,
            ]);

            if ($data['starting'] > 0) {
                $this->move($user, $goal, $data['starting'], __('Starting amount'));
            }

            if ($data['monthly'] > 0) {
                $this->saveFixedLine->save($user, [
                    'name' => __('Savings · :goal', ['goal' => $goal->name]),
                    'type' => LineType::Sinking,
                    'amount' => $data['monthly'],
                    'due_day' => null,
                    'manual_pay' => true,
                    'goal_id' => $goal->id,
                ]);
            }

            return $goal;
        });
    }

    /**
     * @param  array{name: string, target: int|null, target_month: string|null}  $data
     */
    public function update(User $user, Pocket $goal, array $data): void
    {
        $this->validate([...$data, 'monthly' => 0, 'starting' => 0]);

        $goal->update(['name' => trim($data['name']), 'target_amount' => $data['target'], 'target_month' => $this->month($data['target_month'])]);
    }

    /**
     * "Most ennyi van rajta": the goal is set to what is really there; the difference is recorded.
     */
    public function setBalance(User $user, Pocket $goal, int $balance): void
    {
        if ($balance < 0) {
            throw ValidationException::withMessages(['balance' => __('Enter an amount.')]);
        }

        if ($balance !== $goal->balance) {
            $this->move($user, $goal, $balance - $goal->balance, __('Set to what is there'));
        }
    }

    /**
     * Archive the goal: it leaves the lists and its savings line stops; it can be brought back.
     */
    public function archive(User $user, Pocket $goal): void
    {
        // The savings line ends before this month, so the month gets its amount back at once.
        $endsOn = $this->periods->current($user)->starts_on->subDay()->toDateString();

        DB::transaction(function () use ($user, $goal, $endsOn): void {
            $user->budgetLines()->where('pocket_id', $goal->id)->update(['active_to' => $endsOn]);
            $goal->delete();
        });
    }

    public function restore(User $user, int $goalId): Pocket
    {
        $goal = $user->pockets()->onlyTrashed()->findOrFail($goalId);
        $goal->restore();
        $user->budgetLines()->where('pocket_id', $goal->id)->update(['active_to' => null]);

        return $goal;
    }

    /**
     * @param  array{name: string, target: int|null, target_month: string|null, monthly: int, starting: int}  $data
     */
    private function validate(array $data): void
    {
        $errors = [];

        if (trim($data['name']) === '') {
            $errors['name'] = __('Give it a name.');
        }

        if ($data['target'] !== null && $data['target'] < 1) {
            $errors['target'] = __('Enter an amount.');
        }

        if ($data['monthly'] < 0 || $data['starting'] < 0) {
            $errors['monthly'] = __('Enter an amount.');
        }

        if ($data['target_month'] !== null && preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $data['target_month']) !== 1) {
            $errors['target_month'] = __('Choose a month.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function month(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth()->toDateString();
    }

    private function move(User $user, Pocket $goal, int $amount, string $note): void
    {
        $user->pocketMovements()->create([
            'pocket_id' => $goal->id,
            'amount' => $amount,
            'type' => $amount >= 0 ? PocketMovementType::Deposit : PocketMovementType::Withdraw,
            'occurred_on' => $this->periods->today($user->settings())->toDateString(),
            'note' => $note,
        ]);
        $goal->increment('balance', $amount);
    }
}
