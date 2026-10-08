<?php

namespace App\Actions\Capture;

use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\PaymentKind;
use App\Models\PaymentCapture;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CaptureDeduplicator;
use App\Services\Data\CaptureResult;
use App\Services\Data\ParsedPayment;
use App\Services\PaymentTextParser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles one payment sent in by the phone (iOS Shortcut or Android notification).
 *
 * A plain card payment in the base currency becomes a spending right away. Anything the
 * app cannot decide on its own (foreign currency, a refund, a possible duplicate of a manual
 * entry, a closed month) waits for the user and does not count until they decide.
 * Income, transfers, cash withdrawals and declined payments are skipped.
 */
final readonly class IngestPayment
{
    /** A payment time further off than this is not trusted; the arrival time is used instead. */
    private const int MAX_AGE_DAYS = 30;

    public function __construct(
        private PaymentTextParser $parser,
        private CaptureDeduplicator $deduplicator,
        private PeriodService $periods,
        private RecordCapture $recorder,
    ) {}

    /**
     * @param  array{amount?: string|null, currency?: string|null, merchant?: string|null, text?: string|null, title?: string|null, platform?: string|null, id?: string|null, occurred_at?: string|null, test?: bool|null}  $input
     */
    public function handle(User $user, array $input): CaptureResult
    {
        $externalId = $this->filled($input['id'] ?? null);

        if ($externalId !== null && ($existing = $user->paymentCaptures()->where('external_id', $externalId)->first()) instanceof PaymentCapture) {
            return new CaptureResult($existing, repeated: true);
        }

        try {
            return DB::transaction(fn (): CaptureResult => $this->ingest($user, $input, $externalId));
        } catch (UniqueConstraintViolationException) {
            // The same request raced in twice; the first one won.
            return new CaptureResult($user->paymentCaptures()->where('external_id', $externalId)->firstOrFail(), repeated: true);
        }
    }

    /**
     * @param  array{amount?: string|null, currency?: string|null, merchant?: string|null, text?: string|null, title?: string|null, platform?: string|null, id?: string|null, occurred_at?: string|null, test?: bool|null}  $input
     */
    private function ingest(User $user, array $input, ?string $externalId): CaptureResult
    {
        $settings = $user->settings();
        $at = $this->occurredAt($input['occurred_at'] ?? null);
        $amountField = $this->filled($input['amount'] ?? null);
        $text = $this->filled($input['text'] ?? null);
        $title = $this->filled($input['title'] ?? null);

        $base = [
            'external_id' => $externalId,
            'platform' => $this->platform($input['platform'] ?? null),
            'occurred_at' => $at,
        ];

        // A manual run of the Shortcut (no Wallet data) or the explicit test switch.
        if (($input['test'] ?? false) === true || ($amountField === null && $text === null)) {
            return new CaptureResult($user->paymentCaptures()->create([
                ...$base,
                'status' => CaptureStatus::Test,
                'kind' => PaymentKind::Purchase,
                'merchant' => $this->filled($input['merchant'] ?? null),
            ]));
        }

        $payment = $this->parser->parse(
            $settings->currency,
            amountField: $amountField,
            merchantField: $this->filled($input['merchant'] ?? null),
            text: $text,
            title: $title,
            currencyField: $this->filled($input['currency'] ?? null),
        );

        $repeat = $this->deduplicator->findRepeat($user, $payment, $at);

        if ($repeat instanceof PaymentCapture) {
            return new CaptureResult($repeat, repeated: true);
        }

        $capture = $user->paymentCaptures()->create([
            ...$base,
            'status' => CaptureStatus::Ignored,
            'kind' => $payment->kind,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'base_amount' => $payment->baseAmount,
            'merchant' => $payment->merchant,
            // Only for debugging the parser; pruned after a few days (captures:prune).
            'raw_text' => $this->rawText($amountField, $text, $title),
        ]);

        return new CaptureResult($this->decide($user, $capture, $payment, $at));
    }

    private function decide(User $user, PaymentCapture $capture, ParsedPayment $payment, CarbonImmutable $at): PaymentCapture
    {
        if (! $payment->hasAmount()) {
            return $this->set($capture, CaptureStatus::Ignored, CaptureReason::NoAmount);
        }

        if ($payment->kind->isIgnored()) {
            return $this->set($capture, CaptureStatus::Ignored, CaptureReason::NotSpending);
        }

        $date = CarbonImmutable::parse($at->setTimezone($user->settings()->timezone)->toDateString());

        if ($payment->kind === PaymentKind::Refund) {
            $refunded = $payment->baseAmount === null ? null : $this->deduplicator->findRefunded($user, $payment->baseAmount, $payment->merchant, $date);
            $capture->related_transaction_id = $refunded?->id;

            return $this->set($capture, CaptureStatus::Pending, CaptureReason::Refund);
        }

        if ($payment->baseAmount === null) {
            return $this->set($capture, CaptureStatus::Pending, CaptureReason::ForeignCurrency);
        }

        $period = $this->openPeriodFor($user, $date);

        if (! $period instanceof Period) {
            return $this->set($capture, CaptureStatus::Pending, CaptureReason::PeriodClosed);
        }

        $twin = $this->deduplicator->findManualTwin($user, $payment->baseAmount, $date);

        if ($twin instanceof Transaction) {
            $capture->related_transaction_id = $twin->id;

            return $this->set($capture, CaptureStatus::Pending, CaptureReason::PossibleDuplicate);
        }

        return $this->recorder->handle($user, $capture, $payment->baseAmount, $date);
    }

    /**
     * The open period of the day. Days before the current period only count if their period
     * still exists and is open; no past period is opened just for a late notification.
     */
    private function openPeriodFor(User $user, CarbonImmutable $date): ?Period
    {
        $current = $this->periods->current($user);

        $period = $date->greaterThanOrEqualTo($current->starts_on)
            ? $current
            : $user->periods()
                ->whereDate('starts_on', '<=', $date->toDateString())
                ->whereDate('ends_on', '>=', $date->toDateString())
                ->first();

        return $period?->isOpen() ? $period : null;
    }

    private function set(PaymentCapture $capture, CaptureStatus $status, CaptureReason $reason): PaymentCapture
    {
        $capture->status = $status;
        $capture->reason = $reason;
        $capture->save();

        return $capture;
    }

    private function occurredAt(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($value === null || $value === '') {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $now;
        }

        return $at->greaterThan($now->addMinutes(5)) || $at->lessThan($now->subDays(self::MAX_AGE_DAYS)) ? $now : $at;
    }

    private function rawText(?string $amount, ?string $text, ?string $title): string
    {
        return mb_substr(implode("\n", array_filter([$title, $text, $text === null ? $amount : null])), 0, 1000);
    }

    private function platform(?string $value): ?string
    {
        $value = $this->filled($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return in_array($value, ['ios', 'android'], true) ? $value : 'other';
    }

    private function filled(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
