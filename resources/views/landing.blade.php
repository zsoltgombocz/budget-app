@php
    $prices = config('budget.prices.plus');
    $huf = \App\Enums\Currency::HUF;
    $number = fn (int $amount): string => number_format($amount, 0, ',', ' ');
    // The demo month: the same sample numbers as the screenshots below.
    $left = 48_861;
    $budgets = [
        ['name' => 'Élelmiszer', 'left' => 91_100, 'words' => ['kávé', 'kifli', 'pék', 'bolt', 'tesco', 'spar', 'lidl', 'kaja']],
        ['name' => 'Közlekedés', 'left' => 28_300, 'words' => ['tank', 'benzin', 'bkv', 'jegy', 'parkol', 'taxi', 'uber']],
        ['name' => 'Szórakozás', 'left' => 12_400, 'words' => ['mozi', 'koncert', 'sör', 'pizza', 'étterem', 'netflix']],
    ];
    $stages = [
        ['name' => 'Terv', 'clause' => 'Megtervezed a hónapot', 'text' => 'Fizetés, fix kiadások, keretek. Egyszer kell beírni, minden hónapban magától másolódik.', 'image' => 'terv.jpg', 'alt' => 'A Terv képernyő: fizetés, fix tételek és keretek, alul a hó végi maradék.'],
        ['name' => 'Rögzítés', 'clause' => 'Felírod, mire költesz', 'text' => 'Összeg, kategória, mentés. Öt másodperc, a saját számbillentyűzetével.', 'image' => 'rogzites.jpg', 'alt' => 'A rögzítés: 4 590 Ft az Élelmiszer keretből, alatta a számbillentyűzet.'],
        ['name' => 'Ma', 'clause' => 'Látod, mennyi marad', 'text' => 'Egy szám: ennyi marad a hónap végére. Alatta a görbe, hogyan jutsz el odáig.', 'image' => 'ma.jpg', 'alt' => 'A Ma képernyő: Hó végére marad 48 861 Ft, a hónap görbéje és az esedékes tételek.'],
        ['name' => 'Zárás', 'clause' => 'Lezárod, és szétosztod', 'text' => 'Ami megmaradt, mehet tartalékba, előtörlesztésre, vagy marad a számlán.', 'image' => 'honap.jpg', 'alt' => 'A Hónap képernyő: a hónap bontása és a napi költések.'],
    ];
    $bands = [
        ['sentence' => 'Ma nem költöttem.', 'result' => 'Aznap este nem szól az emlékeztető, és megy tovább a sorozatod.', 'color' => '#17402a', 'on' => true],
        ['sentence' => 'Most kihagyom a rezsit.', 'result' => '+35 000 Ft marad a hónap végén, a terved közben nem változik.', 'color' => '#2b2213', 'on' => false],
        ['sentence' => 'Gyűjtök előtörlesztésre.', 'result' => 'Havi 50 000 Ft-tal 2028. december helyett hamarabb leszel hitelmentes.', 'color' => '#15301f', 'on' => true],
        ['sentence' => 'Szóljon, ha esedékes a lakbér.', 'result' => 'Az esedékesség napján reggel 8-kor jön az értesítés.', 'color' => '#1f2a3a', 'on' => false],
    ];
    $faq = [
        ['Kapcsolódik a bankomhoz?', 'Nem. Nem kérünk banki belépést, és az app soha nem mozgat pénzt. Te írod fel, mire költesz; ettől lesz pontos.'],
        ['Mikor indul?', 'Ha elég sokan jelentkeznek. Aki feliratkozik, annak elsőként írunk, és az indulás után 14 napig ingyen próbálhatja a Pluszt.'],
        ['Kell hozzá alkalmazásbolt?', 'Nem. Böngészőben fut, és egy koppintással a kezdőképernyőre teheted iPhone-on és Androidon is, utána úgy működik, mint egy app.'],
        ['Mi van az ingyenes Alapban?', 'Fizetés, fix kiadások, keretek, rögzítés, a hó végi szám és a hónapzárás. A Plusz ehhez hozza a perselyeket, a hiteleket és a félretételt.'],
        ['Mi lesz az adataimmal?', 'EU-s szerveren vannak, senkinek nem adjuk el őket. A Beállításokban bármikor letöltheted vagy törölheted mindet.'],
        ['Le lehet mondani?', 'Bármikor, két koppintással; a kifizetett időszak végéig marad a Plusz. 14 napon belül a teljes összeget visszakapod.'],
    ];
    $waitlistCount = \App\Models\WaitlistSignup::query()->whereNotNull('confirmed_at')->count();
