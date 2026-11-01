<?php

use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\Schedule;

// The scheduler's overlap locks live in the container's file cache: one scheduler, and the
// database cache made every minute's lock a write that Pulse flagged as slow.
Schedule::useCache('file');

Schedule::command('budget:notify')->everyMinute()->withoutOverlapping();
// Broker reports are made overnight; read them in the morning.
Schedule::command('investments:sync')->dailyAt('07:10')->timezone('Europe/Budapest')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [NotificationLog::class, User::class, WaitlistSignup::class]])->dailyAt('03:30');
