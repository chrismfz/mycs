> **Αντίγραφο από το Ekdosi** (`docs/domains/grepp/README.md`, 2026-09-25), για αναφορά. Γράφτηκε για το Ekdosi (`company_id`, φορολογικό
> τιμολόγιο, `ServiceContract`, ανανέωση στην έκδοση)· όπου διαφωνεί, ισχύει το [`docs/domains.md`](../domains.md).
> Τα υλικά του μητρώου ήρθαν από το Ekdosi (2026-10-18, G0): XSD στο `packages/gr-epp/resources/xsd/`, τα 72 παραδείγματα v4.3
> στο `packages/gr-epp/tests/fixtures/v4.3/` (fixtures των tests), το CA bundle (HARICA, δημόσιο) στο `packages/gr-epp/resources/ca/`,
> το δείγμα client του μητρώου (`EppClient.java`, PDF) στο `docs/domains/grepp/`. Από τον ιδιοκτήτη (regweb hostmaster): ο
> **Οδηγός Υλοποίησης EPP v4.3** (`docs/domains/grepp/EPP-Guide-el-v4.3.r20250213.pdf`) και η σελίδα πρόσβασης
> (`docs/domains/grepp/access.md`), οι Συχνές Ερωτήσεις με το τι σημαίνουν για εμάς (`docs/domains/grepp/faq.md`). Το **WHOIS RESTful API v1.0** θέλει δικό του λογαριασμό και IP· η τεκμηρίωσή του είναι
> στο `https://grwhois.ics.forth.gr:800/api/spec.html` (μόνο από δηλωμένη IP: να αποθηκευτεί εδώ όταν το διαβάσουμε).
> Το WHOIS API **δεν** κάνει έλεγχο διαθεσιμότητας ούτε punycode: διαθεσιμότητα μόνο με EPP `<domain:check>`.

## Πλάνο (όπως στο ROADMAP)

