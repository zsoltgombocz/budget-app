# Adatkezelési tájékoztató

Hatályos: {{effective_date}} · Verzió: {{version}}

Ez a tájékoztató az (EU) 2016/679 általános adatvédelmi rendelet (GDPR) 13. cikke és az információs önrendelkezési jogról szóló 2011. évi CXII. törvény (Infotv.) alapján elmondja, milyen személyes adatokat kezelünk a MoneySight használatakor, miért, meddig, kivel osztjuk meg őket, és milyen jogaid vannak.

**Röviden:**

- Csak azt kezeljük, ami a működéshez kell. Nincs analitika, nincs hirdetés, nincs követő süti, és az adataidat nem adjuk el.
- A költségvetési adataidat te írod be. Az app nem kapcsolódik a bankodhoz.
- A szerverünk az EU-ban van. Néhány szolgáltatónk amerikai cég, velük uniós garanciák mellett dolgozunk.
- Az adataidat bármikor letöltheted, és a fiókodat bármikor törölheted az appban.

## 1. Az adatkezelő

- Név: {{operator_name}} egyéni vállalkozó
- Székhely: {{operator_address}}
- Egyéni vállalkozói nyilvántartási szám: {{operator_registration}}
- Adószám: {{operator_tax_number}}
- E-mail (adatvédelmi kérdések is): {{operator_email}}
- Telefon: {{operator_phone}}
- Weboldal: https://moneysight.app

Adatvédelmi tisztviselőt nem neveztünk ki, mert erre a GDPR 37. cikke szerint nem vagyunk kötelesek.

## 2. Milyen adatokat kezelünk

| Adatkör | Példák | Honnan származik |
|---|---|---|
| Fiókadatok | e-mail-cím, név (nem kötelező), a fiók létrehozásának és utolsó belépésének ideje | tőled |
| Belépési adatok | egyszer használatos belépő link vagy kód (rövid ideig érvényes), a passkey nyilvános kulcsa és azonosítója, a készülék elnevezése | tőled, a készülékedtől |
| Költségvetési adatok | fizetés, fix kiadások, költési keretek, költések (összeg, kategória, dátum, megjegyzés), hónapzárások, perselyek, hitelek, félretétel, alapdeviza | tőled |
| Beállítások | időzóna, nyelv, értesítési beállítások, csomag és csomagváltások | tőled, az appból |
| Értesítési adatok | push-feliratkozás (végpont, kulcsok), értesítésnapló (mi ment ki és mikor) | a böngésződtől, az appból |
| Munkamenet és biztonság | munkamenet-azonosító, IP-cím, böngésző típusa (user agent), időbélyegek, biztonsági események | a böngésződtől |
| Előfizetési és fizetési adatok | csomag, előfizetés állapota, Stripe-azonosítók, terhelések összege és ideje; a kártya típusa, utolsó négy számjegye és lejárata | a Stripe-tól |
| Számlázási adatok | név, számlázási cím, adószám (ha megadod), számlaszám, összeg | tőled, a Stripe-tól |
| Szerződési nyilatkozatok | az ÁSZF elfogadása és verziója, lemondás, elállás, időbélyeggel | az appból |
| Hibaadatok | hibaüzenet, technikai környezet (böngésző, oldal), fiókazonosító | az appból |
| Hibabejelentés, levelezés | az általad írt szöveg, e-mail-cím, név, a nem kötelező képernyőkép (ami az akkor látott adataidat is mutathatja) | tőled |
| Várólista (a nyitóoldalon) | e-mail-cím, a feliratkozás és a megerősítés ideje | tőled |

A költésekhez fűzött megjegyzés szabad szöveg. Kérjük, ne írj bele egészségügyi vagy más különleges adatot.

**Ha nem adod meg az adatokat:** e-mail-cím nélkül nem hozható létre fiók, költségvetési adatok nélkül az app nem tud számolni. A név, a push értesítés és a képernyőkép nem kötelező.

## 3. Célok és jogalapok

