> **Αντίγραφο από το Ekdosi** (`docs/domains/whmcs-baseline.md`, 2026-09-25), για αναφορά. Γράφτηκε για το Ekdosi (`company_id`, φορολογικό
> τιμολόγιο, `ServiceContract`, ανανέωση στην έκδοση)· όπου διαφωνεί, ισχύει το [`docs/domains.md`](../domains.md).


# WHMCS domains — baseline από την παραγωγή (MyIP, 2026-09-23)

> Read-only **αθροιστικά** queries στην παραγωγική WHMCS της MyIP (MariaDB 11.4). Μόνο μετρήσεις:
> κανένα όνομα, επαφή, κωδικός ή τιμή additional field. Σκοπός: η «θεωρία» πριν το A4 (grEPP) και
> το A5 (reconciliation). Το import μένει **registrar-first** (απόφαση ιδιοκτήτη, `DomainImportService`):
> αυτά είναι για έλεγχο του σχεδίου, όχι πηγή δεδομένων.

## 1. Portfolio (Active)

| Registrar | Active | TLDs | Άλλες καταστάσεις |
|---|---:|---|---|
| **grepp** (.gr / .ελ) | **1381** | .gr 1340 · .com.gr 25 · .net.gr 7 · .edu.gr 4 · .org.gr 4 · .ελ 1 | Grace 3 · Cancelled 6 |
| **cnic** (CentralNic) | **226** | .com 145 · .eu 27 · .org 17 · .net 12 · +15 άλλα | Grace 2 · Redemption 3 · Pending 1 |
| **openprovider** | **224** | .com 153 · .eu 19 · .net 12 · .org 10 · +20 άλλα | Pending 1 |
| enom | 1 | .io | — |
| (κενό) | 1 | .com | Pending 1 (.gr) · Cancelled 14 |

Σύνολο **1833 active, ~75% είναι .gr**. Καταστάσεις WHMCS που εμφανίζονται: Active, Pending, Grace,
Redemption, Cancelled. **Δεν** εμφανίζονται: Expired, Transferred Away, Fraud (όσα φεύγουν, σβήνονται
ή γίνονται Cancelled).

## 2. Τα δύο ρολόγια

- **`nextduedate = expirydate` σε ΟΛΑ τα active (0 αποκλίσεις).** `DomainSyncNextDueDate=on` με
  `DomainSyncNextDueDateDays=0`: η WHMCS αντιγράφει τη λήξη του registrar στο billing date.
  → Στο import: `next_due_date` του SC = `expires_at` (ήδη ο κανόνας του README §6.2). Στο A5
  η baseline απόκλιση είναι **0**, άρα κάθε διαφορά είναι εύρημα και όχι ανοχή.
- `nextinvoicedate ≠ nextduedate` σε 185 (cnic 166, openprovider 11, grepp 8). Η WHMCS το μετακινεί
  όταν βγάζει ή ακυρώνει τιμολόγιο ανανέωσης. Στο ekdosi το staging γίνεται από το SC, οπότε δεν μεταφέρεται.
- **Τουλάχιστον 355 χειροκίνητες αλλαγές ημερομηνιών** (εγγραφή/λήξη/due) από admin σε 2 χρόνια,
  συχνά με σημείωση `<grEPP>Transfer in: …</grEPP>` στο `additionalnotes`. Οι ημερομηνίες των .gr
  διορθώνονται σήμερα με το χέρι. Το nightly sync του A4 (`domain:info` → exDate) το καλύπτει αυτόματα.

## 3. Περίοδοι & flags

- **grepp: μόνο 2/4/6/8 έτη** (2 έτη: 809 εγγραφές + 522 μεταφορές). Επιβεβαιώνει τον κανόνα
  «άρτια πολλαπλάσια» του `grepp/README.md` §8 (ως 8 έτη· 10 δεν εμφανίζεται).
- cnic/openprovider: 1–5 έτη (+ 9 έτη σε 5 εγγραφές, μάλλον λάθος καταχώρισης).
- `donotrenew`: 70 (grepp 56) → `auto_renew = false`.
- Addons πρακτικά ανύπαρκτα: ID protection 7, DNS management 16, email forwarding 1, premium 0.
  Επιβεβαιώνει το defer (README §11).
- grepp: 837 εγγραφές / 553 μεταφορές (τύπος παραγγελίας).

## 4. Χρεώσεις (`tblinvoiceitems`, όλο το ιστορικό)

Ανανέωση (`Domain`) 10243 · `DomainRegister` 2657 · `DomainTransfer` 1043 · **`DomainRedemptionFee` 276**
· ID protection 7 · DNS 1. Με `DomainExpirationFeeHandling=existing` το fee μπαίνει στο υπάρχον
τιμολόγιο ανανέωσης. Το restore billing είναι σήμερα «εκτός v1 / χειροκίνητα» (BACKLOG) — και μένει
έτσι: βλ. §8, τα 276 είναι σχεδόν όλα ιστορικά.

## 5. Τι γίνεται στην πράξη (activity log, 2 χρόνια)

