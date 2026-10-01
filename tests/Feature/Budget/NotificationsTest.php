<?php

use App\Actions\Budget\RecordTransaction;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Notifications\BudgetAlert;
use App\Notifications\DailyReminder;
use App\Notifications\DueItemsReminder;
use App\Notifications\PaydayReminder;
use App\Notifications\PeriodEndReminder;
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

it('sends the payday notification with the transfers on payday morning', function (): void {
    $this->user->settings()->update(['period_mode' => PeriodMode::Payday, 'payday_day' => 10]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Joint account', 'type' => 'transfer']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 150_000]);

    expect($this->scheduler->run($this->user->refresh(), budapest('2026-10-10 07:59')))->toBe([])
        ->and($this->scheduler->run($this->user, budapest('2026-10-10 08:01')))->toBe(['payday']);

    Notification::assertSentTo($this->user, PaydayReminder::class, fn (PaydayReminder $n): bool => $n->transfers === [['name' => 'Joint account', 'amount' => 150_000]]);
});

it('sends the period end notification on the last day', function (): void {
    expect($this->scheduler->run($this->user, budapest('2026-10-31 09:05')))->toContain('period-end');

    Notification::assertSentTo($this->user, PeriodEndReminder::class);
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

it('alerts when a spending crosses 80 percent of the budget', function (): void {
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $record = resolve(RecordTransaction::class);

    $record->handle($this->user, $fuel->id, 40_000);
    Notification::assertNotSentTo($this->user, BudgetAlert::class);

    $record->handle($this->user, $fuel->id, 10_000);
    Notification::assertSentTo($this->user, BudgetAlert::class, fn (BudgetAlert $alert): bool => $alert->percent === 80 && $alert->remaining === 10_000);
});

it('sends push notifications through the web push channel', function (): void {
    expect(new DailyReminder('2026-10-14')->via($this->user))->toBe([WebPushChannel::class]);
});

it('reminds about fixed items due today at 8:00', function (): void {
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['due_day' => 14]);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 07:59')))->not->toContain('due-items')
        ->and($this->scheduler->run($this->user, budapest('2026-10-14 08:01')))->toContain('due-items')
        ->and($this->scheduler->run($this->user, budapest('2026-10-14 09:00')))->not->toContain('due-items');

    Notification::assertSentTo($this->user, DueItemsReminder::class, fn ($n): bool => $n->items === [['name' => 'Rent', 'amount' => 200_000]]);
});

it('respects the due reminder setting', function (): void {
    $this->user->settings()->update(['due_reminder_enabled' => false]);
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['due_day' => 14]);

    expect($this->scheduler->run($this->user, budapest('2026-10-14 08:30')))->not->toContain('due-items');
});
