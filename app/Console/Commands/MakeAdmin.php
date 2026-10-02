<?php

namespace App\Console\Commands;

use App\Actions\Admin\InviteUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('budget:make-admin {email : Email address of the admin} {--name= : Name, when the account has to be created (it gets an invite)}')]
#[Description('Make a user an admin of the admin panel and the monitoring')]
class MakeAdmin extends Command
{
    public function handle(InviteUser $inviteUser): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $name = $this->option('name');

            if (! is_string($name) || $name === '') {
                $this->error("No account for {$email}. Pass --name to create it and send an invite.");

                return self::FAILURE;
            }

            $user = $inviteUser->handle($name, $email);
            $this->info("Invited {$email}.");
        }

        $user->forceFill(['is_admin' => true, 'disabled_at' => null])->save();
        $this->info("{$email} is an admin now.");

        return self::SUCCESS;
    }
}
