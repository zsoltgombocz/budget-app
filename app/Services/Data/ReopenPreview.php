<?php

namespace App\Services\Data;

use App\Models\Period;
use App\Models\PeriodClose;
use App\Support\Dates;
use Carbon\CarbonImmutable;

/**
 * What undoing the last closing will do, shown in the confirmation before it happens.
 */
final readonly class ReopenPreview
{
    /**
     * @param  CarbonImmutable  $regularEnd  the period runs until this day again
     * @param  list<int>  $movementIds  the pocket movements the closing made, to be deleted
     * @param  list<array{pocket_id: int, name: string, amount: int}>  $pocketChanges  balance change per pocket (the closing's movements reversed)
     * @param  int|null  $removedPocketId  the "Savings" pocket the closing created, deleted when nothing else uses it
     * @param  Period|null  $nextPeriod  the period the closing opened
     * @param  bool  $removesNextPeriod  false when the next period keeps entries dated after $regularEnd
     * @param  int  $movedEntries  spending and pocket movements recorded in the next period that move back
     */
    public function __construct(
        public Period $period,
        public PeriodClose $close,
        public CarbonImmutable $regularEnd,
        public array $movementIds,
        public array $pocketChanges,
        public ?int $removedPocketId,
        public ?string $removedPocketName,
        public ?Period $nextPeriod,
        public bool $removesNextPeriod,
        public int $movedEntries,
    ) {}

    /**
     * The confirmation dialog (window.appConfirm options), saying exactly what will happen.
     *
     * @return array{title: string, body: string, confirm: string}
     */
    public function confirmation(): array
    {
        $parts = [];

        if ($this->pocketChanges === []) {
            $parts[] = __('The closing did not move money in any pocket.');
        } else {
            $lines = array_map(
                fn (array $change): string => '• '.$change['name'].': '.($change['amount'] > 0 ? '+' : '').money($change['amount']),
                $this->pocketChanges,
            );
            $parts[] = __('The pocket movements of the closing are taken back:')."\n".implode("\n", $lines);
        }

        if ($this->removedPocketName !== null) {
            $parts[] = __('The “:pocket” pocket created at the closing is deleted.', ['pocket' => $this->removedPocketName]);
        }

        $parts[] = __('The month is open again: :range.', ['range' => Dates::range($this->period->starts_on, $this->regularEnd)]);

        if ($this->movedEntries > 0) {
            $parts[] = trans_choice('{1} The entry recorded since the closing moves back to this month.|[2,*] The :count entries recorded since the closing move back to this month.', $this->movedEntries, ['count' => $this->movedEntries]);
        }

        if ($this->nextPeriod instanceof Period && $this->removesNextPeriod) {
            $parts[] = __('The new month the closing started (:range) is removed.', ['range' => Dates::range($this->nextPeriod->starts_on, $this->nextPeriod->ends_on)]);
        } elseif ($this->nextPeriod instanceof Period) {
            $parts[] = __('The new month starts on :date instead.', ['date' => Dates::short($this->regularEnd->addDay())]);
        }

        if ($this->close->awaitsTransfer()) {
            $parts[] = __('The reminder to transfer the leftover stops.');
        }

        if ($this->close->surplus_transferred_at !== null) {
            $parts[] = __('You marked the leftover (:amount) as transferred. That mark goes with the closing; the money you transferred stays where it is. After closing again, mark it as transferred again.', ['amount' => money($this->close->to_invest)]);
        }

        $parts[] = __('You can close the month again whenever you are ready.');

        return [
            'title' => __('Undo the :month closing?', ['month' => Dates::monthInSentence($this->period->nameDate(), adjective: true)]),
            'body' => implode("\n\n", $parts),
            'confirm' => __('Undo the closing'),
        ];
    }
}
