# Budget PWA projekt spec

Oct 1, 2026 · @Zsolti

## Áttekintés

Mobilra optimalizált PWA, ami a havi költségvetést nem utólag könyveli, hanem előre tervezi és napközben követi: a terv a fizetésből indul, a fix tételek automatikusan benne vannak, csak a változó költéseket kell rögzíteni, és bármikor látszik, mennyi marad a hónap végén.

A kész budget appok kategóriákat és tranzakciókat kezelnek. Ami ezekből hiányzik, és itt a lényeg:

- **Választható periódus**: fizetésnaptól fizetésnapig vagy naptári hónap.
- **Perselyek és hitelek mint első osztályú elemek**: előtörlesztési persely beállítható lépcsővel, a csökkent részlet visszacsatolása a tervbe.
- **Átlag vagy max alapú tervezés** változó tételeknél (pl. tankolás).
- **Hó végi felosztás**: a maradék szabály szerint megy tartalékba és befektetésre.
- **Napi push emlékeztető**, ami egy érintéssel a rögzítéshez visz.

Univerzális, többfelhasználós app: bárki onboarding varázslóval rakja össze a saját tervét. Nincs beégetett bank, deviza, kategória vagy összeg, minden a felhasználó beállításaiból jön. Minden adat `user_id`-val scope-olt.

## Tech stack

Laravel 13 + Livewire 4 a felhasználói felülethez, mert a napi rögzítéshez gyors, egyedi mobil UI kell, amit egy Filament panel nem ad jól. Filament csak opcionálisan, admin/beállítás felületnek.

| Réteg | Választás | Megjegyzés |
| --- | --- | --- |
| Backend | Laravel 13, PHP 8.4 | Actions + Services, Form Requestek |
| AI fejlesztés | Laravel Boost | MCP Claude Code-nak, guideline-ok, docs search |
| UI | Livewire 4, Flux UI, Alpine.js | Livewire 4 natív single-file komponensek (Volt nélkül) |
| Stílus | Tailwind CSS 4 | Mobile-first, dark mode |
| Auth | Laravel Livewire starter kit (Fortify) | Passkey a kitben már benne van |
| Adatbázis | MariaDB / MySQL 8 | Fejlesztéshez SQLite is mehet |
| Push | laravel-notification-channels/webpush | VAPID kulcsok |
| PWA | vite-plugin-pwa (Workbox) | Manifest, service worker, offline cache |
| Ütemezés | Laravel Scheduler + queue (database driver) | Napi emlékeztető, hó végi zárás |
| Grafikon | Chart.js vagy ApexCharts | Dashboard és hó végi riport |
| Tesztek | Pest 5 | Unit a számításokra, Feature a flow-kra |
| Minőség | Larastan max szint, Pint, Rector | CI-ban kötelező |
| Frontend tooling | bun, Vite | npm helyett bun (`bun.lock`) |

Pénzösszegek **egész számként**, a felhasználó alapdevizájának legkisebb egységében (HUF-nál forint, EUR-nál cent), lebegőpontos szám sehol. Az alapdeviza beállítható; devizás tételeknél az eredeti összeg és deviza is tárolva, a terv alapdevizában számol. A felület i18n-képes: első nyelv a magyar, második az angol.

## Domain modell

Minden a **periódus** körül forog: a felhasználó által választott időszak (fizetésnaptól fizetésnapig vagy naptári hónap), amihez egy terv és a tényleges költések tartoznak.

