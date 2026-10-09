<?php

namespace App\Notifications;

/**
 * The daily reminder sent from Settings as a test. Goes through the queue like the scheduled
 * reminders, so a test that arrives proves the whole path works.
 */
class TestReminder extends DailyReminder
{
    public function logType(): string
    {
        return 'test';
    }
}