| Βήμα | Τι | Κατάσταση |
|---|---|---|
| G0 | Υλικό του μητρώου (οδηγός, κανονισμός, FAQ, WHOIS API) | έγινε (#183) |
| G1 | `packages/gr-epp`: transport, session | έγινε (#184) |
| G2a/b | Εντολές και parsers: ερωτήματα / εγγραφές | έγινε (#185, #186) |
| G3 | Driver ανάγνωσης (sync) | έγινε (#187, #188) |
| G4a/b | Ανανεώσεις σε ζυγά έτη· shop, DACoR, μεταφορά χωρίς NS | έγινε (#189, #191) |
| G5a | Εγγραφή με ανάκληση, ανανέωση, επαφές `c60_` | #192 |
| G5b | Μεταφορά με αντικατάσταση των επαφών `533_`· αλλαγές NS και επαφών· **προειδοποίηση γεωγραφικού όρου** στο checkout (έγκριση ΕΕΤΤ έως 20 μέρες) | |
| G6 | WHOIS API του .gr (NS στη μεταφορά, όπως το myip.gr/clients) και import | |
| G7 | Ζωντανή δοκιμή με τον ιδιοκτήτη: δικά μας domains (cdnet.gr, nac.gr, infected.gr), εγγραφή με ανάκληση | αρχίζει μετά το #192 |
| G8 | «.gr tools»: καθαρισμός `533_`, αλλαγή ονόματος/κατόχου (χρεώσιμη), recall, DNSSEC, υπόλοιπο λογαριασμού, glue hosts | |

## 0. Αποφάσεις ιδιοκτήτη (2026-10-18)

- **Χωρίς UAT:** δοκιμές στην παραγωγή με δικά μας domains που δεν χρησιμοποιούνται (π.χ. cdnet.gr, nac.gr, infected.gr),
  μεταφερμένα στο MyCS και εξαιρεμένα από το WHMCS. Εγγραφή δοκιμής επιτρέπεται, γιατί ακυρώνεται με **ανάκληση (recall)**
  μέσα σε 5 μέρες: η ανάκληση γίνεται κουμπί.
- **Credentials:** τα live του WHMCS (EPP addon και WHOIS addon), μόνο κρυπτογραφημένα στη σύνδεση του registrar (D-21),
  ποτέ στο repo.
- **IP:** δηλωμένες στο μητρώο οι orion, rigel και το alma10 VM (mycs.myip.gr).
- **Πρόθεμα επαφών:** `c60` (π.χ. `c60_q1pcBkTq`), πεδίο της σύνδεσης (text), όπως το ζητά και το τωρινό plugin.
- **Λατινικά επαφών:** όπως τα φτιάχνει το τωρινό plugin: ζωντανά (G3) μόνο `loc`, χωρίς λατινική μορφή· έτσι και στο G5a.
- **NS στη μεταφορά:** από το WHOIS API του μητρώου (όπως το myip.gr/clients στον rigel), όχι RDAP.
- **EPP code:** κουμπί που ζητά από το μητρώο να στείλει τον κωδικό με email (DACoR)· να ελεγχθεί αν δουλεύει ακόμα.
- **Τα 1.388 .gr στο migration:** από το WHMCS, όπως όλα τα υπόλοιπα (importer D6)· όχι από το CSV του grweb. Μέχρι τότε
  ετοιμάζουμε και δοκιμάζουμε το grepp με τα δικά μας domains.
- **Ρυθμός:** baby steps, ένα PR τη φορά.
- **Επαφές `533_`** (ιδιοκτήτης 2026-10-18): το `533` ήταν το πρόθεμά μας πριν την αλλαγή επωνυμίας. Ο καταχωρητής
  διαχειρίζεται μόνο επαφές με το δικό του πρόθεμα (FAQ), οπότε οι `533_` έμειναν ορφανές: γι' αυτό σφάλματα («Μη επαρκή
  δικαιώματα») σε ενέργειες που τις αγγίζουν. Στη ζωντανή δοκιμή ο κάτοχος ήταν ήδη `c60_` με το ίδιο κομμάτι μετά το
  πρόθεμα (`c60_61mw…` / `533_61mw…`), οι ρόλοι `533_`. **G8: καθαρισμός** (δωρεάν, αλλαγή ρόλων): μέτρηση από το sync,
  dry-run με λίστα, μετά `domain:update` που προσθέτει την `c60_` (την υπάρχουσα με το ίδιο κομμάτι, ή νέα από τον
  κάτοχο) και αφαιρεί την `533_`, λίγα-λίγα, πρώτα στα δικά μας. Κάτοχος `533_` (αν βρεθεί): όχι `ownerChange`
  (χρεώσιμο): ερώτηση στο μητρώο (gr-tech@ics.forth.gr) για μαζική μετατροπή, αφού είναι ο ίδιος καταχωρητής.
- **Τιμές .gr** (ιδιοκτήτης 2026-10-18): το μητρώο δεν έχει price feed· οι τιμές έρχονται από το WHMCS (όπως ήδη
  χρεώνονται), στο Domain Pricing (import D6 ή με το χέρι). Το TLD .gr: grace 15, **redemption 0** (δεν υπάρχει στάδιο),
  τιμές μόνο για ζυγά έτη.
- **G4a (έγινε):** ανανέωση σε grace = η μικρότερη περίοδος του TLD (`Domain::stageRenewalYears`: 2 για .gr, 1 αλλού),
  στην τιμή της· ο registrar δηλώνει `renewsInGrace` (ανανέωση, όχι restore) και `renewYearStep` (2): άλλος αριθμός
  ετών δεν ζητείται ποτέ (staff). Οι υπηρεσίες διαβάζουν την κατάσταση του adapter, όχι τις λέξεις ενός registrar
  (`ACT`).
- **G4b (έγινε):** το shop πουλά κάθε TLD για τη μικρότερη περίοδό του (`DomainTld::shortestYears`: «€X / 2 έτη» για
  .gr)· «υπό περιορισμούς» (`DomainSearch::RESTRICTED`) με δικό του μήνυμα, χωρίς «Προσθήκη»· ο κωδικός EPP του .gr
  στέλνεται στον κάτοχο από το μητρώο (DACoR: `EmailsAuthCode`, «Αποστολή στον κάτοχο με email» στο portal και στο
  admin, log `auth_code_email`)· η μεταφορά .gr δεν χρειάζεται NS (`transferKeepsNameservers`: το μητρώο κρατά τους
  τωρινούς). Μετά τα reviews (#191): το shop πουλά ένα TLD μόνο όπου ο registrar του μπορεί να κάνει εγγραφή
  (`supportsRegistration`· ο driver του .gr από το G5a) και δείχνει «Μεταφέρετέ το» μόνο με τιμή μεταφοράς **1 έτους**
  (η μεταφορά δεν προσθέτει περίοδο): για .gr η τιμή μεταφοράς μπαίνει «1 έτος, 0,00». DACoR: 3 email την ώρα ανά domain.
- **G5a (έγινε):** εγγραφή, ανανέωση, ανάκληση. **Επαφές:** id `{πρόθεμα}_mcs{id επαφής σε base36}` (π.χ. `c60_mcs2s`),
  μόνο `loc`, χωρίς disclose, τηλέφωνο `+CC.NUMBER` (`Phone::epp`)· `contact:check` πριν το `contact:create` (μια
  απάντηση που χάθηκε δεν φτιάχνει δεύτερη· μια που υπάρχει ήδη διαβάζεται με `contact:info` και χρησιμοποιείται μόνο αν έχει
  το ίδιο όνομα, αλλιώς «not asked» για το staff: ADV-1 του #192), μία φορά ανά επαφή για όλους τους ρόλους, και το handle κρατιέται στο domain
  αμέσως. Handle που ήδη έχει το πρόθεμά μας χρησιμοποιείται ως έχει. **Hosts:** `host:check` (ανά 10)· εξωτερικός που
  λείπει φτιάχνεται χωρίς IP· εσωτερικός (κάτω από .gr) που λείπει → «not asked» (το glue πρώτα, G8). **Εγγραφή:** 1001
  pending με **protocol**, που κρατιέται στο `meta.registrar.protocol` από το fulfilment· status `PendingRegister` ώσπου
  το sync τη δει ενεργή. **Ανανέωση:** το `curExpDate` διαβάζεται φρέσκο από το μητρώο, μόνο ζυγά έτη. **Ανάκληση:**
  κουμπί staff «Ανάκληση της εγγραφής» στο admin (`admin.domains.manage`), μόνο ως 5 μέρες από την εγγραφή
  (μόνο εγγραφή που στάλθηκε από εδώ, `meta.sent_at`· από το `registered_at`, αλλιώς το `sent_at`), `domain:delete` με `recallApplication` και το protocol· το domain γίνεται
  Cancelled, η επιστροφή χρημάτων γίνεται χωριστά. Ό,τι αποτυγχάνει πριν τη χρέωση (κανόνες, επαφή, host) = «not
  asked»· απάντηση που χάθηκε στη χρέωση = «ίσως έγινε» (staff, ποτέ ξανά τυφλά, D-17). Μένουν για το G5b: μεταφορά με
  αντικατάσταση των επαφών (`533_`), αλλαγές NS και επαφών.
- **Ζωντανή δοκιμή (2026-10-18, G3, μόνο αναγνώσεις, από mycs.myip.gr):** login με `application/epp+xml` ✓·
  account:info (υπόλοιπο, χρεώσεις επιτρέπονται) ✓· check: κατειλημμένα «Σε χρήση», ελεύθερο «Είναι διαθέσιμο», γεωγραφικός
  όρος «Διαθέσιμο υπό περιορισμούς» / «Γεωγραφικός όρος - Άρθρο 8 του Κανονισμού.» ✓· info: `clID` = `myip` (ο καταχωρητής),
  λήξη `…T23:59:59.xZ` (UTC μέρα· το κλάσμα αλλάζει: .9, .0) ✓, `trDate` όταν ήρθε σε εμάς, `crID` ο αρχικός καταχωρητής· **οι επαφές έχουν μόνο
  ελληνική μορφή (`loc`)**, χωρίς λατινική και χωρίς disclose (έτσι τις φτιάχνει το τωρινό module)· σε domains που ήρθαν με
  μεταφορά, admin/tech/billing με πρόθεμα **`533_`** (άλλου καταχωρητή): αντικατάσταση στο G5. Οι απαντήσεις,
  ανωνυμοποιημένες, στο `packages/gr-epp/tests/fixtures/live/`.
- **Για το G5** (από το review του G3, #187): το `DomainRenewalService` διαβάζει τα `rawStatus` του Openprovider (`ACT`/`DEL`)·
  για .gr θέλει τα δικά του (`ok`, statuses). Η ανανέωση σε grace τιμολογείται 1 έτος (D-13): για .gr **2 έτη** (ζυγά).
  Το TLD .gr χωρίς redemption days (δεν υπάρχει στάδιο redemption· grace 15). Στο shop το «υπό περιορισμούς»
  (`restricted:` στο reason) να φαίνεται ως τέτοιο, όχι «κατειλημμένο». Το `clID` του `domain:info` συγκρίνεται με το
  EPP username: ζωντανά (2026-10-18) το `clID` είναι ο **καταχωρητής** (`myip`), όχι ο χρήστης (`myip1`)· διορθώθηκε με
  πεδίο «Registrar id» στη σύνδεση (κενό: το username χωρίς τα ψηφία).
- **Μία σύνδεση «grepp»** στο MyCS (όχι δύο plugins όπως στο WHMCS): EPP username (`myip1`) και password, registrar id
  (`myip`, το `clID` του μητρώου· κενό: το username χωρίς τα ψηφία· να συμπληρώνεται αν το username δεν είναι έτσι), πρόθεμα επαφών
  (`c60`), WHOIS API username (`myip4`) και password (ξεχωριστός λογαριασμός του μητρώου), περιβάλλον. Τα passwords
  κρυπτογραφημένα. Τα license keys των modules του WHMCS δεν χρειάζονται.
- **Από τις ρυθμίσεις του grEPP:** *Geo* (γεωγραφικοί όροι: έγκριση ΕΕΤΤ έως 20 μέρες) → προειδοποίηση στο checkout (G5)·
  *NoTransferAwayEmails* → το έχουμε (TransferredAway από το sync), μένει ποιος παίρνει email· *ForceSync* → το καλύπτει
  το sync σε παρτίδες (ROADMAP).
- **G8 «.gr tools»** (ιδιοκτήτης 2026-10-18, μετά το G5): ό,τι κάνουμε σήμερα στο grweb, κουμπιά στο MyCS μέσω EPP.
  - *Ανά domain* (σελίδα domain στο admin· στο portal μόνο τα ασφαλή): στοιχεία μητρώου (καταστάσεις και ΕΕΤΤ,
    πρωτόκολλο, μορφές .gr/.ελ, έλεγχος DNSSEC)· email επαναφοράς κωδικού εξουσιοδότησης (DACoR, staff και πελάτης)·
    ανάκληση καταχώρησης έως 5 μέρες (staff, με επιστροφή χρημάτων)· αλλαγή επωνυμίας / μεταβίβαση (χρεώσιμες)·
    ενεργοποίηση/διαγραφή δεσμευμένης μορφής (.ελ, ομόγραφα) και αλλαγή τύπου· DNSSEC DS· αντικατάσταση επαφών άλλου
    καταχωρητή μετά από μεταφορά (αυτόματα)· διαγραφή (μη αναστρέψιμη, staff)· ΥΑΑ/grRLS (350 € + ΦΠΑ) αργότερα.
  - *Λογαριασμός* (σελίδα «.gr registry» στο admin): υπόλοιπο, αν επιτρέπονται χρεώσεις, ημερομηνία αναστολής (widget
    και ειδοποίηση πριν τελειώσει)· nameservers .gr (glue: δημιουργία, αλλαγή IP)· στοιχεία επαφής τρίτου καταχωρητή
    (ROID και κωδικός domain, πριν από μεταφορά)· έλεγχος διαθεσιμότητας με «υπό περιορισμούς»· αλλαγή κωδικού EPP.
  - *Μόνο στο grweb* (όχι στο EPP): αποστολή στοιχείων ονόματος χώρου (PDF στον δικαιούχο), αλλαγή IP καταχωρητή,
    χρήστες, στατιστικά: σύνδεσμος στο grweb.
- **Στοιχεία κατόχου** (ιδιοκτήτης 2026-10-18, G4/G5, χρήματα): σε επαφή-κάτοχο .gr αλλάζουν χωρίς χρέωση τηλέφωνο, email,
  διεύθυνση (`contact:update`)· **όχι** το ονοματεπώνυμο/επωνυμία. Αυτά είναι χρεώσιμες πράξεις ανά domain: **αλλαγή
  επωνυμίας** (`ownerNameChange`, ο ίδιος κάτοχος) και **μεταβίβαση** (`ownerChange`, άλλος κάτοχος). Γραμμή τιμολογίου
  με τιμή του TLD (νέες πράξεις στον τιμοκατάλογο), αποστολή στο μητρώο μετά την πληρωμή (όπως D8b-2), ρητή επιβεβαίωση
  (μη ανακλήσιμες)· .gov.gr και γεωγραφικοί όροι: έγκριση ΕΕΤΤ, έως 30 μέρες πριν τη λήξη. Στο portal το όνομα επαφής
  που είναι κάτοχος .gr κλειδώνει, με εξήγηση και κουμπί για την πράξη.
- **Το τωρινό module (ionCube, κλειστό):** μάλλον δεν αποθηκεύει τίποτα και ρωτά το μητρώο συνέχεια (εξηγεί και την
  αργή σελίδα με πολλά domains). Εμείς κρατάμε κατάσταση, λήξη, NS και κωδικούς επαφών στη βάση· το μητρώο μόνο στο sync,
  σε ενέργεια και πριν από χρέωση. Από το module παίρνουμε μόνο δεδομένα και μορφή (βάση του WHMCS, `contact:info`).

# .gr / .ελ Registry (ICS-FORTH) — Οδηγός Υλοποίησης EPP

Τεχνικές σημειώσεις για το registrar plugin μας. Πηγή: **Οδηγός Υλοποίησης EPP v4.3**
του Μητρώου (παραδείγματα XML + XSD), το reference `EppClient.java` του Μητρώου, και
τα δημόσια κείμενα του `grweb.ics.forth.gr`.

> **Κατάσταση**: γραμμένο πριν την πρώτη σύνδεση σε UAT. Ό,τι είναι σημειωμένο
> `[ΕΠΙΒΕΒΑΙΩΣΗ]` πρέπει να ελεγχθεί ζωντανά πριν βασιστούμε πάνω του.

---

## 1. Πρόσβαση & προϋποθέσεις

- Διαπίστευση καταχωρητή μέσω **ΕΕΤΤ** (η ΕΕΤΤ τηρεί το επίσημο μητρώο καταχωρητών).
- **IP whitelisting**: όλες οι υπηρεσίες προς καταχωρητές είναι προσβάσιμες μόνο από
  στατικές IP δηλωμένες στο σύστημα του Μητρώου. Τουλάχιστον μία ανά καταχωρητή.
  → Δήλωσε **και** την IP του staging, αλλιώς το UAT δεν δουλεύει από dev μηχάνημα.
- Δύο περιβάλλοντα: production και testing (UAT), με ξεχωριστά credentials.
- Επικοινωνία: `hmaster-info@ics.forth.gr`, +30 2810 391450.

### Endpoints

| Περιβάλλον | URL |
|---|---|
| UAT | `https://uat-regepp.ics.forth.gr:700/epp/proxy` |
| Production | `https://regepp.ics.forth.gr:700/epp/proxy` |

Βοηθητικά (εκτός EPP, για availability checks χωρίς session):

```
https://grwhois.ics.forth.gr:800/plainwhois/plainWhois?domainName=
https://grwhois.ics.forth.gr:800/plainDomainCheck?domainName=
https://uat-grwhois.ics.forth.gr:800/plainwhois/plainWhois?domainName=
https://uat-grwhois.ics.forth.gr:800/plainDomainCheck?domainName=
```

Το `plainWhois` επιστρέφει HTML (όχι JSON) — να ζητηθεί το τρέχον WHOIS REST API doc
πριν βασιστούμε σε parsing. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

---

## 2. Transport — ΤΟ ΠΙΟ ΣΗΜΑΝΤΙΚΟ ΣΗΜΕΙΟ

**Δεν είναι RFC 5734 EPP over TCP/700.** Είναι **XML Web service πάνω από HTTPS**.
Το `:700` είναι απλώς η πόρτα του HTTPS listener. Οποιαδήποτε έτοιμη EPP library
που κάνει socket + 4-byte length framing **δεν δουλεύει**.

Χαρακτηριστικά (από το `EppClient.java` του Μητρώου):

- `POST` του EPP XML στο `/epp/proxy`
- `Content-Type`: ο **Οδηγός v4.3** (αλλαγές, σημ. 8) ορίζει ότι η μόνη επιτρεπτή τιμή, αν δοθεί, είναι
  `application/epp+xml;charset=UTF-8`· το δείγμα client (`EppClient.java`) στέλνει `text/xml;charset=UTF-8`. Το G1
  στέλνει του Οδηγού (ρυθμίζεται στο `Config`)· **επιβεβαιώθηκε ζωντανά** (2026-10-18): το μητρώο το δέχεται.
- Μόνο χαρακτήρες GREEK_AND_COPTIC, GREEK_EXTENDED, BASIC_LATIN (Οδηγός, σημ. 9): ό,τι άλλο απορρίπτεται (G2).
- **Ημερομηνίες:** το μητρώο γράφει τη λήξη ως `23:59:59.xZ` της μέρας της (το κλάσμα αλλάζει). Κρατάμε τη μέρα **όπως τη γράφει**
  (`DomainInfo::expiresOn`), ποτέ μετατρεπμένη στη ζώνη της εφαρμογής (Europe/Athens θα την έκανε την επόμενη).
  Τα συστήματα του μητρώου λειτουργούν σε **UTC** (FAQ «Ώρα Λειτουργίας»): η μέρα του μητρώου είναι η UTC μέρα.
  Μια λήξη `2026-11-01T23:59:59.9Z` σημαίνει ότι το όνομα λήγει 02:00 (χειμώνας) / 03:00 (καλοκαίρι) ώρα Ελλάδας της 2/11:
  ως τότε η ανανέωση γίνεται δεκτή.
- **«Διαθέσιμο υπό περιορισμούς»** (γεωγραφικοί όροι, .gov.gr): `avail="0"` αλλά όχι «κατειλημμένο» (`CheckResult::restricted`,
  από το κείμενο του λόγου· η αγγλική διατύπωση **[ΕΠΙΒΕΒΑΙΩΣΗ]**). Όρια ανά check: 7 domains, 3 επαφές, 10 hosts.
- Session state με **cookie `JSESSIONID`**
- Το cookie έρχεται στο `Set-Cookie` και μπορεί να έχει πολλαπλές τιμές χωρισμένες
  με `;` — κρατάμε **μόνο το πρώτο κομμάτι** (`servCook.split(";")[0]`)
- Το reference client κάνει πρώτα ένα "priming" request για να πάρει cookie, και
  μετά στέλνει το `login`. Με `http.Client` + cookie jar γίνεται αυτόματα.
- `hello` χρησιμοποιείται ως **keep-alive** για να μη λήξει η συνεδρία.

### Κύκλος ζωής συνεδρίας

```
POST (κενό/hello)  → Set-Cookie: JSESSIONID=...
POST login.xml     + Cookie: JSESSIONID=...   → 1000
POST <commands>    + Cookie: JSESSIONID=...
POST hello.xml     + Cookie: ...              (keep-alive, όποτε χρειάζεται)
POST logout.xml    + Cookie: ...              → 1500 "ending session"
```

**Συνέπεια για την υλοποίηση**: μία σύνδεση = ένα session = σειριακά commands.
Χρειάζεται lock/mutex ανά session· μην μοιράζεσαι cookie ανάμεσα σε παράλληλα jobs.

### TLS

Server certs από **HARICA** (μέσω GÉANT TCS):

- Root: `HARICA TLS RSA Root CA 2021` (RSA 4096, λήξη 2045-02-13)
- Intermediate: `GEANT TLS RSA 1` (RSA 3072, έκδοση 2025-01-03, λήξη 2039-12-31)

Το root υπάρχει ήδη στα system trust stores (Mozilla/Microsoft/Apple/`ca-certificates`),
οπότε **δεν χρειάζεται** custom CA bundle. Αν κάνουμε pinning για επιπλέον ασφάλεια:
πίναρε το **root**, ποτέ leaf fingerprint, και βάλε alerting για αλλαγή αλυσίδας.

Το `ca-bundle.pem` του Μητρώου = intermediate + root (σε σωστή σειρά).

---

## 3. Namespaces

### Standard (IETF)

```
urn:ietf:params:xml:ns:epp-1.0
urn:ietf:params:xml:ns:domain-1.0
urn:ietf:params:xml:ns:contact-1.0
urn:ietf:params:xml:ns:host-1.0
urn:ietf:params:xml:ns:eppcom-1.0
urn:ietf:params:xml:ns:secDNS-1.1      (DNSSEC, RFC 5910)
```

### FORTH extensions

| Namespace | Χρήση |
|---|---|
| `urn:ics-forth:params:xml:ns:extdomain-1.3` | Ο πυρήνας — create/check/renew/transfer/update/delete + regulator statuses + bundles |
| `urn:ics-forth:params:xml:ns:account-1.1` | Υπόλοιπο λογαριασμού καταχωρητή |
| `urn:ics-forth:params:xml:ns:extcommon-1.0` | Σχόλια σε responses (π.χ. login) |
| `urn:ics-forth:params:xml:ns:extcontact-1.0` | Σχόλια σε contact responses |
| `urn:ics-forth:params:xml:ns:exthost-1.0` | Σχόλια σε host responses |
| `urn:ics-forth:params:xml:ns:extSecDNS-1.0` | Αποτέλεσμα επαλήθευσης DS (read-only) |
| `urn:ics-forth:params:xml:ns:dacor-1.0` | Domain Authorization Code Reset |
| `urn:ics-forth:params:xml:ns:ext-gr-rls-1.0` | Υπηρεσία Αυξημένης Ασφάλειας (Registry Lock) |

> ⚠️ **Το `extdomain` είναι 1.3, όχι 1.2.** Παλιότερος κώδικας που κυκλοφορεί
> (π.χ. το WHMCS module `emavro/eppgr` του 2018) χρησιμοποιεί το 1.2, που έχει
> **διαφορετική δομή** στα `create` / `renew` / `transfer`. Μην τον αντιγράψεις τυφλά.

### ⚠️ Χωρίς `<svcExtension>` στο login

Το επίσημο `03-login-request.xml` δηλώνει **μόνο** τα τρία `objURI` και **κανένα**
`<svcExtension>`. Το greeting επίσης διαφημίζει μόνο `objURI`.

```xml
<svcs>
  <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
  <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
  <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
</svcs>
```

Οι extensions στέλνονται απευθείας μέσα στο `<extension>` κάθε command, χωρίς
προδήλωση. **Αν χρησιμοποιήσουμε metaregistrar, μην καλέσουμε `useExtension()`** —
προσθέτει `<svcExtension>` στο login και πιθανότατα θα απορριφθεί. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

### Greeting (`hello` response)

```xml
<greeting>
  <svID>.gr and .ελ ccTLD EPP Service</svID>
  <svcMenu>
    <version>1.0</version>
    <lang>en</lang><lang>el</lang>
    <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
    <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
    <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
  </svcMenu>
  ...
</greeting>
```

Γλώσσα μηνυμάτων: `el` ή `en` μέσω `<options><lang>`. Τα μηνύματα σφάλματος
έρχονται στη γλώσσα που ζητήσαμε — **χρήσιμο**: με `lang=el` μπορούμε να δείξουμε
το μήνυμα του Μητρώου κατευθείαν στον πελάτη.

---

## 4. Τι ΔΕΝ υποστηρίζεται

### `<poll>` — μη υλοποιημένο

```xml
<result code="2101"><msg>Μη υλοποιημένη εντολή</msg></result>
```

**Δεν υπάρχει message queue.** Κάθε ασύγχρονη κατάσταση πρέπει να ανιχνεύεται με
**polling μέσω `domain:info`**:

- εγκρίσεις ΕΕΤΤ (`pendingRegulatorApproval`)
- ολοκλήρωση `ownerChange` σε .gov.gr / γεωγραφικά
- επαλήθευση DS records (μένουν σε `serverUpdateProhibited` μέχρι να ολοκληρωθεί)
- εκκρεμείς μεταφορές

→ Scheduled job με backoff. Σχεδίασέ το από την αρχή, δεν είναι patch.

---

## 5. Command reference

### 5.1 Session

**login** — `<pw>` σε CDATA. Αλλαγή κωδικού με `<newPW>` μέσα στο ίδιο login:

```xml
<login>
  <clID>my-username</clID>
  <pw>current-password</pw>
  <newPW>new-password</newPW>
  <options><version>1.0</version><lang>el</lang></options>
  <svcs>...</svcs>
</login>
```

Το login response φέρνει `extcommon:comment` («Καλώς ήλθατε στο Μητρώο.»).

**logout** → `1500 Command completed successfully; ending session`.

---

### 5.2 Domain check

Απλό check:

```xml
<domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
  <domain:name>forth.gr</domain:name>
  <domain:name>forth.gov.gr</domain:name>
</domain:check>
```

Με extension (έλεγχος στο πλαίσιο συγκεκριμένου δικαιούχου):

```xml
<extension>
  <extdomain:check xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_cont0343</extdomain:registrantid>
  </extdomain:check>
</extension>
```

Response: `domain:cd/domain:name@avail` + `domain:reason` (ελληνικά), και
`extdomain:comment ref="forth.gr"` ανά όνομα.

---

### 5.3 Domain info

Το response φέρνει, εκτός των standard, στο `<extension>`:

- `extdomain:status s="..."` — **regulator statuses** (βλ. §6)
- `extdomain:protocol` — αριθμός πρωτοκόλλου δήλωσης
- `extdomain:punycode` — για IDN
- `extdomain:bundle/bundleName` με `chargeable` και `recordType`
- `extSecDNS:lastValidationResult` — `wasSuccessful`, `validationOutcome`, `validationDate`

---

### 5.4 Domain create

```xml
<domain:create>
  <domain:name>forth.gr</domain:name>
  <domain:period unit="y">2</domain:period>
  <domain:ns><domain:hostObj>ns1.forth.gr</domain:hostObj></domain:ns>
  <domain:registrant>reg_contact001</domain:registrant>
  <domain:contact type="tech">reg_contact002</domain:contact>
  <domain:contact type="admin">reg_contact003</domain:contact>
  <domain:contact type="billing">reg_contact004</domain:contact>
  <domain:authInfo><domain:pw/></domain:authInfo>
</domain:create>
```

Response `1001` (**εκκρεμούν ενέργειες** — όχι 1000!) με:

```xml
<extdomain:resData>
  <extdomain:protocol>66155</extdomain:protocol>
  <extdomain:comment>Αριθμός Πρωτοκόλλου Αίτησης 66155: Προς Έγκριση...</extdomain:comment>
</extdomain:resData>
```

**Κράτα το `protocol`** — χρειάζεται για `recallApplication`.

#### Homograph (.ελ / IDN bundle)

```xml
<domain:create>
  <domain:name>ιτέ.gr</domain:name>
  <domain:authInfo>
    <!-- authInfo του ΗΔΗ εκχωρημένου ονόματος -->
    <domain:pw>domain-auth-code</domain:pw>
  </domain:authInfo>
</domain:create>
...
<extension>
  <extdomain:create xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:record>dname</extdomain:record>
  </extdomain:create>
</extension>
```

`extdomain:record` ∈ `{domain, dname}`. Το `dname` = ενεργοποίηση δεσμευμένης
ομόγραφης μορφής (DNAME record). Είναι **χρεώσιμο** (`chargeable="1"`).

---

### 5.5 Domain renew

```xml
<domain:renew>
  <domain:name>forth.gr</domain:name>
  <domain:curExpDate>2012-03-19</domain:curExpDate>
  <domain:period unit="y">6</domain:period>
</domain:renew>
```

Όταν ανανεώνουμε **ληγμένο όνομα άλλου καταχωρητή** (μεταφορά μέσω ανανέωσης):

```xml
<extension>
  <extdomain:renew xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_newRegistrnt</extdomain:registrantid>
    <extdomain:currentPW>domain-auth-code</extdomain:currentPW>
  </extdomain:renew>
</extension>
```

⚠️ Στο 1.3 **δεν υπάρχει πια `newPW`** εδώ (υπήρχε στο 1.2).

---

### 5.6 Domain transfer

```xml
<transfer op="request">
  <domain:transfer>
    <domain:name>forth.gr</domain:name>
    <domain:authInfo><domain:pw>123123</domain:pw></domain:authInfo>
  </domain:transfer>
</transfer>
<extension>
  <extdomain:transfer xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_contact001</extdomain:registrantid>
  </extdomain:transfer>
</extension>
```

⚠️ Μόνο `registrantid`. Το `newPW` του 1.2 έφυγε.

---

### 5.7 Domain update

**Standard** (nameservers, contacts) — χωρίς extension:

```xml
<domain:update>
  <domain:name>forth.gr</domain:name>
  <domain:add>
    <domain:ns><domain:hostObj>ns2.forth.gr</domain:hostObj></domain:ns>
    <domain:contact type="tech">reg_contact003</domain:contact>
  </domain:add>
  <domain:rem>
    <domain:ns><domain:hostObj>ns1.forth.gr</domain:hostObj></domain:ns>
    <domain:contact type="tech">reg_contact001</domain:contact>
  </domain:rem>
</domain:update>
```

**Αλλαγή δικαιούχου** — `<domain:chg><domain:registrant>` + extension `op`:

| `extdomain:op` | Σημασία | Εκτέλεση |
|---|---|---|
| `ownerChange` | Μεταβίβαση σε **άλλο** πρόσωπο | Άμεση, **εκτός** .gov.gr / γεωγραφικών (εκεί `1001` + έγκριση ΕΕΤΤ). **Μη ανακλήσιμη.** |
| `ownerNameChange` | Αλλαγή **στοιχείων** του ίδιου δικαιούχου | Άμεση για όλα. **Μη ανακλήσιμη.** |

**Αλλαγή τύπου εγγραφής σε bundle** (ποιο είναι domain και ποιο dname):

```xml
<extdomain:update>
  <extdomain:chg>
    <extdomain:record>
      <extdomain:recordType type="domain">ίτε.gr</extdomain:recordType>
      <extdomain:recordType type="dname">ιτέ.gr</extdomain:recordType>
    </extdomain:record>
  </extdomain:chg>
</extdomain:update>
```

**Regulator statuses**: `<extdomain:add>` / `<extdomain:rem>` με `<extdomain:status>`
— διαχειρίζεται η ΕΕΤΤ, όχι εμείς.

---

### 5.8 Domain delete

```xml
<extdomain:delete xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
  <extdomain:pw>domain-auth-code</extdomain:pw>   <!-- ή <extdomain:protocol> -->
  <extdomain:op>deleteDomain</extdomain:op>
  <extdomain:reason>...</extdomain:reason>         <!-- προαιρετικό, ≤256 χαρ. -->
</extdomain:delete>
```

`op` ∈ `{deleteDomain, deleteHomograph, recallApplication}`:

- `deleteDomain` — διαγραφή, με `pw`. **Άμεση, μη ανακλήσιμη.**
- `deleteHomograph` — αφαίρεση της ομόγραφης μορφής.
- `recallApplication` — **ανάκληση δήλωσης** που είναι ακόμη `pendingCreate`,
  με `<extdomain:protocol>` αντί για `pw`. Άμεση.

---

### 5.9 Contact

- Δύο `postalInfo`: `type="loc"` (ελληνικά) **και** `type="int"` (λατινικά).
- `<contact:voice x="1450">+30.2810391400</contact:voice>` — το `x` είναι extension.
- **ID protection** = `<contact:disclose flag="0">` με λίστα πεδίων προς απόκρυψη.
- Contact IDs έχουν prefix καταχωρητή, μορφή `<prefix>_<id>` (π.χ. `reg_contact001`).
  Έτσι ξεχωρίζουμε δικές μας επαφές από ξένες — μετά από incoming transfer οι ξένες
  επαφές πρέπει να αντικατασταθούν. `[ΕΠΙΒΕΒΑΙΩΣΗ ποιο είναι το δικό μας prefix]`
- `contact:status s="serverDeleteProhibited"` όταν η επαφή χρησιμοποιείται.

---

### 5.10 Host

Standard `host-1.0`. `<host:addr ip="v4|v6">`, default `v4`. Το `host:update`
υποστηρίζει `add` / `rem` / `chg` (μετονομασία).

---

### 5.11 Account info (υπόλοιπο καταχωρητή)

```xml
<account:info xmlns:account="urn:ics-forth:params:xml:ns:account-1.1"/>
```

Response:

```xml
<account:infData>
  <account:roid>ca0009...-gr</account:roid>
  <account:ddPaymentCode>RF00000000000000000000000</account:ddPaymentCode>
  <account:caAllowed>true</account:caAllowed>
  <account:balance currency="EUR">-25</account:balance>
  <account:tbSuspendedOn>2012-01-16T10:00:00Z</account:tbSuspendedOn>
  <account:suspendedOn>...</account:suspendedOn>
</account:infData>
```

⚠️ Το **balance μπορεί να είναι αρνητικό**. Τα `tbSuspendedOn` / `suspendedOn`
δείχνουν επικείμενη / ενεργή αναστολή λογαριασμού.

→ **Monitoring job**: `account:info` σε τακτά διαστήματα, alert κάτω από όριο και
σε οποιοδήποτε `suspendedOn`. Αν μας αναστείλουν, σταματάνε όλα τα registrations.

---

### 5.12 DACoR — Domain Authorization Code Reset

Λύνει το «ο πελάτης έχασε το authInfo». Στέλνεται μέσα σε `domain:update`:

```xml
<update>
  <domain:update><domain:name>example.gr</domain:name></domain:update>
</update>
<extension>
  <dacor:issueToken xmlns:dacor="urn:ics-forth:params:xml:ns:dacor-1.0"/>
</extension>
```

Προαιρετικά με `<dacor:email>` και `<dacor:ref>` (10–256 χαρ.).
Το token πάει στο email του δικαιούχου.

→ Κουμπί στο panel του πελάτη. Γλιτώνει tickets.

---

### 5.13 grRLS — Υπηρεσία Αυξημένης Ασφάλειας (Registry Lock)

Namespace: `urn:ics-forth:params:xml:ns:ext-gr-rls-1.0`

**Είναι συνδρομητική υπηρεσία** με `period` / `exDate` / `autoRenew` — δηλαδή
χρεώσιμο προϊόν, όχι απλό flag. Εμπορική ευκαιρία: ελάχιστοι Έλληνες καταχωρητές
το προσφέρουν καθαρά.

Elements: `create`, `renew`, `update`, `info` (+ `creData`, `renData`, `infData`).

**Operations** (`op` attribute):

| op | Σημασία |
|---|---|
| `activateService` | Ενεργοποίηση υπηρεσίας |
| `deactivateService` | Απενεργοποίηση |
| `cancelServiceDeactivation` | Ακύρωση απενεργοποίησης |
| `lock` | Κλείδωμα |
| `unlock` | Ξεκλείδωμα |
| `cancelUnlock` | Ακύρωση αιτήματος ξεκλειδώματος |

**Τι κλειδώνει** (`object`): `domain` (και τα subordinate hosts) | `registrant`
(η επαφή δικαιούχου) | `all` | `none`

**Κατάσταση υπηρεσίας**: `active` | `inactive` | `pendingCreate` | `pendingDeactivation`

**Κατάσταση αιτήματος**: `pendingAuthorisation` | `authorised` | `authorisationOverridden`

**Χρονικό παράθυρο αλλαγών** — το ξεκλείδωμα δεν είναι στιγμιαίο, ορίζεις παράθυρο:

```xml
<grRLS:chgWindow>
  <grRLS:timeSlotStarts at="..."/>
  <grRLS:timeSlotEnds at="..."/>
</grRLS:chgWindow>
```

**`info` με `crl="true"`**: επιστρέφει `registrantLockHeldBy` — ποια **άλλα** domains
(έως 200) κρατούν lock στην ίδια επαφή δικαιούχου. Σημαντικό: ένα registrant lock
επηρεάζει όλα τα domains που μοιράζονται την επαφή.

⚠️ Κλειδωμένο domain θα **απορρίπτει** update/transfer/delete. Το error handling
πρέπει να το ξεχωρίζει από γενικό authorization error, αλλιώς ο πελάτης βλέπει
ακατανόητο μήνυμα.

---

### 5.14 DNSSEC

- Οι DS εγγραφές πάνε με **standard `secDNS-1.1`** (RFC 5910) — όχι custom.
  Άρα υπάρχει έτοιμη υποστήριξη σε libraries.
- Το αποτέλεσμα επαλήθευσης έρχεται read-only στο `extSecDNS-1.0`:
  `wasSuccessful`, `validationOutcome` (κείμενο στα ελληνικά), `validationDate`.
- ⚠️ Ονόματα με **εκκρεμή επαλήθευση DS μένουν σε `serverUpdateProhibited`**
  μέχρι να ολοκληρωθεί. Άλλος ένας λόγος για polling.

---

## 6. Statuses

### Regulator statuses (ΕΕΤΤ)

Διαχειρίζονται **αποκλειστικά** από την ΕΕΤΤ και **υπερισχύουν** των `server` statuses.
Έρχονται ως `<extdomain:status s="...">`:

```
pendingRegulatorApproval          εκκρεμεί έγκριση (γεωγραφικά / .gov.gr)
regulatorUpdateProhibited
regulatorDeleteProhibited
regulatorTransferProhibited
regulatorRenewProhibited
regulatorHold
regulatorOwnerChangeProhibited
regulatorOwnerNameChangeProhibited
regulatorExpirationProhibited
regulatorDelegationChangeProhibited
regulatorDebtOwnership            καθεστώς οφειλής
```

→ Χαρτογράφησέ τα **όλα** σε ανθρώπινα μηνύματα στο UI.

### Standard EPP statuses που μας αφορούν

- `pendingCreate` — η δήλωση δεν έχει εγκριθεί ακόμη· επιτρέπεται `recallApplication`
- `pendingDelete` — μετά τη λήξη (grace period)
- `serverUpdateProhibited` — π.χ. εκκρεμής επαλήθευση DS
- `inactive` — χωρίς nameservers

---

## 7. Result codes που έχουμε δει

| Code | Σημασία |
|---|---|
| `1000` | Επιτυχία |
| `1001` | Επιτυχία, **εκκρεμούν ενέργειες** — το κανονικό για `create` |
| `1500` | Επιτυχία, τερματισμός συνεδρίας (logout) |
| `2101` | Μη υλοποιημένη εντολή (π.χ. `poll`) |

⚠️ Μην θεωρήσεις `1001` ως αποτυχία. Είναι η **αναμενόμενη** απάντηση σε create.

---

### Μηνύματα από την παραγωγή (WHMCS activity log, 2 χρόνια — `../whmcs-baseline.md` §8)
Οι κωδικοί ήταν μασκαρισμένοι, κρατάμε το κείμενο του μητρώου → οδηγία για τον operator στο A4:

| Μήνυμα μητρώου | Πού | Φορές | Συνήθως σημαίνει |
|---|---|---:|---|
| «Μη επαρκή δικαιώματα» | transfer 19 · renew 2 · NS 2 | 23 | λάθος/ληγμένος κωδικός· ή το όνομα δεν είναι (πια) σε εμάς |
| «Λάθος ταυτοποίηση χρήστη» | renew | 9 | login/credentials του καταχωρητή |
| «Η αποδεκτή διάρκεια καταχώρησης … είναι τα δύο (2) έτη» | register | 5 | λάθος περίοδος (βλ. §8) |
| «Η κατάσταση του αντικειμένου απαγορεύει την ενέργεια» | transfer | 3 | status του ονόματος μπλοκάρει |
| «Το αντικείμενο δεν υπάρχει» | transfer | 2 | λάθος όνομα / δεν υπάρχει |
| «Αιτήματα αλλαγής καταχωρητή … μόνο σε ονόματα που δε βρίσκονται στη διαχείρισή σας» | transfer | 2 | ήδη δικό μας |
| «Δεν έχουν συμπληρωθεί τα απαραίτητα στοιχεία του προσώπου επαφής» | register | 2 | ελλιπής επαφή |

## 8. Επιχειρησιακοί κανόνες

- **Διαδικασία εκχώρησης**: το όνομα ελέγχεται αυτόματα, **ενεργοποιείται εντός 3 ωρών**
  και **εκχωρείται με την παρέλευση 5 ημερών** από την υποβολή.
- **.gov.gr και γεωγραφικοί όροι**: δεν ενεργοποιούνται προσωρινά — εξετάζονται από
  την ΕΕΤΤ και εγκρίνονται/απορρίπτονται **εντός 20 ημερών**.
- **Ισχύον πλαίσιο**: απόφαση ΕΕΤΤ **1110/6/29-04-2024**.
- 2ου επιπέδου: `com.gr`, `edu.gr`, `net.gr`, `org.gr`, `gov.gr` (+ `co.gr` ιστορικά).
- **Περίοδος**: τα παραδείγματα δείχνουν `unit="y"` με τιμές 2 και 6. Ο παλιός κώδικας
  επέβαλλε **άρτια πολλαπλάσια, 2–10 έτη**. Τα δεδομένα της WHMCS το επιβεβαιώνουν: 1381 .gr domains
  αποκλειστικά σε 2/4/6/8 έτη (`../whmcs-baseline.md` §3)· το 10 δεν εμφανίζεται. `[ΕΠΙΒΕΒΑΙΩΣΗ του 10 στον Οδηγό v4.3]`
- Ονόματα 2 χαρακτήρων έχουν ειδικούς κανόνες. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

---

## 9. Παγίδες υλοποίησης — checklist

- [ ] **Μην** χρησιμοποιήσεις EPP library που κάνει TCP/700 framing
- [ ] `Content-Type: text/xml;charset=UTF-8` (όχι `application/epp+xml`)
- [ ] Cookie `JSESSIONID`, κόψε στο πρώτο `;`
- [ ] **Μην** στείλεις `<svcExtension>` στο login
- [ ] Ένα session = σειριακά commands (mutex)
- [ ] `hello` ως keep-alive σε μακροχρόνια sessions
- [ ] `poll` **δεν** υπάρχει → polling με `domain:info`
- [ ] `1001` = επιτυχία, όχι σφάλμα
- [ ] Κράτα το `extdomain:protocol` κάθε create (χρειάζεται για recall)
- [ ] Δύο `postalInfo` (loc + int) σε κάθε contact
- [ ] UTF-8 παντού· προσοχή σε IDNA/punycode και normalization ελληνικών
- [ ] `ownerChange` / `ownerNameChange` / `deleteDomain` είναι **μη ανακλήσιμα** —
      βάλε επιβεβαίωση στο UI
- [ ] Monitoring `account:balance` και `suspendedOn`
- [ ] Χειρισμός grRLS locks στα error paths
- [ ] Δήλωσε **όλες** τις IP (prod, staging, dev) στο Μητρώο

---

## 10. Αρχιτεκτονική (Laravel)

Ενοποίηση σε επίπεδο **registrar driver**, όχι σε επίπεδο πρωτοκόλλου — γιατί για
όλα τα άλλα TLD (.com/.net/.org/.eu) πάμε μέσω **OpenProvider REST**, όχι EPP.

```
DomainRegistrar (interface)
├── check(domain): Availability
├── register(DomainOrder): Result
├── renew(domain, period)
├── transfer(domain, authInfo)
├── delete(domain, reason)
├── getNameservers / setNameservers
├── getContacts / updateContacts
└── getInfo(domain): DomainInfo

ForthEppDriver          → EPP/HTTPS + extdomain-1.3 + grRLS + dacor
OpenproviderRestDriver  → https://api.openprovider.eu/v1, bearer token
```

Γιατί όχι «όλα EPP»: ο OpenProvider έχει EPP, αλλά είναι shim πάνω από το REST τους,
καλύπτει μόνο domains+contacts (όχι SSL/licenses), και οι ίδιοι συνιστούν το REST.

Κοινά (γράφονται μία φορά): DTOs, jobs, retries, sync expiry dates, billing hooks,
audit log. Τα .gr-specific (2ετίες, homographs, grRLS, regulator statuses) ζουν
μέσα στον `ForthEppDriver`.

### Jobs που χρειαζόμαστε

| Job | Συχνότητα | Τι κάνει |
|---|---|---|
| `PollPendingDomains` | κάθε 15–30' | `domain:info` σε ό,τι είναι `pendingCreate` / `pendingRegulatorApproval` |
| `PollDsValidation` | ωριαία | ονόματα σε `serverUpdateProhibited` λόγω DS |
| `SyncExpiryDates` | ημερήσια | ευθυγράμμιση `exDate` με το billing |
| `MonitorAccountBalance` | ωριαία | `account:info`, alert σε χαμηλό/αρνητικό ή `suspendedOn` |

---

## 11. Επιλογές βιβλιοθήκης

| Package | HTTP transport | Κρίση |
|---|---|---|
| `metaregistrar/php-epp-client` | **Ναι** (`eppHttpsConnection`, curl + cookies) | Η μόνη έτοιμη· παλιάς σχολής κώδικας |
| `struzik-vladislav/epp-client` | Όχι (socket/RabbitMQ) | Καθαρότερο, PSR-3, έτοιμα secDNS/IDN — γράφουμε ~60 γρ. `HttpConnection` |
| `epptools/sdk` | Όχι (TLS/700 framing) | Δεν κάνει |
| `ywatchman/laravel-epp` | wrapper metaregistrar | Εγκαταλελειμμένο, SIDN-focused |
| `pstoute/laravel-domain-registrar` | — | Δεν κάνει EPP· REST σε Namecheap/GoDaddy |

Αν πάμε metaregistrar, το subclass:

```php
class ForthEppConnection extends \Metaregistrar\EPP\eppHttpsConnection
{
    public function __construct($logging = false, $settingsfile = null)
    {
        parent::__construct($logging, $settingsfile);
        parent::setServices([
            'urn:ietf:params:xml:ns:domain-1.0'  => 'domain',
            'urn:ietf:params:xml:ns:contact-1.0' => 'contact',
            'urn:ietf:params:xml:ns:host-1.0'    => 'host',
        ]);
        // ΠΡΟΣΟΧΗ: ΧΩΡΙΣ useExtension() — το Μητρώο δεν θέλει <svcExtension>
    }

    protected function initCurl($postMode = true)
    {
        $ch = parent::initCurl($postMode);
        curl_setopt($ch, CURLOPT_URL, config('epp.gr.endpoint'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml;charset=UTF-8']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // η base κλάση απενεργοποιεί την επαλήθευση αν δεν δοθεί client cert — το διορθώνουμε
        return $ch;
    }
}
```

---

## 12. Πρώτα βήματα σε UAT

1. Δήλωσε IP, πάρε credentials UAT.
2. `hello` → **κράτα το greeting**. Επιβεβαίωσε ότι τα namespaces είναι αυτά που
   λέει το v4.3 και ενημέρωσε αυτό το αρχείο αν διαφέρουν.
3. `login` → επιβεβαίωσε ότι περνάει χωρίς `<svcExtension>`.
4. `account:info` → επιβεβαίωσε parsing.
5. `domain:check` σε γνωστό ελεύθερο + γνωστό πιασμένο όνομα.
6. `contact:create` → `domain:create` → δες το `1001` + `protocol`.
7. `recallApplication` με το protocol → επιβεβαίωσε ότι καθαρίζει.
8. Μόνο μετά: transfer, renew, grRLS.

---

## 13. Παραπομπές

- Παραδείγματα v4.3: `epp-guide-examples-v4.3/` (66 XML + 14 XSD, δίπλα σε αυτό το αρχείο)
- Reference client Μητρώου: `EPP_client_sample/EppClient.java` (+ `epp-client-sample.pdf`)
- TLS bundle: `ca-bundle-2025/` (HARICA root + GEANT intermediate — δημόσια certs)
- Δημόσιες σελίδες: `https://grweb.ics.forth.gr/public/registrars`
- Κανονισμός ΕΕΤΤ: `https://grweb.ics.forth.gr/public/domains/regulation`
- Παλιό reference (⚠️ **extdomain-1.2**, μόνο για ιδέες): `github.com/emavro/eppgr`
