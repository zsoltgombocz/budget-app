<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The steps of the "How it works" spotlight tour (components/tour), one list per screen.
 *
 * Every step points at the elements marked data-tour="{id}" on its page; the tour skips a
 * step whose element is not on screen (no due items, no pockets…). The texts use the user's
 * own numbers where that helps, and they follow the app's real formulas (docs/PRODUCT.md):
 * keep them in step with the explainer sheets (components/explainer) and the services.
 *
 * @phpstan-type TourStep array{id: string, title: string, body: list<string>}
 */
final class Tour
{
    /**
     * @return list<TourStep>
     */
    public static function today(int $leftover, int $unspentBudget, int $ifSpent, int $daily, int $remainingDays, int $variableSpent, int $variablePlanned): array
    {
        return [
            self::step('leftover', __('The main number: your leftover'), [
                __('This much would be left if the month ended today: :amount.', ['amount' => money($leftover)]),
                __('Your fixed items, transfers, loan installments and monthly savings are already taken off in full; of the variable spending only what you have spent so far.'),
                $unspentBudget > 0
                    ? __('So your budget not spent yet (:amount) is still in it: early in the month the number is high and it goes down as you spend.', ['amount' => money($unspentBudget)])
                    : __('It goes down with every spending you record.'),
            ]),
            self::step('if-spent', __('If you spend every budget'), [
                __('The smaller number: what is left at the end of the month if you spend every variable budget in full: :amount.', ['amount' => money($ifSpent)]),
                __('As long as you stay within your budgets it does not change. Whatever you spend over a budget lowers it.'),
            ]),
            self::step('daily', __('You can still spend today'), [
                __(':amount is yours for variable spending today.', ['amount' => money($daily)]),
                __('The app splits what is left of your variable budgets over the :count days still to go, today included.', ['count' => $remainingDays]),
                __('Fixed items are not in it. It is worked out again after every spending, and it shows 0 once the budgets are used up.'),
            ]),
            self::step('record', __('Recording a spending'), [
                __('Spent something? Tap here, pick the category, type the amount and save: it takes a few seconds.'),
                __('The green + at the bottom right does the same on every main screen.'),
                __('Fixed items need no recording, they are already in the plan.'),
            ]),
            self::step('no-spend', __("I didn't spend today"), [
                __('Did you spend nothing today? Tell the app with one tap.'),
                __('Then there is no reminder in the evening, and the day shows up in the Month list. You can undo it any time.'),
            ]),
            self::step('due', __('Fixed items due'), [
                __('The fixed items due today, or already past their day, are gathered here.'),
                __('Open it and mark what you have paid as Done. The tick is only a note to yourself: it changes none of the numbers.'),
            ]),
            self::step('categories', __('Variable spending'), [
                __('How much of each budget you have spent. In total: :spent of :planned.', ['spent' => money($variableSpent), 'planned' => money($variablePlanned)]),
                __('The bar is green, turns amber above 80% and red over the budget, with the amount you went over.'),
                __('Tap a category to see its spending in the Month list.'),
            ]),
            self::step('fixed', __('Fixed items'), [
                __('These are in the plan already, you never record them.'),
                __('Tap one once you have paid it: the tick is only a reminder.'),
                __('Does an item not come up this month? Use the three dots to mark it “Skipped this month”: then it counts as 0.'),
            ]),
            self::step('tabs', __('The other screens'), [
                __('You switch screens down here.'),
                __('Plan: your income and items. Pockets: the money you put aside. Month: the list of spending and the closing.'),
                __('Shall I show you these as well?'),
            ]),
        ];
    }

