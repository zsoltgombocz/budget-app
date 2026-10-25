@php
    $prices = config('budget.prices.plus');
    $number = fn (int $amount): string => number_format($amount, 0, ',', ' ');
    // A made-up sample month: the same numbers as the app screenshots on this page.
    $left = 48_861;
    $budgets = [
        ['name' => 'Élelmiszer', 'left' => 91_100],
        ['name' => 'Közlekedés', 'left' => 28_300],
        ['name' => 'Szórakozás', 'left' => 12_400],
    ];
    $stages = [
        ['name' => 'Terv', 'clause' => 'Egyszer beállítod', 'text' => 'Fizetés, fix kiadások és havi keretek, például élelmiszerre vagy közlekedésre. Minden hónapban magától átmásolódik.', 'image' => 'terv.jpg', 'alt' => 'A Terv képernyő egy minta-hónappal: fizetés, fix tételek és keretek.'],
        ['name' => 'Rögzítés', 'clause' => 'Felírod, mire költesz', 'text' => 'Összeg, keret, mentés, a saját számbillentyűzetével, pár másodperc alatt.', 'image' => 'rogzites.jpg', 'alt' => 'A rögzítés: egy költés összege és a kerete, alatta a számbillentyűzet.'],
        ['name' => 'Ma', 'clause' => 'Látod, mennyi marad', 'text' => 'Egy szám a kezdőképernyőn: ennyi marad a hónap végére, ha a terv szerint haladsz.', 'image' => 'ma.jpg', 'alt' => 'A Ma képernyő egy minta-hónappal: a hó végi maradék, a hónap görbéje és az esedékes tételek.'],
        ['name' => 'Zárás', 'clause' => 'Lezárod, és te döntesz', 'text' => 'A hónap végén eldöntöd, mi legyen a maradékkal: marad a számlán, vagy tartalékba, megtakarításba kerül.', 'image' => 'honap.jpg', 'alt' => 'A Hónap képernyő egy minta-hónappal: a hónap bontása és a napi költések.'],
    ];
    $bands = [
        ['sentence' => 'Mennyit költhetek még kajára?', 'result' => 'Minden keret mutatja, mennyi maradt belőle. Ha túllépsz egyet, a hó végi számban azonnal látszik.', 'color' => '#17402a', 'on' => true],
        ['sentence' => 'Ebben a hónapban kimarad egy fix kiadás.', 'result' => 'Egy koppintás, és a hó végi szám ennyivel nő, például 15 000 Ft-tal. A jövő havi terved nem változik.', 'color' => '#2b2213', 'on' => false],
        ['sentence' => 'Szóljon, ha esedékes a lakbér.', 'result' => 'Az esedékesség napján értesítést kapsz, és egy koppintással kifizetettnek jelölöd.', 'color' => '#1f2a3a', 'on' => true],
        ['sentence' => 'Félreteszek előtörlesztésre.', 'result' => 'Pluszban: megadod, mennyit teszel félre havonta, és az app kiszámolja, mennyivel lesz kisebb a részlet vagy rövidebb a futamidő.', 'color' => '#15301f', 'on' => false],
    ];
    $faq = [
        ['Kapcsolódik a bankomhoz?', 'Nem. Nem kérünk banki belépést, és az app soha nem mozgat pénzt. Te írod be, mire költesz, így pontosan azt látod, amit a saját hónapodról tudsz.'],
        ['Nem túl macerás mindent felírni?', 'A tervet egyszer állítod be, utána minden hónapban magától átmásolódik. Naponta csak a költéseket írod be: összeg, keret, mentés. Ha aznap nem költöttél, azt is egy koppintással jelzed.'],
        ['Mikor indul?', 'Akkor, ha elég ember jelentkezik a várólistára, ezért fix dátumot most nem ígérünk. Aki feliratkozik, annak elsőként írunk, amikor elindul.'],
        ['Kell hozzá alkalmazásbolt?', 'Nem. Böngészőben fut, és iPhone-on és Androidon is a kezdőképernyőre teheted. Onnan úgy indul, mint egy app.'],
        ['Mi lesz az adataimmal?', 'Európai szerveren tároljuk őket, és senkinek nem adjuk el. A Beállításokban bármikor letöltheted vagy véglegesen törölheted mindet.'],
        ['Mi van, ha mégsem kell a Plusz?', 'Bármikor lemondhatod, és a kifizetett időszak végéig megmarad. Az első fizetéstől számított 14 napon belül indoklás nélkül elállhatsz, és a teljes díjat visszatérítjük. Az Alap ezután is ingyenes marad.'],
    ];
    $waitlistCount = \App\Models\WaitlistSignup::query()->whereNotNull('confirmed_at')->count();
    $sent = session('waitlist') === 'sent';
