# Domains (Φάση 2): σχέδιο

> Το ένα σημείο για τα domains του MyCS (D0, 2026-10-16). Μαζεύει ό,τι ήταν διάσπαρτο: το Ekdosi, το
> `spike/domains`, το blesta-modules, το WHMCS της myip και τις αποφάσεις του ιδιοκτήτη. Όπου κάποια πηγή
> διαφωνεί, ισχύει αυτό το αρχείο. Οι πηγές, για λεπτομέρειες:
> - [`domains/ekdosi-design.md`](domains/ekdosi-design.md): το σχέδιο του Ekdosi (μοντέλο, Openprovider, ροές, guards).
> - [`domains/whmcs-baseline.md`](domains/whmcs-baseline.md): μετρήσεις από το WHMCS της myip (μόνο αθροιστικά).
> - [`domains/grepp.md`](domains/grepp.md): το μητρώο .gr (EPP over HTTPS, DACoR, κανόνες, μηνύματα λάθους).
> - [`domains/spike.md`](domains/spike.md): το port στο MyCS και η live μεταφορά του nixpal.com.
> - [`history/blesta-modules/openprovider.md`](history/blesta-modules/openprovider.md): κανόνες ανανέωσης/restore,
>   ημερομηνία λήξης ανά TLD, pricing sync· [`history/blesta-modules/domain-tools.md`](history/blesta-modules/domain-tools.md).
> - `decisions.md` §5, `settings-map.md` («Domains»), `accounts.md` (επαφές), `importer.md`, `whmcs-usage.md`.

## 1. Πλαίσιο και αριθμοί
- **Τι υπάρχει:** στο `main` μόνο τα `packages/myopenprovider` (client, `RenewalPlan`) και `packages/domain-tools`
  (ονόματα/IDN, RDAP, whois). Το Ekdosi έχει πλήρες υποσύστημα (7 πίνακες, Openprovider, register/transfer/renew/
  manage/restore, pricing sync, imports, Filament, ~170 tests με mock HTTP), **χωρίς** χρήση σε παραγωγή. Το spike
  μετέφερε τον πυρήνα (48 tests) και έκανε μία live μεταφορά· είναι 670+ commits πίσω και **δεν** γίνεται merge.
- **nixpal (pilot):** 14 εγγραφές: Openprovider active 7· CNIC active 3, grace 1, expired 1, transferred away 2.
  10 TLDs, όλα με autoreg Openprovider, μόνο ετήσια, EUR και USD.
- **myip** (2026-10-16): 1.843 active: .gr (grepp) 1.388, CNIC 198, Openprovider 256. Εκτός .gr: **~450–500**.
  35 TLDs (grepp 6, Openprovider 29), EUR και USD. Ανανεώσεις με
  τον registrar μετά την πληρωμή 1.603 σε 2 χρόνια· «Credit limit exceeded» 46· υπενθυμίσεις 34.296.
- **Δεν υπάρχει πουθενά:** κώδικας .gr EPP, importer του WHMCS για domains, υπενθυμίσεις λήξης, αυτόματο grace/
  redemption, margin στις τιμές.

## 2. Αποφάσεις
Του ιδιοκτήτη, 2026-10-16, εκτός αν γράφει αλλιώς.