| Ενέργεια | Πλήθος | Για το ekdosi |
|---|---:|---|
| Αυτόματη ανανέωση **μετά την πληρωμή** / επιτυχείς | 1603 / 1507 | ekdosi: στην οριστικοποίηση (κανονικά μετά την πληρωμή) — §7.4 |
| Αυτόματη εγγραφή μετά την πληρωμή / επιτυχείς | 214 / 228 | ίδιο με ekdosi (register = post-payment) |
| Μεταφορά μετά την πληρωμή / ξεκίνησαν | 194 / 364 | πολλές από τη μετάβαση CNIC→Openprovider |
| Αιτήματα EPP code | 198 | .gr = DACoR (A4)· αργότερα self-service στην πύλη |
| Ο πελάτης / ο admin έκλεισε το auto-renew | 66 / 36 | toggle στην πύλη (Πυλώνας D) |
| Μεταφορά domain σε άλλον πελάτη | 36 | ανάθεση/αλλαγή πελάτη |
| Ακυρώσεις/αναπαραγωγή τιμολογίων ανανέωσης | 196 + 182 + 39 | θόρυβος της WHMCS· το SC κάνει staging μία φορά |
| **Αποτυχία ανανέωσης: «Credit limit exceeded»** | 46 | έλεγχος υπολοίπου registrar πριν την ανανέωση + ειδοποίηση |
| «Renewal replaced by Openprovider transfer» | 168 | η μετάβαση CNIC→OP γίνεται **στην ανανέωση** (custom hooks) |
| Αποθήκευση nameservers | 47 | ✅ υπάρχει |
| Registrar lock (cnic) / unlock για μετάβαση | 76 / 56 | ✅ υπάρχει (OP)· .gr χωρίς lock |

Custom εργασίες της WHMCS: `[MIGRATION_AUTORENEW]` (daily), `[NS_CORRECTION]`, `[cnicmigration]`.

## 6. Υπενθυμίσεις λήξης

`DomainRenewalNotices = 60,30,15,10,5`, με **34.296 αποστολές** (8765 / 8145 / 6897 / 5790 / 4699).
Templates: «Ενημέρωση λήξης domain - Upcoming Domain Renewal Notice» + «Domain Renewal Confirmation».
**Κενό στο ekdosi:** δεν υπάρχουν υπενθυμίσεις λήξης domain (ο `domain_reminders` του README §3.8 δεν
χτίστηκε, και το README έγραφε 15/10/5). **Cutover blocker** για τα domains.

## 7. Τι αλλάζει στο σχέδιο

1. **Το A4 (grEPP) είναι το κύριο κομμάτι**, όχι «ένας 2ος registrar για απόδειξη του abstraction».
2. **CNIC: 226 active είναι ακόμα εκεί.** Το README έγραφε «η MyIP έφυγε» — η μετάβαση είναι σε εξέλιξη
   και γίνεται στην ανανέωση. Το registrar-first import από Openprovider **δεν τα βλέπει** → απόφαση §9.
3. **Υπενθυμίσεις λήξης (60/30/15/10/5)** πριν το cutover.
4. **Χρονισμός ανανέωσης → ΑΠΟΦΑΣΗ** (ιδιοκτήτης 2026-09-23): ανανέωση στην οριστικοποίηση, που κανονικά
   έρχεται μετά την πληρωμή (προτιμολόγιο ως τότε, όπως οι υπηρεσίες)· για έμπιστους πελάτες ο operator
   οριστικοποιεί νωρίτερα. Η πληρωμή που δηλώνεται αργότερα **δεν** ξαναανανεώνει — ήδη εγγυημένο (README §6.1).
5. ~~Redemption billing σε v1~~ → όχι (2ος γύρος: τα 276 είναι σχεδόν όλα ιστορικά, κανένα .gr).
6. Έλεγχος υπολοίπου registrar πριν την ανανέωση (grEPP account info §5.11 / υπόλοιπο OP).
7. WHMCS «Pending» (3 εγγραφές) → `pending_register` / `pending_transfer` ανάλογα με τον τύπο.

## 8. 2ος γύρος (2026-09-23)

**Tables:**
- `wDomain_handle` (type, domain_id → `wHandles`): «handles» = ορολογία του **Openprovider** για επαφές·
  823 συνδέσεις ≈ τα ~224 OP domains × τύποι επαφής. Το ekdosi ήδη τα διαβάζει από το API (A3). Το **grEPP
  module δεν κρατά δικό του πίνακα επαφών**: μόνο σημειώσεις `<grEPP>…</grEPP>` στο `additionalnotes`. Άρα τα
  contact IDs του .gr έρχονται από το μητρώο (`domain:info`) στο import του A4, και το prefix μας φαίνεται στο
  πρώτο πραγματικό `domain:info`. (Επιβεβαίωση: `wDomain_handle` join `tbldomains` ανά registrar.)
- `mod_domain_migration` (160): το log της μετάβασης CNIC→OP: unlock → απενεργοποίηση ID protection → EPP code
  → transfer submit → status. Η στήλη `epp` κρατά EPP codes **σε plain text**.
