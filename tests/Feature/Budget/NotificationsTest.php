<?php

use App\Actions\Budget\RecordTransaction;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Notifications\BudgetAlert;
use App\Notifications\CloseReminder;
use App\Notifications\DailyReminder;
use App\Notifications\DueItemsReminder;
use App\Notifications\PeriodEndReminder;
use App\Notifications\WeeklySummary;
use App\Services\NotificationScheduler;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(function (): void {
    Notification::fake();
    $this->user = onboardedUser(['reminder_time' => '20:30', 'timezone' => 'Europe/Budapest']);
    $this->user->updatePushSubscription('https://push.example.com/abc', 'key', 'token');
    $this->scheduler = resolve(NotificationScheduler::class);
});

/**
 * Budapest is UTC+2 in October (CEST).
 */
function budapest(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Budapest');
}

it('sends the daily reminder after the reminder time in the user\'s timezone, once a day', function (): void {
    expect($this->scheduler->run($this->user, budapest('2026-10-14 20:29')))->toBe([]);
    expect($this->scheduler->run($this->user, budapest('2026-10-14 20:31')))->toBe(['daily']);
    expect($this->scheduler->run($this->user, budapest('2026-10-14 20:45')))->toBe([]);

    Notification::assertSentToTimes($this->user, DailyReminder::class, 1);
});

it('skips the reminder when something was recorded or the day was marked', function (): void {
    $period = resolve(PeriodService::class)->forDate($this->user, budapest('2026-10-14'));
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())
        ->create(['occurred_on' => '2026-10-14']);
    $this->user->dayMarks()->create(['date' => '2026-10-15']);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 21:00')))->toBe([])
        ->and($this->scheduler->run($this->user, budapest('2026-10-15 21:00')))->toBe([]);

    Notification::assertNothingSent();
});

it('respects a disabled reminder', function (): void {
    $this->user->settings()->update(['reminder_enabled' => false]);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 21:00')))->toBe([]);
});

it('reminds that a month is waiting to be closed, once, the morning after it ended', function (): void {
    $october = resolve(PeriodService::class)->forDate($this->user, budapest('2026-10-14'));

    expect($this->scheduler->run($this->user, budapest('2026-11-01 09:59')))->not->toContain('close')
        ->and($this->scheduler->run($this->user, budapest('2026-11-01 10:01')))->toContain('close')
        ->and($this->scheduler->run($this->user, budapest('2026-11-02 10:01')))->not->toContain('close');

    Notification::assertSentTo($this->user, CloseReminder::class, fn (CloseReminder $n): bool => $n->periodId === $october->id && $n->leftover === 300_000);
});

it('sends the period end notification on the last day evening with what the month still has', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())->create(['amount' => 15_000, 'occurred_on' => '2026-10-20']);

    expect($this->scheduler->run($this->user, budapest('2026-10-31 18:59')))->not->toContain('period-end')
        ->and($this->scheduler->run($this->user, budapest('2026-10-31 19:05')))->toContain('period-end');

    Notification::assertSentTo($this->user, PeriodEndReminder::class, function (PeriodEndReminder $reminder): bool {
        $message = $reminder->toWebPush($this->user, $reminder)->toArray();

        return $reminder->remaining === 285_000 && str_contains((string) $message['body'], money(285_000));
    });
});

it('sends the weekly summary on Sunday evening, when it is on', function (): void {
    $period = resolve(PeriodService::class)->forDate($this->user, budapest('2026-10-18'));
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Groceries')->firstOrFail())->create(['amount' => 12_300, 'occurred_on' => '2026-10-17']);

    expect($this->scheduler->run($this->user, budapest('2026-10-18 17:59')))->not->toContain('weekly')
        ->and($this->scheduler->run($this->user, budapest('2026-10-18 18:01')))->toContain('weekly');

    Notification::assertSentTo($this->user, WeeklySummary::class, fn (WeeklySummary $n): bool => $n->spent === 12_300 && $n->topCategory === 'Groceries');

    $this->user->settings()->update(['weekly_summary_enabled' => false]);

    expect($this->scheduler->run($this->user, budapest('2026-10-25 18:01')))->not->toContain('weekly');
});

