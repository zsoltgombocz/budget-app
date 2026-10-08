<?php

namespace App\Console\Commands;

use App\Enums\CaptureStatus;
use App\Models\PaymentCapture;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('captures:prune')]
#[Description('Forget the raw notification texts of automatically captured payments after a few days, and old settled captures')]
class PruneCaptures extends Command
{
    /** Raw texts are only for debugging the parser. */
    public const int RAW_TEXT_DAYS = 7;

    /** Settled captures are only needed for the status display and duplicate checks. */
    public const int SETTLED_DAYS = 90;

    public function handle(): int
    {
        $texts = PaymentCapture::query()->withoutGlobalScopes()
            ->whereNotNull('raw_text')
            ->where('created_at', '<', now()->subDays(self::RAW_TEXT_DAYS))
            ->update(['raw_text' => null]);

        $settled = PaymentCapture::query()->withoutGlobalScopes()
            ->where('status', '!=', CaptureStatus::Pending)
            ->where('created_at', '<', now()->subDays(self::SETTLED_DAYS));
        $deleted = $settled->count();
        $settled->delete();

        $this->line("Cleared {$texts} raw texts, deleted {$deleted} old captures.");

        return self::SUCCESS;
    }
}