| Fogalom | Jelentés | Példa |
| --- | --- | --- |
| Periódus | Fizetésnaptól a következő fizetésnap előtti napig | 2026.10.31 – 2026.11.29, vagy naptári hónap (beállítás) |
| Bevétel | Tervezett és tényleges nettó fizetés | 609 000 Ft |
| Tervsor | Egy kategória havi tervezett összege | Tankolás: átlag 65k / max 80k |
| Utalás | Fizetésnapon elmenő fix összeg perselybe vagy közös számlára | Közös megélhetés 150k |
| Hitel | Tőke, havi részlet, biztosítás, THM | Személyi kölcsön 4M, 87 549 Ft/hó |
| Fix díj | Havonta azonos, esedékességi nappal | Edzőterem 15k, iCloud 1 290 |
| Változó keret | Tervezett keret, napközben rögzített költésekkel | Bolt, apróság 15k |
| Persely | Célzott gyűjtés egyenleggel és opcionális céllal | Előtörlesztés persely, 500k lépcső |
| Tartalék | Persely, aminek célösszege van; a maradék először ide megy | Cél 100k |
| Befektetés | Hó végi maradékból befektetési számlára menő összeg | Maradék 50%-a, ha a tartalék kész |
| Zárás | A periódus lezárása: tény vs terv, maradék felosztása | Snapshot JSON-nal |

Tervsor típusok: `transfer`, `loan`, `fixed`, `variable`, `sinking` (havonta perselybe gyűlő, pl. fehérje, digitális játék). A típus dönti el, hogy a tétel automatikusan teljesültnek számít-e, vagy rögzíteni kell.

## MVP funkciók

Az MVP öt dolgot tud: tervezni, gyorsan rögzíteni, élőben mutatni a várható maradékot, naponta emlékeztetni és lezárni a hónapot.

1. **Terv szerkesztő**
   - Indulás onboarding varázslóval: bevétel, periódus típusa, alapdeviza, kategória sablon (minden utólag szerkeszthető). Kategóriák típus szerint csoportosítva (utalás, hitel, fix, változó, persely).
   - Változó tételnél átlag és max összeg, plusz kapcsoló, melyikkel számoljon.
   - Esedékességi nap fix tételeknél; aktív időszak (pl. Klarna lejárta).
   - Élő összesítő alul: összes kiadás, tervezett maradék.
2. **Gyors rögzítés (2 érintés)**
   - Kategória gombrács a változó tételekből, utána numerikus billentyűzet.
   - Dátum alapból ma, megjegyzés opcionális.
   - "Ma nem költöttem" gomb, ami elnémítja aznapra az emlékeztetőt.
   - Utolsó tétel visszavonása toast-ból.
3. **Dashboard (real time)**
   - Fő szám: várható maradék a hónap végén, a jelenlegi tempó alapján.
   - Kategóriánként progress bar: elköltve / keret, színkód 80% és 100% felett.
   - Hátralévő napok, napi átlagos keret a hónap végéig.
   - Fix tételek listája pipával: teljesült / esedékes.
4. **Napi értesítő**
   - Web Push beállítható időpontban (alap 20:30).
   - Kihagyja, ha aznap már volt rögzítés vagy "Ma nem költöttem".
   - Értesítésre koppintva a rögzítő képernyő nyílik.
5. **Hó végi zárás**
   - Tény vs terv kategóriánként, eltérések kiemelve.
   - Maradék felosztása a szabály szerint: tartalék, majd a választott cél (befektetési számla vagy persely).
   - Persely egyenlegek frissítése, előtörlesztési jelzés, ha a persely elérte a lépcsőt.
   - Új periódus nyitása a terv másolásával.

## Későbbi funkciók

Ezek az MVP után jönnek, a séma már most úgy készül, hogy ne kelljen átírni.

| Funkció | Mit ad | Megjegyzés |
| --- | --- | --- |
| CSV import (bank adapterek + általános) | Hó végén a kivonat feltöltése, párosítás a kézi rögzítésekkel | `source` + `external_ref` mező már az MVP-ben |
| Kereskedő szabályok | MOL → Tankolás, Steam → Digitális játék automatikusan | Import után tanul a javításokból |
| Előtörlesztés szimulátor | Részletcsökkentés vs futamidő-csökkentés, megspórolt kamat | Annuitás képlet, THM alapján |
| Befektetési számla követés | Portfólió érték és befizetések havonta | Kézi érték, később bróker integráció (pl. IBKR Flex Query) |
| Közös nézet | Háztartás: közös költségek partnerrel, ki mennyit tett be | households tábla, megosztott perselyek |
| Árfolyam frissítés | Devizás előfizetések HUF értéke | MNB árfolyam, napi job |
| Keret riasztás | Push, ha egy kategória eléri a 80%-ot | Ugyanaz a webpush csatorna |
| Éves riport | Havi trendek, megtakarítási ráta | Zárás snapshotokból |
| AI összefoglaló | Havi szöveges értékelés, javaslatok | Claude API, opcionális |

