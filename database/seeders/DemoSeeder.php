<?php

namespace Database\Seeders;

use App\Actions\Budget\ApplyCategoryTemplate;
use App\Enums\AccountType;
use App\Enums\CalcMode;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\TransactionSource;
use App\Models\CategoryTemplate;
use App\Models\User;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A made-up user with generic numbers and a few months of generated spending.
 * Development and tests only.
 */
class DemoSeeder extends Seeder
{
    public const string EMAIL = 'demo@example.com';

    /**
     * Planned amounts per template category name (English keys).
     *
     * @var array<string, array{0: int, 1?: int, 2?: int}>
     */
    private const array AMOUNTS = [
        'Housing' => [180_000],
        'Utilities' => [35_000],
        'Phone and internet' => [12_990],
        'Groceries' => [110_000],
        'Transport' => [55_000, 55_000, 70_000],
        'Entertainment' => [25_000],
        'Other' => [15_000],
        'Reserve' => [20_000],
        'Loan' => [87_549],
        'Prepayment fund' => [50_000],
    ];

    public function __construct(
        private readonly ApplyCategoryTemplate $applyTemplate,
        private readonly PeriodService $periods,
        private readonly PeriodCloser $closer,
    ) {}

    public function run(): void
    {
        $this->call(CategoryTemplateSeeder::class);

        User::query()->where('email', self::EMAIL)->first()?->delete();

        $user = User::factory()->create([
            'name' => 'Demo User',
            'email' => self::EMAIL,
        ]);

        $user->budgetSetting()->create([
            'period_mode' => PeriodMode::Payday,
            'payday_day' => 10,
            'income' => 650_000,
            'currency' => Currency::HUF,
            'reserve_pct' => 100,
            'onboarded_at' => now(),
        ]);

        $this->applyTemplate->handle($user, CategoryTemplate::query()->where('key', 'with_loan')->firstOrFail());
        $this->fillAmounts($user);

        $user->pockets()->where('is_reserve', true)->update(['balance' => 180_000, 'target_amount' => 300_000]);
        $user->pockets()->whereNotNull('prepay_step')->update(['balance' => 350_000]);
        $user->loans()->update([
            'name' => 'Személyi kölcsön',
            'lender' => 'Demo Bank',
            'principal_balance' => 3_200_000,
            'installment' => 85_000,
            'insurance' => 2_549,
            'thm' => 12.9,
            'remaining_months' => 44,
        ]);

        $investment = $user->accounts()->create(['name' => 'Befektetési számla', 'type' => AccountType::Investment, 'currency' => Currency::HUF]);
        $user->settings()->update(['surplus_account_id' => $investment->id]);

        $this->generateSpending($user->refresh());
    }

    private function fillAmounts(User $user): void
    {
        $amountsByName = [];

        foreach (self::AMOUNTS as $name => $amounts) {
            $amountsByName[__($name)] = $amounts;
        }

        foreach ($user->budgetLines()->with('category')->get() as $line) {
            $amounts = $amountsByName[$line->category->name ?? ''] ?? null;

            if ($amounts === null) {
                continue;
            }

            $line->update([
                'amount' => $amounts[0],
                'amount_avg' => $amounts[1] ?? null,
                'amount_max' => $amounts[2] ?? null,
                'calc_mode' => isset($amounts[2]) ? CalcMode::Avg : CalcMode::Fixed,
                'due_day' => $line->category?->type === LineType::Fixed ? 12 : null,
            ]);
        }
    }

    /**
     * Two past periods (closed) plus the current one up to today.
     */
    private function generateSpending(User $user): void
    {
        $today = $this->periods->today($user->settings());
        $current = $this->periods->forDate($user, $today);
        $categories = $user->categories()->where('type', LineType::Variable)->get();

        $periods = [
            $this->periods->forDate($user, $current->starts_on->subMonths(2)),
            $this->periods->forDate($user, $current->starts_on->subMonth()),
            $current,
        ];

        mt_srand(42);

        foreach ($periods as $period) {
            $lastDay = $period->ends_on->lessThan($today) ? $period->ends_on : $today;

            for ($day = $period->starts_on; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
                foreach ($categories as $category) {
                    if (mt_rand(1, 100) > 35) {
                        continue;
                    }

                    $user->transactions()->create([
                        'period_id' => $period->id,
                        'category_id' => $category->id,
                        'amount' => mt_rand(8, 120) * 100,
                        'occurred_on' => CarbonImmutable::parse($day)->toDateString(),
                        'source' => TransactionSource::Manual,
                        'client_uuid' => (string) Str::uuid(),
                    ]);
                }
            }

            if ($period->ends_on->lessThan($today)) {
                $this->closer->close($user, $period);
            }
        }
    }
}
