<?php

namespace App\Http\Controllers;

use App\Actions\Budget\MarkNoSpendDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * "I didn't spend today" from the notification action button. Called by the service
 * worker without opening the app, so it is authorised by the signed URL, not a session.
 */
class NoSpendFromNotificationController extends Controller
{
    public function __invoke(User $user, string $date, MarkNoSpendDay $markNoSpendDay): Response
    {
        $markNoSpendDay->handle($user, CarbonImmutable::createFromFormat('Y-m-d', $date) ?: null);

        return response()->noContent();
    }
}
