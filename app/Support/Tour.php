<?php

namespace App\Support;

use App\Enums\LineType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The steps of the "How it works" spotlight tour (components/tour), one list per screen.
 *
 * Every step points at the elements marked data-tour="{id}" on its page. A step that would
 * not apply to the user (no fixed items, no loans…) is left out here, so the step counter that
 * runs across the screens is right from the first card. The first sentence of each card carries
 * its rule, for the reader who only skims. The texts use the user's own numbers where that helps,
 * and every claim follows the app's real formulas (ForecastService, PeriodCloser, PeriodService,
 * NotificationScheduler): keep them in step with the explainer sheets (components/explainer).
 *
 * @phpstan-type TourStep array{id: string, title: string, body: list<string>}
 */
final class Tour
{
    /**
     * The screens after Today, in the order the tour visits them.
     */
    public const array SECTIONS_AFTER_TODAY = ['plan', 'pockets', 'month'];

    /**
     * @param  list<LineType>  $committedTypes  the kinds of fixed lines in this month (taken off in full)
     * @return list<TourStep>
     */
    public static function today(
        int $leftover,
        int $unspentBudget,
        int $ifSpent,
        int $daily,
        int $remainingDays,
        int $variableSpent,
        int $variablePlanned,
        array $committedTypes = [],
        int $pocketDeposits = 0,
        bool $hasCategories = true,
        bool $hasUnbudgeted = false,
        bool $hasFixed = true,
        bool $canMarkNoSpend = true,
    ): array {
        $taken = self::committedList($committedTypes);

        $steps = [
            self::step('leftover', __('The big number: your leftover'), [
                $unspentBudget > 0
                    ? __('If the month ended today, :amount would be left – your budget not spent yet (:unspent) included.', ['amount' => money($leftover), 'unspent' => money($unspentBudget)])
                    : __('If the month ended today, :amount would be left.', ['amount' => money($leftover)]),
                self::onlyIf($taken !== null, __(':items are already taken off in full.', ['items' => $taken])),
                self::onlyIf($pocketDeposits > 0, __('So is the :amount you put into pockets.', ['amount' => money($pocketDeposits)])),
                __('Of the variable spending only what you have spent so far counts, so the number goes down as you spend.'),
            ]),
            self::step('if-spent', __('If you spend every budget'), [
                __('Watch this one: :amount is left at the end of the month if you spend every budget in full.', ['amount' => money($ifSpent)]),
                __('As long as you spend within your budgets, it stays put.'),
                __('Overspending and money put into a pocket lower it; money taken out of a pocket and a skipped fixed item raise it.'),
            ]),
            self::step('daily', __('You can still spend today'), [
                trans_choice('[0,1] :amount is today’s share: the money left for variable spending, and this is the last day.|[2,*] :amount is today’s share: the money left for variable spending, spread evenly over the :count days to go, today included.', $remainingDays, ['amount' => money($daily), 'count' => $remainingDays]),
                __('Spend more today and the days after get less: after every entry it spreads what is left again.'),
                __('Money put into a pocket lowers it, money taken out raises it. Once the budgets are used up it shows 0.'),
            ]),
            self::step('record', __('Recording a spending'), [
                __('Only the variable spending needs writing down: tap +, pick the category, type the amount and save.'),
                self::onlyIf($canMarkNoSpend, __('Spent nothing today? Tap “I didn’t spend today”: no reminder comes in the evening, and you can undo it.')),
            ]),
        ];

        if ($hasCategories) {
            $steps[] = self::step('categories', __('Variable spending'), [
                __('Each budget with what you have spent of it; in total :spent of :planned.', ['spent' => money($variableSpent), 'planned' => money($variablePlanned)]),
                __('No need for a budget this month? Just do not spend from it: what is left of it raises the month-end leftover.'),
                self::onlyIf($hasUnbudgeted, __('Where it says “no budget”, you planned no money: spending there comes out of the month-end leftover.')),
            ]);
        }

        if ($hasFixed) {
            $steps[] = self::step('fixed', __('Fixed items'), [
                __('Tap the circle once it is paid: it is only a reminder and changes none of the numbers.'),
                __('Not coming up this month? In its ⋯ menu choose “Skipped this month”: then it counts as 0.'),
            ]);
        }

        return $steps;
    }

    /**
     * @return list<TourStep>
     */
    public static function plan(int $income, int $expenses, int $left, bool $hasSavings = false, bool $hasVariable = true, int $reserveMonthly = 0, string $reserveName = ''): array
    {
        $steps = [
            self::step('plan-summary', __('Your monthly plan'), [
                __('Whatever you change here applies to the current month as well, from its first day.'),
                $hasSavings
                    ? __('Income :income, expenses and savings :expenses, left :left.', ['income' => money($income), 'expenses' => money($expenses), 'left' => money($left)])
                    : __('Income :income, expenses :expenses, left :left.', ['income' => money($income), 'expenses' => money($expenses), 'left' => money($left)]),
                __('To change your income (pay, pension…), tap its row.'),
            ]),
        ];

        if ($hasVariable) {
            $steps[] = self::step('plan-variable', __('Variable spending'), [
                __('Only this spending needs recording: the monthly budgets for groceries, the market, medicine. Bills like rent are taken off in advance.'),
                __('Tap an item to change it, add a new one with the pencil at the top. The green + always records a spending.'),
            ]);
        }

        $steps[] = self::step('plan-month-end', __('Money left at the end of the month'), [
            __('The app moves no money: here you decide in advance where the leftover goes, and you move it in your bank.'),
            __('At closing you can still change it.'),
            self::onlyIf($reserveMonthly > 0, __(':pocket: the :amount a month goes into the pocket when you close the month.', ['amount' => money($reserveMonthly), 'pocket' => $reserveName])),
        ]);

        return $steps;
    }

