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
| D-14 | **Τιμολόγιο Ν μέρες πριν τη λήξη, ρύθμιση ανά εταιρεία** (`CreateDomainInvoiceDaysBefore`: myip 15· nixpal κενό → 7 των υπηρεσιών). **Υπενθυμίσεις λήξης** σε μέρες που ρυθμίζονται, **και μετά τη λήξη** (αρνητικές μέρες, template «Expired Domain Notice»): myip 60/30/15/10/5, nixpal 30/7/−3 (στο MyCS ενιαίο default `30,7,1,-3` και τιμολόγιο 15 μέρες πριν, ιδιοκτήτης 2026-10-17, D7-1). Χωριστό template για δωρεάν domains. **Auto-renew ενεργό by default.** **Ανανέωση on demand:** ο πελάτης (ή το staff) ζητά ανανέωση όποια στιγμή θέλει πριν βγει το τιμολόγιο (σήμερα μέσω καλαθιού: 872 checkouts στο myip)· βγαίνει τιμολόγιο για την επόμενη περίοδο και ανανεώνεται όταν πληρωθεί (D-1). Do-not-renew ανά domain. **Cut-over blocker.** | ιδιοκτήτης· `settings-map.md`· έλεγχος WHMCS |
| D-15 | Έλεγχος υπολοίπου στον registrar πριν την ανανέωση· αποτυχία → Pending Module Actions και ειδοποίηση staff· η πληρωμή του πελάτη **δεν** αποτυγχάνει ποτέ λόγω registrar. | whmcs-baseline §5· `provisioning.md` |
| D-16 | Μεταφορά: στέλνουμε **πάντα** ρητά τους τρέχοντες nameservers (με glue IP για NS μέσα στο ίδιο domain)· η λήξη υιοθετείται μόνο με `ACT`, όχι όσο είναι pending. | spike (live) |
| D-17 | Κάθε ενέργεια που χρεώνει στον registrar: lock ανά domain, log (αίτημα, απάντηση, τιμολόγιο), καμία δεύτερη εκτέλεση· **ένα live write test ανά ενέργεια** πριν το cut-over. | Ekdosi `GuardsRegistrarWrites`· spike |
| D-18 | **Τα domains που τελείωσαν μένουν** (expired, cancelled, transferred away, deleted) με το ιστορικό τους· τίποτα δεν σβήνεται. Στο myip ένα custom hook (`ExpiredDomains.php`) τα σβήνει κάθε μέρα από τη βάση, γι' αυτό ο importer δεν θα τα βρει. **Ξανά-εγγραφή του ίδιου ονόματος** (ο ίδιος πελάτης που το ξέχασε, ή άλλος μήνες μετά): νέα γραμμή, η παλιά μένει ως ιστορικό. «Τέλος» είναι μόνο deleted (το μητρώο το ελευθέρωσε), transferred away, cancelled· το expired/grace/redemption ανήκει ακόμα στον πελάτη (ανανέωση/ανάκτηση). Η βάση δεν δέχεται δεύτερη *ενεργή* γραμμή ανά όνομα (`live_fqdn`, generated). Το παλιό γίνεται deleted με το sync (Openprovider) ή με το cron μετά τη λήξη + grace + redemption (registrars χωρίς API)· αν έχει μείνει, η παραγγελία (D8) το κλείνει ως deleted (με audit) όταν ο registrar πει ότι το όνομα είναι διαθέσιμο. | έλεγχος WHMCS |
| D-19 | **ΦΠΑ στα domains** (`TaxDomains=on` και στα δύο). | έλεγχος WHMCS· `sweeps/billing.md` |
| D-20 | **Staff:** to-do για ό,τι θέλει χειροκίνητη ενέργεια (`DomainToDoListEntries`), admin emails «Renewal Successful/Failed», ημερήσια λίστα domains που λήγουν σύντομα (σήμερα μόνο .eu, 10 μέρες· γενικά ανά TLD). Registrar admin contact = τα στοιχεία του πελάτη. | έλεγχος WHMCS |
| D-21 | **Συνδέσεις registrar:** δικό τους μοντέλο και πίνακας (ο registrar **δεν** είναι extension). Τα credentials αποθηκεύονται ως κρυπτογραφημένα `Setting` της σύνδεσης (`settingable`), όπως των extensions: κρυπτογράφηση, καθαρισμός από τα logs (`Redact`), απόκρυψη στο audit, ποτέ πίσω στη φόρμα. Mode off/sandbox/production, «Test Connection», σύνδεση `manual` χωρίς API. Δεν σβήνεται όσο τη χρησιμοποιεί TLD ή domain. | ιδιοκτήτης· sweep |
| D-22 | **Ό,τι ξέρει ο registrar φαίνεται και διορθώνεται από το MyCS** (2026-10-16), ώστε να μη χρειάζεται το panel του: στο view όλα όσα φέρνει το API (status και id εκεί, ημερομηνίες, lock/lockable, privacy, DNSSEC, auto-renew εκεί, glue, handles), και ενέργειες για nameservers, lock, privacy, επαφές ανά ρόλο, EPP code (ποτέ αποθηκευμένο ο κωδικός που διαβάζεται από τον registrar· ο κωδικός **μεταφοράς** που δίνει ο πελάτης αποθηκεύεται κρυπτογραφημένος και το staff τον βλέπει στην παραγγελία, όπως στο WHMCS, για debugging: ιδιοκτήτης 2026-10-17, D8b), επαφές όπως τις έχει ο registrar· admin notes και auto-renew εδώ. Κάθε κλήση με lock ανά domain και log με ποιος τη ζήτησε. | ιδιοκτήτης |

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

## 2b. Ειδοποιήσεις λήξης: τι κάνουν σήμερα (έλεγχος μόνο ανάγνωσης, 2026-10-17)
Μόνο ρυθμίσεις, ονόματα templates, κώδικας hooks και αθροιστικά· κανένα στοιχείο πελάτη.
- **WHMCS nixpal** (`clnixpal_nixcldb`, orion): `DomainRenewalNotices = 30,7,-3`, `CreateDomainInvoiceDaysBefore` κενό
  (→ 7 των υπηρεσιών), `DomainAutoRenewDefault` on, `AutoRenewDomainsonPayment` on, `DomainExpirationFeeHandling =
  existing`, `FreeDomainAutoRenewRequiresProduct` on, `EnableDomainRenewalOrders` on, `DomainToDoListEntries` on.
  Templates (μη τροποποιημένα): «Upcoming Domain Renewal Notice», «Upcoming Free Domain Renewal Notice», «Expired Domain
  Notice», «Domain Renewal Confirmation», και τα registration/transfer.
