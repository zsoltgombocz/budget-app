<?php

namespace App\Services\Investments;

use RuntimeException;

/**
 * Interactive Brokers did not hand over the report: the message says why, for the account card.
 */
final class IbkrFlexFailed extends RuntimeException {}