## Adatbázis séma

Minden tábla `user_id`-val scope-olt (global scope a modellen). Összegek `unsignedBigInteger` vagy `bigInteger`, a legkisebb pénzegységben.

| Tábla | Cél | Fő oszlopok |
| --- | --- | --- |
| `budget_settings` | Felhasználói beállítások | `period_mode` (payday/calendar), `payday_day`, `locale`, `timezone`, `reminder_time`, `reminder_enabled`, `reserve_pct`, `currency` |
| `categories` | Kategóriák | `name`, `type` (transfer/loan/fixed/variable/sinking), `icon`, `color`, `sort`, `is_quick_entry` |
| `budget_lines` | A terv sorai | `category_id`, `amount`, `amount_avg`, `amount_max`, `calc_mode` (fixed/avg/max), `due_day`, `pocket_id`, `loan_id`, `orig_amount`, `orig_currency`, `active_from`, `active_to`, `note` |
| `periods` | Periódusok | `starts_on`, `ends_on`, `income_planned`, `income_actual`, `status` (open/closed), `plan_snapshot` (json) |
| `period_line_statuses` | Fix tételek teljesülése periódusonként | `period_id`, `budget_line_id`, `amount_actual`, `paid_on` |
| `transactions` | Rögzített költések | `period_id`, `category_id`, `amount`, `occurred_on`, `note`, `source` (manual/import), `client_uuid` (unique), `external_ref` |
| `day_marks` | "Ma nem költöttem" jelölés | `date` (unique per user) |
| `pockets` | Perselyek | `name`, `account_id`, `balance`, `target_amount`, `prepay_step`, `is_reserve`, `is_shared` |
| `pocket_movements` | Persely mozgások | `pocket_id`, `period_id`, `amount` (+/-), `type` (deposit/withdraw/prepay), `occurred_on`, `note` |
| `loans` | Hitelek | `name`, `lender`, `principal_balance`, `installment`, `insurance`, `thm`, `remaining_months`, `prepay_mode` (reduce\_installment/reduce\_term) |
| `loan_events` | Hitel események | `loan_id`, `type` (payment/prepayment/rate\_change), `amount`, `principal_after`, `installment_after`, `occurred_on` |
| `period_closes` | Zárás eredménye | `period_id`, `planned_total`, `actual_total`, `leftover`, `to_reserve`, `to_invest`, `breakdown` (json) |
| `push_subscriptions` | Web Push | a webpush csomag migrációja |

Kiegészítő táblák: `accounts` (a felhasználó számlái: `name`, `type` bank/cash/investment, `currency`), szabadon felvehetők, a perselyek és tranzakciók opcionálisan hivatkoznak rá. `category_templates` az onboarding sablonokhoz (pl. "Alap", "Hitellel", "Párban élő").

A `plan_snapshot` a periódus nyitásakor menti a terv állapotát, így egy későbbi tervmódosítás nem írja át a lezárt hónapok összevetését.

## Képernyők és mobil UX

Alsó tab navigáció négy füllel és egy középső, kiemelt "+" gombbal a rögzítéshez. Minden fő művelet hüvelykujjal elérhető az alsó harmadban.

| Képernyő | Tartalom | Fő interakció |
| --- | --- | --- |
| Ma (dashboard) | Várható maradék nagy számmal, kategória progress barok, hátralévő napok, napi keret | Kategóriára koppintva a tranzakciói |
| + Rögzítés (sheet) | Kategória gombrács, numpad, dátum, megjegyzés | Összeg → Mentés, 2 érintés |
| Terv | Tervsorok típusonként, átlag/max kapcsoló, élő összesítő | Inline szerkesztés, húzással rendezés |
| Perselyek és hitelek | Egyenlegek, célok, előtörlesztési lépcső haladása, hitel tőke és részlet | Kézi befizetés/kivét, előtörlesztés rögzítése |
| Hónap | Folyó hónap tranzakciói, korábbi zárások, zárás indítása | Zárás varázsló 3 lépésben |

