<?php

it('has a Hungarian translation for every UI string', function (): void {
    $translations = json_decode((string) file_get_contents(lang_path('hu.json')), true);

    $files = array_merge(
        glob(app_path('{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE) ?: [],
        glob(resource_path('views/{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE) ?: [],
        glob(database_path('seeders/*.php')) ?: [],
    );

    expect(count($files))->toBeGreaterThan(30);

    $missing = [];

    foreach ($files as $file) {
        preg_match_all('/(?:__\(|#\[Title\()\s*([\'"])((?:\\\\.|(?!\1).)*)\1/', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1] === "'" ? str_replace("\\'", "'", $match[2]) : $match[2];

            if (! array_key_exists($key, $translations)) {
                $missing[] = $key.' ('.basename($file).')';
            }
        }
    }

    expect($missing)->toBe([]);
});