| # | Απόφαση | Πηγή |
|---|---|---|
| D-1 | **Ανανέωση στον registrar όταν πληρωθεί το τιμολόγιο.** Είναι υπηρεσία τρίτου που πληρώνουμε. Εξαίρεση: έμπιστοι πελάτες/συνεργάτες, που το staff ανανεώνει με «Ανανέωση τώρα» και πληρώνουν μετά. Η πληρωμή που έρχεται μετά **δεν** ξαναανανεώνει (το bug του WHMCS, το «hack» με το relid). | ιδιοκτήτης· `decisions.md` §5 |
| D-2 | Μία ανανέωση ανά τιμολόγιο **και** περίοδο (`invoice_items.period_start/end`, #32). Πριν ανανεώσει, κάνει sync: αν ο registrar καλύπτει ήδη την περίοδο, την **υιοθετεί** (adopt) χωρίς δεύτερη χρέωση. | Ekdosi, spike |
| D-3 | **Επαφές:** οι κοινές `contacts` του λογαριασμού, που φτιάχτηκαν για διπλό σκοπό (domains και, αργότερα, παραστατικά στο Ekdosi). Το domain δείχνει ποια επαφή είναι registrant/admin/tech/billing και κρατά μόνο το **handle** του registrar ανά ρόλο. Κανένας ξεχωριστός πίνακας επαφών domain. | ιδιοκτήτης· `accounts.md` |
| D-4 | **Το domain είναι ξεχωριστό και χρεώνεται απευθείας** (απόφαση 2026-10-16, μετά το sweep του κώδικα): γραμμή τιμολογίου με `reference_type = Domain`, `period_start/end` ολόκληρα έτη, ΦΠΑ (D-19). Δικό του βήμα τιμολόγησης στο cron (Ν μέρες πριν τη λήξη, D-14) και δικός του χειρισμός στην πληρωμή (D-1). Ξαναχρησιμοποιεί ό,τι δουλεύει στο επίπεδο του τιμολογίου: ομαδοποίηση με τις ανανεώσεις υπηρεσιών της ίδιας ημερομηνίας (hosting και domain στο ίδιο τιμολόγιο, όπως στο WHMCS), separate invoices, πιστώσεις, Mass Pay, merge/split, auto-charge. **Due date = λήξη.** **Όχι κρυφό `Service`:** θα ήθελε φίλτρο σε ~25 σημεία και εξαιρέσεις από αναστολή/τερματισμό (που ακυρώνουν το ανοιχτό τιμολόγιο), proration, upgrades, `datesOnStart` και από το `RenewServiceService` (που μετακινεί τη λήξη πριν απαντήσει ο registrar). | ιδιοκτήτης |
| D-5 | **Πολυετή, ανά πράξη:** τα επιτρεπτά έτη ορίζονται ανά TLD **και** πράξη (register/renew/transfer), 1–10. Π.χ. .gr: register 2/4/6/8/10, renew 2/4/6/8, transfer χωρίς έτος· .ai ελάχιστο 2· .eu/.io ως 5· πολλά ccTLD μόνο 1. Το nixpal πουλάει σήμερα μόνο ετήσια. Τιμή ανά TLD, πράξη, έτη και νόμισμα. | ιδιοκτήτης· έλεγχος WHMCS 2026-10-16 |
| D-6 | **Τιμές TLD με importer/sync όπως το WHMCS** (Utilities → TLD Import & Pricing Sync): επιλογή registrar, margin (%/ποσό), στρογγυλοποίηση, προαιρετικά και grace/redemption fee, «auto-registration» με αυτόν τον registrar. Πίνακας κόστους/τρέχουσας τιμής/margin ανά TLD· εισάγει μόνο όσα επιλέξει το staff. **Τρεις καταστάσεις τιμής:** κλειστό (καμία τιμή), **δωρεάν και επιτρεπτό (0,00**: μεταφορά .gr, .uk, .co.uk, .ch, .es, .at), τιμή. Τιμές **ανά νόμισμα του MyCS**: όσα έχει ο πίνακας `currencies` (όπως οι τιμές των προϊόντων), τίποτα hardcoded· σήμερα και τα δύο WHMCS έχουν EUR και USD. | ιδιοκτήτης (screenshots WHMCS) |
| D-7 | **Ρυθμίσεις ανά TLD όπως το WHMCS** (Domains/TLDs): registrar αυτόματης εγγραφής, EPP code για μεταφορά, DNS management / email forwarding / ID protection, τιμές, **grace και redemption: μέρες και τέλος**. Μαζική επεξεργασία. | ιδιοκτήτης |
| D-8 | **Grace/redemption από το API** του registrar όπου τα δίνει (Openprovider: μέρες και τιμή restore), αλλιώς με το χέρι. Το cron περνά το domain σε grace και μετά σε redemption με βάση τη λήξη. Το τέλος μπαίνει **στο υπάρχον τιμολόγιο** ανανέωσης (`DomainExpirationFeeHandling=existing`). Σήμερα: grace fee 0 παντού· redemption 30 μέρες και €96,50 στα gTLD, ειδικά ανά ccTLD (π.χ. .eu 40/25,50 · .es 10/25,50 · .uk 1/10,97)· .gr grace 15· `-1` = «default του TLD». Το nixpal έχει χρεώσει redemption (2 φορές). | ιδιοκτήτης· `settings-map.md` |
| D-9 | **Sync με cron:** pending transfers, grace/redemption, active/cancelled/expired, και λήξη που άλλαξε έξω από εμάς (π.χ. ανανέωση από την κονσόλα του Openprovider). Συχνότητα όπως σήμερα: **κάθε 4 ώρες** και για τις καταστάσεις και για τις μεταφορές. Λήξη = `expiration_date` του registrar. Κάθε αλλαγή καταγράφεται· ο registrar μόνο προχωρά την κατάσταση. | ιδιοκτήτης· blesta H4· Ekdosi |
| D-10 | **Registrars:** Openprovider σίγουρα. **CNIC = `manual`** (χρέωση κανονικά, χωρίς API/sync, με ρητή σύνδεση ανά domain), εκτός αν προλάβουμε μέσα σε 4–6 μήνες· τότε module/hook όπως σήμερα (μεταφορά στο Openprovider αντί για ανανέωση). Τα CNIC μεταφέρονται σταδιακά στο Openprovider. .gr EPP: Φάση 4 (myip). | ιδιοκτήτης |
| D-11 | **Importer από το WHMCS** (~450–500 domains εκτός .gr): `tbldomains`, `tbldomainpricing`, ρυθμίσεις ανά TLD, additional fields· parity και σύγκριση με τους registrars. **Ποτέ** οι σημειώσεις (`additionalnotes`), το `mod_domain_migration.epp` ή το additional field `X-ALLOCATION-TOKEN`: έχουν EPP codes και κωδικούς σε απλό κείμενο. Statuses όπως είναι (και Expired/Transferred Away του nixpal)· Pending → pending_register ή pending_transfer κατά τον τύπο της παραγγελίας, Fraud → cancelled· τα ονόματα κανονικοποιούνται (πεζά, punycode) και ο importer γράφει μέσω του μοντέλου· τα domains που φτιάχτηκαν πριν (δοκιμές) δεν πιάνουν id του WHMCS (AUTO_INCREMENT πάνω από το μέγιστο)· έλεγχος για τα πολυετή (το 5ετές του nixpal: `nextinvoicedate` 5 χρόνια πριν). | ιδιοκτήτης· whmcs-baseline §10 |
| D-12 | Autorenew **κλειστό** στον registrar: το ρολόι της χρέωσης είναι δικό μας. Κάθε domain το ανανεώνει ένα μόνο σύστημα (κλείνει στο cut-over). | blesta `openprovider.md` |
| D-13 | Restore: αυτόματο μόνο στην soft quarantine και για 1 έτος· στην hard quarantine αρνείται και αποφασίζει άνθρωπος. | blesta `RenewalPlan` |
| D-14 | **Τιμολόγιο Ν μέρες πριν τη λήξη, ρύθμιση ανά εταιρεία** (`CreateDomainInvoiceDaysBefore`: myip 15· nixpal κενό → 7 των υπηρεσιών). **Υπενθυμίσεις λήξης** σε μέρες που ρυθμίζονται, **και μετά τη λήξη** (αρνητικές μέρες, template «Expired Domain Notice»): myip 60/30/15/10/5, nixpal 30/7/−3. Χωριστό template για δωρεάν domains. **Auto-renew ενεργό by default.** **Ανανέωση on demand:** ο πελάτης (ή το staff) ζητά ανανέωση όποια στιγμή θέλει πριν βγει το τιμολόγιο (σήμερα μέσω καλαθιού: 872 checkouts στο myip)· βγαίνει τιμολόγιο για την επόμενη περίοδο και ανανεώνεται όταν πληρωθεί (D-1). Do-not-renew ανά domain. **Cut-over blocker.** | ιδιοκτήτης· `settings-map.md`· έλεγχος WHMCS |
| D-15 | Έλεγχος υπολοίπου στον registrar πριν την ανανέωση· αποτυχία → Pending Module Actions και ειδοποίηση staff· η πληρωμή του πελάτη **δεν** αποτυγχάνει ποτέ λόγω registrar. | whmcs-baseline §5· `provisioning.md` |
| D-16 | Μεταφορά: στέλνουμε **πάντα** ρητά τους τρέχοντες nameservers (με glue IP για NS μέσα στο ίδιο domain)· η λήξη υιοθετείται μόνο με `ACT`, όχι όσο είναι pending. | spike (live) |
| D-17 | Κάθε ενέργεια που χρεώνει στον registrar: lock ανά domain, log (αίτημα, απάντηση, τιμολόγιο), καμία δεύτερη εκτέλεση· **ένα live write test ανά ενέργεια** πριν το cut-over. | Ekdosi `GuardsRegistrarWrites`· spike |
| D-18 | **Τα domains που τελείωσαν μένουν** (expired, cancelled, transferred away, deleted) με το ιστορικό τους· τίποτα δεν σβήνεται. Στο myip ένα custom hook (`ExpiredDomains.php`) τα σβήνει κάθε μέρα από τη βάση, γι' αυτό ο importer δεν θα τα βρει. **Ξανά-εγγραφή του ίδιου ονόματος** (ο ίδιος πελάτης που το ξέχασε, ή άλλος μήνες μετά): νέα γραμμή, η παλιά μένει ως ιστορικό. «Τέλος» είναι μόνο deleted (το μητρώο το ελευθέρωσε), transferred away, cancelled· το expired/grace/redemption ανήκει ακόμα στον πελάτη (ανανέωση/ανάκτηση). Η βάση δεν δέχεται δεύτερη *ενεργή* γραμμή ανά όνομα (`live_fqdn`, generated). Το παλιό γίνεται deleted με το sync (Openprovider) ή με το cron μετά τη λήξη + grace + redemption (registrars χωρίς API)· αν έχει μείνει, η παραγγελία (D8) το κλείνει ως deleted (με audit) όταν ο registrar πει ότι το όνομα είναι διαθέσιμο. | έλεγχος WHMCS |
| D-19 | **ΦΠΑ στα domains** (`TaxDomains=on` και στα δύο). | έλεγχος WHMCS· `sweeps/billing.md` |
| D-20 | **Staff:** to-do για ό,τι θέλει χειροκίνητη ενέργεια (`DomainToDoListEntries`), admin emails «Renewal Successful/Failed», ημερήσια λίστα domains που λήγουν σύντομα (σήμερα μόνο .eu, 10 μέρες· γενικά ανά TLD). Registrar admin contact = τα στοιχεία του πελάτη. | έλεγχος WHMCS |
| D-21 | **Συνδέσεις registrar:** δικό τους μοντέλο και πίνακας (ο registrar **δεν** είναι extension). Τα credentials αποθηκεύονται ως κρυπτογραφημένα `Setting` της σύνδεσης (`settingable`), όπως των extensions: κρυπτογράφηση, καθαρισμός από τα logs (`Redact`), απόκρυψη στο audit, ποτέ πίσω στη φόρμα. Mode off/sandbox/production, «Test Connection», σύνδεση `manual` χωρίς API. Δεν σβήνεται όσο τη χρησιμοποιεί TLD ή domain. | ιδιοκτήτης· sweep |

## 2a. Πώς δουλεύουν σήμερα τα δύο WHMCS (έλεγχος μόνο ανάγνωσης, 2026-10-16)
Μόνο ρυθμίσεις και αθροιστικά· κανένα στοιχείο πελάτη. Ό,τι αλλάζει απόφαση είναι ήδη στο §2.
- **Ρολόι:** `nextduedate = expirydate` σε όλα τα active και στα δύο (0 αποκλίσεις). Sync κάθε 4 ώρες (status και
  transfers), «Domain Expiry» και υπενθυμίσεις ημερήσια.
- **Μεταφορά CNIC → Openprovider** (custom hook `DomainMigration.php` στο `PreRegistrarRenewDomain`, και στα δύο):
  στην ανανέωση ενός CNIC domain παίρνει EPP code, κάνει transfer στο Openprovider με τους τρέχοντες NS (και glue
  IP) και ακυρώνει την ανανέωση· το πληρωμένο τιμολόγιο πληρώνει τη μεταφορά (που προσθέτει έτος). myip: 188
  υποβλήθηκαν, 25 απέτυχαν. **Λάθη που δεν επαναλαμβάνουμε** αν το ξαναχτίσουμε (D-10): αλλάζει τη βάση
  (registrar, Pending Transfer, `donotrenew=1`) **πριν** υποβάλει τη μεταφορά, οπότε μια αποτυχία αφήνει λάθος
  κατάσταση· το `donotrenew` το καθαρίζει ένα δεύτερο hook στο myip, στο nixpal ποτέ· το EPP code μένει σε απλό
  κείμενο (`mod_domain_migration`). Στο myip ένα τρίτο hook διορθώνει τους NS μετά τη μεταφορά (bug του module).
- **«Ανανέωση πριν την πληρωμή» σήμερα:** το addon `relid_remover` ξεκολλά με το χέρι το τιμολόγιο από το domain
  (~114 γραμμές domains τον χρόνο στο myip)· το παλιό hook είναι νεκρό (PHP 8). Στο MyCS: «Ανανέωση τώρα» και
  adopt (D-1, D-2), χωρίς ξεκόλλημα.
- **.gr (grEPP, ionCube):** sync ημερομηνιών μετά από register/renew/transfer, DACoR για το EPP code, κανόνες
  καλαθιού (και μια άγνωστη προσαρμογή συνόλου), widget υπολοίπου. 198 χειροκίνητες αλλαγές ημερομηνίας εγγραφής
  τον χρόνο.
- **Order form:** domain-only orders, `AllowDomainsTwice`, Spotlight/bulk-check TLDs, lookup μέσω Openprovider,
  default NS ανά εταιρεία (ns1–3.nixpal.com / ns1–3.myip.gr), IDN μόνο στο myip, premium κλειστό.
- **Δωρεάν domain με hosting:** κανένα προϊόν δεν το έχει ρυθμισμένο· 6 domains με τιμή 0 → `price_override` ανά
  domain, όχι λειτουργία προϊόντος.
- **Additional fields:** ελάχιστα (nixpal 7 ονόματα, ≤3 domains)· το `X-ALLOCATION-TOKEN` είναι μυστικό σαν το
  EPP code και **δεν** εισάγεται (D-11).
- **nixpal:** custom περιγραφές domain στο τιμολόγιο (addon `clientproductdescriptions`).
- **Templates:** τα standard του WHMCS (registration, renewal, transfer initiated/completed/failed, expired, upcoming
  renewal, upcoming free renewal) και στο myip custom (EPP request, ID protection, unlock, αλλαγή ιδιοκτήτη).

## 3. Μοντέλο (από το Ekdosi, χωρίς `company_id`)
- `domain_registrar_connections`: registrar, label, active, mode (off/sandbox/production)· τα credentials ως
  κρυπτογραφημένα `Setting` της σύνδεσης (D-21). Πολλές συνδέσεις, και δύο στον ίδιο registrar. `manual` χωρίς API.
- `domain_tlds`: tld (χωρίς τελεία), σύνδεση αυτόματης εγγραφής, EPP code, addons (DNS, email forwarding, ID
  protection), IDN, **μέρες grace/redemption** (null = του registrar ή καμία), active. Δεν σβήνεται όσο έχει domains.
- `domain_tld_prices`: tld, πράξη (**register/renew/transfer/grace/redemption**), **έτη**, νόμισμα του MyCS, `price`
  (null = δεν προσφέρεται, 0,00 = δωρεάν), `cost` και `cost_currency`. Τα έτη που προσφέρονται = οι γραμμές με τιμή·
  τα τέλη grace/redemption = η γραμμή 1 έτους της πράξης τους. Το sync γράφει μόνο `cost`· η `price` αλλάζει μόνο με
  το import του D-6 ή με το χέρι. Ιστορικό αλλαγών: το audit.
- `domains`: **`id` = `tbldomains.id`** όταν εισάγεται (όπως ο importer κρατά τα IDs του WHMCS· D-11· τα domains που
  φτιάχτηκαν πριν το import, π.χ. η δοκιμή του §7, θέλουν AUTO_INCREMENT πάνω από το μέγιστο του WHMCS), `client_id`, tld, σύνδεση (υπερισχύει του TLD), fqdn, status, ημερομηνίες εγγραφής/
  μεταφοράς/λήξης, auto-renew, lock, privacy, DNSSEC, id στον registrar, `last_synced_at`, `sync_error`, τιμή ανανέωσης και έτη
  της επόμενης περιόδου, auto-renew/do-not-renew, overrides (grace/redemption/fee/τιμή). Το όνομα δεν είναι unique:
  ένα domain που τελείωσε μένει (D-18) και το όνομα μπορεί να ξανάρθει· unique είναι το `live_fqdn` (= fqdn όσο δεν
  έχει τελειώσει, αλλιώς null).
- `domain_contacts` → **όχι** (D-3): `registrant/admin/tech/billing_contact_id` στο domain (admin: η κύρια επαφή,
  D-20) και handle ανά ρόλο. Επαφή που είναι ρόλος σε ενεργό domain δεν αρχειοθετείται.
- `domain_reminders`: ποια ειδοποίηση στάλθηκε για ποια λήξη (όπως το `invoice_reminders`), ώστε να μη στέλνεται δύο φορές.
- `domain_nameservers`, `domain_registrar_logs` (και `invoice_id` για το «μία φορά ανά τιμολόγιο»).
- Statuses: active, pending_register, pending_transfer, expired, grace, redemption, transferred_away, cancelled,
  deleted (όπως στο Ekdosi· labels σε `lang/`).

## 4. Ροές
- **Παραγγελία:** register/transfer στο καλάθι με τα έτη του TLD → τιμολόγιο → μετά την πληρωμή εγγραφή/μεταφορά
  → το domain γίνεται active μόλις ο registrar επιβεβαιώσει.
- **Ανανέωση:** τιμολόγιο Ν μέρες πριν τη λήξη, ή νωρίτερα on demand (D-14) → πληρωμή → `renewForInvoice`
  (D-1, D-2, D-15) → η λήξη, και μαζί το due date, προχωρά κατά τα έτη της περιόδου.
  «Ανανέωση τώρα» του staff (D-1): ανανεώνει, γράφει την περίοδο, και η μεταγενέστερη πληρωμή την υιοθετεί.
- **Λήξη:** expired → grace (ανανέωση με το τέλος grace, αν υπάρχει) → redemption (restore με τέλος, D-13) →
  deleted. Οι μέρες από το TLD ή το override του domain.
- **Sync (D-9):** κάθε 4 ώρες, για όλα και για τα pending transfers (λήξη μεταφοράς στις 14 μέρες). Πρώτα
  **αναφορά διαφορών**· η εφαρμογή γράφει audit.
- **Διαχείριση:** NS, lock, privacy, επαφές, EPP code (ποτέ αποθηκευμένο), restore. Πελάτης στο portal: NS, EPP
  code, auto-renew.

## 5. Τι παίρνουμε από πού
| Κομμάτι | Από | Αλλαγές |
|---|---|---|
| Contract `DomainRegistrar`, DTOs, registry/factory/null, credentials | Ekdosi (= spike) | καμία ουσιαστική |
| `OpenproviderRegistrar` | spike | **με τις 3 διορθώσεις της live μεταφοράς**· grace/redemption από το API (D-8) |
| Sync, Transfer, Renewal (adopt) | spike | renewal on-paid (D-1), Pending Module Actions (D-15), grace/redemption (D-8) |
| Registration, Management, Pricing sync, imports | Ekdosi | χωρίς `company_id`· χρέωση απευθείας στο domain (D-4) αντί για `ServiceContract` |
| `RenewalPlan` (restore/refuse) | `packages/myopenprovider` | adapter αντί για το Blesta meta store |
| Αναζήτηση, whois, suggestions | `packages/domain-tools` | — |
| Pricing importer με margin (D-6), grace/redemption cron, υπενθυμίσεις, importer WHMCS | **νέα** | — |

## 5a. Πού κουμπώνει στον κώδικα (sweep, 2026-10-16)
- **Τιμολόγηση:** νέο βήμα στο `CronJob` (δίπλα στο `invoices_created`) με ρύθμιση `domain_invoice_days` (κενό →
  `cronjob_invoice`)· το ίδιο κλειδί ομαδοποίησης (`renewalGroupKey`) ώστε να μπαίνει στο τιμολόγιο των υπηρεσιών
  της ίδιας μέρας. Η χρέωση γραμμής σε service (`billRenewal`) βγαίνει σε service για να τη χρησιμοποιεί και η
  ανανέωση on demand (D-14). `InvoiceItemReference::TYPES` και `LineTax` μαθαίνουν το `Domain`.
- **Πληρωμή:** κλάδος για το domain στο `ProcessPaidInvoiceService` (με το ίδιο retry μέσω `apply_pending_since`):
  `renewForInvoice`, και η λήξη αλλάζει μόνο με την απάντηση του registrar.
- **Παραγγελία:** `PlaceOrderService`: γραμμή domain (register/transfer)· οι γραμμές 0,00 (δωρεάν μεταφορά) **δεν**
  ενεργοποιούνται αμέσως όπως σήμερα· περιμένουν τον registrar.
- **Pending Module Actions:** το `ModuleAction` επεκτείνεται σε register/transfer/renew/restore του domain (ενέργειες,
  supersedes, retry, resolve), μαζί με το health και τα MCP tools.
- **Ειδοποιήσεις:** το μοτίβο του `PaymentReminders` (λίστα ημερών, dedup) και του `CardExpiryNotices`· νέα templates
  (en/el) στο `EmailTemplateSeeder`· mail audit (`EmailLog.related` = Domain)· η προτίμηση email «domains» της επαφής
  ενεργοποιείται. Οι υπενθυμίσεις τιμολογίων **δεν** γράφουν «θα ανασταλεί» για γραμμές domain.
- **Ρυθμίσεις:** νέα καρτέλα «Domains» στο `Settings.php`: μέρες τιμολογίου, μέρες ειδοποιήσεων (και αρνητικές),
  default NS 1–5, sync, ημερήσια λίστα για το staff.
- **Schedule:** `domains:sync` κάθε 4 ώρες (`onOneServer`)· βήματα στο CronStat· έλεγχος «domains» στο Health.
- **Portal:** τα υπάρχοντα δικαιώματα χρήστη `domains` / `managedomains` (`ClientUser::PERMISSIONS`).
- **Packages:** το `myopenprovider` Client δεν έχει register/transfer/check/EPP/NS/handles/τιμές· αυτά από τον driver
  του spike. Το `RenewalPlan` (restore/refuse) ως έχει.
- **Importer:** σήμερα κρατά το `reference_id` των γραμμών «Domain» με `reference_type` κενό· το D6 τις ξαναδένει στο
  domain (ίδιο id) με περιόδους.

## 6. PRs
Κάθε PR με κώδικα: code review. Όσα αγγίζουν χρήματα (τιμές, τιμολόγια, ανανέωση): **και adversarial review**.
1. **D1 Σχήμα και μοντέλα:** πίνακες, statuses, ρόλοι επαφών (και ο guard αρχειοθέτησης), συνδέσεις registrar με
   `Setting`, admin read-only (μενού Domains, πίνακας στη Summary του πελάτη). Code review.
2. **D2 Registrars** (#159): contract, συνδέσεις (credentials κρυπτογραφημένα, «Test Connection» μόνο για όσους
   επεξεργάζονται, χωρίς log), Openprovider, `manual`. Code + adversarial. Τα guards (lock, log κάθε απόπειρας,
   adopt) έρχονται με τον πρώτο που γράφει (D4/D5), εκεί που ελέγχονται. Σημειώσεις:
   - Ένα token του Openprovider ανήκει στα ακριβή credentials (endpoint, username **και** password).
   - Το δοκιμαστικό του Openprovider είναι σε **απλό HTTP** (`:8480`, όπως το τεκμηριώνει): μόνο τα στοιχεία του
     δοκιμαστικού λογαριασμού, ποτέ του live (η φόρμα το λέει).
   - Handles: ένας customer στον Openprovider ανά επαφή **ανά domain** (όχι κοινός σε domains)· αλλάζει η επαφή ενός
     ρόλου → σβήνει το handle και φτιάχνεται νέο στην επόμενη εγγραφή· μόνο επαφές του ίδιου λογαριασμού, με χώρα.
   - Register/transfer αρνούνται χωρίς nameservers (αλλιώς ο Openprovider βάζει τους δικούς του) και δεν υιοθετούν
     λήξη όσο είναι pending.
   - Το EPP code είναι secret και στα logs (`Redact`).
3. **D3 TLDs και τιμές** (#160): «Domain Pricing» (ρυθμίσεις ανά TLD, τιμές ανά πράξη/έτη/νόμισμα), «Sync costs» και
   `domains:sync-costs` (εβδομαδιαίο, **μόνο** κόστος), «TLD Import & Pricing Sync» (margin %/ποσό, στρογγυλοποίηση
   x.00/x.50/x.99 προς τα πάνω, register/renew 1–N έτη = N × τιμή 1 έτους, transfer 1 έτος, τέλος redemption αν
   ζητηθεί, αυτόματη καταχώριση, ισοτιμίες για τα άλλα νομίσματα). Χρήματα με bcmath. Code + adversarial. Οι μέρες
   grace/redemption μπαίνουν με το χέρι ανά TLD ως ότου δούμε τι δίνει το API (§8).
4. **D4 Sync:** `domains:sync` και schedule, pending transfers, grace/redemption, αναφορά διαφορών. Code review.
5. **D5 Χρέωση:** γραμμή `Domain` στο τιμολόγιο, βήμα τιμολόγησης με ομαδοποίηση, κλάδος στην πληρωμή, renew on-paid, «Ανανέωση τώρα», adopt, fee grace/
   redemption στο υπάρχον τιμολόγιο, Pending Module Actions. Code + adversarial.
6. **D6 Importer WHMCS:** domains, τιμές, TLDs, ρόλοι επαφών, additional fields· parity και σύγκριση με τον
   registrar (`importer.md`). Code + adversarial.
7. **D7 Υπενθυμίσεις και portal:** 60/30/15/10/5, do-not-renew, domains του πελάτη, NS, EPP code. Code review.
8. **D8 Παραγγελίες:** register/transfer στο καλάθι, αναζήτηση. Code + adversarial.
Μετά: CNIC module (αν προλάβουμε, D-10), .gr EPP (`packages/gr-epp`, Φάση 4), additional fields ανά ccTLD, DNSSEC.

## 7. Δοκιμές σε live
- **Domain δοκιμών: nixpal.com** (δικό μας, ήδη στο Openprovider από τη μεταφορά του spike), στον πελάτη δοκιμών
  του staff. Από το D2: σύνδεση με τον registrar, sync ημερομηνιών και nameservers, το cron, οι
  καταστάσεις· στο D5 μια ανανέωση από τιμολόγιο.
- Πρώτα μόνο ανάγνωση (login, info, sync)· **κάθε ενέργεια που χρεώνει** (renew, transfer, restore) μόνο με ρητή
  έγκριση του ιδιοκτήτη, μία φορά ανά ενέργεια (D-17).
- Τα credentials του registrar μόνο κρυπτογραφημένα στη βάση (σύνδεση registrar), ποτέ στο repo, σε logs ή στο chat.

## 8. Ανοιχτά
- Openprovider: ποια ημερομηνία είναι η αλήθεια, `expiration_date` ή `renewal_date` (το `RenewalPlan` χρησιμοποιεί
  και τις δύο· blesta H4: `expiration_date`)· επιβεβαίωση στο sandbox με το nixpal.com.
- Ποια πεδία δίνει το API του Openprovider για grace/redemption ανά TLD (μέρες, τιμή restore, soft/hard
  quarantine): επιβεβαίωση στο D2/D3 με το sandbox.
- Νόμισμα στο import του D-6: το κόστος του registrar είναι σε ένα νόμισμα (Openprovider: EUR) και το MyCS **δεν έχει
  ισοτιμίες**. Για τα άλλα νομίσματα του `currencies`: ισοτιμία που δίνει το staff στη φόρμα του import (όπως η
  ισοτιμία του WHMCS), ή τιμή με το χέρι. Ποτέ σιωπηλή μετατροπή.
- Χρεωμένη αλλαγή ιδιοκτήτη (2 περιπτώσεις): με το χέρι ως τότε.
- Οι 145 additional fields του WHMCS: ποια χρειάζονται τα ~450 domains εκτός .gr (≤16 domains το καθένα).
