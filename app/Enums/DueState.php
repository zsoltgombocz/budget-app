<?php

namespace App\Enums;

/**
 * Where a fixed line stands on a given day of the month. The amount is taken off in advance in every
 * state; these only say what the user sees and whether there is anything to do.
 */
enum DueState: string
{
    /** Its day is still ahead. */
    case Upcoming = 'upcoming';
    /** Goes out today on its own: "Ma megy le … előre levonva, nincs teendő". */
    case DueToday = 'due_today';
    /** Its day has passed (assumed, no bank data): "levonva (a napja elmúlt)". */
    case Deducted = 'deducted';
    /** Paid by hand and due today: "Ma esedékes … Elintéztem / Félretettem". */
    case ToDo = 'to_do';
    /** Paid by hand, its day has passed and it is not marked yet: "Még nincs elintézve". */
    case Overdue = 'overdue';
    /** Paid by hand and marked: "elintézve" / "félretéve". */
    case Done = 'done';
    /** Not this month ("Ebben a hónapban nem lesz"). */
    case Skipped = 'skipped';
    /** Has no day, so the app never speaks about it. */
    case NoDay = 'no_day';

    /**
     * Whether it counts as gone out in "6-ból 5 levonva / elintézve".
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::DueToday, self::Deducted, self::Done], true);
    }

    /**
     * Whether the Today screen shows a row for it.
     */
    public function showsOnToday(): bool
    {
        return in_array($this, [self::DueToday, self::ToDo, self::Overdue], true);
    }
}