| Cél | Adatkör | Jogalap |
|---|---|---|
| Fiók létrehozása, belépés | fiók- és belépési adatok | szerződés teljesítése (GDPR 6. cikk (1) b)) |
| A Szolgáltatás nyújtása (számolás, hónapzárás, perselyek, hitelek) | költségvetési adatok, beállítások | szerződés teljesítése (6. cikk (1) b)) |
| Értesítések (push, ha bekapcsolod; szolgáltatási e-mailek) | értesítési adatok, e-mail-cím | szerződés teljesítése (6. cikk (1) b)); a push a böngésző engedélyével indul, és bármikor kikapcsolható |
| Munkamenet, biztonság, visszaélések megelőzése | munkamenet- és biztonsági adatok | jogos érdek: a Szolgáltatás és a fiókok biztonsága (6. cikk (1) f)) |
| Előfizetés és fizetés | előfizetési és fizetési adatok | szerződés teljesítése (6. cikk (1) b)) |
| Számlázás, könyvelés | számlázási adatok | jogi kötelezettség (6. cikk (1) c)): Áfa tv., Számv. tv. |
| Szerződési nyilatkozatok igazolása | szerződési nyilatkozatok | jogi kötelezettség (6. cikk (1) c)) és jogos érdek: jogi igények érvényesítése (6. cikk (1) f)) |
| Hibakövetés | hibaadatok | jogos érdek: a hibamentes működés (6. cikk (1) f)) |
| Hibabejelentés, ügyfélszolgálat | hibabejelentés, levelezés | jogos érdek: a kérés kezelése (6. cikk (1) f)) |
| Panaszkezelés | név, elérhetőség, a panasz és a válasz | jogi kötelezettség (6. cikk (1) c)), Fgytv. 17/A. § |
| Biztonsági mentés | minden tárolt adat | jogos érdek: adatvesztés elleni védelem (6. cikk (1) f)) |
| Várólista: értesítés az indulásról | e-mail-cím, időpontok | hozzájárulás (6. cikk (1) a)), amelyet a megerősítő levélben lévő linkkel adsz meg, és bármikor visszavonhatsz |

**Profilalkotás, automatizált döntés:** a számokat az app a te adataidból, a te kérésedre számolja ki. Rólad joghatással járó automatizált döntést nem hozunk, és marketingprofilt sem készítünk.

## 4. Meddig őrizzük az adatokat

| Adatkör | Megőrzési idő |
|---|---|
| Fiók-, költségvetési és beállítási adatok | a fiók törléséig |
| Soha be nem lépett (meg nem erősített) fiók | 7 nap, utána automatikusan töröljük |
| Belépő link és kód | néhány percig érvényes, egyszer használható |
| Értesítésnapló | 30 nap |
| Munkamenet | 120 perc inaktivitás vagy kijelentkezés; a „bejelentkezve tartás” süti legfeljebb 400 nap |
| Biztonsági események | 7 nap |
| Webszerver-naplók | 14 nap |
| Előfizetési és fizetési adatok | a fiók törléséig; a jogi igények érvényesítéséhez szükséges részük az előfizetés megszűnése után 5 évig |
| Számlák és számviteli bizonylatok | 8 év (Számv. tv. 169. §), a fiók törlése után is |
| Szerződési nyilatkozatok | a szerződés megszűnése után 5 év |
| Hibaadatok (Sentry) | legfeljebb 90 nap |
| Hibabejelentés, levelezés | az ügy lezárása után 1 év |
| Panasz és válasz | 3 év (Fgytv. 17/A. §) |
| Biztonsági mentések | legfeljebb 30 nap |
| Várólista | a leiratkozásig, legfeljebb az indulás után 6 hónapig; a meg nem erősített feliratkozást 30 nap után töröljük |

A fiók törlésekor az adatokat az élő adatbázisból azonnal töröljük. A biztonsági mentésekből legkésőbb 30 nap alatt tűnnek el.

## 5. Adatfeldolgozók és címzettek

Az adataidhoz a Szolgáltató részéről csak az üzemeltetéshez szükséges személy fér hozzá, titoktartási kötelezettséggel. Az alábbi szolgáltatók a mi utasításunkra (adatfeldolgozóként) vagy önálló adatkezelőként kapnak adatot. Mindegyikkel adatfeldolgozási szerződést kötöttünk, ahol ez szükséges.

