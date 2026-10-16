<?php

namespace App\Support;

/**
 * Small Hungarian grammar helpers for sentences built around user data.
 */
final class Hungarian
{
    /**
     * The definite article before a word: "az" before a vowel (and 5, read "öt…"),
     * "a" otherwise: "az anna@example.com", "a peter@example.com".
     */
    public static function withArticle(string $word): string
    {
        return (preg_match('/^[aáeéiíoóöőuúüű5]/iu', $word) === 1 ? 'az ' : 'a ').$word;
    }
}
