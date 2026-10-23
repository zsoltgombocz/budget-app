<?php

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Models\User;
use App\Services\Plans;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('budget:plan {email : Email address of the user} {plan : basic, plus or auto} {--until= : Last day of the plan (Y-m-d); none: no end} {--note= : Why}')]
#[Description('Set a user\'s plan (as the admin panel does); auto goes back to the trial, then Alap')]
class SetPlan extends Command
{
    public function handle(Plans $plans): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();
        $value = (string) $this->argument('plan');
        $plan = Plan::tryFrom($value);
        $until = $this->option('until');
        $note = $this->option('note');

        if (! $user instanceof User) {
            $this->error('No user with this email.');

            return self::FAILURE;
        }

        if ($value !== 'auto' && ! in_array($plan, [Plan::Basic, Plan::Plus], true)) {
            $this->error('The plan is basic, plus or auto.');

            return self::FAILURE;
        }

        $plans->set($user, $plan, is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null, null, is_string($note) && $note !== '' ? $note : 'budget:plan');
        $this->info("{$user->email}: {$user->currentPlan()->label()}");

        return self::SUCCESS;
    }
}