| Szolgáltató | Feladat | Adatok | Hely és garancia |
|---|---|---|---|
| Contabo GmbH, Aschauer Straße 32a, 81549 München, Németország | szerver, adatbázis, mentések | minden tárolt adat | EU (Németország) |
| Cloudflare, Inc., 101 Townsend St., San Francisco, CA 94107, USA | DNS, proxy, titkosított kapcsolat, támadások elleni védelem | IP-cím, kérésadatok, a forgalom átvitel közben | USA, globális hálózat; EU–USA adatvédelmi keretrendszer (DPF) és általános szerződési feltételek (SCC) |
| Resend (Plus Five Five, Inc.), USA | e-mailek kiküldése (belépés, értesítések, visszaigazolások) | e-mail-cím, név, a levél tartalma, kézbesítési napló | USA; DPF, illetve SCC |
| Functional Software, Inc. (Sentry), 45 Fremont St., San Francisco, CA 94105, USA | hibakövetés, hibabejelentések fogadása | hibaadatok, hibabejelentés, képernyőkép | az adatok az EU-ban (Frankfurt, Németország) tárolódnak; amerikai hozzáférés esetén DPF, illetve SCC |
| Stripe Payments Europe, Limited, 1 Grand Canal Street Lower, Dublin 2, Írország (és kapcsolt vállalkozásai, pl. Stripe, Inc., USA) | fizetés, előfizetés kezelése; a fizetésnél, csalásmegelőzésnél és a pénzmosás elleni kötelezettségeinél önálló adatkezelő | név, e-mail-cím, kártyaadatok, számlázási cím, IP-cím, eszközadatok, tranzakciók | EU és USA; DPF és SCC; stripe.com/privacy |
| Billingo Technologies Zrt. (online számlázó) | számla kiállítása, NAV-adatszolgáltatás | számlázási adatok | Magyarország |
| A Szolgáltató könyvelője | könyvelés | számlázási adatok | Magyarország |
| Apple Inc. és Google LLC push-szolgáltatása (a készülékedtől függően) | push értesítések kézbesítése | csak a készülék push-azonosítója és az értesítés szövege | USA / globális; a szolgáltatók saját feltételei szerint |
| Hatóságok (pl. NAV, bíróság) | jogszabályi kötelezettség teljesítése | a megkeresésben kért adatok | Magyarország |

Az árfolyamokat a Magyar Nemzeti Bank nyilvános szolgáltatásából, személyes adat nélkül kérdezzük le. Külső betűtípust, analitikát és közösségimédia-modult nem használunk.

## 6. Továbbítás az EGT-n kívülre

Ha egy szolgáltató az Európai Gazdasági Térségen kívül (jellemzően az USA-ban) fér hozzá adathoz, ez az Európai Bizottság megfelelőségi határozata (EU–USA adatvédelmi keretrendszer, a tanúsított cégeknél, GDPR 45. cikk) vagy az általános adatvédelmi kikötések (SCC, GDPR 46. cikk) alapján történik. A garanciák másolatát kérésre elküldjük.

### A várólista

A nyitóoldalon megadhatod az e-mail-címed, hogy szóljunk, amikor a MoneySight fizetős változata indul. A címet csak a megerősítő levélben lévő link megnyitása után vesszük fel; csak az indulásról írunk, legfeljebb néhány levelet. Minden levélben van leiratkozó link: leiratkozáskor a címet véglegesen töröljük. A hozzájárulás visszavonása nem érinti a korábbi adatkezelés jogszerűségét.

## 7. A jogaid

- **Hozzáférés** (GDPR 15. cikk): tájékoztatást és másolatot kérhetsz az adataidról.
- **Helyesbítés** (16. cikk): a legtöbb adatot az appban magad javíthatod.
- **Törlés** (17. cikk): a fiókodat az appban azonnal törölheted (lásd lent).
- **Korlátozás** (18. cikk): kérheted, hogy bizonyos ideig csak tároljuk az adatot.
- **Adathordozhatóság** (20. cikk): az adataidat géppel olvasható formában letöltheted (lásd lent).
- **Tiltakozás** (21. cikk): a jogos érdeken alapuló adatkezelés ellen tiltakozhatsz. Ilyenkor megvizsgáljuk, van-e a jogaidnál erősebb, kényszerítő okunk.
- **Push értesítések:** bármikor kikapcsolhatod az appban vagy a böngésződben.

### Adataim letöltése