it('builds the reminder payload with the record and no-spend actions', function (): void {
    $message = new DailyReminder('2026-10-14')->toWebPush($this->user, new DailyReminder('2026-10-14'))->toArray();

    expect($message['title'])->not->toBeEmpty()
        ->and(array_column($message['actions'], 'action'))->toBe(['record', 'no-spend'])
        ->and($message['data']['url'])->toBe('/rogzites')
        ->and($message['data']['noSpendUrl'])->toContain('signature=');
});

it('uses the user\'s language', function (): void {
    $this->user->settings()->update(['locale' => 'hu']);

    expect($this->user->refresh()->preferredLocale())->toBe('hu');
});

it('pushes the 80% note on a watched category once, only when asked for', function (): void {
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->user->watchedCategories()->create(['category_id' => $fuel->id, 'amount' => 50_000]);
    $record = resolve(RecordTransaction::class);

    $record->handle($this->user, $fuel->id, 40_000);
    Notification::assertNotSentTo($this->user, BudgetAlert::class);

    $this->user->settings()->update(['watch_push_enabled' => true]);
    $record->handle($this->user->refresh(), $fuel->id, 1_000);
    Notification::assertNotSentTo($this->user, BudgetAlert::class);

    $this->user->watchedCategories()->update(['amount' => 45_000]);
    $record->handle($this->user, $fuel->id, 100);
    $record->handle($this->user, $fuel->id, 100);
    Notification::assertSentToTimes($this->user, BudgetAlert::class, 0);
});

it('pushes when a spending crosses 80% of a watched category', function (): void {
    $this->user->settings()->update(['watch_push_enabled' => true]);
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->user->watchedCategories()->create(['category_id' => $fuel->id, 'amount' => 50_000]);
    $record = resolve(RecordTransaction::class);

    $record->handle($this->user->refresh(), $fuel->id, 39_000);
    $record->handle($this->user, $fuel->id, 2_000);
    $record->handle($this->user, $fuel->id, 2_000);

    Notification::assertSentToTimes($this->user, BudgetAlert::class, 1);
    Notification::assertSentTo($this->user, BudgetAlert::class, fn (BudgetAlert $alert): bool => $alert->percent === 80 && $alert->remaining === 9_000);
});

it('sends push notifications through the web push channel', function (): void {
    expect(new DailyReminder('2026-10-14')->via($this->user))->toBe([WebPushChannel::class]);
});

it('reminds about the day\'s fixed lines at 8:30, in one notification', function (): void {
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['due_day' => 14]);
    $bills = Category::factory()->for($this->user)->create(['name' => 'Bills', 'type' => 'fixed']);
    BudgetLine::factory()->for($this->user)->for($bills)->create(['amount' => 38_000, 'due_day' => 14, 'manual_pay' => true]);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 08:29')))->not->toContain('due-items')
        ->and($this->scheduler->run($this->user, budapest('2026-10-14 08:31')))->toContain('due-items')
        ->and($this->scheduler->run($this->user, budapest('2026-10-14 09:00')))->not->toContain('due-items');

    Notification::assertSentTo($this->user, DueItemsReminder::class, fn ($n): bool => $n->items === [['name' => 'Bills', 'amount' => 38_000, 'kind' => 'manual'], ['name' => 'Rent', 'amount' => 200_000, 'kind' => 'auto']]
        && str_contains((string) $n->toWebPush($this->user, $n)->toArray()['body'], 'Goes out today: Rent'));
});

it('respects the due reminder setting', function (): void {
    $this->user->settings()->update(['due_reminder_enabled' => false]);
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['due_day' => 14]);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 08:30')))->not->toContain('due-items');
});

it('logs a queued reminder and a skipped one with the reason', function (): void {
    $this->scheduler->run($this->user, budapest('2026-10-14 20:31'));

    $period = resolve(PeriodService::class)->forDate($this->user, budapest('2026-10-15'));
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())
        ->create(['occurred_on' => '2026-10-15']);
    $this->scheduler->run($this->user, budapest('2026-10-15 20:31'));
    $this->scheduler->run($this->user, budapest('2026-10-15 20:40'));

    $logs = $this->user->notificationLogs()->orderBy('id')->get(['type', 'status', 'reason', 'notification_id']);

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->only(['type', 'status']))->toBe(['type' => 'daily-reminder', 'status' => 'queued'])
        ->and($logs[0]->notification_id)->not->toBeNull()
        ->and($logs[1]->only(['type', 'status', 'reason']))->toBe(['type' => 'daily-reminder', 'status' => 'skipped', 'reason' => 'recorded-today']);
});
