# MyCS: WHMCS importer με 1:1 parity (απαίτηση, 2026-10-11)

> Πρότυπο: το `migrate:firebird` του Ekdosi (`app/Console/Commands/MigrateFromFirebird.php`, 1264 γρ.), που έφερε
> περίπου 7.000 παραστατικά από το Ekdosi Legacy σε λεπτά και χωρίς απώλειες, και το `ekdosi:go-live-check`. Ο
> importer του upstream **δεν** χρησιμοποιείται (`history/audit-importer-health.md`: σβήνει τη βάση σε κάθε run, δεν
> περνάει ποσά, domains ή tokens).

## Αρχές (από το `migrate:firebird`)
1. **Re-runnable:** upsert στο `(legacy_id)`· κάθε εισαγόμενη γραμμή κρατάει το `whmcs_*_id` της. Το ίδιο run δύο
   φορές = ίδιο αποτέλεσμα. Γραμμές που δημιουργήθηκαν στο MyCS (χωρίς legacy id) δεν αγγίζονται ποτέ.
2. **Στήλες με ιδιοκτήτη:** οι στήλες που έρχονται από το WHMCS ανανεώνονται σε κάθε run· οι στήλες που διαχειρίζεται
   το MyCS επιβιώνουν.
3. **Id map ανά πίνακα** (legacy → new) για τα foreign keys. Όπου γίνεται, **ίδια IDs** (πελάτες, υπηρεσίες,
   τιμολόγια, tickets), ώστε τα παλιά νούμερα που γράφουν οι πελάτες στην αιτιολογία κατάθεσης να βρίσκονται.
   Αρίθμηση τιμολογίων: συνέχεια μετά το 32453 (myip).
4. **`--dry-run`:** μόνο ανάγνωση και preflight (συγκρούσεις, ορφανά, άγνωστα gateways/modules)· δεν γράφει τίποτα.
5. **`--counts-out`:** JSON με πλήθη ανά πίνακα, για σύγκριση με την πηγή.
6. **Πηγή = αντίγραφο της βάσης** (`migration/prepare_whmcs_copy.sh` εδώ), ποτέ η παραγωγή. Ρυθμίσεις gateways
   ξανακρυπτογραφημένες (`migration/reencrypt_gateway_settings.php`).
7. **Go-live check** (read-only, όπως το `ekdosi:go-live-check`): μπορεί αυτό το instance να πάρει πραγματικούς πελάτες;

## Parity: τι πρέπει να συμφωνεί 1:1 μετά το import
- **Πελάτες:** πλήθος ανά status· επαφές/sub-users· custom fields· credit balance ανά πελάτη (άθροισμα).
- **Υπηρεσίες:** πλήθος ανά status, κύκλο χρέωσης και προϊόν· next due date / λήξη· τιμή ανά υπηρεσία· config options·
  addons· server και username (τα credentials κρυπτογραφημένα).
- **Domains:** πλήθος ανά registrar/status· ημερομηνίες λήξης και next due· auto-renew, ID protection, DNS management·
  σύγκριση με το μητρώο μετά το import (sync).
- **Τιμολόγια:** πλήθος και **ποσά** ανά status και ανά μήνα· γραμμές (με αναφορά σε υπηρεσία/domain)· φόρος·
  πιστώσεις· due dates.
- **Πληρωμές:** άθροισμα ανά gateway και ανά μήνα· refunds· fees.
- **Αποθηκευμένοι τρόποι πληρωμής:** Stripe (customer + pm), PayPal vault (13/15 ελεγμένα), ανά πελάτη· η αυτόματη
  χρέωση διαλέγει τον σωστό.
