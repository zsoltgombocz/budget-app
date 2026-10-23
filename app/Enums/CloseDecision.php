<?php

namespace App\Enums;

/**
 * The one decision at closing: what happens to the month's leftover, or to its deficit.
 */
enum CloseDecision: string
{
    /** Leftover goes to a goal ("Félreteszem"). */
    case Save = 'save';
    /** Leftover is added to next month's money ("Viszem a következő hónapba"). */
    case Carry = 'carry';
    /** Leftover is only noted ("Csak feljegyzem"). */
    case Note = 'note';
    /** Deficit is taken off next month's money ("Levonjuk a következő hónapból"). */
    case Deduct = 'deduct';
    /** Deficit was covered outside the app ("Megoldottam máshonnan"). */
    case Covered = 'covered';
    /** Deficit is covered from a goal ("Célból fedezem"). */
    case FromGoal = 'from_goal';

    public function isForDeficit(): bool
    {
        return in_array($this, [self::Deduct, self::Covered, self::FromGoal], true);
    }
}
