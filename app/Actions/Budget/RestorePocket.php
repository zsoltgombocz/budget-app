<?php

namespace App\Actions\Budget;

use App\Models\Pocket;

/**
 * Brings an archived pocket back with its balance and history. Its monthly saving was removed
 * from the plan when it was archived and is not brought back; the user adds it again if needed.
 */
final class RestorePocket
{
    public function handle(Pocket $pocket): void
    {
        if ($pocket->trashed()) {
            $pocket->restore();
        }
    }
}
