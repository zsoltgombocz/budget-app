<?php

namespace App\Services\Data;

use App\Enums\CalcMode;
use App\Enums\LineType;

/**
 * A plan line detached from Eloquent, so calculations work the same on the live plan
 * and on a frozen period snapshot.
 */
final readonly class PlanLine
{
    public function __construct(
        public ?int $lineId,
        public int $categoryId,
        public string $categoryName,
        public LineType $type,
        public int $amount,
        public ?int $amountAvg = null,
        public ?int $amountMax = null,
        public CalcMode $calcMode = CalcMode::Fixed,
        public ?int $dueDay = null,
        public ?int $pocketId = null,
        public ?int $loanId = null,
    ) {}

    /**
     * The amount the plan counts with, according to the calculation mode.
     */
    public function planned(): int
    {
        return match ($this->calcMode) {
            CalcMode::Avg => $this->amountAvg ?? $this->amount,
            CalcMode::Max => $this->amountMax ?? $this->amount,
            CalcMode::Fixed => $this->amount,
        };
    }

    /**
     * @return array{line_id: int|null, category_id: int, category_name: string, type: string, amount: int, amount_avg: int|null, amount_max: int|null, calc_mode: string, due_day: int|null, pocket_id: int|null, loan_id: int|null, planned: int}
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'amount_avg' => $this->amountAvg,
            'amount_max' => $this->amountMax,
            'calc_mode' => $this->calcMode->value,
            'due_day' => $this->dueDay,
            'pocket_id' => $this->pocketId,
            'loan_id' => $this->loanId,
            'planned' => $this->planned(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            lineId: self::intOrNull($data['line_id'] ?? null),
            categoryId: (int) self::intOrNull($data['category_id'] ?? 0),
            categoryName: is_string($data['category_name'] ?? null) ? $data['category_name'] : '',
            type: LineType::from(is_string($data['type'] ?? null) ? $data['type'] : LineType::Fixed->value),
            amount: (int) self::intOrNull($data['amount'] ?? 0),
            amountAvg: self::intOrNull($data['amount_avg'] ?? null),
            amountMax: self::intOrNull($data['amount_max'] ?? null),
            calcMode: CalcMode::from(is_string($data['calc_mode'] ?? null) ? $data['calc_mode'] : CalcMode::Fixed->value),
            dueDay: self::intOrNull($data['due_day'] ?? null),
            pocketId: self::intOrNull($data['pocket_id'] ?? null),
            loanId: self::intOrNull($data['loan_id'] ?? null),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }
}