    /**
     * @return list<TourStep>
     */
    public static function pockets(int $saved, bool $hasPockets = true, bool $hasLoans = false): array
    {
        if (! $hasPockets) {
            $steps = [
                self::step('pockets-header', __('Pockets'), [
                    __('A pocket is only a record: the money stays in your bank, the app counts what you put aside for a goal.'),
                    __('A reserve, a holiday, a present: add your first one with the + at the top.'),
                ]),
            ];
        } else {
            $steps = [
                self::step('pocket-card', __('Pockets'), [
                    __('A pocket is only a record: the money stays in your bank, the app counts what you put aside for a goal.'),
                    __('Saved in total: :amount. A new pocket comes from the + at the top; tap a pocket for its settings.', ['amount' => money($saved)]),
                ]),
                self::step('pocket-deposit', __('Deposit'), [
                    __('The pocket’s balance goes up and the month’s leftover goes down by the same amount.'),
                    __('“If you spend every budget” goes down by the same amount too, and the daily amount gets smaller: this money is no longer for spending this month.'),
                    __('You make the real transfer in your bank.'),
                ]),
                self::step('pocket-withdraw', __('Withdraw'), [
                    __('Withdraw offers two ways: “Withdraw to the monthly budget” or “Pay a spending”.'),
                    __('Withdraw to the monthly budget: the money counts as extra income this month, the leftover and the daily amount go up.'),
                    __('Pay a spending: the pocket pays it, your budgets stay untouched.'),
                ]),
            ];
        }

        if ($hasLoans) {
            $steps[] = self::step('loans', __('Loans'), [
                __('Each loan with what you still owe and the monthly installment.'),
                __('A new loan’s installment goes into the plan by itself, you never record it.'),
            ]);
        }

        return $steps;
    }

    /**
     * @return list<TourStep>
     */
    public static function month(int $leftover, ?CarbonInterface $closableFrom, bool $hasSpending = true, bool $hasFilters = true, bool $isLast = true): array
    {
        return [
            self::step('month-now', __('If the month ended today'), [
                __('The same number as the big one on Today: :amount.', ['amount' => money($leftover)]),
                __('The badge next to it compares the month with the plan; the i button shows what makes the difference.'),
            ]),
            self::step('month-close', __('Closing the month'), [
                __('Nothing moves without you: at closing you decide where the leftover goes, and in the red whether the reserve covers it.'),
                $closableFrom instanceof CarbonInterface
                    ? __('You can close this month in its last 7 days, :from.', ['from' => Dates::from($closableFrom)])
                    : __('You can close this month now.'),
                __('Not closed? Nothing gets stuck: the new month starts by itself, and you can close the old one later.'),
            ]),
            self::step('month-list', __('The month’s spending'), [
                $hasSpending ? __('Every spending you recorded, day by day. Tap one to correct or delete it.') : __('Every spending you record shows up here, day by day. Tap one to correct or delete it.'),
                $hasFilters ? __('Search in the notes and amounts, or filter by category.') : __('Search in the notes and amounts.'),
                self::onlyIf($isLast, __('You can watch this again any time: Settings → How it works.')),
            ]),
        ];
    }

    /**
     * How many steps the screens after Today will have for this user, for the step counter that
     * runs across the screens. Mirrors the conditions the screens pass to plan() and pockets().
     *
     * @return array<string, int>
     */
    public static function upcoming(User $user): array
    {
        $hasVariable = $user->budgetLines()->whereRelation('category', 'type', LineType::Variable->value)->exists();

        return [
            'plan' => count(self::plan(0, 0, 0, hasVariable: $hasVariable)),
            'pockets' => count(self::pockets(0, hasPockets: $user->pockets()->exists(), hasLoans: $user->loans()->exists())),
            'month' => count(self::month(0, null)),
        ];
    }

    /**
     * "Your fixed items, your transfers and your loan installments", naming only the kinds the user has.
     *
     * @param  list<LineType>  $types
     */
    private static function committedList(array $types): ?string
    {
        $names = [
            LineType::Fixed->value => __('your fixed items'),
            LineType::Transfer->value => __('your transfers'),
            LineType::Loan->value => __('your loan installments'),
            LineType::Sinking->value => __('your monthly savings'),
        ];
        $items = [];

        foreach ($names as $type => $name) {
            foreach ($types as $present) {
                if ($present->value === $type) {
                    $items[] = $name;
                    break;
                }
            }
        }

        if ($items === []) {
            return null;
        }

        $last = array_pop($items);
        $list = $items === [] ? $last : __(':items and :last', ['items' => implode(', ', $items), 'last' => $last]);

        return Str::ucfirst($list);
    }

    /**
     * The line when it applies, null (left out of the card) when it does not.
     */
    private static function onlyIf(bool $applies, string $line): ?string
    {
        return $applies ? $line : null;
    }

    /**
     * @param  list<string|null>  $body  the lines; a null (a line that does not apply) is left out
     * @return TourStep
     */
    private static function step(string $id, string $title, array $body): array
    {
        $lines = [];

        foreach ($body as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return ['id' => $id, 'title' => $title, 'body' => $lines];
    }
}