    /**
     * @return list<TourStep>
     */
    public static function plan(int $income, int $expenses, int $left): array
    {
        return [
            self::step('plan-summary', __('Your monthly plan'), [
                __('Every month starts with this plan.'),
                __('Income :income, expenses :expenses, and what is left: :left.', ['income' => money($income), 'expenses' => money($expenses), 'left' => money($left)]),
                __('This band stays on top while you scroll.'),
            ]),
            self::step('plan-income', __('Income'), [
                __('Your monthly net pay. Tap it when it changes.'),
                __('A change to the plan applies to the current month as well, from its first day.'),
            ]),
            self::step('plan-fixed', __('Fixed items'), [
                __('The same amount every month: rent, utilities, subscriptions. Transfers and loan installments work the same way.'),
                __('They count in the leftover with their full amount, so you never record them.'),
                __('Give one a due day and the app tells you when it is due.'),
            ]),
            self::step('plan-variable', __('Variable spending'), [
                __('The monthly budgets for everyday spending: groceries, coffee, fuel.'),
                __('This is the only spending you record. Today works out from these how much you can still spend.'),
            ]),
            self::step('plan-sinking', __('Sinking funds'), [
                __('Money put aside every month for something later.'),
                __('Linked to a pocket, it goes into the pocket when you close the month.'),
            ]),
            self::step('plan-edit', __('New items, order'), [
                __('Add a new item or change their order with the pencil.'),
                __('Tap an item to change its amount or remove it.'),
            ]),
            self::step('plan-month-end', __('Reserve and leftover'), [
                __('Decide here, in advance, where the money left at the end of the month goes.'),
                __('Part of it can go into your reserve pocket, the rest to the target you choose. At the closing you can still change it.'),
                __('The app only keeps track: you move the money in your bank yourself.'),
            ]),
        ];
    }

    /**
     * @return list<TourStep>
     */
    public static function pockets(int $saved): array
    {
        return [
            self::step('pockets-header', __('Pockets'), [
                __('Pockets keep track of money put aside for a goal: a reserve, a holiday, a loan prepayment.'),
                __('Saved in total: :amount. The money itself stays in your bank, the app counts it.', ['amount' => money($saved)]),
                __('A new pocket comes from Add.'),
            ]),
            self::step('pocket-card', __('One pocket'), [
                __('Its balance and, if it has a goal, how far along you are.'),
                __('Tap it for its settings and its latest movements.'),
            ]),
            self::step('pocket-deposit', __('Deposit'), [
                __('You put money from this month into the pocket.'),
                __('Its balance goes up and this month’s leftover goes down by the same amount. Your budgets do not change.'),
            ]),
            self::step('pocket-withdraw', __('Withdraw to the monthly budget'), [
                __('Money taken out of the pocket counts as extra income this month: your leftover and today’s amount go up.'),
                __('You can also pay a spending straight from the pocket: then it does not use up your budget.'),
            ]),
            self::step('loans', __('Loans'), [
                __('What you owe: the principal, the installment, the months left.'),
                __('Add a loan and its installment goes into the plan by itself.'),
            ]),
        ];
    }

    /**
     * @return list<TourStep>
     */
    public static function month(int $leftover, ?CarbonInterface $closableFrom): array
    {
        return [
            self::step('month-now', __('If the month ended today'), [
                __('The same number as the big one on Today: :amount.', ['amount' => money($leftover)]),
                __('The badge next to it tells you whether you are doing better than the plan or spent more.'),
            ]),
            self::step('month-close', __('Closing the month'), [
                $closableFrom instanceof CarbonInterface
                    ? __('You can close the month in its last 7 days: this time :from.', ['from' => Dates::from($closableFrom)])
                    : __('You can close the month in its last 7 days: now is the time.'),
                __('You see the plan next to what really happened, and decide where the leftover goes: the reserve, a pocket, or it stays on the account.'),
                __('If the month ends in the red, you decide whether the reserve covers it. Nothing moves without you.'),
            ]),
            self::step('month-list', __('The month’s spending'), [
                __('Every spending you recorded, day by day. Search, or filter by category.'),
                __('Tap a spending to correct or delete it.'),
            ]),
        ];
    }

    /**
     * @param  list<string>  $body
     * @return TourStep
     */
    private static function step(string $id, string $title, array $body): array
    {
        return ['id' => $id, 'title' => $title, 'body' => $body];
    }
}
