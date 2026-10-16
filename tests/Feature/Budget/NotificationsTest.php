<?php

use App\Actions\Budget\PayFromPocket;
use App\Actions\Budget\RecordTransaction;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Notifications\BudgetAlert;
use App\Notifications\DailyReminder;
use App\Notifications\DueItemsReminder;
use App\Notifications\PaydayReminder;
use App\Notifications\PeriodEndReminder;
use App\Services\NotificationScheduler;
use App\Services\PeriodCloser;
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

    Notification::assertSentTo($this->user, PaydayReminder::class, fn (PaydayReminder $n): bool => $n->transfers === [['name' => 'Joint account', 'amount' => 150_000]]
        && $n->toWebPush($this->user, $n)->toArray()['title'] === 'Payday – time for the transfers');
});

it('sends the period end notification on the last day', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())->create(['amount' => 15_000, 'occurred_on' => '2026-10-20']);

    expect($this->scheduler->run($this->user, budapest('2026-10-31 09:05')))->toContain('period-end');

    // The main number on Today (leftover as things stand), not the "every budget spent" forecast.
    $leftover = resolve(PeriodCloser::class)->preview($this->user, $period)->leftover();
    Notification::assertSentTo($this->user, PeriodEndReminder::class, function (PeriodEndReminder $reminder) use ($leftover): bool {
        $message = $reminder->toWebPush($this->user, $reminder)->toArray();

        return $reminder->leftover === $leftover && str_contains((string) $message['body'], 'Leftover as things stand: '.money($leftover));
    });
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

it('does not alert or count spending paid from a pocket against the budget', function (): void {
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 100_000]);

    $paid = resolve(PayFromPocket::class)->handle($this->user, $pocket, $fuel->id, 50_000);
    Notification::assertNotSentTo($this->user, BudgetAlert::class);
    expect($paid->pocket_id)->toBe($pocket->id);

    // 40 000 of the 60 000 budget: under 80 % once the pocket-paid 50 000 is left out.
    resolve(RecordTransaction::class)->handle($this->user, $fuel->id, 40_000);
    Notification::assertNotSentTo($this->user, BudgetAlert::class);

    resolve(RecordTransaction::class)->handle($this->user, $fuel->id, 10_000);
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

it('reminds once the next morning about an untransferred leftover', function (): void {
    $broker = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);
    $period = resolve(PeriodService::class)->forDate($this->user, budapest('2026-10-14'));
    $close = new PeriodClose(['period_id' => $period->id, 'planned_total' => 0, 'actual_total' => 0, 'leftover' => 50_000, 'to_invest' => 50_000, 'surplus_account_id' => $broker->id, 'breakdown' => []]);
    $close->user_id = $this->user->id;
    $close->created_at = budapest('2026-10-13 20:00');
    $close->save();

    expect($this->scheduler->run($this->user, budapest('2026-10-14 08:59')))->not->toContain('surplus-transfer')
        ->and($this->scheduler->run($this->user, budapest('2026-10-14 09:01')))->toContain('surplus-transfer')
        ->and($this->scheduler->run($this->user, budapest('2026-10-15 09:01')))->not->toContain('surplus-transfer');
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