UX szabályok:

- Lokalizált felület és pénzformátum (`NumberFormatter` / `Intl.NumberFormat`), magyarul pl. (`609 000 Ft`).
- Numpad `inputmode="numeric"`, autofókusz, nincs tizedes.
- Dark mode a rendszer beállítás szerint, safe-area kezelés iOS-en.
- Haptic feedback (`navigator.vibrate`) mentéskor, ahol támogatott.
- Üres állapotok magyarázattal, nem üres listával.

## Üzleti logika és számítások

Minden számítás egy-egy tiszta, unit tesztelt service-ben él (`BudgetCalculator`, `ForecastService`, `PeriodCloser`, `LoanCalculator`), Livewire komponensben számítás nincs.

**Tervezett maradék**

```latex
M_{terv} = B - \sum utal\acute{a}s - \sum hitel - \sum fix - \sum v\acute{a}ltoz\acute{o}_{sz\acute{a}molt}
```

Változó tételnél a számolt összeg a `calc_mode` szerint `amount_avg` vagy `amount_max`. Persely típusú (`sinking`) tétel képlettel is megadható, pl. fehérje: kg ár × 2 / hány hónapig bír.

**Várható maradék (dashboard)**

- Fix, utalás, hitel: mindig a tervezett összeg, akár teljesült már, akár nem.
- Változó kategóriánként a várható költés: az 5. nap előtt a terv; utána a nagyobb a tervezett keret és a lineáris előrevetítés közül.

```latex
v\acute{a}rhat\acute{o}_k = \max\left(terv_k,\ elk\ddot{o}ltve_k \cdot \frac{napok_{\ddot{o}sszes}}{napok_{eltelt}}\right)
```

- Ha egy kategória már túllépte a keretet, a várható az eddig elköltött összeg, nem kevesebb.

**Hó végi felosztás**

- Ha a maradék negatív: a hiány a tartalék perselyből jön, a folyószámla keretet nem használjuk.
- Ha pozitív: tartalékba megy `min(maradék × reserve_pct, max(0, cél − tartalék egyenleg))`, a többi a felhasználó által választott célra (befektetési számla vagy persely).

**Előtörlesztés**

- Ha az előtörlesztési persely egyenlege eléri a `prepay_step` értéket (alap 500 000 Ft), a zárás jelzi, és egy gombbal rögzíthető az előtörlesztés.
- Részletcsökkentésnél az új részlet annuitással, a THM és a hátralévő futamidő alapján:

```latex
A = P \cdot \frac{r(1+r)^n}{(1+r)^n - 1}, \quad r = \frac{THM}{12}
```

- Ha THM vagy futamidő nincs megadva, arányos becslés: új részlet = régi tőkerész × új tőke / régi tőke + biztosítás.
- Az új részlet automatikusan frissíti a hitel tervsorát, így a következő periódus maradéka nő.

## PWA, értesítések, offline

Telepíthető PWA, iOS-en kezdőképernyőre téve (iOS 16.4+ csak így kap Web Push-t), Androidon install prompttal.

**PWA**

- `manifest.webmanifest`: név, ikonok (192, 512, maskable), `display: standalone`, `theme_color`, `start_url: /ma`.
- Service worker vite-plugin-pwa-val: app shell és statikus assetek cache-elve, API hívások network-first.
- Telepítés után egyszeri onboarding: értesítés engedélyezése, emlékeztető időpont kiválasztása.

**Értesítések**

| Értesítés | Mikor | Kattintásra |
| --- | --- | --- |
| Napi emlékeztető | Beállított időpont, ha aznap nem volt rögzítés | Rögzítő sheet |
| Fizetésnap | Fizetésnap reggel: utalások listája (számlák, perselyek) | Terv, pipálható utalások |
| Keret riasztás | Egy kategória eléri a 80%-ot | Kategória részletei |
| Hó vége | Periódus utolsó napja | Zárás varázsló |