- `mod_cnic_zones` (826): **όχι** DNS zones. Είναι ο κατάλογος TLD του CNIC (periods, `grace_days`,
  `redemption_days`, epp_required, transferlock, `renews_on_transfer`, handle_updatable, needs_trade,
  dnssec_dsdata). Χρήσιμη αναφορά για τα grace/redemption ανά TLD του `domain_tlds`.
- `tblmodulelog`: άδειο (το module logging είναι κλειστό).

**Additional fields:** 145 διαφορετικά ονόματα, σχεδόν όλα απαιτήσεις ccTLD μέσω CNIC/OP (.it/.es/.fr/.eu/.de/
.uk/.nl/.au: citizenship, Tax ID, codice fiscale, ημερομηνία γέννησης…), με ≤16 domains το καθένα. Τα δύο
συχνότερα γενικά (Consent to Publish 156, IDN Script 156) = ήδη `consent_publish` / `idn_script` ✅. Άγνωστο σε
ποιο TLD ανήκουν: «Domain Name Content Description» 182, «Description» 147, «ContactLegal» 147. Εγγραφή νέου
ccTLD με ειδικά στοιχεία = χειροκίνητα προς το παρόν (ελάχιστος όγκος).
`tbldomains_extra`: DNSSEC management σε 7 domains του OP (το key management είναι στο BACKLOG).

**Redemption fees:** μόνο 7 από τα 276 αντιστοιχούν σε domain που υπάρχει ακόμα (cnic .com 4 · OP .com 1 ·
.es 1 · .eu 1), κανένα .gr. Είναι κυρίως ιστορικά, από domains που έχουν σβηστεί. **Το restore billing μένει
χειροκίνητο (v1)**, η §7.5 αποσύρεται.

**CNIC — πότε λήγουν τα 226:** 155 ως τον 2027-02 (69%) · ~198 ως το τέλος του 2027 (88%) · ~28 με λήξη
2028–2034. Η μετάβαση της WHMCS γίνεται στην ανανέωση, άρα ο κύριος όγκος φεύγει μόνος του ως τις αρχές του 2027.

**Σπάνιες ενέργειες (2 χρόνια):**
- Διαγραφές domain από admin 16 · αλλαγές κατάστασης με το χέρι (Active→Cancelled 10, Pending→Active 8,
  Pending Transfer→Active 8, Grace→Expired 3, Redemption→Active 2, →Transferred Away 2, →Fraud 1 και πίσω).
- Αλλαγή registrar με το χέρι (''→grepp 9, cnic→openprovider 8, ispapi→cnic 6…) · αλλαγή τιμής ανά domain
  ~33 (= `price_override` ✅) · αλλαγή περιόδου ~29.
- **Αλλαγή ιδιοκτήτη χρεώνεται** («Αλλαγή στοιχείων ιδιοκτησίας domain», 2) → A5 «Μεταφορά ιδιοκτησίας».
- Δωρεάν domain μαζί με πακέτο hosting (η ανανέωση παρακάμπτεται, 2).
- **Υπόλοιπο registrar:** credit limit (cnic/ispapi) ~25 + OP insufficient funds 6 → ο έλεγχος υπολοίπου πριν
  την ανανέωση επιβεβαιώνεται.
- **Ποιότητα στοιχείων επαφής:** τηλέφωνα εκτός RFC (9, cnic), invalid VAT / first name / zipcode (8, OP transfer).
  Το OP adapter ήδη χωρίζει το τηλέφωνο· στο A4 το EPP θέλει `+CC.NUMBER`.
- Τα μηνύματα λάθους του μητρώου .gr → `grepp/README.md` §7.

## 9. Αποφάσεις

- **CNIC → «χωρίς API»** (ιδιοκτήτης, 2026-09-23). Μπαίνουν σε σύνδεση registrar `manual` (NullDomainRegistrar):
  χρέωση κανονικά, χωρίς sync. **Προσοχή:** ένα domain χωρίς δική του σύνδεση κληρονομεί του TLD, και το .com
  είναι στο Openprovider. Τα CNIC domains χρειάζονται **ρητό** `registrar_connection_id` = η manual σύνδεση,
  αλλιώς το sync τα ψάχνει στο OP (μόνο `sync_error`, όχι φθορά). Το `domains:import-csv` σήμερα δεν ορίζει
  σύνδεση → χρειάζεται `--connection`. Στην ανανέωση: μεταφορά στο OP (με EPP code από το panel του CNIC,
  αφού δεν έχουμε API). Στόχος σταδιακά όλα στο Openprovider.

## 10. Υγιεινή δεδομένων (WHMCS)

Οι σημειώσεις domain (`additionalnotes`) και το `mod_domain_migration.epp` περιέχουν **EPP codes, στοιχεία
σύνδεσης σε panel και στοιχεία ιδιοκτητών σε plain text**. Κανόνας για το ekdosi: **ποτέ** import των
σημειώσεων ή του `epp` της WHMCS (το registrar-first import ούτως ή άλλως δεν τα διαβάζει). Στη WHMCS αξίζει
καθάρισμα.
