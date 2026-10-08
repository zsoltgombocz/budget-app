<?php

namespace App\Actions\Capture;

use App\Enums\LineType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantCategorizer;
use Illuminate\Validation\ValidationException;

/**
 * Moves a spending to another variable category. For a payment with a merchant the choice
 * is remembered, so that merchant's next payment lands there by itself.
 */
final readonly class RecategorizeTransaction
{
    public function __construct(private MerchantCategorizer $categorizer) {}

    public function handle(User $user, int $transactionId, int $categoryId): Transaction
    {
        $transaction = $user->transactions()->whereKey($transactionId)->whereRelation('period', 'status', 'open')->firstOrFail();
        $category = $user->categories()->where('type', LineType::Variable)->find($categoryId);

        if (! $category instanceof Category) {
            throw ValidationException::withMessages(['category' => __('Choose a category.')]);
        }

        $transaction->update(['category_id' => $category->id]);

        if (filled($transaction->merchant)) {
            $this->categorizer->learn($user, (string) $transaction->merchant, $category->id);
        }

        return $transaction;
    }
}
