<?php

/*
 * User-facing changelog, newest first. Every change has a line in each app language.
 * The first entry is the current app version (shown in Settings and the "new version" popup).
 */
return [
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