@endphp
<!DOCTYPE html>
<html lang="hu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>MoneySight – mennyi marad hó végére</title>
        <meta name="description" content="Havi költségvetés-app: beírod a fizetésed és a kiadásaid, és mindig látod, mennyi marad a hónap végén. Nem kapcsolódik a bankodhoz.">
        <meta name="theme-color" content="#0c0e0d">
        <meta property="og:title" content="MoneySight – mennyi marad hó végére">
        <meta property="og:description" content="Egy szám a kezdőképernyőn: ennyi marad a hónap végére. Iratkozz fel, és szólunk, amikor indul.">
        <meta property="og:image" content="{{ asset('images/landing/ma.jpg') }}">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    </head>
    <body>
        <header class="hero wrap">
            <div class="top">
                <a href="{{ route('home') }}" class="wordmark"><x-app-logo-icon />MoneySight</a>
                <nav aria-label="Oldal">
                    <a href="#hogyan" class="link optional">Hogyan működik</a>
                    <a href="#arak" class="link optional">Árak</a>
                    <a href="{{ rtrim(config('app.url'), '/').route('login', [], false) }}" class="link">Belépés</a>
                </nav>
            </div>

            <div class="hero-body">
                <h1 class="sentence" aria-label="Ennyi marad hó végére: {{ $number($left) }} forint. MoneySight, havi költségvetés-app.">
                    <span class="word" aria-hidden="true">Ennyi</span>
                    <span class="word" aria-hidden="true">marad</span>
                    <span class="slot" data-ui aria-hidden="true">
                        <span class="slot-label">Hó végére<br>marad</span>
                        <span class="slot-number" data-count-from="650000" data-count-to="{{ $left }}">{{ $number($left) }}</span>
                        <span class="slot-currency">Ft</span>
                    </span>
                    <span class="word" aria-hidden="true">hó végére.</span>
                </h1>
                <p class="hero-foot">A <strong>MoneySight</strong> havi költségvetés-app. Beírod a fizetésed és a kiadásaid, és mindig látod, mennyi marad a hónap végén. Nem kapcsolódik a bankodhoz.</p>
                <div class="hero-action">
                    <a href="#varolista" class="button">Szólj, ha indul</a>
                </div>
            </div>
        </header>

        <main>
            <section class="showcase wrap" aria-labelledby="showcase-title">
                <label class="typing rise" data-typing data-budgets="{{ json_encode($budgets) }}">
                    <span class="sr-only">Írd be, mire költöttél</span>
                    <input type="text" value="4 590 kávé és kifli" maxlength="40" autocomplete="off" spellcheck="false" data-ui>
                </label>
                <div class="answer">
                    <div class="card rise" style="--i: 1" data-ui aria-live="polite">
                        <div class="card-label">Hó végére marad</div>
                        <div class="card-number"><b data-left data-base="{{ $left }}">{{ $number($left - 4_590) }}</b><span>Ft</span></div>
                        <div class="card-rows">
                            <div class="card-row"><span data-budget-name>{{ $budgets[0]['name'] }}</span><span><b data-budget-left>{{ $number($budgets[0]['left'] - 4_590) }}</b> Ft marad</span></div>
                        </div>
                    </div>
                    <div class="rise" style="--i: 2">
                        <h2 id="showcase-title">Írd be, mire költöttél, és a szám már változik is.</h2>
                        <p>Próbáld ki: ez egy minta-hónap. Az appban ugyanígy, a saját számaiddal.</p>
                        <div class="examples" style="margin-top: 24px">
                            <button type="button" data-example="12 000 tankolás">12 000 tankolás</button>
                            <button type="button" data-example="3 200 mozi">3 200 mozi</button>
                            <button type="button" data-example="890 pék">890 pék</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="stages" id="hogyan" data-stages aria-labelledby="stage-title">
                <div class="stage-text">
                    <div>
                        @foreach ($stages as $index => $stage)
                            <div data-clause @if ($index > 0) hidden @endif>
                                <h2 @if ($index === 0) id="stage-title" @endif>{{ $stage['clause'] }}</h2>
                                <p>{{ $stage['text'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="stage-control">
                        <div class="stage-range">
                            <span class="stage-track" aria-hidden="true"></span>
                            <span class="stage-fill" data-fill aria-hidden="true"></span>
                            <input type="range" min="0" max="{{ count($stages) - 1 }}" step="1" value="0" aria-label="Hogyan működik: lépés">
                        </div>
                        <div class="stage-names">
                            @foreach ($stages as $index => $stage)
                                <button type="button" data-stage @if ($index === 0) aria-current="step" @endif>{{ $stage['name'] }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="stage-screen">
                    @foreach ($stages as $index => $stage)
                        @if ($stage['image'] === 'rogzites.jpg')
                            {{-- A real recording of the app: 4 590 Ft typed and saved, the month-end number follows. --}}
                            <video src="{{ asset('images/landing/rogzites.webm') }}" poster="{{ asset('images/landing/rogzites.jpg') }}" width="390" height="844" muted loop playsinline preload="metadata" aria-label="{{ $stage['alt'] }}" data-screen></video>
                        @else
                            <img src="{{ asset('images/landing/'.$stage['image']) }}" alt="{{ $stage['alt'] }}" width="390" height="844" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" data-screen @class(['is-current' => $index === 0])>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="bands" aria-labelledby="band-title">
                @foreach ($bands as $index => $band)
                    <div @class(['band', 'is-on' => $band['on']]) style="--band: {{ $band['color'] }}">
                        <div class="rise">
                            @if ($index === 0)
                                <h2 id="band-title">{{ $band['sentence'] }}</h2>
                            @else
                                <h3>{{ $band['sentence'] }}</h3>
                            @endif
                            <p>{{ $band['result'] }}</p>
                        </div>
                        <button type="button" class="switch" role="switch" aria-checked="{{ $band['on'] ? 'true' : 'false' }}" aria-label="{{ $band['sentence'] }}" data-switch data-ui></button>
                    </div>
                @endforeach
            </section>

            <section class="pricing wrap" id="arak" aria-labelledby="price-title">
                <div>
                    <h2 id="price-title" class="rise">Alap ingyen. Plusz <span class="price">{{ $number($prices['month']) }} Ft</span> havonta.</h2>
                    <div class="pricing-more rise" style="--i: 1">
                        <p><strong>Alap:</strong> fizetés, fix kiadások, keretek, rögzítés, a hó végi szám és a zárás. Örökre ingyen.</p>
                        <p><strong>Plusz:</strong> perselyek, hitelek előtörlesztés-tervezővel, félretétel. Évente {{ $number($prices['year']) }} Ft. Indulás után 14 napig ingyen kipróbálhatod.</p>
                        <p>{{ config('budget.operator.vat_note') }}</p>
                    </div>
                    <div class="pricing-action rise" style="--i: 2">
                        <a href="#varolista" class="button">Szólj, ha indul</a>
                    </div>
                </div>
                <div class="pricing-shots rise" style="--i: 1">
                    <img src="{{ asset('images/landing/perselyek.jpg') }}" alt="A Perselyek: előtörlesztési persely és tartalék, a havi gyűjtéssel." width="390" height="844" loading="lazy">
                    <img src="{{ asset('images/landing/hitelek.jpg') }}" alt="A Hitelek: tartozás, havi részlet, és a következő előtörlesztés után várható részlet." width="390" height="844" loading="lazy">
                </div>
            </section>

            <section class="faq wrap" aria-labelledby="faq-title">
                <h2 id="faq-title" class="rise">Amit kérdezni szoktak</h2>
                <div class="faq-list">
                    @foreach ($faq as [$question, $answer])
                        <details class="rise" style="--i: {{ $loop->index }}">
                            <summary>{{ $question }}<span class="chevron" aria-hidden="true"></span></summary>
                            <p>{{ $answer }}</p>
                        </details>
                    @endforeach
                </div>
            </section>

            <section class="cta wrap" id="varolista" aria-labelledby="cta-title">
                <h2 id="cta-title" class="rise">Ha indul, írjatok ide:</h2>
                <form method="POST" action="{{ route('waitlist.store') }}" class="rise" style="--i: 1" novalidate>
                    @csrf
                    <label class="sr-only" for="waitlist-email">E-mail címed</label>
                    <input id="waitlist-email" type="email" name="email" value="{{ old('email') }}" placeholder="e-mail címed" autocomplete="email" required>
                    <input class="honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <button type="submit">Kérem</button>
                </form>
                @if (session('waitlist') === 'sent')
                    <p class="cta-status" role="status">Elküldtük a megerősítő levelet. Nézd meg a postafiókod.</p>
                @endif
                @error('email')
                    <p class="cta-error" role="alert">{{ $message }}</p>
                @enderror
                <p class="cta-note">Megerősítő levelet küldünk. Csak az indulásról írunk, és egy kattintással leiratkozhatsz, akkor töröljük a címed. Részletek az <a href="{{ route('legal', 'adatvedelem') }}" class="link" style="text-decoration-color: currentColor">adatkezelési tájékoztatóban</a>.@if ($waitlistCount >= 10) Már {{ $waitlistCount }} ember várja.@endif</p>
            </section>
        </main>

        <footer class="footer wrap" aria-labelledby="contact-title">
            <div class="footer-top">
                <p>MoneySight – havi költségvetés-app. Nem kapcsolódik a bankodhoz, és soha nem mozgat pénzt.</p>
                <a href="#" class="link">Vissza a tetejére</a>
            </div>
            <h2 id="contact-title"><a href="mailto:{{ config('budget.operator.email') }}" class="link"><span>{{ \Illuminate\Support\Str::before(config('budget.operator.email'), '@') }}</span><span>{{ '@'.\Illuminate\Support\Str::after(config('budget.operator.email'), '@') }}</span></a></h2>
            <div class="footer-meta">
                <nav aria-label="Jogi dokumentumok">
                    <a href="{{ route('legal', 'aszf') }}" class="link">ÁSZF</a>
                    <a href="{{ route('legal', 'adatvedelem') }}" class="link">Adatvédelem</a>
                    <a href="{{ route('legal', 'impresszum') }}" class="link">Impresszum</a>
                </nav>
                <span>Képek: az app képernyői, minta-hónappal</span>
                <span>© {{ date('Y') }} MoneySight</span>
            </div>
        </footer>
    </body>
</html>