- **WHMCS myip** (`myipgr_clients`, rigel): `DomainRenewalNotices = 60,30,15,10,5` (μόνο πριν τη λήξη),
  `CreateDomainInvoiceDaysBefore = 15`, τα υπόλοιπα όπως της nixpal. Ελληνικό θέμα ανά μέρα «Λήξη σε N ημέρες: domain»
  (~250–300 ανά ημέρα-ειδοποίησης τον χρόνο) και «Ενημέρωση λήξης domain - Upcoming Domain Renewal Notice» (2.256 τον
  χρόνο). Custom templates: «Domain EPP Request», «Domain Unlock Request», «Domain ID Protection Request», «Αλλαγή
  ονόματος ιδιοκτήτη - επωνυμίας» (αιτήματα πελάτη που σήμερα πάνε με email· στο portal γίνονται ενέργειες, D7-2).
- **Hooks της myip** (`includes/hooks`, μόνο ανάγνωση κώδικα):
  - `expiring_eu_domains.php`: κάθε μέρα λίστα στο staff με τα active **.eu** που λήγουν σε 10 μέρες (`sendadminnotification`).
    → Γενικεύεται στη λίστα «λήγουν σύντομα» του staff (D-20), όχι μόνο για .eu.
  - `auto-cancellation.php`: ακυρώνει **απλήρωτα τιμολόγια μόνο-domain** ενός domain «Expired» 30 μέρες μετά την
    έκδοσή τους (και παραγγελίες/add funds 31/30 μέρες). → Ανοιχτό για εμάς: σήμερα ένα domain που πεθαίνει αφήνει το
    τιμολόγιό του ανοιχτό· απόφαση στο D7-1 (παρακάτω).
  - `ExpiredDomains.php`: σβήνει εγγραφές Expired/Cancelled/Transferred Away (με λάθη: η «grace» δεν ορίζεται, οι μέρες
    δεν χρησιμοποιούνται). → **Δεν** μεταφέρεται: κρατάμε το ιστορικό (D1: αρχειοθέτηση με guard, όχι διαγραφή).
  - `domain_manual_renewal.php`: μετά από ανανέωση `nextduedate = expirydate` και .gr transferred → Active. → Δεν
    χρειάζεται: ένα ρολόι (D-2, D-14).
