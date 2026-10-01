<?php

namespace App\Support;

/**
 * The app version and its user-facing changelog (resources/changelog.php).
 */
final class Changelog
{
    /**
     * @return list<array{version: string, date: string, changes: list<array<string, string>>}>
     */
    public static function all(): array
    {
        /** @var list<array{version: string, date: string, changes: list<array<string, string>>}> $entries */
        $entries = require resource_path('changelog.php');

        return $entries;
    }

    public static function version(): string
    {
        return self::all()[0]['version'];
    }

    /**
     * Changes of a version in the given (or current) language, falling back to English.
     *
     * @param  array{changes: list<array<string, string>>}  $entry
     * @return list<string>
     */
    public static function changes(array $entry, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return array_map(fn (array $change): string => $change[$locale] ?? $change['en'] ?? (string) current($change), $entry['changes']);
    }
}
