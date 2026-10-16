<?php

namespace App\Actions\Budget;

use App\Enums\LineStatus;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Validation\ValidationException;

/**
 * Sets a fixed item's status in a period: open, paid or not paid ("skipped", counts as 0).
 * Used for marking an item as not paid and for undoing a change to the previous status.
 */
final readonly class SetLineStatus
{
    public function __construct(private PeriodService $periods) {}

    public function handle(User $user, Period $period, int $budgetLineId, LineStatus $status): void
    {
        $line = $user->budgetLines()->with('category')->findOrFail($budgetLineId);

        if ($status === LineStatus::Skipped && ! self::canSkip($line)) {
            throw ValidationException::withMessages(['status' => __('Only a fixed item without a pocket or loan can be marked as not paid.')]);
        }

        $today = $this->periods->today($user->settings())->toDateString();
        $values = [
            'paid_on' => $status === LineStatus::Paid ? $today : null,
            'skipped_on' => $status === LineStatus::Skipped ? $today : null,
        ];

        $existing = $period->lineStatuses()->where('budget_line_id', $line->id)->first();

        if ($existing !== null) {
            // Keep the original day when the status itself does not change.
            $existing->update([
                'paid_on' => $status === LineStatus::Paid ? ($existing->paid_on ?? $today) : null,
                'skipped_on' => $status === LineStatus::Skipped ? ($existing->skipped_on ?? $today) : null,
            ]);

            return;
        }

        if ($status === LineStatus::Open) {
            return;
        }

        $record = new PeriodLineStatus(['budget_line_id' => $line->id, ...$values]);
        $record->user_id = $user->id;
        $period->lineStatuses()->save($record);
    }

    /**
     * A plain fixed item: not variable spending, not filling a pocket, not repaying a loan.
     */
    public static function canSkip(BudgetLine $line): bool
    {
        return $line->category !== null && $line->category->type !== LineType::Variable && $line->pocket_id === null && $line->loan_id === null;
    }
}
