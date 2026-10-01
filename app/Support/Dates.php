<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Short, localised date labels used across the screens.
 */
final class Dates
{
    /**
     * "okt 3" / "Oct 3" — Hungarian abbreviations lose their trailing dot.
     */
    public static function short(CarbonInterface $date): string
    {
        $date = $date->locale(app()->getLocale());

        return app()->getLocale() === 'hu'
            ? rtrim($date->isoFormat('MMM'), '.').' '.$date->day
            : $date->isoFormat('MMM D');
    }

    /**
     * "okt 3 – nov 2".
     */
    public static function range(CarbonInterface $from, CarbonInterface $to): string
    {
        return self::short($from)."\u{2009}–\u{2009}".self::short($to);
    }

    /**
     * Day heading in lists: "Ma · okt 16., péntek", "Tegnap · …", "okt 14., szerda".
     */
    public static function day(CarbonInterface $date, CarbonInterface $today): string
    {
        $date = $date->locale(app()->getLocale());
        $label = app()->getLocale() === 'hu'
            ? rtrim($date->isoFormat('MMM'), '.').' '.$date->day.'., '.$date->isoFormat('dddd')
            : $date->isoFormat('MMM D, dddd');

        if ($date->isSameDay($today)) {
            return __('Today').' · '.$label;
        }

        if ($date->isSameDay($today->subDay())) {
            return __('Yesterday').' · '.$label;
        }

        return $label;
    }

    /**
     * Capitalised month name of the period: "Október".
     */
    public static function monthName(CarbonInterface $date): string
    {
        return mb_convert_case($date->locale(app()->getLocale())->isoFormat('MMMM'), MB_CASE_TITLE);
    }

    /**
     * Month for use inside a sentence. Hungarian adds the article and, as an adjective,
     * the -i suffix: "az októberi", "a szeptemberi"; English: "October".
     */
    public static function monthInSentence(CarbonInterface $date, bool $adjective = false): string
    {
        $name = $date->locale(app()->getLocale())->isoFormat('MMMM');

        if (app()->getLocale() !== 'hu') {
            return $name;
        }

        $name = mb_strtolower($name).($adjective ? 'i' : '');

        return (preg_match('/^[aáeéiíoóöőuúüű]/u', $name) === 1 ? 'az ' : 'a ').$name;
    }
}
