<?php

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Schedule;

// The scheduler's overlap locks live in the container's file cache: one scheduler, and the
// database cache made every minute's lock a write that Pulse flagged as slow.
Schedule::useCache('file');

Schedule::command('budget:notify')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [NotificationLog::class]])->dailyAt('03:30');