@endphp
<!DOCTYPE html>
<html lang="hu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>MoneySight – tudd előre, mennyi marad hó végére</title>
        <meta name="description" content="Havi költségvetés-app fizetésből élőknek. Beírod a fizetésed és a fix kiadásaid, felírod, mire költesz, és mindig látod, mennyi marad a hónap végére. Banki belépés nélkül.">
        <meta name="theme-color" content="#0c0e0d">
        <meta property="og:title" content="MoneySight – ne a hónap végén tudd meg">
        <meta property="og:description" content="Egy szám a telefonodon: ennyi pénzed marad a hónap végére. Iratkozz fel, és szólunk, amikor indul.">
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
                    <a href="#hogyan" class="link">Hogyan működik</a>
                    <a href="#arak" class="link">Árak</a>
                </nav>
            </div>

            <div class="hero-body">
                <div class="hero-copy">
                    <h1 class="headline">Ne a hónap végén tudd meg.</h1>
                    <p class="hero-foot">A MoneySight már hónap közben megmutatja, mennyi pénzed marad a hónap végére, miután a lakbért, a rezsit és a többi fix kiadást is levonta.</p>
                    @include('partials.waitlist-form', ['id' => 'hero-email', 'sent' => $sent])
                    <p class="hero-note">Böngészőben fut, banki belépés nélkül.</p>
                </div>
                {{-- The app's own home-screen card, with a made-up sample month. --}}
                <div class="hero-card" data-ui role="img" aria-label="Hó végére marad: {{ $number($left) }} forint (minta)">
                    <div class="hero-card-label">Hó végére marad</div>
                    <div class="hero-card-number"><span data-count-from="650000" data-count-to="{{ $left }}">{{ $number($left) }}</span><small>Ft</small></div>
                    <div class="hero-card-rows">
                        <div><span>Fizetés</span><b>650 000</b></div>
                        <div><span>Fix kiadások</span><b>−385 539</b></div>
                        <div><span>Keretek, várhatóan</span><b>−215 600</b></div>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <section class="showcase wrap" aria-labelledby="showcase-title" data-demo>
                <div class="showcase-copy rise">
                    <h2 id="showcase-title">Próbáld ki: írj be egy költést.</h2>
                    <p>Ez egy kitalált minta-hónap. Az appban a saját fizetéseddel működik: beírod az összeget, kiválasztod a keretet, és a hó végi szám azonnal frissül.</p>
                    <label class="amount" for="demo-amount">
                        <span class="sr-only">Összeg forintban</span>
                        <input id="demo-amount" type="text" inputmode="numeric" value="4 590" maxlength="9" autocomplete="off" data-amount data-ui>
                        <span aria-hidden="true">Ft</span>
                    </label>
                    <div class="chips" role="radiogroup" aria-label="Keret">
                        @foreach ($budgets as $index => $budget)
                            <button type="button" role="radio" aria-checked="{{ $index === 0 ? 'true' : 'false' }}" data-budget data-name="{{ $budget['name'] }}" data-left="{{ $budget['left'] }}">{{ $budget['name'] }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="card rise" style="--i: 1" data-ui aria-live="polite">
                    <div class="card-label">Hó végére marad</div>
                    <div class="card-number"><b data-demo-left data-base="{{ $left }}">{{ $number($left - 4_590) }}</b><span>Ft</span></div>
                    <div class="card-row"><span data-demo-budget>{{ $budgets[0]['name'] }}</span><span><b data-demo-budget-left>{{ $number($budgets[0]['left'] - 4_590) }}</b> Ft marad</span></div>
                </div>
            </section>

            <section class="stages wrap" id="hogyan" data-stages aria-labelledby="stages-title">
                <div class="stage-text rise">
                    <h2 id="stages-title" class="stages-title">Így működik</h2>
                    <div class="stage-tabs" role="tablist" aria-label="Lépések">
                        @foreach ($stages as $index => $stage)
                            <button type="button" role="tab" id="stage-tab-{{ $index }}" aria-controls="stage-panel-{{ $index }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}" tabindex="{{ $index === 0 ? 0 : -1 }}" data-stage>
                                <span class="stage-bar" aria-hidden="true"><span class="stage-progress"></span></span>
                                <span class="stage-name">{{ $stage['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    @foreach ($stages as $index => $stage)
                        <div class="stage-panel" role="tabpanel" id="stage-panel-{{ $index }}" aria-labelledby="stage-tab-{{ $index }}" data-clause @if ($index > 0) hidden @endif>
                            <h3>{{ $stage['clause'] }}</h3>
                            <p>{{ $stage['text'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="stage-screen rise" style="--i: 1">
                    @foreach ($stages as $index => $stage)
                        @if ($stage['image'] === 'rogzites.jpg')
                            {{-- A real recording of the app: an amount typed and saved. --}}
                            <video src="{{ asset('images/landing/rogzites.webm') }}" poster="{{ asset('images/landing/rogzites.jpg') }}" width="390" height="844" muted loop playsinline preload="metadata" aria-label="{{ $stage['alt'] }}" data-screen></video>
                        @else
                            <img src="{{ asset('images/landing/'.$stage['image']) }}" alt="{{ $stage['alt'] }}" width="390" height="844" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" data-screen @class(['is-current' => $index === 0])>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="bands" aria-labelledby="bands-title">
                <div class="bands-head wrap rise">
                    <h2 id="bands-title">Ahogy a hónapod alakul</h2>
                    <p>Pár példa arra, mit csinál az app, ha valami közbejön. A számok példák.</p>
                </div>
                @foreach ($bands as $band)
                    <div @class(['band', 'is-on' => $band['on']]) style="--band: {{ $band['color'] }}">
                        <div class="rise" style="--i: {{ $loop->index }}">
                            <h3>{{ $band['sentence'] }}</h3>
                            <p>{{ $band['result'] }}</p>
                        </div>
                        <button type="button" class="switch" role="switch" aria-checked="{{ $band['on'] ? 'true' : 'false' }}" aria-label="{{ $band['sentence'] }}" data-switch data-ui></button>
                    </div>
                @endforeach
            </section>

            <section class="pricing wrap" id="arak" aria-labelledby="price-title">
                <div>
                    <h2 id="price-title" class="rise">Az alapok ingyenesek. A Plusz havi <span class="price">{{ $number($prices['month']) }} Ft</span></h2>
                    <div class="pricing-more rise" style="--i: 1">
                        <p><strong>Alap – ingyenes, nincs lejárata.</strong> Fizetés, fix kiadások, keretek, költések rögzítése, a hó végi szám és a hónapzárás. Ennyi elég ahhoz, hogy mindig tudd, hol tartasz.</p>
                        <p><strong>Plusz – {{ $number($prices['month']) }} Ft/hó vagy {{ $number($prices['year']) }} Ft/év.</strong> Ha gyűjtesz valamire vagy hitelt törlesztesz: perselyek a céljaidra, a hiteleid előtörlesztés-tervezővel, és félretétel a havi tervben. Az éves díj havonta {{ $number((int) floor($prices['year'] / 12)) }} Ft. Új fiókkal 14 napig ingyen kipróbálhatod, bankkártya nélkül.</p>
                        <p class="muted">{{ config('budget.operator.vat_note') }}</p>
                    </div>
                    <div class="pricing-action rise" style="--i: 2">
                        <a href="#varolista" class="button">Értesíts, ha indul</a>
                    </div>
                </div>
                <div class="pricing-shot rise" style="--i: 1">
                    <img src="{{ asset('images/landing/hitelek.jpg') }}" alt="Egy minta-hitel: tartozás, havi részlet, és a várható részlet a következő előtörlesztés után." width="390" height="844">
                </div>
            </section>

            <section class="faq wrap" aria-labelledby="faq-title">
                <h2 id="faq-title" class="rise">Gyakori kérdések</h2>
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
                <div class="cta-copy rise">
                    <h2 id="cta-title">Szólunk, amikor indul.</h2>
                    <p>A MoneySight akkor indul el, ha elég ember szeretné használni. Ha te is, hagyd itt az e-mail címed, és az indulásról elsőként értesítünk.</p>
                </div>
                <div class="rise" style="--i: 1">
                    @include('partials.waitlist-form', ['id' => 'waitlist-email', 'sent' => $sent])
                    <p class="cta-note">Megerősítő levelet küldünk. Csak az indulásról írunk, és egy kattintással leiratkozhatsz; ekkor a címedet töröljük. Részletek az <a href="{{ route('legal', 'adatvedelem') }}" class="link link-underlined">adatkezelési tájékoztatóban</a>.@if ($waitlistCount >= 10) Eddig {{ $waitlistCount }} ember iratkozott fel.@endif</p>
                </div>
            </section>
        </main>

        <footer class="footer wrap" aria-labelledby="contact-title">
            <p class="footer-top">MoneySight – havi költségvetés-app. Nem kapcsolódik a bankodhoz, és soha nem mozgat pénzt.</p>
            <h2 id="contact-title" class="footer-mail"><a href="mailto:{{ config('budget.operator.email') }}" class="link">{{ config('budget.operator.email') }}</a></h2>
            <div class="footer-meta">
                <nav aria-label="Jogi dokumentumok">
                    <a href="{{ route('legal', 'aszf') }}" class="link">ÁSZF</a>
                    <a href="{{ route('legal', 'adatvedelem') }}" class="link">Adatvédelem</a>
                    <a href="{{ route('legal', 'impresszum') }}" class="link">Impresszum</a>
                    <a href="{{ rtrim(config('app.url'), '/').route('login', [], false) }}" class="link">Belépés</a>
                </nav>
                <span>© {{ date('Y') }} MoneySight</span>
            </div>
        </footer>
    </body>
</html>
