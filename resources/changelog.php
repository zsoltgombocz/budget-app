<?php

/*
 * User-facing changelog, newest first. Every change has a line in each app language.
 * The first entry is the current app version (shown in Settings and the "new version" popup).
 */
return [
    [
        'version' => '1.9.0',
        'date' => '2026-10-08',
        'changes' => [
            ['hu' => 'Automatikus rögzítés: a telefonos és kártyás fizetéseid gépelés nélkül bekerülnek. iPhone-on egy Parancsok-automatizálás küldi be az Apple Pay fizetéseket, Androidon a MacroDroid app továbbítja a banki értesítéseket. Beállítás lépésről lépésre: Beállítások → Automatikus rögzítés.', 'en' => 'Automatic capture: your phone and card payments come in without typing. On iPhone a Shortcuts automation sends Apple Pay payments, on Android the MacroDroid app forwards bank notifications. Step-by-step setup: Settings → Automatic capture.'],
            ['hu' => 'A beérkezett fizetés azonnal költésként rögzül, és rögtön látszik a várható maradékban és a napi keretben. Villám jelöli, és egy koppintással áttehető másik kategóriába vagy törölhető.', 'en' => 'A payment that comes in is recorded as a spending at once and shows in the expected leftover and the daily allowance right away. A bolt marks it, and one tap moves it to another category or deletes it.'],
            ['hu' => 'Ha egy bolt fizetését áthelyezed, a következők már maguktól oda kerülnek.', 'en' => 'Move a shop’s payment once and its next payments go there by themselves.'],
            ['hu' => 'Amit nem dönthetünk el helyetted – más pénznemű fizetés, visszatérítés, a kézzel már rögzítettel egyező tétel –, az a Ma képernyőn vár rád, és addig nem számít bele semmibe. Ugyanaz a fizetés sosem kerül be kétszer.', 'en' => 'What we cannot decide for you – a payment in another currency, a refund, one that matches what you typed in – waits for you on the Today screen and counts nowhere until then. The same payment never comes in twice.'],
            ['hu' => 'Csak az összeget, a pénznemet, a boltot és az időpontot tároljuk; a kulcs bármikor törölhető.', 'en' => 'Only the amount, currency, shop and time are kept; the key can be deleted any time.'],
        ],
    ],
    [
        'version' => '1.8.1',
        'date' => '2026-10-05',
        'changes' => [
            ['hu' => 'A várható hó végi maradék nem vetíti előre a költési tempót: egy nagyobb egyszeri vásárlás nem mutat többé hamis, több százezres mínuszt. Minden kategória a keretével, vagy ha már túllépted, az eddig elköltött összeggel számít.', 'en' => 'The expected month-end leftover no longer extrapolates your spending pace: one bigger purchase no longer shows a false deficit of hundreds of thousands. Each category counts with its budget, or with what you spent if you are already over it.'],
        ],
    ],
    [
        'version' => '1.8.0',
        'date' => '2026-10-02',
        'changes' => [
            ['hu' => 'A Terv alján „Hó végi maradék” kártya: bevétel, terv, tartalék félretétel, várható maradék, és ott állítod be, hová menjen a maradék.', 'en' => 'A “Month-end leftover” card at the bottom of the Plan: income, plan, reserve saving, expected leftover, and where the leftover goes is set right there.'],
            ['hu' => 'A Tervben minden tétel mellett ott a kategória ikonja.', 'en' => 'Every plan line shows its category icon.'],
            ['hu' => 'A hitelnél megadható a törlesztő esedékességének napja, és a Tervből megnyitott hitel szerkesztése után visszakerülsz a Tervre.', 'en' => 'A loan has a due day for its installment, and a loan opened from the Plan returns you to the Plan.'],
            ['hu' => 'Törlés előtt az app saját, egyértelmű megerősítő ablaka kérdez rá.', 'en' => 'Before deleting, the app asks in its own clear confirmation dialog.'],
            ['hu' => 'Persely törlésekor a havi félretétele is kikerül a Tervből.', 'en' => 'Deleting a pocket also removes its monthly saving from the plan.'],
            ['hu' => 'A nyelv és az időzóna a Megjelenés beállításokba költözött.', 'en' => 'Language and time zone moved to the Appearance settings.'],
        ],
    ],
    [
        'version' => '1.7.0',
        'date' => '2026-10-02',
        'changes' => [
            ['hu' => 'Az app új neve MoneySight, új címe moneysight.app.', 'en' => 'The app is now called MoneySight, at moneysight.app.'],
            ['hu' => 'Új beállító varázsló: sablon helyett végigkérdez (közös kassza, hitel, napi költések), így bármilyen kombináció összeáll. Előfizetés, edzőterem és biztosítás is felvehető, és az egész ki is hagyható.', 'en' => 'New setup wizard: instead of a preset it asks about each part (shared costs, a loan, daily spending), so any mix works. Subscriptions, gym and insurance can be added, and it can be skipped too.'],
            ['hu' => 'A varázslóban a hitel adatai (tőke, THM, futamidő) is megadhatók, a tartalék és a hó végi maradék lépés pedig a te számaidból mutatja, hová megy a pénz.', 'en' => 'In the wizard the loan details (principal, APR, term) can be entered, and the reserve and leftover step shows with your numbers where the money goes.'],
            ['hu' => 'Új hitel felvételekor a törlesztő magától bekerül a tervbe a „Hiteltörlesztés” csoportba, rögzíteni nem kell.', 'en' => 'A new loan puts its installment into the plan under “Loan repayments” by itself, nothing to record.'],
            ['hu' => 'A Terv oldalról is felvehetsz hitelt, és a törlesztőre koppintva megnyílnak a hitel adatai.', 'en' => 'Loans can be added from the Plan too, and tapping a repayment opens the loan.'],
            ['hu' => 'Mínuszos hónap zárásakor te döntöd el, hogy a hiányt levonjuk-e a tartalékból; magától semmi nem mozdul.', 'en' => 'When a month closes in the red, you decide whether the gap is taken from the reserve; nothing moves on its own.'],
            ['hu' => 'Letiltott fiókkal nem lehet belépőkódot kérni.', 'en' => 'A disabled account cannot request a sign-in code.'],
        ],
    ],
    [
        'version' => '1.6.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Átdolgozott szerkesztő ablakok: csoportosított mezők, mindig látható Mentés gomb, és az összeghez külön felcsúszó számbillentyűzet.', 'en' => 'Reworked editors: grouped fields, an always visible Save button and a numpad that slides up for amounts.'],
            ['hu' => 'A perselynél külön fül a pénzmozgásnak és a beállításoknak.', 'en' => 'Pockets have separate tabs for moving money and for settings.'],
            ['hu' => 'Görgetés után is teljesen feljönnek az ablakok, és az onboardingban megjelenik a számbillentyűzet.', 'en' => 'Sheets open fully even after scrolling, and the numpad shows up in onboarding.'],
            ['hu' => 'A fejléc nem homályosodik el a státuszsáv alatt, és iPhone-on a belépőkód felajánlható az e-mailből.', 'en' => 'The header no longer blurs under the status bar, and iPhone can offer the sign-in code from the email.'],
            ['hu' => 'Ha olyan címmel lépsz be, amihez nincs fiók, e-mailben szólunk, hogy előbb regisztrálj.', 'en' => 'Signing in with an address that has no account now emails you to register first.'],
            ['hu' => 'A Beállítások bekerült a menübe; a + gomb lebeg, görgetéskor eltűnik, felfelé görgetve előjön.', 'en' => 'Settings is in the tab bar now; the + button floats, hides while scrolling down and comes back on scroll up.'],
            ['hu' => 'A Mégse minden ablakot szépen lecsúsztat.', 'en' => 'Cancel slides every sheet away.'],
            ['hu' => 'Az app meghívásos lett: új fiókot meghívóval lehet létrehozni.', 'en' => 'The app is invite only: new accounts come with an invite.'],
        ],
    ],
    [
        'version' => '1.5.1',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Belépés 6 jegyű kóddal: az e-mailből az appban írod be, így a telepített app is belép.', 'en' => 'Sign in with a 6-digit code typed in the app, so the installed app signs in too.'],
            ['hu' => 'A passkey megszakítása nem ad hibát, a hibaüzenetek magyarul jelennek meg.', 'en' => 'Cancelling a passkey is not an error any more, and errors are translated.'],
            ['hu' => 'A telepítési útmutató az appon belül nyílik a beállításokból.', 'en' => 'The install guide opens inside the app from Settings.'],
            ['hu' => 'Szebb e-mailek mobilon, javított ikonok és több hely a fejléc fölött iPhone-on.', 'en' => 'Nicer emails on phones, fixed icons and more room above the header on iPhone.'],
        ],
    ],
    [
        'version' => '1.5.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Jelszó nélküli belépés: e-mailes belépési link vagy passkey. A regisztrációhoz elég a név és az e-mail-cím.', 'en' => 'Passwordless sign-in: an emailed sign-in link or a passkey. Registering needs only a name and email.'],
            ['hu' => 'Új beállítások: menüből érhető el minden szekció, mindenhol van vissza gomb.', 'en' => 'New settings: every section opens from a menu and has a back button.'],
            ['hu' => 'Az értesítések külön oldalt kaptak, a változás azonnal mentődik.', 'en' => 'Notifications have their own page and save instantly.'],
            ['hu' => 'Gyorsabb navigáció: a menüpont azonnal kijelölődik, és töltésjelző mutatja a betöltést.', 'en' => 'Snappier navigation: the tab lights up instantly and a loader shows the page is coming.'],
            ['hu' => 'Onboarding: animált lépések, a gombok nem takarják a tartalmat, működik a „Most nem”.', 'en' => 'Onboarding: animated steps, buttons no longer cover content, “Not now” works.'],
        ],
    ],
    [
        'version' => '1.4.1',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Telepített iPhone appban nem homályosodik el a fejléc a státuszsáv alatt.', 'en' => 'The header no longer blurs under the status bar in the installed iPhone app.'],
            ['hu' => 'Levegősebb összegmegadás az onboardingban, kisebb kapcsolók mindenhol.', 'en' => 'Roomier amount step in onboarding and smaller switches everywhere.'],
        ],
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Kivét a perselyből a havi keretbe: nő a várható maradék és a napi keret.', 'en' => 'Withdraw from a pocket into the monthly budget: the expected leftover and daily budget grow.'],
            ['hu' => 'Költés fizetése perselyből: nem terheli a keretet, törléskor visszakerül a pénz.', 'en' => 'Pay a spending from a pocket: it does not use up the budget and is refunded when deleted.'],
            ['hu' => 'Zárásnál kiválaszthatod, hová menjen a maradék, akár új perselybe is.', 'en' => 'Choose where the leftover goes when closing, even into a new pocket.'],
            ['hu' => 'Befektetési utalás emlékeztető: push, főoldali kártya és „Átutaltam” gomb.', 'en' => 'Investment transfer reminder: push, dashboard card and a “Transferred” button.'],
            ['hu' => 'Verziószám, újdonságlista és értesítés új verzióról.', 'en' => 'App version, changelog and a notice about new versions.'],
            ['hu' => 'Finom animációk, oldalváltás és töltésjelzők.', 'en' => 'Subtle animations, page transitions and loading indicators.'],
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Telepítési útmutató a telefonodhoz és böngésződhöz (/telepites).', 'en' => 'Install guide for your phone and browser (/telepites).'],
            ['hu' => 'iPhone-on nincs többé zoom a numpadon és a mezőkön.', 'en' => 'No more zooming on the numpad and inputs on iPhone.'],
            ['hu' => 'Telepített appban a fejlécek nem csúsznak a státuszsáv alá.', 'en' => 'Headers no longer slide under the status bar in the installed app.'],
            ['hu' => 'Az onboarding elmagyarázza a befektetési számla és a persely közti különbséget.', 'en' => 'Onboarding explains the difference between an investment account and a pocket.'],
        ],
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Új design: sötét téma, új képernyők minden oldalon.', 'en' => 'New design: dark theme and new screens everywhere.'],
            ['hu' => 'Összegek beírása numpaddal, billentyűzet nélkül.', 'en' => 'Amounts are typed on a numpad, without the keyboard.'],
            ['hu' => 'A „Ma nem költöttem” mindenhol visszavonható.', 'en' => '“I didn’t spend today” can be undone everywhere.'],
            ['hu' => 'Onboarding magyarázatokkal, kikapcsolható tételekkel; költségvetés visszaállítása.', 'en' => 'Onboarding with explanations and switchable lines; budget reset.'],
            ['hu' => 'Magyar, designos e-mailek.', 'en' => 'Hungarian, design-styled emails.'],
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-10-01',
        'changes' => [
            ['hu' => 'Első kiadás: terv, gyors rögzítés, élő várható maradék, napi emlékeztető, hó végi zárás, perselyek és hitelek.', 'en' => 'First release: plan, quick entry, live expected leftover, daily reminder, month-end closing, pockets and loans.'],
        ],
    ],
];
