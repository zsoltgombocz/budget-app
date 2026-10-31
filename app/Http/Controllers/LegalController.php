<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The public legal pages (ÁSZF, privacy notice, imprint, cookies), written in Markdown under
 * resources/legal with {{placeholders}} for the operator's details.
 */
class LegalController extends Controller
{
    /** URL slug => file name. */
    public const array PAGES = ['aszf' => 'aszf', 'adatvedelem' => 'adatvedelem', 'impresszum' => 'impresszum', 'sutik' => 'sutik'];

    public function __invoke(Request $request, string $page): View
    {
        $path = resource_path('legal/'.self::PAGES[$page].'.md');
        abort_unless(is_file($path), 404);

        $markdown = strtr((string) file_get_contents($path), self::placeholders());
        preg_match('/^#\s+(.+)$/m', $markdown, $title);
        // The title goes into the page header, so the document starts with its date line.
        $body = (string) preg_replace('/^#\s+.+$/m', '', $markdown, 1);
        $html = Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        // A list of nothing but links (the imprint's documents) is shown as tappable rows.
        $html = (string) preg_replace('#<ul>(?=(?:\s*<li><a [^>]*>[^<]*</a></li>)+\s*</ul>)#', '<ul class="legal-links">', $html);

        return view('legal', ['html' => $html, 'title' => $title[1] ?? config('app.name'), 'page' => $page, 'back' => $this->backUrl($request)]);
    }

    /**
     * Where the back button leads: the page the reader came from (kept while they switch between
     * the documents), else the app's settings or the landing page. The installed app opens these
     * pages in its own window, where there is no browser back button.
     */
    private function backUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $hosts = array_filter([$request->getHost(), config('budget.landing_domain')]);
        $fromLegal = in_array(trim((string) parse_url($referer, PHP_URL_PATH), '/'), array_keys(self::PAGES), true);

        if ($referer !== '' && in_array(parse_url($referer, PHP_URL_HOST), $hosts, true) && ! $fromLegal) {
            $request->session()->put('legal_back', $referer);
        }

        $fallback = $request->user() !== null ? route('settings') : url('/');
        $back = $request->session()->get('legal_back');

        return is_string($back) ? $back : $fallback;
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
