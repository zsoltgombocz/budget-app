<?php

namespace App\Enums;

/**
 * Why the month moves away from its plan: the only things that change the leftover the plan
 * expects once every budget is spent in full.
 */
enum DeviationReason: string
{
    /** A variable category went over its budget (or has spending without a budget). */
    case Overspent = 'overspent';

    /** A fixed item does not come up this month, so it counts as 0. */
    case Skipped = 'skipped';

    /** Money put into a pocket from this month. */
    case ToPockets = 'to_pockets';

    /** Money taken out of a pocket into this month's budget. */
    case FromPockets = 'from_pockets';
}
