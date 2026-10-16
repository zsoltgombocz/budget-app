<?php

namespace App\Actions\Budget;

use App\Enums\LineStatus;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\User;
use App\Services\Data\FixedItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Ticking a fixed item off, marking it as not paid this period and setting it back, for Today and
 * Month. Each change answers with the toast to show, whose undo sets the item back to the status
 * it had; null means nothing changed (closed period, same status). An item the period does not
 * have is not found.
 *
 * @phpstan-type Toast array{title: string, subtitle: string|null, icon: string, undo: string, params: array{budgetLineId: int, status: string}}
 */
final readonly class ChangeFixedItemStatus
{
    public const string UNDO_EVENT = 'fixed-item-undo';

    public function __construct(private ToggleLinePaid $toggleLinePaid, private SetLineStatus $setLineStatus) {}

    /**
     * Tick the item off, or untick it; ticking an item marked as not paid makes it paid after all.
     *
     * @param  list<FixedItem>  $items  the period's fixed items as they stand now
     * @return Toast|null
     */
    public function toggle(User $user, Period $period, array $items, int $budgetLineId): ?array
    {
        $item = $this->findOrFail($items, $budgetLineId);

        if (! $period->isOpen()) {
            return null;
        }

        $paid = $this->toggleLinePaid->handle($user, $period, $budgetLineId);
        $isTransfer = in_array($item->line->type, [LineType::Transfer, LineType::Sinking], true);

        return $this->toast(
            title: match (true) {
                ! $paid => __('Marked as not done.'),
                $isTransfer => __('Marked as transferred.'),
                default => __('Marked as done.'),
            },
            subtitle: null,
            icon: $paid ? 'check_circle' : 'remove',
            budgetLineId: $budgetLineId,
            previous: $item->status(),
        );
    }

    /**
     * Mark the item as not paid this period (it counts as 0) or set it back to due.
     *
     * @param  list<FixedItem>  $items
     * @return Toast|null
     */
    public function set(User $user, Period $period, array $items, int $budgetLineId, LineStatus $status): ?array
    {
        $item = $this->findOrFail($items, $budgetLineId);

        if (! $period->isOpen() || $item->status() === $status || ($status === LineStatus::Skipped && ! $item->line->isSkippable())) {
            return null;
        }

        $this->setLineStatus->handle($user, $period, $budgetLineId, $status);
        $skipped = $status === LineStatus::Skipped;
        $effect = __('It counts as 0, so :amount is not taken off the leftover.', ['amount' => money($item->line->planned())]);

        return $this->toast(
            title: $skipped ? __('Marked as not paid this month.') : __('Due again.'),
            subtitle: $skipped && is_string($effect) ? $effect : null,
            icon: $skipped ? 'event_busy' : 'undo',
            budgetLineId: $budgetLineId,
            previous: $item->status(),
        );
    }

    /**
     * Undo from the toast: set the item back to the status it had, once (a second tap changes nothing).
     *
     * @param  list<FixedItem>  $items
     */
    public function undo(User $user, Period $period, array $items, int $budgetLineId, string $status): bool
    {
        $item = $this->find($items, $budgetLineId);
        $previous = LineStatus::tryFrom($status);

        if (! $period->isOpen() || ! $item instanceof FixedItem || ! $previous instanceof LineStatus || $item->status() === $previous) {
            return false;
        }

        $this->setLineStatus->handle($user, $period, $budgetLineId, $previous);

        return true;
    }

    /**
     * @param  list<FixedItem>  $items
     */
    private function findOrFail(array $items, int $budgetLineId): FixedItem
    {
        return $this->find($items, $budgetLineId) ?? throw new ModelNotFoundException()->setModel(BudgetLine::class, [$budgetLineId]);
    }

    /**
     * @param  list<FixedItem>  $items
     */
    private function find(array $items, int $budgetLineId): ?FixedItem
    {
        foreach ($items as $item) {
            if ($item->line->lineId === $budgetLineId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return Toast
     */
    private function toast(string $title, ?string $subtitle, string $icon, int $budgetLineId, LineStatus $previous): array
    {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'icon' => $icon,
            'undo' => self::UNDO_EVENT,
            'params' => ['budgetLineId' => $budgetLineId, 'status' => $previous->value],
        ];
    }
}
