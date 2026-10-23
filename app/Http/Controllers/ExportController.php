<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Adataim letöltése": every spending, extra income and month of the user as one CSV file.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $currency = $user->settings()->currency;

        return response()->streamDownload(function () use ($user, $currency): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date'), __('Kind'), __('Category'), __('Amount'), __('Currency'), __('Note')], ';', escape: '');

            foreach ($user->transactions()->with('category')->orderBy('occurred_on')->orderBy('id')->lazy() as $transaction) {
                fputcsv($out, [$transaction->occurred_on->toDateString(), __('Spending entry'), $transaction->category->name ?? '', Money::toInput($transaction->amount, $currency), $currency->value, $transaction->note ?? ''], ';', escape: '');
            }

            foreach ($user->extraIncomes()->orderBy('occurred_on')->lazy() as $income) {
                fputcsv($out, [$income->occurred_on->toDateString(), __('Got money'), '', Money::toInput($income->amount, $currency), $currency->value, $income->note ?? ''], ';', escape: '');
            }

            foreach ($user->periods()->with('close')->orderBy('starts_on')->lazy() as $period) {
                fputcsv($out, [$period->starts_on->toDateString(), __('Month'), '', Money::toInput($period->income(), $currency), $currency->value, $period->close !== null ? __('Left over: :amount', ['amount' => Money::toInput($period->close->leftover, $currency)]) : __('open')], ';', escape: '');
            }

            fclose($out);
        }, 'moneysight-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
