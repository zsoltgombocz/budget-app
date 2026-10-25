# Előfizetési szövegek

Hatályos: {{effective_date}} · Verzió: {{version}}

Rövid referencia az Előfizetés oldal, a Stripe Checkout és az e-mailek szövegeihez. A `{…}` részeket az app tölti ki.

## Előfizetési összefoglaló (a gomb fölött, mindig látható)

- **Plusz {havi / éves}:** {3 990 Ft / hó | 31 990 Ft / év}. {{vat_note}}
- **Megújul** {havonta / évente} automatikusan, amíg le nem mondod.
- **Első terhelés:** {ma | a próbaidő végén, {dátum}}.
- **Lemondás:** bármikor, két koppintással itt. A Plusz a kifizetett időszak végéig marad, utána Alap. Semmi nem törlődik.
- **Elállás:** 14 napon belül teljes visszatérítés, indoklás nélkül.
- {{operator_name}} · [ÁSZF](/aszf) · [Adatkezelés](/adatvedelem)

## Gomb

**Előfizetek – fizetési kötelezettséggel**

## Stripe Checkout – `custom_text.submit.message`

Fizetési kötelezettséggel járó előfizetés: {ma | {dátum}-án} {összeg} Ft, utána {havonta / évente} ugyanennyi, amíg le nem mondod. 14 napon belül teljes visszatérítés.

## E-mail: elindult az előfizetés

**Tárgy:** Elindult a Plusz

Köszönjük. {dátum}-án megrendelted a MoneySight Plusz {havi / éves} előfizetést.

- Díj: {összeg} Ft {havonta / évente}. {{vat_note}}
- Első terhelés: {dátum}. Utána automatikusan megújul, amíg le nem mondod.
- Lemondás: Beállítások → Előfizetés. A Plusz a kifizetett időszak végéig marad.
- Elállás: {dátum + 14 nap}-ig indoklás nélkül, teljes visszatérítéssel. Az Előfizetés oldalon az „Elállás” gombbal, vagy válaszolj erre a levélre.

Az ÁSZF (verzió: {{version}}) és az elállási tájékoztató nyilatkozatmintával: {link}. A számlát külön levélben küldjük.

{{operator_name}} · {{operator_address}} · {{operator_email}}

## E-mail: lemondás

**Tárgy:** Lemondtad a Pluszt

A Plusz {dátum}-ig marad, utána Alapra váltasz. Több terhelés nem lesz. Az adataid megmaradnak.

Meggondoltad? {dátum}-ig visszavonhatod: Beállítások → Előfizetés.

{{operator_name}} · {{operator_email}}

## E-mail: elállás

**Tárgy:** Elálltál a Plusztól

{dátum, időpont}-kor elálltál a MoneySight Plusz előfizetéstől. A Plusz kikapcsolt, Alapra váltottál. Az adataid megmaradnak.

Visszatérítés: {összeg} Ft a kártyádra, legkésőbb {dátum}-ig. A helyesbítő számlát külön levélben küldjük.

{{operator_name}} · {{operator_address}} · {{operator_email}}
