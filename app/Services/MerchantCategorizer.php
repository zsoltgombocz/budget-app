<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Picks the category of an automatically captured payment: the one the user last moved
 * that merchant's payments to, otherwise the default capture category.
 */
final readonly class MerchantCategorizer
{
    /** Card processors that prefix the real merchant, e.g. "SUMUP *KAVEZO". */
    private const string PROCESSOR_PREFIX = '/^(?:sumup|sq|zettle|izettle|paypal|pp|sp|google|apple\.com\/bill|wolt)\s*\*\s*/';

    /** @var list<string> */
    private const array NOISE = ['kft', 'zrt', 'bt', 'nyrt', 'kkt', 'ltd', 'gmbh', 'inc', 'llc', 'sa', 'srl', 'ab', 'as', 'budapest', 'bp', 'hu', 'hun', 'hungary', 'magyarorszag'];

    /**
     * A stable key for a merchant name, so "TESCO ARUHAZ BUDAPEST 41012" and "Tesco Áruház" match.
     */
    public function key(string $merchant): string
    {
        $value = Str::lower(Str::ascii($merchant));
        $value = (string) preg_replace(self::PROCESSOR_PREFIX, '', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        $words = array_values(array_filter(
            explode(' ', $value),
            fn (string $word): bool => $word !== '' && preg_match('/\d/', $word) !== 1 && ! in_array($word, self::NOISE, true),
        ));

        return Str::limit(implode(' ', array_slice($words, 0, 3)), 100, '');
    }

    /**
     * The remembered category for this merchant (exact name first, then same first word),
     * or the default capture category.
     */
    public function categoryFor(User $user, ?string $merchant): ?Category
    {
        $key = $merchant === null ? '' : $this->key($merchant);

        if ($key !== '') {
            $firstWord = explode(' ', $key)[0];

            $rules = $user->merchantRules()
                ->with('category')
                ->where(fn ($query) => $query->where('merchant_key', $key)
                    ->orWhere('merchant_key', $firstWord)
                    ->orWhere('merchant_key', 'like', $firstWord.' %'))
                ->latest('updated_at')
                ->get();

            $rule = $rules->firstWhere('merchant_key', $key) ?? $rules->first();

            if ($rule instanceof MerchantRule && $rule->category instanceof Category && $rule->category->type === LineType::Variable) {
                return $rule->category;
            }
        }

        return $this->defaultCategory($user);
    }

    /**
     * The category chosen in the settings, otherwise the first quick entry variable category
     * (the same one the entry sheet starts on), otherwise any variable category.
     */
    public function defaultCategory(User $user): ?Category
    {
        $variable = fn () => $user->categories()->where('type', LineType::Variable);
        $chosen = $user->settings()->capture_category_id;

        if ($chosen !== null && ($category = $variable()->find($chosen)) instanceof Category) {
            return $category;
        }

        return $variable()->orderByDesc('is_quick_entry')->orderBy('sort')->orderBy('name')->first();
    }

    /**
     * Remember that this merchant's payments belong to the category.
     */
    public function learn(User $user, string $merchant, int $categoryId): void
    {
        $key = $this->key($merchant);

        if ($key === '') {
            return;
        }

        $user->merchantRules()->updateOrCreate(['merchant_key' => $key], ['category_id' => $categoryId])->touch();
    }
}
