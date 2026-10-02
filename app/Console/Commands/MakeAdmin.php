<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('budget:make-admin {email : Email address of the admin} {--name= : Name of the admin (defaults to the part before the @)}')]
#[Description('Add an admin account for the admin panel and the monitoring')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = $this->option('name');

        $admin = Admin::query()->firstOrCreate(['email' => $email], [
            'name' => is_string($name) && $name !== '' ? $name : Str::before($email, '@'),
            'password' => Str::random(64),
        ]);

        $this->info($admin->wasRecentlyCreated
            ? "Admin {$email} added. Sign in on the admin login page with a code sent to this address."
            : "{$email} is already an admin.");

        return self::SUCCESS;
    }
}
