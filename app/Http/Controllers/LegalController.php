<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The public legal pages (ÁSZF, privacy notice, imprint, cookies), written in Markdown under
 * resources/legal with {{placeholders}} for the operator's details.
 */
class LegalController extends Controller
{
    /** URL slug => file name. */
    public const array PAGES = ['aszf' => 'aszf', 'adatvedelem' => 'adatvedelem', 'impresszum' => 'impresszum', 'sutik' => 'sutik'];

    public function __invoke(string $page): View
    {
        $path = resource_path('legal/'.self::PAGES[$page].'.md');
        abort_unless(is_file($path), 404);

        $markdown = strtr((string) file_get_contents($path), self::placeholders());
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        preg_match('/^#\s+(.+)$/m', $markdown, $title);

        return view('legal', ['html' => $html, 'title' => $title[1] ?? config('app.name'), 'page' => $page]);
    }

    /**
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        $missing = '[kitöltés alatt]';
        $operator = (array) config('budget.operator');
        $value = fn (string $key): string => is_string($operator[$key] ?? null) && $operator[$key] !== '' ? $operator[$key] : $missing;

        return [
            '{{operator_name}}' => $value('name'),
            '{{operator_address}}' => $value('address'),
            '{{operator_registration}}' => $value('registration'),
            '{{operator_tax_number}}' => $value('tax_number'),
            '{{operator_email}}' => $value('email'),
            '{{operator_phone}}' => $value('phone'),
            '{{conciliation_body}}' => $value('conciliation_body'),
            '{{vat_note}}' => $value('vat_note'),
            '{{hosting_provider}}' => 'Contabo GmbH, Aschauer Straße 32a, 81549 München, Németország',
            '{{effective_date}}' => config()->string('budget.legal.effective_date'),
            '{{version}}' => config()->string('budget.legal.version'),
        ];
    }
}