- Az ütemező percenként fut, és a felhasználó időzónája szerint dönt (`Europe/Budapest`).
- Notification action gombok: "Rögzítés" és "Ma nem költöttem" (utóbbi API hívással, app nyitása nélkül).

**Offline**

- MVP: a dashboard utolsó állapota offline is látszik.
- 2. kör: offline rögzítés IndexedDB sorba, Background Sync-kel küldés, ha visszajön a net. A szerver `client_uuid` alapján idempotensen fogadja.

## Onboarding sablonok és demo adat

Az app üresen indul, a terv az onboarding varázslóból épül fel. Beégetett, személyes adat sehol nincs.

**Kategória sablonok** (`category_templates`, szerkeszthető kiindulópont):

| Sablon | Tartalom |
| --- | --- |
| Alap | Lakhatás, rezsi, telefon, élelmiszer, közlekedés, szórakozás, egyéb, tartalék persely |
| Hitellel | Alap + hitel típusú sor és előtörlesztési persely |
| Párban élő | Alap + közös hozzájárulás (transfer) és közös persely |
| Üres | Csak a típusok, kategória nélkül |

A varázsló lépései: bevétel → periódus (fizetésnap vagy naptári hónap) → alapdeviza → sablon választás → összegek kitöltése → tartalék cél és felosztási szabály.

**DemoSeeder** (csak fejlesztéshez és tesztekhez): egy kitalált felhasználó általános számokkal, pár hónapnyi generált tranzakcióval, hogy a dashboard és a zárás azonnal tesztelhető legyen.

## Fejlesztési fázisok Claude Code-nak

Hat fázis, mindegyik végén zöld Pest, Larastan max és Pint. Claude Code egy fázist csinál egy menetben, a következőt csak az elfogadási kritériumok teljesülése után kezdi.

**CLAUDE.md alapszabályok**

- Kód és adatbázis angolul, felület magyarul (`lang/hu`).
- Pénz mindig `int` a legkisebb pénzegységben, saját `Money` value object vagy cast.
- Számítás csak service-ben, Livewire komponens vékony.
- Minden service metódushoz unit teszt, minden képernyőhöz feature teszt.
- N+1 tilos (`Model::preventLazyLoading()` dev-ben), Boost guideline-ok követése.

**Fázisok**

1. **Alap**: `laravel new` Livewire starter kittel, Boost telepítése, Pest, Larastan, Pint, Rector, CLAUDE.md, CI workflow.
   - [ ] `composer test` és `composer analyse` zöld üres projekten
2. **Domain és számítás**: migrációk, modellek, enumok, `DemoSeeder`, `CategoryTemplateSeeder`, `BudgetCalculator`, `ForecastService`, `LoanCalculator`.
   - [ ] Tesztben definiált fixture terveken (HUF és EUR, payday és calendar periódus) a tervezett maradék max és átlag módban is a kézzel számolt értéket adja
   - [ ] Előrevetítés, felosztás és annuitás unit tesztekkel lefedve
3. **Terv és rögzítés**: Onboarding varázsló, Terv képernyő, rögzítő sheet, tranzakció lista, "Ma nem költöttem".
   - [ ] Egy költés rögzítése 2 érintés mobilon
   - [ ] Terv módosítása azonnal frissíti az összesítőt
4. **Dashboard**: várható maradék, kategória progress barok, napi keret, fix tételek pipálása.
   - [ ] A dashboard 375 px szélességen görgetés nélkül mutatja a fő számot és a top 4 kategóriát
5. **PWA és push**: manifest, service worker, webpush, ütemezett emlékeztetők, onboarding.
   - [ ] Telepíthető iOS-en és Androidon, a napi emlékeztető megérkezik és a rögzítőt nyitja
   - [ ] Lighthouse PWA audit átmegy
6. **Hó végi zárás**: zárás varázsló, felosztás, persely és hitel frissítés, új periódus nyitása.
   - [ ] Lezárt periódus snapshotja nem változik tervmódosításra
   - [ ] 500k-s persely után az előtörlesztés rögzítése csökkenti a következő havi részletet

Utána jöhet a CSV import, mert ez adja a legtöbbet a terv és a valóság összevetéséhez.
