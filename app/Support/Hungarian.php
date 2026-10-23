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

    /**
     * A number with a case suffix in the right vowel harmony, as it is read out:
     * hármat → "3-ból", ötöt → "5-ből"; $back and $front are the two forms ("ból"/"ből", "nak"/"nek").
     * The last non-zero group decides: 10 (tíz) front, 20 (húsz) back, 100 (száz) back, 1000 (ezer) front.
     */
    public static function withSuffix(int $number, string $back, string $front): string
    {
        return $number.'-'.(self::readsBack($number) ? $back : $front);
    }

    private static function readsBack(int $number): bool
    {
        $number = abs($number);

        if ($number === 0) {
            return false;
        }

        if ($number % 1000 === 0) {
            return false;
        }

        if ($number % 100 === 0) {
            return true;
        }

        $ones = $number % 10;

        if ($ones !== 0) {
            return in_array($ones, [3, 6, 8], true);
        }

        return in_array(intdiv($number % 100, 10), [2, 3, 6, 8], true);
    }

    /**
     * A day of the month with the "on" suffix as it is read: 1-jén, 2-án, 4-én, 10-én, 20-án, 31-én.
     */
    public static function onDay(int $day): string
    {
        if ($day === 1) {
            return '1-jén';
        }

        $ones = $day % 10;
        $back = $ones === 0 ? in_array($day, [20, 30], true) : in_array($ones, [2, 3, 6, 8], true);

        return $day.'-'.($back ? 'án' : 'én');
    }
}
