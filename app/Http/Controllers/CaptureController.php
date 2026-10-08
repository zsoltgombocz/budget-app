<?php

namespace App\Http\Controllers;

use App\Actions\Capture\IngestPayment;
use App\Enums\CaptureStatus;
use App\Models\User;
use App\Services\Data\CaptureResult;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives a card payment from the phone automation (iOS Shortcut, Android MacroDroid).
 * The answer's `message` is short Hungarian (or English) text the automation may show.
 */
class CaptureController extends Controller
{
    public function __invoke(Request $request, IngestPayment $ingest): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->settings()->isOnboarded()) {
            return response()->json(['status' => 'not_ready', 'message' => __('Finish setting up MoneySight first.')], 409);
        }

        // Automations may send numbers as JSON numbers; the parser works on text.
        foreach (['amount', 'merchant', 'text', 'title', 'id'] as $field) {
            if (is_int($request->input($field)) || is_float($request->input($field))) {
                $request->merge([$field => (string) $request->input($field)]);
            }
        }

        /** @var array{amount?: string|null, currency?: string|null, merchant?: string|null, text?: string|null, title?: string|null, platform?: string|null, id?: string|null, occurred_at?: string|null, test?: bool|null} $input */
        $input = $request->validate([
            'amount' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:8'],
            'merchant' => ['nullable', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:160'],
            'app' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:20'],
            'id' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'string', 'max:40'],
        ]);

        $input['test'] = $request->boolean('test');

        $result = $ingest->handle($user, $input);

        return response()->json([
            'status' => $result->repeated ? 'duplicate' : $result->capture->status->value,
            'reason' => $result->capture->reason?->value,
            'message' => $this->message($user, $result),
        ], $result->capture->status === CaptureStatus::Recorded && ! $result->repeated ? 201 : 200);
    }

    private function message(User $user, CaptureResult $result): string
    {
        $capture = $result->capture->loadMissing('transaction.category');

        if ($result->repeated) {
            return __('Already received, not recorded again.');
        }

        return match ($capture->status) {
            CaptureStatus::Recorded => __('Recorded: :merchant :amount → :category', [
                'merchant' => $capture->merchant ?? __('Card payment'),
                'amount' => Money::of((int) $capture->base_amount, $user->settings()->currency)->format(),
                'category' => $capture->transaction?->category->name ?? '',
            ]),
            CaptureStatus::Pending => __('Waiting for you in MoneySight: :reason', ['reason' => $capture->reason?->label() ?? '']),
            CaptureStatus::Ignored => __('Skipped: :reason', ['reason' => $capture->reason?->label() ?? '']),
            CaptureStatus::Test => __('The connection works. MoneySight is ready.'),
            CaptureStatus::Dismissed => '',
        };
    }
}