- **Blesta** (Domain Manager, [docs](https://docs.blesta.com/display/user/Domain+Manager)): τρεις ειδοποιήσεις με δικό
  τους template, **1η 26–35 μέρες πριν** (default 35), **2η 4–10 πριν** (default 10), **λήξη 1–5 μετά** (default 1),
  σύμφωνα με το ICANN ERRP. Γνωστό bug τους (CORE-5332, CORE-5482): η ειδοποίηση έδειχνε την εσωτερική ημερομηνία
  ανανέωσης αντί για τη λήξη όταν είχε ήδη βγει τιμολόγιο. → Εμείς δείχνουμε πάντα τη λήξη του domain.
- **ICANN ERRP** (gTLDs: .com/.net/.org κ.λπ.): ο registrar στέλνει ~1 μήνα και ~1 εβδομάδα πριν, και μία έως 5 μέρες
  μετά τη λήξη. Το 30/7/−3 της nixpal το καλύπτει· το 60/30/15/10/5 της myip δεν έχει ειδοποίηση μετά τη λήξη.
- **Συμπέρασμα για το D7-1:** ρύθμιση `domain_reminder_days`· μετά τη συζήτηση με τον ιδιοκτήτη **default `30,7,1,-3`
  και για τις δύο** (ICANN· τιμολόγιο 15 μέρες πριν, χωρίς ειδοποίηση την ίδια μέρα)· templates «domain_renewal_notice»,
  «domain_free_renewal_notice», «domain_expired_notice» (en/el)· μόνο domains με auto renew (do-not-renew: καμία, όπως
  WHMCS)· όχι όταν η επόμενη περίοδος είναι ήδη πληρωμένη· με το ανοιχτό τιμολόγιο ανανέωσης και «θα χρεωθεί αυτόματα»
  όπου ισχύει· μετά τη λήξη μόνο όσο ανακτάται (ως το τέλος grace/redemption).

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
   grace/redemption μπαίνουν με το χέρι ανά TLD ως ότου δούμε τι δίνει το API (§8). Σημειώσεις:
   - Μία στρογγυλοποίηση, από την ακριβή τιμή (κόστος → ισοτιμία → margin), πάντα προς τα πάνω· το 0 μένει 0.
   - Το σταθερό margin είναι στο νόμισμα της τιμής (+5 = +5 EUR στις EUR, +5 USD στις USD).
   - Τίποτα τσεκαρισμένο εξ ορισμού· εισάγεται μόνο από τη σύνδεση που έκανε το Fetch· τα κόστη του Fetch δεν
     δέχονται τιμή από τον browser.
   - Οι δωρεάν (0,00) και οι «δεν προσφέρεται» τιμές κρατιούνται, εκτός αν το staff πει αλλιώς· όσα δεν ζητήθηκαν
     (άλλα νομίσματα, περισσότερα έτη, grace) μένουν ως έχουν· TLD χωρίς καμία τιμή δεν δημιουργείται.
   - Αρνητικό ή ακατανόητο κόστος από τον registrar αγνοείται.
4. **D4 Sync** (#161): `domains:sync` κάθε 4 ώρες (και `--dry-run`: η αναφορά διαφορών, τίποτα γραμμένο),
   «Sync now» στο domain (`admin.domains.update`), ο κύκλος ζωής από τις ημερομηνίες (expired → grace → redemption →
   deleted με τις μέρες του TLD ή του domain· πίσω σε active όταν η λήξη πάει μπροστά· χωρίς γνωστές μέρες μόνο
   expired), `domains:adopt` (ένα domain που είναι ήδη στον registrar, σε λογαριασμό: μόνο ανάγνωση εκεί), έλεγχος
   «domains» στο health (σφάλματα sync, μεταφορές pending 14+ μέρες). Οι αλλαγές του cron γράφονται στο audit.
   Code + adversarial (κύκλος ζωής). Κανόνες μετά τα reviews:
   - Μόνο εγγραφές **αυτού του ονόματος** από τον registrar (μια αναζήτηση μπορεί να φέρει άλλο domain).
   - Μόνο προς τα εμπρός: active ξανά μόνο με λήξη στο μέλλον· λήξη που πάει **πίσω** δεν εφαρμόζεται, γράφεται ως
     σφάλμα για το staff· τα deleted και τα τέλη του staff δεν συγχρονίζονται (η ανάκτηση με δική της ενέργεια).
   - Ποτέ πέρα από ό,τι ξέρουμε: χωρίς μέρες grace μόνο expired· grace χωρίς redemption → grace και μετά expired·
     deleted από τις ημερομηνίες **μόνο** χωρίς API (με API το λέει ο registrar).
   - Audit μόνο όταν κάτι αλλάζει (το `last_synced_at` είναι λογιστική)· οι αλλαγές nameservers φαίνονται στο audit.
   - «Add existing domain» στο admin (`admin.domains.create`) = `domains:adopt`: μόνο ενεργό domain του ονόματος, με
     λήξη· νόμισμα του λογαριασμού (τελευταίο τιμολόγιο ή υπηρεσία) ή default. Domain **χωρίς πελάτη** (αδέσποτο):
     με το D6 (εισαγωγή όλου του portfolio και «ανάθεση σε πελάτη»).
4b. **D4b Διαχείριση από το admin** (D-22): το sync γράφει και ημερομηνία εγγραφής, handles, σύνδεση με το TLD όταν
   λείπει και τα στοιχεία του registrar (`meta.registrar`, από allow-list: ποτέ auth code). «At the registrar»:
   nameservers (2–13, glue από το DNS για NS μέσα στο domain), lock, privacy, επαφές (του λογαριασμού· νέα επαφή → νέο
   handle· αν ο registrar αρνηθεί, οι ρόλοι μένουν ως ήταν), «Contacts at the registrar», «Get EPP code» (δείχνεται μία
   φορά, ούτε στο log). «Edit»: auto-renew και admin notes, μόνο εδώ. Permissions `admin.domains.manage`,
   `admin.domains.epp`. Καρτέλα «Registrar log» (με ποιος, `staff_id`). Καμία ενέργεια που χρεώνει: το restore πάει στο D5.
   Code + adversarial. Κανόνες μετά τα reviews:
   - Handle από τον registrar μόνο σε ρόλο **με** επαφή και χωρίς handle, και **μόνο αν η εγγραφή του στον registrar έχει
     το email της επαφής** (αλλιώς θα έδενε την επαφή μας με άλλο πρόσωπο εκεί)· αλλιώς ή διαφορετικό handle → σημείωση
     στο sync_error, ποτέ σιωπηλή σύνδεση. Όλα τα handles του registrar φαίνονται στα στοιχεία του.
   - **Αλλαγή registrant** μόνο όπου ο registrar λέει `modify_owner_allowed`· αλλιώς είναι trade (χρέωση, έγκριση
     κατόχου) και πάει στο D5. Αλλαγή άλλων ρόλων αρνείται όσο ο registrant δεν είναι συνδεδεμένος (θα έφευγε ως νέος
     κάτοχος).
   - Customer που έφτιαξε ένα domain για μια επαφή κρατιέται (`meta.contact_handles`) και ξαναχρησιμοποιείται σε retry.
   - Το sync παίρνει το ίδιο lock με τις εγγραφές: δεν γράφει πάνω σε αλλαγή που γίνεται.
   - Ημερομηνία εγγραφής μόνο αν λείπει (ο importer του D6 κρατά τη δική του).
   - Χωρίς απάντηση (timeout) τίποτα δεν αναιρείται: ξαναδιαβάζεται ο registrar (και δεν δένει handle που δεν επαληθεύεται).
     Άρνηση στις επαφές → οι ρόλοι πίσω (με audit).
   - Το EPP code φαίνεται μόνο μέσα στο modal (όχι notification, που το Filament κρατά σε cache).
   - Lock 10 λεπτά ανά domain (όσο η μεγαλύτερη κλήση).
   - Το WHOIS privacy εδώ είναι το `id_protection` του domain· το D5 **δεν** το χρεώνει επειδή το άλλαξε το staff
     (να επιβεβαιωθεί ότι ο Openprovider δεν το χρεώνει πριν το D5).
4c. **D4c Επαφές από το domain** (ιδιοκτήτης 2026-10-16): κάθε ρόλος δείχνει το handle του (συνδεδεμένο, ή «μόνο στον
   registrar»: `meta.registrar_handles` από το sync), «At the registrar» (η εγγραφή του handle εκεί), «Edit contact» (η
   επαφή του λογαριασμού, `admin.clients.update`· **δεν** στέλνεται στον registrar) και **«Link here»**
   (`admin.domains.manage`): το staff λέει ποιος είναι ένα handle που δεν συνδέθηκε αυτόματα (π.χ. ο τελικός πελάτης
   ενός agency, με άλλο email). Σε υπάρχουσα επαφή του λογαριασμού (η εξαίρεση στον κανόνα του email του D4b, με την
   ευθύνη του staff), ή σε **νέα επαφή** από την εγγραφή του registrar (θέλει και `admin.clients.update`). Κάθε ρόλος με
   το ίδιο handle συνδέεται μαζί. Τίποτα δεν στέλνεται στον registrar· audit και log. Ένα handle που συνδέθηκε έτσι
   (`meta.linked_handles`) δεν «δανείζεται» σε άλλο ρόλο της ίδιας επαφής σε μια αλλαγή επαφών. Το re-read μετά τη
   σύνδεση είναι best-effort. Κανόνες μετά το adversarial: η σύνδεση διαβάζει πρώτα τον registrar κάτω από το lock και
   αρνείται αν το handle άλλαξε ή ο ρόλος συνδέθηκε στο μεταξύ· μαζί συνδέονται μόνο ρόλοι **χωρίς** επαφή εδώ (ποτέ δεν
   αλλάζει ο registrant επειδή συνδέθηκε ο admin)· «νέα επαφή» για handle που ήδη έχει επαφή εδώ = εκείνη η επαφή.
   Αλλαγή επαφών: ρόλοι χωρίς handle **δεν** στέλνονται (ο registrar κρατά τους δικούς του, αντί για fallback στον
   registrant), και ρόλος με επαφή που δεν είναι συνδεδεμένος με το handle του registrar αρνείται ως ότου συνδεθεί. Μαζί: σελίδα domain σε δύο ανεξάρτητες στήλες· ο ρόλος #1 (`*`) δεν προσφέρεται για
   επεξεργασία (ήταν σκέτο 403)· το εύρος του D8 (ζήτησε ο ιδιοκτήτης στο ίδιο PR). Code + adversarial.
5a. **D5a Grace και redemption από τον registrar** (2026-10-17, χωρίς χρεώσεις): ο Openprovider δίνει ανά TLD
   `soft_quarantine_period` (= grace: η ανανέωση γυρνά στην κανονική τιμή) και `quarantine_period` (= redemption: με
   τέλος restore)· 0 = το στάδιο δεν υπάρχει (π.χ. .com 40/0: «Restore + Renew charges», χωρίς hard quarantine, το
   μητρώο πίσω του, Verisign, ορίζει τι επιτρέπεται). Το «Sync costs» φέρνει τις μέρες και τα κόστη: το `restore_price`
   πάει στο redemption όπου το TLD έχει hard quarantine, αλλιώς στο grace· το `soft_restore_price` στο grace ενός TLD με
   τα δύο. Το import (επιλογή «grace and redemption fees») βάζει τιμές με το margin. Ανά domain: ένα ληγμένο domain του
   Openprovider είναι `DEL` **μέσα** στην quarantine, με `soft_quarantine_expiry_date` / `hard_quarantine_expiry_date`
   (ώρα Amsterdam)· το sync τις κρατά (`grace_ends_on`, `redemption_ends_on`) και το κάνει grace/redemption, **ποτέ**
   deleted όσο τρέχει κάποια (πριν: deleted, ενώ σωζόταν). Χωρίς αυτές, λήξη + μέρες του TLD (redemption μόνο μετά από
   γνωστό grace). Η σελίδα του domain δείχνει «Grace until … (N days left)». Μένει να επιβεβαιωθεί στο πρώτο πραγματικό
   restore ότι το .com χρεώνει restore μέσα στη soft quarantine (το registrar log θα το δείξει). Code + adversarial.
   Κανόνες μετά τα reviews:
   - **Οι μέρες του registrar κερδίζουν:** το «Sync costs» γράφει τις μέρες grace/redemption του TLD από τον registrar
     του (αλλαγή του staff ή του WHMCS import αναιρείται στο επόμενο sync· ένα re-run του catalog import τις ξαναγράφει
     από το WHMCS ως το επόμενο sync). Το import γράφει μέρες μόνο από τη σύνδεση του ίδιου του TLD.
   - TLDs που τιμολογήθηκαν πριν το D5a: **ξανά import με την επιλογή των τελών** (το restore του .com πήγε από το
     redemption στο grace· το sync κόστους δεν φτιάχνει γραμμές).
   - Οι ημερομηνίες είναι σε επίπεδο ημέρας (Amsterdam, όπως τις κρατά ο Openprovider, και για το sync και για τον
     κύκλο ζωής). Το D5b **ξαναδιαβάζει τον registrar** πριν από κάθε restore ή τέλος (όπως το `RenewalPlan`).
   - Όσο υπάρχουν ημερομηνίες του registrar **και ο registrar του έχει API**, κερδίζουν τη λήξη (σβήστηκε πριν τη λήξη
     του)· τις σβήνει ένα sync που το βρίσκει ξανά active, ή μια νέα (μεταγενέστερη) λήξη. Ένα domain που πέρασε σε
     manual registrar πάει ξανά με λήξη + μέρες. Αρνητικές ή ακατανόητες μέρες/ημερομηνίες του registrar = «δεν
     αναφέρθηκαν». Μια παραγγελία σε εκκρεμότητα δεν μετακινείται από το παρελθόν του ονόματός της. Ο κύκλος
     ζωής δεν πάει πίσω από grace/redemption σε expired.
   - Domains που το παλιό sync είχε κάνει deleted ενώ ήταν σε quarantine δεν διορθώνονται αυτόματα (κανένα σήμερα).
5b-1. **D5b-1 Τιμολόγηση** (2026-10-17, χωρίς κλήσεις στον registrar): το domain είναι γραμμή τιμολογίου
   (`reference_type = Domain`, `InvoiceItemReference`)· δικό του βήμα στο `invoices_created` του cron:
   `domain_invoice_days` ημέρες πριν τη λήξη (κενό = όσο οι υπηρεσίες, `cronjob_invoice`), για τα domains active,
   expired, grace, redemption με auto renew. Περίοδος από τη λήξη για τα έτη του, τιμή `recurring_amount` αλλιώς η renew
   του TLD (χωρίς τιμή: σημείωση, δεν χρεώνεται), due = λήξη (αν πέρασε: σήμερα). Μία φορά ανά περίοδο, ό,τι κι αν έγινε
   το τιμολόγιό της (ακυρωμένο από το staff = δεν ξαναβγαίνει· το staff το ξανανοίγει ή κλείνει το auto renew).
   Ομαδοποίηση με τις ανανεώσεις υπηρεσιών της ίδιας ημέρας (εκτός «Separate invoices»), email, πίστωση και auto-charge
   όπως εκείνες. Σε grace/redemption: το τέλος του σταδίου (`stageFee`: override του domain, αλλιώς του TLD) στο ανοιχτό
   τιμολόγιο της ανανέωσης, ένα ανά τιμολόγιο (του grace γίνεται του redemption)· ποτέ σε τιμολόγιο με χρήματα (σημείωση
   στο staff). Γραμμές τέλους: `invoice_items.kind` (`grace_fee`, `redemption_fee`). Μια πληρωμένη ανανέωση domain
   **περιμένει** (paid, not applied) το D5b-2 που την ανανεώνει στον registrar· ποτέ «applied» χωρίς αυτό. ΦΠΑ με τους
   κανόνες του πελάτη (D-19). Code + adversarial. Κανόνες μετά το code review:
   - **`domain_billing_enabled`** (κλειστό): η τιμολόγηση domains ανοίγει μαζί με το D5b-2, ποτέ τιμολόγιο που δεν θα
     ανανέωνε τίποτα.
   - Μια πληρωμένη ανανέωση domain αφήνει τη γραμμή της με `applied_at` κενό· το τιμολόγιο εφαρμόζεται κανονικά (όχι
     καθημερινή επανάληψη, όχι δεύτερο Paid). Το D5b-2 βρίσκει τις πληρωμένες γραμμές domain χωρίς `applied_at`.
   - «Χρεώθηκε» = γραμμή με περίοδο που περνά τη λήξη (μια λήξη που μετακινήθηκε μία μέρα είναι η ίδια περίοδος). ~~ή
     γραμμή χωρίς περίοδο σε ανοιχτό τιμολόγιο~~ (αντικαταστάθηκε από το #174: μόνο περίοδοι, βλ. 5b-2).
   - Όχι τιμολόγιο μετά την τελευταία ημέρα ανάκτησης (σημείωση)· σε grace/redemption 1 έτος (D-13).
   - Οι τιμές του καταλόγου είναι όπως τις βλέπει ο πελάτης: στη γραμμή καθαρές (Δ1, `toNet`).
   - Το τέλος σταδίου είναι ένα ανά ανανέωση (και αν το staff το μετέφερε)· αλλαγή συνόλου σε τιμολόγιο που στάλθηκε →
     ξανά email πριν από χρέωση· σημείωση μία φορά ανά τιμολόγιο και στάδιο.
   - Auto-charge και υπενθυμίσεις («θα χρεωθεί»): domains με auto renew και όχι τελειωμένα.
   - Ομαδοποίηση μόνο μέσα στο ίδιο run: με διαφορετικές μέρες (domain 15, υπηρεσίες 7) ένα domain και μια υπηρεσία
     της ίδιας ημέρας βγαίνουν σε διαφορετικά τιμολόγια.
   - Για το D5b-2: ποτέ lock του domain κρατώντας lock τιμολογίου/πελάτη (σειρά: domain → πελάτης → τιμολόγιο)·
     `billsEndedService` και MoneyCheck (`paidForCancelled`, `openForCancelled`) να δουν και domains.
   - Μετά το adversarial: η πίστωση δεν πληρώνει ανανέωση domain τελειωμένου ή χωρίς auto renew· ένα τέλος που το staff
     χάρισε (ακυρωμένο τιμολόγιο) δεν ξαναμπαίνει· ~~γραμμή ανανέωσης χωρίς περίοδο (του staff) του τελευταίου έτους
     μετρά ως χρεωμένη~~ (αντικαταστάθηκε από το #174: η ανανέωση με το χέρι ζητείται ρητά και παίρνει περίοδο)· ανανέωση ήδη πληρωμένη που πέφτει σε στάδιο με τέλος → σημείωση στο staff (μία φορά).
   - **Για το D5b-2:** μια πληρωμένη ανανέωση N ετών σε redemption δίνει τα N έτη: restore (1 έτος) και μετά renew τα
     υπόλοιπα (adversarial ADV-11).
5b-2. **D5b-2 Πληρωμή → registrar** (`DomainRenewalService`): η πληρωμή παραδίδει τη γραμμή σε job μετά το commit
   (η πληρωμή δεν περιμένει ποτέ τον registrar, D-15)· το cron (`domains_renewed`) ξαναπιάνει όσες πληρωμένες γραμμές
   δεν εφαρμόστηκαν (χαμένο job, registrar κάτω). Με lock ανά domain:
   - **Πρώτα ο registrar** (D-2): sync· αν η λήξη του καλύπτει το τέλος της πληρωμένης περιόδου (±3 μέρες) → **adopt**,
     καμία χρέωση (ανανέωση αλλού, «Ανανέωση τώρα», προηγούμενη προσπάθεια).
   - **Μία φορά ανά τιμολόγιο** (D-17): κάθε χρέωση γράφεται «pending» στο log **πριν** ζητηθεί· χωρίς απάντηση
     (timeout) μένει pending και δεν ξαναζητείται για 7 μέρες (το επόμενο run κοιτά αν φάνηκε)· μετά, staff.
   - ACT → renew για τα πληρωμένα έτη· σε grace/redemption στον registrar (`DEL` μέσα σε quarantine) → **restore** (1
     έτος) και renew τα υπόλοιπα (ADV-11)· οτιδήποτε άλλο → staff.
   - Manual registrar, τελειωμένο domain, άρνηση, άγνωστη έκβαση → **Pending Module Action** (`domain_renew`, με το
     domain και τη γραμμή)· «Retry» ξανατρέχει, «Mark resolved» = ανανεώθηκε με το χέρι: εφαρμόζει τη γραμμή και πάει
     τη λήξη στο τέλος της περιόδου.
   - **«Ανανέωση τώρα»** (`admin.domains.renew`, D-1): τιμολογεί αν δεν έχει τιμολογηθεί η περίοδος, ανανεώνει στον
     registrar με log στο τιμολόγιο· η μεταγενέστερη πληρωμή βρίσκει την περίοδο καλυμμένη και την υιοθετεί.
   - Η γραμμή εφαρμόζεται (`applied_at`) όταν ο registrar καλύπτει την περίοδο ή χρέωσε για αυτήν.
   - Μετά τα reviews: **άρνηση** του registrar = 4xx (`RegistrarRefused`, τίποτα δεν έγινε)· οτιδήποτε άλλο μετά την
     αποστολή (5xx, timeout) = **άγνωστη έκβαση**, μένει pending. Μια pending χρέωση του domain (από οποιαδήποτε γραμμή,
     τιμολόγιο ή «Ανανέωση τώρα») μπλοκάρει κάθε νέα. Τα έτη που χρεώνονται = όσα λείπουν από τη λήξη του registrar
     ως το τέλος της πληρωμένης περιόδου, μείον όσα έχουν ήδη χρεωθεί (OK) για τη γραμμή: δύο πληρωμένες περίοδοι με
     οποιαδήποτε σειρά παίρνουν ακριβώς τα έτη τους, ένας registrar που αργεί να δείξει τη λήξη δεν ξαναχρεώνεται.
     Restore αυτόματα **μόνο σε grace** (soft quarantine) και μόνο με πληρωμένο το τέλος του· redemption → staff.
     «Ανανέωση τώρα» αρνείται όσο υπάρχει pending χρέωση, πληρωμένη ανανέωση σε εξέλιξη, ή ανανέωση που έγινε και
     περιμένει πληρωμή. Pending Module Actions του domain: «Retry», «Renewed by hand» (με API: μόνο αν ο registrar
     το δείχνει, η λήξη είναι του registrar· manual: η λήξη πάει στο τέλος της περιόδου), «Close (not renewed)» (κλείνει
     και τις pending χρεώσεις ως «δεν έγινε» αφού το staff έλεγξε τον registrar)· `admin.domains.renew`· όχι μαζικά.
     Health: οι ανανεώσεις domain που περιμένουν staff είναι warning.
   - **Τι είναι μια γραμμή domain** (review R6 του #172, #174 με τα reviews του): **ανανέωση μόνο με την περίοδό της**
     (του cron, «Ανανέωση τώρα», «Invoice next period») ή όταν ζητηθεί ρητά (Admin API `kind=renewal`: παίρνει την
     πρώτη περίοδο του domain που δεν καλύπτει ζωντανό τιμολόγιο, από τη λήξη, 1 έτος σε grace/redemption· με lock του
     domain· όχι για domain χωρίς λήξη). **Χωρίς περίοδο = `charge`** (αλλαγή ιδιοκτήτη, ένα τέλος): ποτέ ανανέωση, ποτέ
     «χρεώθηκε». Η ασφαλής πλευρά by default: καμία ανανέωση στον registrar επειδή ξεχάστηκε να δηλωθεί «charge».
     Το είδος και το domain μιας γραμμής **δεν αλλάζουν** αφού γίνει (μόνο τέλος grace → τέλος redemption, από το cron).
     Το `billedLine` κοιτά **μόνο περιόδους που καλύπτουν** τη λήξη (±31 μέρες για λήξη που μετακίνησε ο sync), όχι ένα
     μεταγενέστερο έτος πέρα από κενό. Migration `2026_11_25`: όσες γραμμές domain χωρίς περίοδο υπήρχαν γίνονται `charge`
     (στο live: καμία). Το API δείχνει `kind` και περίοδο.
   - Η ρύθμιση **«Bill domain renewals»** είναι πλέον **ανοιχτή by default** (ιδιοκτήτης 2026-10-17): κλείνει μόνο
     όσο άλλο σύστημα χρεώνει ακόμα τα domains (cut-over του myip, D-12) ή σε έκτακτη ανάγκη στον registrar.
     Code + adversarial.
5c. **D7a «Invoice selected items»** (όπως στο WHMCS, ιδιοκτήτης 2026-10-17): η τιμολόγηση ανανεώσεων του cron
   μεταφέρθηκε αυτούσια στο `App\Services\Billing\RenewalBilling` (το cron την καλεί όπως πριν)· το
   `InvoiceSelectedItems` τη χρησιμοποιεί για ό,τι επιλέξει το staff: σελίδα πελάτη (checkboxes υπηρεσιών και domains),
   bulk στις λίστες Services/Domains του πελάτη, «Invoice next period» στη σελίδα υπηρεσίας και domain
   (`admin.invoices.create`).
   - Η **επόμενη μη τιμολογημένη περίοδος** κάθε επιλογής, τώρα, όποια κι αν είναι η λήξη της· **ένα τιμολόγιο ανά
     πελάτη και νόμισμα** (και με «Separate invoices»: το staff ζήτησε ένα)· λήξη = της νωρίτερης γραμμής. Ολοκληρώνεται
     (email, πίστωση) όπως κάθε τιμολόγιο ανανέωσης, **μόνο τα δικά του** (όχι όσα γεμίζει ένα cron που τρέχει).
   - Οι κανόνες του cron ισχύουν: ποτέ η ίδια περίοδος δύο φορές, ποτέ πάνω από κενό, όχι με ανοιχτό τιμολόγιο, όχι
     πληρωμή που δεν εφαρμόστηκε. Δεν τιμολογούνται, **με αιτία** στην ειδοποίηση: τελειωμένα, συνδρομές gateway,
     δωρεάν (δεν ανανεώνονται κιόλας), χωρίς πλάνο, με αίτημα ακύρωσης· domains χωρίς auto renew, εκτός κύκλου, ή με
     κλειστή τη ρύθμιση. Πληρωμένο, ένα domain ανανεώνεται στον registrar όπως στο D5b-2.
   - Ένα domain τιμολογείται **από τη λήξη του**: όταν αυτή η περίοδος έχει τιμολογηθεί (π.χ. πληρωμένη, περιμένει τον
     registrar), δεν τιμολογείται η μεθεπόμενη· η αιτία λέει σε ποιο τιμολόγιο είναι. Μια υπηρεσία χωρίς χρέωση την
     επόμενη περίοδο (επόμενο πλάνο, κουπόνι) ανανεώνεται αμέσως, όπως στο cron.
   - Όσο γεμίζει τα τιμολόγια ενός πελάτη κρατά lock ανά πελάτη· το cron δεν ολοκληρώνει τότε τα τιμολόγιά του (ένα
     email με όλες τις γραμμές). Οι λίστες της φόρμας μόνο με `admin.services.viewAny` / `admin.domains.viewAny`.
   - Τιμολόγιο που ακυρώνεται **πριν θα τη χρέωνε το cron** δεν «παραιτείται» από την περίοδο (ADV-4, ιδιοκτήτης
     2026-10-17, `billing-cursor.md` §Δ3a): το cron τη χρεώνει στην ώρα της, υπηρεσία ή domain.
5. **D5 Χρέωση:** γραμμή `Domain` στο τιμολόγιο, βήμα τιμολόγησης με ομαδοποίηση, κλάδος στην πληρωμή, renew on-paid, «Ανανέωση τώρα», adopt, fee grace/
   redemption στο υπάρχον τιμολόγιο, Pending Module Actions. Code + adversarial.
6. **D6 Importer WHMCS:** domains, τιμές, TLDs, ρόλοι επαφών, additional fields· parity και σύγκριση με τον
   registrar (`importer.md`). Code + adversarial.
7. **D7 Υπενθυμίσεις και portal:** δύο PRs.
   - **D7-1 Ειδοποιήσεις λήξης** (`DomainReminders`, βήμα cron `domain_reminders` μετά τις υπενθυμίσεις τιμολογίων·
     ευρήματα και επιλογές στο §2b). Ενιαία defaults (ιδιοκτήτης 2026-10-17): ειδοποιήσεις **30, 7, 1 μέρα πριν και 3
     μετά** (`domain_reminder_days`), τιμολόγιο **15 μέρες πριν** (`domain_invoice_days`). Templates `domain_renewal_notice`,
     `domain_free_renewal_notice`, `domain_expired_notice` (en/el). Μία ανά domain, μέρα και λήξη (`domain_reminders`)·
     catch-up 3 μέρες. Καμία για do-not-renew, καμία όταν η επόμενη περίοδος είναι πληρωμένη, η «τελευταία» (≤1 μέρα)
     όχι όταν χρεώνεται αυτόματα. Μετά τη λήξη μόνο σε expired/grace/redemption και ως την τελευταία μέρα ανάκτησης
     (όχι Active που δεν συγχρονίστηκε ακόμα). **Λένε τι κοστίζει η καθυστέρηση** (ιδιοκτήτης: ο σκοπός είναι να πληρωθεί πριν): πριν τη
     λήξη το τέλος του πρώτου σταδίου μετά από αυτήν, μετά τη λήξη το τέλος του σταδίου όπου βρίσκεται (το ίδιο που
     μπαίνει στο τιμολόγιο, D5b-1). Ένα τιμολόγιο μόνο ανανεώσεων τέτοιων domains **δεν** παίρνει και τις γενικές
     υπενθυμίσεις τιμολογίου (οι ειδοποιήσεις το αναφέρουν): ένα email, όχι δύο. Μόνο όταν οι ειδοποιήσεις όντως το
     καλύπτουν (code review R1): ημέρα ειδοποίησης από εκείνη την πλευρά της λήξης, template ενεργό, γραμμή της τρέχουσας
     λήξης, domain που παίρνει ειδοποιήσεις· αλλιώς το τιμολόγιο κρατά τις δικές του.
   - **D7-2 Portal** (`/domains`, `Livewire\Domains`): λίστα με φίλτρα (ενεργά, ληγμένα, τερματισμένα) και αναζήτηση,
     όπως το «Τα Domain μου» του WHMCS· σελίδα domain με ημερομηνίες, κατάσταση, «ανακτάται έως» σε grace/redemption.
     Βλέπει με `domains`, διαχειρίζεται με `managedomains` (`DomainPolicy::update`, όχι τερματισμένο):
     - **Auto renew** on/off (off = δεν ανανεώνεται: κανένα τιμολόγιο, καμία ειδοποίηση· με επιβεβαίωση).
     - **Nameservers** (2–13), **κλείδωμα μεταφοράς**, **ID protection**, **EPP code** (διαβάζεται όταν ζητηθεί, ποτέ
       αποθηκευμένο ή στη σελίδα): μέσω του ίδιου `DomainManagementService` με το staff (lock ανά domain, log)· το log
       γράφει **τον χρήστη του πελάτη** (`domain_registrar_logs.user_id`, D-22) και το admin το δείχνει· έως 10 αλλαγές
       το λεπτό ανά χρήστη· registrar χωρίς API → «επικοινωνήστε μαζί μας».
     - **«Ανανέωση»**: στο ανοιχτό τιμολόγιο ανανέωσης αν υπάρχει, αλλιώς τιμολογεί την επόμενη περίοδο (D7a, οι κανόνες
       του cron)· ένα domain με auto renew off ξαναμπαίνει σε ανανέωση (το ζήτησε).
     - Ξένο domain ή ενέργεια χωρίς δικαίωμα → 404, όπως οι υπηρεσίες. Μενού «Domains» μόνο σε όποιον έχει domains.
     - Επόμενα: επεξεργασία επαφών από τον πελάτη (D4c υπάρχει για staff), DNSSEC, D8 (αναζήτηση/παραγγελία).
     - **Ανοιχτό (ιδιοκτήτης 2026-10-17, «θα δούμε αργότερα»):** η «Ανανέωση» θέλει και `invoices` (φτιάχνει τιμολόγιο,
       εφαρμόζει πίστωση· review R3 του #176). Ένας χρήστης μόνο με `managedomains` διαχειρίζεται το domain αλλά δεν το
       ανανεώνει. Στο WHMCS η ανανέωση περνά από το καλάθι και θέλει `orders`· να αποφασιστεί αν ταιριάζει εκείνο.
   - **Τιμολόγιο domain που χάθηκε** (ιδιοκτήτης 2026-10-17): το τιμολόγιο ακολουθεί το domain (τέλος grace → τέλος
     redemption) και **ακυρώνεται όταν το domain χαθεί** (deleted, transferred away ή cancelled από staff), όχι σε σταθερές
     30 μέρες όπως το hook της myip. Βήμα cron `domain_invoices_cancelled` (πριν τη χρήση πίστωσης): τιμολόγιο μόνο του
     domain ακυρώνεται ολόκληρο, σε μικτό φεύγουν μόνο οι γραμμές ανανέωσης και τέλους σταδίου (`CancelOpenInvoices` με
     `$onlyFor` = domain)· ένα `charge` (π.χ. αλλαγή ιδιοκτήτη) και μια γραμμή χωρίς αναφορά μένουν. Μετά τα reviews
     (#175): **deleted** μετρά ως χαμένο μόνο μετά την τελευταία μέρα ανάκτησης (τέλος redemption· χωρίς ημερομηνίες,
     λήξη + μέρες του TLD, τουλάχιστον 30) γιατί ο registrar μπορεί ακόμα να το επαναφέρει με το χέρι· transferred
     away και cancelled αμέσως. Μένουν ανοιχτά για το staff (ειδοποίηση μία φορά τον μήνα ανά αιτία): όποιο έχει χρήματα,
     όποιο **ανανεώθηκε ήδη στον registrar** («Ανανέωση τώρα», ή χρέωση χωρίς απάντηση: το πληρώσαμε, οφείλεται),
     γραμμές που εφαρμόστηκαν, ή ό,τι μένει μηδενικό. Ένα πληρωμένο δεν αγγίζεται (D5b-2: Pending Module Action).
     Τιμολόγιο που το staff ξανάνοιξε μετά από αυτή την ακύρωση δεν ξανακυρώνεται. Όσα μένουν ανοιχτά παίρνουν ακόμα τις
     υπενθυμίσεις τους (το staff αποφασίζει). Δεν καλύπτει εισαγόμενα τιμολόγια του WHMCS (οι γραμμές Domain τους είναι
     χωρίς αναφορά ως το D6). Το `cronjob_end_keeps_overdue` (Δ3, απόφαση 4) αφορά υπηρεσίες· για domains αποφάσισε ο
     ιδιοκτήτης ακύρωση.
   - **Επόμενα** (ιδιοκτήτης 2026-10-17): D7-2 portal → **widget του staff** (ανανεώσεις domains/υπηρεσιών/servers που
     έρχονται, «σπασμένες» εγγραφές με κενή λήξη ή ημερομηνία πληρωμής, μεταφορές σε εξέλιξη, υπόλοιπο registrar: όπως το
     hack της myip στο dashboard του WHMCS) → D8a → D8b → **grepp (.gr EPP)** μετά το D8.
8. **D8 Παραγγελίες, αναζήτηση, whois, τιμοκατάλογος** (εύρος που ζήτησε ο ιδιοκτήτης 2026-10-16· πρώτα ιδέες από
   WHMCS και Blesta). Δύο PRs:
   - **D8a (χωρίς χρήματα):** αναζήτηση διαθεσιμότητας στο shop (μέσω registrar `checkAvailability`, RDAP ως fallback)
     με suggestions (διαθέσιμο / κατειλημμένο → «μεταφορά» ή εναλλακτικές), **δημόσιο whois** (RDAP, port 43 ως fallback,
     rate limit, χωρίς προσωπικά όπου το μητρώο τα κρύβει), **δημόσια σελίδα τιμοκαταλόγου** TLDs (register/renew/transfer
     ανά έτη και νόμισμα) και **JSON API τιμοκαταλόγου** (cached, rate-limited) για το site. `packages/domain-tools`
     έχει ήδη RDAP/port43/suggestions/rate limiter. Code + adversarial (κατάχρηση, enumeration).
   - **D8a έγινε** (`App\Livewire\DomainShop`, `DomainSearch`, `Lookup\DomainLookup`): **ένα κουτί** (ιδιοκτήτης
     2026-10-17, πιο απλό από το Blesta: χωρίς checkboxes TLDs) → πρώτα το ακριβές όνομα, μετά τα TLDs με τη σειρά του
     Domain Pricing (6), το καθένα «Διαθέσιμο / Κατειλημμένο» με τιμή ανά έτος όπως στον κατάλογο και «μετά Χ/έτος» όπου
     η ανανέωση διαφέρει· premium → «επικοινωνήστε». Διαθεσιμότητα από τον registrar του TLD, αλλιώς RDAP (cache 5′).
     Δημόσιο **whois** (`/whois`): RDAP → port 43 με μάσκα· .gr «όχι ακόμα» (grepp). **Τιμοκατάλογος** `/domain-pricing`
     και **JSON** `GET /api/v1/domain-pricing?currency=EUR` (cache 10′, 60/λεπτό). Όρια ανά IP: 30 αναζητήσεις και 10 whois
     ανά 10′. Μενού «Αναζήτηση domain» όταν υπάρχουν ενεργά TLDs. Οι τιμές σε νόμισμα του επισκέπτη· χωρίς τιμές σε αυτό,
     μήνυμα. Επόμενα: **προτάσεις ονομάτων** (Openprovider `suggest-name`, `history/blesta-modules/domain-tools.md`) και το
     κουμπί «Προσθήκη» (D8b).
   - **D8b-1 έγινε** (η παραγγελία): «Προσθήκη» (ελεύθερο) και «Είναι δικό σας; Μεταφέρετέ το» (κατειλημμένο) από την
     αναζήτηση → γραμμή domain στο καλάθι (`cart_items.domain`: όνομα, TLD, πράξη, έτη· τα έτη όσα τιμολογεί το TLD για
     την πράξη, D-5· ο κωδικός EPP χωριστά, κρυπτογραφημένος) → στο checkout ο **registrant** (η κύρια επαφή, ή άλλη του
     λογαριασμού με πλήρη στοιχεία· η ίδια και στους άλλους ρόλους, D-20) → `PlaceOrderService` ξαναελέγχει (`DomainOffer`:
     TLD, τιμή, ελεύθερο για εγγραφή / κατειλημμένο για μεταφορά, όχι δικό μας ήδη, IDN μόνο όπου επιτρέπεται, EPP όπου
     απαιτείται) και φτιάχνει domain `pending_register`/`pending_transfer` της παραγγελίας (`order_id`) με γραμμή
     τιμολογίου **`kind` register / transfer** (ποτέ ανανέωση: εκτός `waitingLines`/`billedLine`), καθαρή τιμή (ΦΠΑ D-19)·
     σε μηδενική τιμή (δωρεάν μεταφορά, D-6) χωρίς γραμμή. Απλήρωτη παραγγελία μετά από `cronjob_order_cancel` μέρες:
     domain cancelled, οι γραμμές του από το τιμολόγιο, το όνομα ελεύθερο ξανά. **Ο registrant** και **οι NS** (ιδιοκτήτης
     2026-10-17): η μεταφορά κρατά τους NS που έχει (από το μητρώο, RDAP)· η εγγραφή παίρνει τους default της εταιρείας
     (ρύθμιση, D8b-2)· ο πελάτης τους αλλάζει από το portal.
   - **D8b-2 (επόμενο):** μετά την πληρωμή εγγραφή/μεταφορά στον registrar (lock, log, ποτέ δύο φορές, Pending Module
     Actions `domain_register`/`domain_transfer`), default nameservers, emails.
   - **D8b (χρήματα):** register/transfer στο καλάθι (έτη του TLD, EPP code στη μεταφορά), τιμολόγιο, εγγραφή/μεταφορά
     μετά την πληρωμή. Code + adversarial.
   - **Whois ανά TLD (μητρώο), όχι ανά registrar** (ιδιοκτήτης 2026-10-17): «είναι διαθέσιμο;» → η σύνδεση registrar
     του TLD (`checkAvailability`: Openprovider σήμερα, grEPP `check` για το .gr αργότερα), RDAP ως fallback· «ποιος το
     έχει;» → αλυσίδα πηγών ανά TLD: RDAP (bootstrap του IANA) → port 43 (server του TLD από το IANA) → **adapter** για
     μητρώα εκτός προτύπων. Το **.gr** (ICS-FORTH) δεν έχει RDAP/port 43: HTTPS `plainWhois`/`plainDomainCheck`
     (`domains/grepp.md` §1· HTML, να ζητηθεί το τρέχον REST doc). Προαιρετική «πηγή whois» ανά TLD στο Domain Pricing.
     Νέος registrar δεν αλλάζει το whois· ένα ιδιόρρυθμο νέο TLD θέλει μόνο έναν adapter. Ο Openprovider δεν είναι
     δημόσιο whois (μόνο για τα δικά μας), μόνο fallback διαθεσιμότητας.
Μετά: CNIC module (αν προλάβουμε, D-10), .gr EPP (`packages/gr-epp`, Φάση 4: μαζί και η **ανάκληση** του .gr μέσα σε
5 μέρες από την καταχώριση), additional fields ανά ccTLD, DNSSEC (επεξεργασία· σήμερα φαίνεται μόνο αν είναι ενεργό).

## 7. Δοκιμές σε live
- **Domain δοκιμών: nixpal.com** (δικό μας, ήδη στο Openprovider από τη μεταφορά του spike), στον πελάτη δοκιμών
  του staff. Από το D2: σύνδεση με τον registrar, sync ημερομηνιών και nameservers, το cron, οι
  καταστάσεις· στο D5 μια ανανέωση από τιμολόγιο.
- Πρώτα μόνο ανάγνωση (login, info, sync)· **κάθε ενέργεια που χρεώνει** (renew, transfer, restore) μόνο με ρητή
  έγκριση του ιδιοκτήτη, μία φορά ανά ενέργεια (D-17).
- Τα credentials του registrar μόνο κρυπτογραφημένα στη βάση (σύνδεση registrar), ποτέ στο repo, σε logs ή στο chat.

## 8. Ανοιχτά
- ~~Openprovider: `expiration_date` ή `renewal_date`;~~ **Απαντήθηκε (2026-10-17, live nixpal.com):**
  `expiration_date`, `renewal_date` και `registry_expiration_date` είναι ίδια· κρατάμε `expiration_date` (όπως το
  blesta H4). Αν διαφέρουν σε άλλο TLD, φαίνονται και τα τρία στα στοιχεία του registrar (D4b).
- Ποια πεδία δίνει το API του Openprovider για grace/redemption ανά TLD (μέρες, τιμή restore, soft/hard
  quarantine): **πρώτο βήμα του D5** (σήμερα οι μέρες μπαίνουν με το χέρι ανά TLD, D3).
- Πριν το D5: ότι ο Openprovider δεν χρεώνει το WHOIS privacy (το `id_protection` που αλλάζει το staff, D4b).
- Νόμισμα στο import του D-6: το κόστος του registrar είναι σε ένα νόμισμα (Openprovider: EUR) και το MyCS **δεν έχει
  ισοτιμίες**. Για τα άλλα νομίσματα του `currencies`: ισοτιμία που δίνει το staff στη φόρμα του import (όπως η
  ισοτιμία του WHMCS), ή τιμή με το χέρι. Ποτέ σιωπηλή μετατροπή.
- Χρεωμένη αλλαγή ιδιοκτήτη (2 περιπτώσεις): με το χέρι ως τότε.
- Οι 145 additional fields του WHMCS: ποια χρειάζονται τα ~450 domains εκτός .gr (≤16 domains το καθένα).
