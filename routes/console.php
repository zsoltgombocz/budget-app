<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budget:notify')->everyMinute()->withoutOverlapping()->onOneServer();

// Raw notification texts of captured payments are kept for a week at most.
Schedule::command('captures:prune')->dailyAt('03:30')->onOneServer();
