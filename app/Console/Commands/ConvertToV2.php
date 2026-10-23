<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\V2Conversion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('budget:convert-v2 {email : The user to convert} {--force : Write the changes; without it this is a dry run}')]
#[Description('Move a user\'s plan to the 2.0 model (dry run unless --force)')]
class ConvertToV2 extends Command
{
    public function handle(V2Conversion $conversion): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error('No user with this email.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('force');
        $changes = $conversion->convert($user, $dryRun);

        foreach ($changes as $change) {
            $this->line(' - '.$change);
        }

        $this->info(match (true) {
            $changes === [] => 'Nothing to convert.',
            $dryRun => count($changes).' change(s) would be made. Run again with --force to write them.',
            default => count($changes).' change(s) made.',
        });

        return self::SUCCESS;
    }
}