Beállítások → **Adataim letöltése**. Egy ZIP-fájlt kapsz, benne táblánként egy CSV-fájllal (pontosvesszővel tagolt, UTF-8). Minden benne van, amit rólad az adatbázisban tárolunk: fiók, költések, tervtételek, perselyek, hitelek, hónapok, értesítésnapló, munkamenetek, előfizetés és a többi. Csak a belépéshez használt titkok (pl. passkey-kulcsok, push-kulcsok, munkamenet-tartalom) maradnak ki. Az összegek az alapdeviza legkisebb egységében szerepelnek (forintban, illetve euró esetén centben).

Ami nincs az exportban, például a számláid, a hibabejelentéseid vagy az e-mail-kézbesítési napló, azt kérésre e-mailben elküldjük. A számláid a Beállítások → Előfizetés oldalon is elérhetők.

### Fiók törlése

Beállítások → Profil → **Fiók törlése**. A fiókot és minden adatodat azonnal töröljük, az élő Plusz előfizetést azonnal leállítjuk. A biztonsági mentésekből az adatok legkésőbb 30 nap alatt tűnnek el. A számlákat 8 évig, a panaszokat 3 évig jogszabály alapján megőrizzük.

### Kérelem benyújtása

Kérelmedet a {{operator_email}} címre küldheted, lehetőleg a fiókhoz tartozó e-mail-címről. Egy hónapon belül, díjmentesen válaszolunk. Ez indokolt esetben két hónappal meghosszabbítható, erről a határidőn belül tájékoztatunk. Ha kétségünk van a személyazonosságodat illetően, további adatot kérhetünk.

## 8. Adatbiztonság

- Titkosított kapcsolat (HTTPS) a böngésződ és a szerver között.
- Nincs jelszó, amit el lehetne lopni: rövid ideig érvényes, egyszer használható belépő linkkel vagy kóddal, illetve passkey-jel lehet belépni. A belépési kísérletek számát korlátozzuk.
- A szerver EU-s adatközpontban van, az adatbázis az internet felől nem érhető el.
- Minden lekérdezés a bejelentkezett felhasználóra szűr, így senki nem láthatja más adatait.
- A kártyaadatok soha nem érik el a szerverünket, azokat a Stripe kezeli.
- A hibakövetőbe alapból nem küldünk személyes adatot (IP-címet sem).
- Rendszeres, hozzáférés-korlátozott biztonsági mentés.

## 9. Adatvédelmi incidens

Ha adatvédelmi incidens történik, azt nyilvántartjuk, és ha kockázatot jelent, a tudomásszerzéstől számított 72 órán belül bejelentjük a NAIH-nak. Ha az incidens valószínűleg magas kockázattal jár a jogaidra, téged is haladéktalanul értesítünk, és elmondjuk, mit tehetsz.

## 10. Jogorvoslat

Ha úgy gondolod, hogy megsértettük az adatvédelmi jogaidat, kérjük, először nekünk írj, hogy orvosolhassuk. Panaszt tehetsz a felügyeleti hatóságnál is:

**Nemzeti Adatvédelmi és Információszabadság Hatóság (NAIH)**
Cím: 1055 Budapest, Falk Miksa utca 9–11. · Postacím: 1363 Budapest, Pf. 9. · Telefon: +36 1 391 1400 · E-mail: ugyfelszolgalat@naih.hu · www.naih.hu

Választásod szerint bírósághoz is fordulhatsz. A pert a lakóhelyed vagy tartózkodási helyed szerinti törvényszék előtt is megindíthatod.

## 11. Gyermekek

A Szolgáltatást 16 év alatti személy nem használhatja. Ha tudomásunkra jut, hogy 16 év alatti személy hozott létre fiókot, a fiókot töröljük.

## 12. Sütik

Csak a működéshez feltétlenül szükséges sütiket és helyi tárolást használunk. Részletek: Süti és helyi tárolás tájékoztató (moneysight.app/sutik).

## 13. A tájékoztató módosítása

A tájékoztatót a változásoknak megfelelően frissítjük. Lényeges változásról (új cél, új adatkör, új adatfeldolgozó) a hatálybalépés előtt e-mailben és az appban értesítünk. A korábbi verziókat kérésre elküldjük.
