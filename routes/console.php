<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budget:notify')->everyMinute()->withoutOverlapping()->onOneServer();