- **Tickets:** πλήθος ανά τμήμα/status, **με συνημμένα** και replies· predefined replies.
- **Licensing (nixpal):** πλήθος ανά status· `zlic.php` byte-for-byte στα fixtures· shadow-run.
- **Pending υπηρεσίες με μόνο ακυρωμένα τιμολόγια** (BIL-12, #75): στο πρώτο run του cron μετά το cut-over
  ακυρώνονται όλες μαζί (παλαιότερες από `cronjob_order_cancel` ημέρες) και το stock τους επιστρέφει. Να μετρηθούν
  πριν από το cut-over και να αποφασίσει το staff (να γίνουν cancelled στο import ή να μείνουν).
- **Ρυθμίσεις αυτοματισμού:** από το `tblconfiguration` της εταιρείας στην καρτέλα Automation (`AutoSuspension`, `AutoSuspensionDays`, `AutoTermination`, `AutoTerminationDays`, `CreateInvoiceDaysBefore`, `CloseInactiveTickets` σε ώρες → ημέρες, `DailyCronExecutionHour`)· αντιστοίχιση στο `settings-map.md` (Αυτοματισμοί).
- **Mass Pay:** τα γονικά τιμολόγια δεν γίνονται τιμολόγια· η πληρωμή τους πάει στα παιδιά (`WhmcsMassPay`, PR F3,
  `payments.md`). Parity: πλήθος πληρωμένων γονικών = ομάδες με `whmcs_invoice_id`· χρήματα γονικών = άμεσα + allocations.
- **Ό,τι άλλο βγάλει η απογραφή χρήσης του WHMCS** (`whmcs-usage.md`): mass pay/merge, πιστώσεις, promotions και εκπτώσεις ομάδων, κανόνες ΦΠΑ/VIES, custom fields, suspend overrides, guest tickets.

## Status τιμολογίων (2026-10-13)
Το MyCS λέει ό,τι και το WHMCS, στη βάση και στις οθόνες: το «Unpaid» είναι `unpaid` (μέχρι 2026-10-13 ήταν `pending`,
λέξη του upstream· το migration `rename_invoice_status_pending_to_unpaid` μετέτρεψε τα παλιά). Οι οθόνες γράφουν
«Unpaid» / «Απλήρωτο» (`Invoice::statusLabel`). Status εκτός `Invoice::STATUSES` απορρίπτεται από το `InvoiceObserver`.
Αντιστοίχιση για τον importer:

| WHMCS | MyCS |
|---|---|
| Draft | `draft` |
| Unpaid, Payment Pending | `unpaid` (το «Payment Pending» είναι συναλλαγή `processing`, όχι status τιμολογίου) |
| Paid | `paid` |
| Cancelled | `cancelled` |
| Refunded | `refunded` (PR 2c) |
| Collections | να αποφασιστεί (δεν χρησιμοποιείται σήμερα· πρόταση: `unpaid` με σημείωση) |

Ο importer του upstream (`ImportFromWhmcs.php`) γράφει πλέον τις λέξεις του πίνακα για τα τιμολόγια. Δεν
χρησιμοποιείται· ο νέος importer ακολουθεί τον πίνακα.

## Status υπηρεσιών (2026-10-13)
Όπως στο WHMCS, ο τερματισμός και η ακύρωση είναι διαφορετικά (`Service::ENDED_STATUSES` = και τα δύο: δεν χρεώνονται,
δεν ανανεώνονται, δεν ξαναζωντανεύουν από πληρωμή). Status εκτός `Service::STATUSES` απορρίπτεται από το `ServiceObserver`.

| WHMCS | MyCS | Πότε το γράφει το MyCS |
|---|---|---|
| Pending | `pending` | παραγγελία που δεν ενεργοποιήθηκε |
| Active | `active` | |
| Suspended | `suspended` | |
| Terminated | `terminated` | cron για απλήρωτα (Termination Days), ή «Terminate server» από το staff αφού πετύχει |
| Cancelled | `cancelled` | αίτημα ακύρωσης (άμεσο ή στο τέλος της περιόδου), απλήρωτη παραγγελία που εγκαταλείπεται |
| Fraud | `cancelled` | δεν χρησιμοποιήθηκε στο WHMCS ως status υπηρεσίας· η εκτίμηση για απάτη γίνεται στην **παραγγελία** (IP, PTR, ίσως GeoIP/mmdb), βλ. Δ4 |
| Completed | να αποφασιστεί (Δ4) | |

Οι υπηρεσίες που τερματίστηκαν πριν από αυτή την αλλαγή έμειναν `cancelled` (δεν ξεχωρίζουν πια· δεν υπάρχουν
ζωντανά δεδομένα).

## Έλεγχος
- **Πριν το migration του myip, ο server:** OPcache όπως στο `install.md` («Μέγεθος server και PHP»), ελεύθερος δίσκος
  για τη βάση και ένα backup της (`df -h`), και ένα ζεστό `/admin` μετά το import (το πρώτο request μεταγλωττίζει).
- Κάθε αριθμός της λίστας parity βγαίνει **και από τις δύο πλευρές** με μία εντολή (π.χ. `mycs:parity --whmcs-copy=…`)
  και τυπώνει diff. Cut-over μόνο με μηδενικό diff ή με κάθε διαφορά εξηγημένη και καταγεγραμμένη.
- Tests του importer σε fixture βάση WHMCS (ανωνυμοποιημένη, CLAUDE.md), όπως τα `WhmcsSchema`/`BlestaSchema` του
  `lib/nixpal-licensing/tests`.
- Η nixpal πρώτη (31 ενεργοί, ~330 άδειες), μετά 2–3 δοκιμαστικά runs του myip πριν το cut-over.

## Πεδία υπηρεσίας (tblhosting, 2026-10-14)
`domain`, `username`, `dedicatedip`, `assignedips` (μία ανά γραμμή), `notes` (staff), `suspendreason` (μόνο σε
Suspended) περνούν στα native πεδία της υπηρεσίας. Ο **κωδικός** (`password`) είναι κρυπτογραφημένος με το
`cc_encryption_hash` του WHMCS, που δεν είναι στη βάση: θέλει δικό του βήμα πριν το cut-over (WHMCS API
`DecryptPassword` ανά υπηρεσία), και αποθηκεύεται εδώ κρυπτογραφημένος (encrypted cast). `ns1`/`ns2` (1 υπηρεσία)
δεν μεταφέρονται· disk/bw usage τα ξαναδιαβάζει το sync του cPanel. Server ανά υπηρεσία: `services.server_id` (και server groups, `server_groups`). Ο importer θα φέρει τους servers
του WHMCS (`tblservers`: όνομα, module, max accounts, χωρίς credentials, που τα συμπληρώνει το staff ή το βήμα των
κωδικών), τα groups (`tblservergroups`, `tblservergroupsrel`, `filltype` 1 = least full, 2 = in order), το
`servergroup` των προϊόντων και το `tblhosting.server` κάθε υπηρεσίας. Το `maxaccounts` 0 του WHMCS γίνεται null
(χωρίς όριο)· ένα `tblhosting.server` που δείχνει σε server που δεν υπάρχει μένει null (όχι ο server του προϊόντος). myip: 6 cPanel servers (657 λογαριασμοί),
7 groups· nixpal: 1 server.

## Κατάλογος (`app:import-whmcs-catalog`, 2026-10-16)
Ένα «carbon copy» της εγκατάστασης **χωρίς πελάτες** (απόφαση ιδιοκτήτη 2026-10-16): νομίσματα (το default του
WHMCS γίνεται default εδώ, εκτός με `--keep-default-currency`), product groups, products με plans και τιμές (WHMCS `-1` =
δεν προσφέρεται, `0.00` = δωρεάν και προσφέρεται), config options, upgrades, servers και server groups (**χωρίς** password
ή API token: τα βάζει το staff), το `package` των cPanel products, οι ρυθμίσεις των licensing products (χωρίς
licenses), τα TLDs με τις τιμές τους ανά έτη και νόμισμα (μόνο η βασική τιμή, όχι των client groups), τις μέρες
grace/redemption και τα τέλη τους (στο default νόμισμα του WHMCS· `0.00` = δωρεάν, `-1` = όχι). Κανένας λογαριασμός,
υπηρεσία, τιμολόγιο, παραγγελία ή ticket.
- **Ό,τι έφερε ακολουθεί το WHMCS σε κάθε re-run** (βρίσκεται με `whmcs_id`· `whmcs_ref` στα config options): πεδία,
  plans (ανά κύκλο, όχι όνομα: το staff μπορεί να τα μετονομάσει), τιμές. Κύκλος, νόμισμα ή περίοδος TLD που το WHMCS
  δεν προσφέρει πια **αποσύρεται**: το plan σβήνεται, ή, αν το χρησιμοποιούν υπηρεσίες, μένει χωρίς τιμές· η τιμή TLD
  γίνεται null (το κόστος του registrar μένει). Αλλαγές του staff σε πεδία εισαγμένων εγγραφών (όνομα, hidden, «Show in menu» της κατηγορίας,
  ρυθμίσεις licensing) **αντικαθίστανται** από το re-run: αλλάζονται στο WHMCS ώσπου να γίνει το cut-over.
- **Ό,τι υπήρχε εδώ πριν δεν το αγγίζει:** υπάρχον νόμισμα κρατά prefix/suffix/format· TLD με το ίδιο όνομα μένει
  όπως είναι (σημείωση)· server του ίδιου module και host υιοθετείται χωρίς να αλλάξουν οι ρυθμίσεις του. Το host και
  username ενός server γράφονται μόνο αν λείπουν (URL `https://host:port`, cPanel 2087), ώστε η διόρθωση του staff να
  μένει. Upgrades και config options που πρόσθεσε το staff μένουν· ένα link που το WHMCS έβγαλε μένει κι αυτό (το
  σβήνει το staff). Server που το staff έσβησε μένει σβησμένος.
- **Retired** product του WHMCS: hidden και stock 0 (δεν παραγγέλνεται)· product κρυφού group: hidden. Ένα cPanel
  product παίρνει server (ο πρώτος του group του, αλλιώς ο active server του module στο WHMCS), αλλιώς σημείωση.
- Όλα σε ένα transaction. **`--dry-run`:** οι ίδιοι αριθμοί, τίποτα γραμμένο, η cache ανέγγιχτη. Σε κανονικό run, νέο
  νόμισμα αδειάζει την cache μετά το commit (όπως όταν το προσθέτει το staff) και οι ρυθμίσεις ξαναφορτώνουν
  (`queue:restart`). `--registrar-connection=ID`: τα TLDs με αυτόματη καταχώριση στον registrar αυτής της σύνδεσης·
  λάθος id = αποτυχία.
- **Πηγή: αντίγραφο**, ποτέ το production. Αρκούν οι πίνακες του καταλόγου (χωρίς δεδομένα πελατών):
  `tblcurrencies tblproductgroups tblproducts tblpricing tblproductconfiggroups tblproductconfigoptions
  tblproductconfigoptionssub tblproductconfiglinks tblproduct_upgrade_products tblservers tblservergroups
  tblservergroupsrel tbldomainpricing tblcustomfields`. Στο αντίγραφο του `tblservers` τα `password`/`accesshash`
  μηδενίζονται (ο importer δεν τα διαβάζει ούτως ή άλλως), όπως και το `configoption8` (PSK) των products.
- **Σειρά με τον πλήρη importer:** το `app:import-from-whmcs` ξεκινά με `migrate:fresh`: στο cut-over σβήνει ό,τι έφερε
  αυτός ο κατάλογος και ό,τι πρόσθεσε το staff από πάνω (API keys, servers, συνδέσεις registrar, TLDs). Το
  `app:import-whmcs-licensing` βρίσκει τα products με `whmcs_id` όταν υπάρχει, αλλιώς με το ίδιο id (πλήρης importer).
- Οι συνδέσεις στο WHMCS είναι `utf8mb4` (αλλιώς το `€` και τα ελληνικά φτάνουν χαλασμένα).
- Modules χωρίς extension εδώ (π.χ. άλλοι registrars ή servers) αναφέρονται στο τέλος και παραλείπονται.

## Admins (`app:import-whmcs-admins`, 2026-10-16)
Οι admins μιας εγκατάστασης WHMCS ως staff σε ένα MyCS που **ήδη τρέχει** (ο πλήρης importer τους φέρνει μόνο με
`migrate:fresh`). Οι ίδιοι κανόνες ρόλων: Full Administrator → `admin` (`*`), άλλος ρόλος → ρόλος με το όνομά του χωρίς
permissions (τα δίνει το staff), οι disabled μένουν disabled. Ταίριασμα **με email**, ποτέ με id· staff που ήδη υπάρχει
μένει όπως είναι (κωδικός, ρόλος, status). Το bcrypt hash του WHMCS κρατιέται όπως είναι (μπαίνουν με τον κωδικό του
WHMCS)· οτιδήποτε άλλο → «Forgot password». Ένα transaction, `--dry-run`.
- **Είσοδος:** αρχείο JSON με γραμμές του `tbladmins` και `rolename`. Τα hashes **δεν** περνούν ποτέ σε καθαρό κείμενο
  από κανάλι που καταγράφεται: στον server του WHMCS κρυπτογραφούνται (`openssl cms`, AES-256) με δημόσιο κλειδί που
  φτιάχτηκε στον host του MyCS, ο οποίος μόνο τα αποκρυπτογραφεί σε αρχείο root-only, το εισάγει και το σβήνει μαζί με
  το κλειδί. Ούτε η εντολή ούτε το audit τυπώνουν hash.

## Πύλες πληρωμών και promotions (2026-10-20)
- **Πύλη κάθε πληρωμής** (`tblaccounts.gateway` → `invoice_transactions.gateway_id`, για το «Payment Method» της
  παραγγελίας και το money trail): `stripe*` → Stripe, `paypal`/`paypal_ppcpv`/`paypalcheckout` → PayPal, `mollie*` →
  Mollie, `cardlink*`/`eurobank` → Cardlink (`ImportFromWhmcs::GATEWAYS`). Αν η βάση (μετά το `migrate:fresh`) δεν έχει
  το extension, ο importer το εγκαθιστά όπως το «Install» του admin, **απενεργοποιημένο**: το staff βάζει τα κλειδιά και
  το ενεργοποιεί. Έμβασμα (`banktransfer`) και ό,τι άλλο: χωρίς πύλη (χειροκίνητη πληρωμή), με πλήθος ανά module στην
  έξοδο. Nixpal (2026-10-20): `stripe`, `paypal`, `paypal_ppcpv`. Myip: έμβασμα, Eurobank vPOS, Stripe SEPA
  (`whmcs-usage.md`)· **να επιβεβαιωθεί το όνομα του module της Eurobank** στο `tblaccounts` της myip πριν το cut-over.
- **Promotions** (`tblpromotions` → `coupons`, ίδια ids· `appliesto` → `coupon_products`): Percentage → percentage,
  Fixed Amount → fixed, Free Setup → 100% στο setup fee· `recurring` 0 → μόνο η πρώτη πληρωμή, 1 με `recurfor` 0 → κάθε
  κύκλος· `maxuses`, `onceperclient` → ανά πελάτη 1, ημερομηνίες. Όποιο το MyCS θα εφάρμοζε **αλλιώς** από το WHMCS
  (Price Override, ορισμένοι κύκλοι μόνο, νέοι/υπάρχοντες πελάτες, «requires», once per order, upgrades, lifetime,
  `recurfor` > 0, addons/domains) έρχεται **κλειστό για νέες παραγγελίες** (`expires_at` = η ώρα του import) και
  αναφέρεται: το staff το ξαναφτιάχνει αν προσφέρεται ακόμα. Nixpal: και τα 4 (FREETRIAL, 25OffAgentNode, MOBIAN10/25).
- **Υπηρεσίες** (`tblhosting.promoid` → `coupon_id`, `coupon_started_at` = `regdate`): η τιμή μένει το recurring amount
  του WHMCS, που έχει ήδη την έκπτωση, και το κουπόνι μπαίνει **used up** (`coupon_expired_at`), ώστε ούτε να
  ξαναεφαρμοστεί ούτε να ξαναϋπολογιστεί η τιμή από τον κατάλογο (ο importer δεν αλλάζει ποσά). Promotion με `recurfor`
  > 0 σε ζωντανή υπηρεσία που δεν έχει τελειώσει (`tblhosting.promocount` < `recurfor`) αναφέρεται **και γράφεται
  στις σημειώσεις της υπηρεσίας**: το staff βάζει την πλήρη τιμή όταν τελειώσει (αλλιώς μένει η μειωμένη, π.χ. 0 του
  FREETRIAL). Σε upgrade ή αλλαγή κύκλου η τιμή βγαίνει από τον κατάλογο χωρίς το κουπόνι (το WHMCS το κρατά μόνο με
  `lifetimepromo`): ο πελάτης πληρώνει την πλήρη.
- **Refunds του WHMCS** (γραμμή του `tblaccounts` με `amountout` και `refundid`): μπαίνουν στο `refunded_amount` της
  πληρωμής που επέστρεψαν (μέχρι το ποσό της), ώστε να μην επιστραφεί ξανά. Η πληρωμή που έχει refund, και η γραμμή του
  refund, έρχονται **χωρίς πύλη**: τυχόν υπόλοιπο επιστρέφεται με το χέρι, ποτέ ξανά μέσω της πύλης.
- **Πριν το cut-over:** να ελεγχθούν μερικά `transid` των stripe/paypal (`pi_…` ή `ch_…`, classic PayPal ids). Ένα refund
  μέσω πύλης για πληρωμή της εποχής του WHMCS μπορεί να το αρνηθεί η πύλη (δεν φεύγουν χρήματα, βγαίνει σφάλμα): τότε
  γίνεται με το χέρι. Ένα promotion με διπλό κωδικό (κεφαλαία/μικρά) θα σταματούσε το import (unique).

## Γραμμές domain (D6): απαίτηση για όταν γραφτεί
- Σήμερα ο importer γράφει τις γραμμές `Domain` του WHMCS **χωρίς** `reference_type` (μόνο Hosting αντιστοιχίζεται) και
  με `DB::table()->insert`, χωρίς observers.
- Όταν το D6 τις συνδέσει με domains: κάθε γραμμή ανανέωσης/εγγραφής/μεταφοράς μπαίνει με την **ιστορική** της περίοδο
  και, αν είναι πληρωμένη, με `applied_at` (το έτος έχει ήδη γίνει στον registrar)· ό,τι άλλο με `kind = charge`.
  **Ποτέ** χωρίς περίοδο και χωρίς `kind`, και ποτέ μέσω του `kind=renewal` (θα έπαιρνε την επόμενη μη τιμολογημένη
  περίοδο και, πληρωμένη χωρίς `applied_at`, θα ανανέωνε μαζικά στον registrar· review F11 του #174).
