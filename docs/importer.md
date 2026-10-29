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

## Πώς τρέχει (2026-10-29)
- **Η πηγή:** δίνεται μόνο ρητά, με `--source-user` και `--source-host`/`--source-port` ή `--source-socket` (ή με τα
  ορίσματα `username host port`). Ποτέ από τα `DB_*` του `.env`: εκείνα είναι η βάση της ίδιας της MyCS. Ο κωδικός
  έρχεται από το `WHMCS_SOURCE_PASSWORD` του shell για run χωρίς τερματικό (**όχι** στο `.env`: το Laravel το διαβάζει
  κι από εκεί, και ένα ξεχασμένο θα χρησιμοποιούνταν σιωπηλά), αλλιώς τον ρωτά. Ποτέ ως όρισμα, γιατί τον δείχνει η
  λίστα διεργασιών.
- **Η πηγή ελέγχεται ότι είναι WHMCS** (`tblclients`, `tblconfiguration`, `tblcurrencies`) πριν από οτιδήποτε άλλο.
- **`--dry-run`:** συνδέεται και στις δύο βάσεις, μετρά τους βασικούς πίνακες του WHMCS και λέει πού θα γράψει (και
  σε νέο instance που δεν έχει γίνει migrate). Δεν γράφει τίποτα.
- **Το import αδειάζει πρώτα τη βάση αυτού του instance** (`migrate:fresh`, βλ. «Αρχές» 1: το upsert δεν έχει γραφτεί
  ακόμα).
  - Γράφει μόνο με `--confirm-database=<όνομα της βάσης>`, πληκτρολογημένο.
  - Αρνείται πηγή με το ίδιο όνομα βάσης σε τοπικό host ή στο ίδιο host:port. Είναι πρώτος, φθηνός έλεγχος· ένα
    αντίγραφο της MyCS με άλλο όνομα το πιάνει ο έλεγχος των πινάκων του WHMCS.
  - Στις δοκιμές τρέχει σε δικό του worktree με δικό του `.env`, προς μια scratch βάση. Ποτέ με το `.env` του live site.

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
- **Knowledgebase:** άρθρα και κατηγορίες με τα ids του WHMCS, συνδέσεις, tags, μεταφράσεις, εικόνες στο MyCS (`knowledgebase.md`).
- **Licensing (nixpal):** πλήθος ανά status· `zlic.php` byte-for-byte στα fixtures· shadow-run.
- **Pending υπηρεσίες με μόνο ακυρωμένα τιμολόγια** (BIL-12, #75): στο πρώτο run του cron μετά το cut-over
  ακυρώνονται όλες μαζί (παλαιότερες από `cronjob_order_cancel` ημέρες) και το stock τους επιστρέφει. Να μετρηθούν
  πριν από το cut-over και να αποφασίσει το staff (να γίνουν cancelled στο import ή να μείνουν).
- **Ρυθμίσεις αυτοματισμού (έγινε, 2026-10-29: `importAutomationSettings`· η πλήρης λίστα, με υπενθυμίσεις, πίστωση και το override ανά υπηρεσία, στο «Ρυθμίσεις αυτοματισμού» παρακάτω):** από το `tblconfiguration` της εταιρείας στην καρτέλα Automation (`AutoSuspension`, `AutoSuspensionDays`, `AutoTermination`, `AutoTerminationDays`, `CreateInvoiceDaysBefore`, `CloseInactiveTickets` σε ώρες → ημέρες, `DailyCronExecutionHour`)· αντιστοίχιση στο `settings-map.md` (Αυτοματισμοί).
- **Mass Pay:** τα γονικά τιμολόγια δεν γίνονται τιμολόγια· η πληρωμή τους πάει στα παιδιά (`WhmcsMassPay`, PR F3,
  `payments.md`). Parity: πλήθος πληρωμένων γονικών = ομάδες με `whmcs_invoice_id`· χρήματα γονικών = άμεσα + allocations.
- **Ό,τι άλλο βγάλει η απογραφή χρήσης του WHMCS** (`whmcs-usage.md`): mass pay/merge, πιστώσεις, promotions και εκπτώσεις ομάδων, κανόνες ΦΠΑ/VIES, custom fields, suspend overrides, guest tickets.

## `mycs:parity` (2026-10-29)
- **Τι κάνει:** `php artisan mycs:parity <βάση του WHMCS> --source-user=… --source-host=…|--source-socket=…`
  - Βγάζει κάθε αριθμό και από τις δύο πλευρές, δίπλα δίπλα, με τη διαφορά.
  - Μόνο ανάγνωση και στις δύο. Ο κωδικός από το `WHMCS_SOURCE_PASSWORD` του shell, αλλιώς τον ρωτά.
  - Επιστρέφει 1 όταν κάτι διαφέρει. `--only-differences` για να φαίνονται μόνο οι διαφορές.
- **Ίδιοι κανόνες και στις δύο πλευρές:**
  - Ποσό τιμολογίου = ό,τι χρεώθηκε πριν από κάθε πίστωση: στο WHMCS `subtotal + tax + tax2`, γιατί το δικό του
    `total` είναι **μετά** την πίστωση.
  - Τα γονικά του Mass Pay δεν μετρούν: τα χρήματά τους είναι στα παιδιά. Τα αναγνωρίζει με τον κανόνα του ίδιου του
    importer (`WhmcsMassPay::PARENTS_SQL`), κοινό στους δύο.
  - Κάθε status του WHMCS αντιστοιχίζεται όπως στον importer (`ImportFromWhmcs::invoiceStatus`). Μετράει κάθε status
    που βρίσκεται σε οποιαδήποτε πλευρά, όχι μια σταθερή λίστα.
  - Χρήματα, επιστροφές και προμήθειες, το καθένα χωριστά, μόνο οι πληρωμές που πέρασαν.
  - Πίστωση που εφαρμόστηκε = το `tblinvoices.credit` (> 0) κάθε τιμολογίου εκτός από τα γονικά. Εδώ: οι πληρωμές από
    πίστωση, συν το μέρος της πληρωμής ενός γονικού του Mass Pay που κράτησε το παιδί όπου «προσγειώθηκε» (η πληρωμή
    κρατά το id της στο WHMCS). Το «credit» ενός παιδιού στο WHMCS είναι τα χρήματα του γονικού **και** η πίστωση του
    ίδιου του πελάτη: έτσι μετρά και στις δύο πλευρές ίδια.
  - Πίστωση από αρνητικά τιμολόγια (αρνητικό `credit` στο WHMCS) = τα `credit_in` με αιτία `invoice_reduced` του import.
  - Ποσό τιμολογίου εδώ = οι γραμμές του, όχι οι σημειώσεις (μια επιστροφή γράφει σημείωση, το WHMCS όχι).
  - Πληρωμές του WHMCS χωρίς τιμολόγιο δεν έρχονται (`importPayments`): το parity λέει πόσες λείπουν από τα «Money in».
  - Ένα ticket που έγινε merge σε άλλο είναι εκείνου (`WhmcsTicketImport::merges`).
  - Με πάνω από ένα νόμισμα, τα ποσά αθροίζονται μαζί, και το parity το λέει.
- **Πρώτο run, nixpal (2026-10-29, πριν από το #518):**
  - **Ίδια:**
    - πελάτες, και υπηρεσίες ανά status·
    - τιμολόγια ανά status, και σε πλήθος και σε ποσό, στο λεπτό·
    - χρήματα που μπήκαν, επιστροφές·
    - υπόλοιπα πίστωσης.
  - **Διαφορές, με γνωστή αιτία:**
    - **πίστωση του πελάτη που εφαρμόστηκε σε τιμολόγια:** ο importer φέρνει μόνο αυτή των γονικών του Mass Pay, όχι το
      `tblinvoices.credit` κάθε τιμολογίου (μηδενίστηκε: «Το ημερολόγιο των χρημάτων»)·
    - **προμήθειες των gateways** (`tblaccounts.fees`): δεν έρχονται·
    - **domains:** δεν έχουν γραφτεί στον importer ακόμα.

    Τα 4 tickets που φαίνονταν να λείπουν είναι merged (review του #521), και με τον κανόνα συμφωνούν.

  Κάθε μία γίνεται δικό της PR, και το parity τη δείχνει να μηδενίζει.

## Το ημερολόγιο των χρημάτων (2026-10-29)
Ο importer γράφει το `money_events` όπως θα το έγραφε η εφαρμογή αν τα χρήματα είχαν μπει εδώ, ώστε το import να
περνά τους ίδιους ελέγχους (`mycs:money-check`, `mycs:credits-check`). Το κάνει το `WhmcsMoneyJournal`, μετά το Mass Pay
(`ImportFromWhmcs::journalMoney`). Όλες οι κινήσεις έχουν source `import`.

| Στο WHMCS | Εδώ |
|---|---|
| πληρωμή (`tblaccounts.amountin`) | συναλλαγή με το id της + κίνηση `payment` |
| επιστροφή (γραμμή με `amountout`, `refundid`) | `refunded_amount` στην πληρωμή + μία κίνηση `refund` ανά επιστροφή, με τη δική της ημερομηνία (η γραμμή της δεν γίνεται συναλλαγή) |
| επιστροφή σε πίστωση («… to Credit Balance» + «Credit from Refund of Invoice ID N») | `credited_amount` + `credit_in` (`refund_to_credit`) δεμένο στην πληρωμή, χωρίς `refund`, όπως το `refundToCredit` |
| επιστροφή σε μετρητά υπερπληρωμής που είχε γίνει πίστωση («Removal of Credit from Invoice #N») | παίρνει πίσω το `credited_amount` της πληρωμής: `credit_out` (`overpayment`) + `refund` |
| ό,τι έλειψε από πληρωμένο τιμολόγιο λόγω επιστροφής | σημείωση πίστωσης (`is_refund`), όπως το `ExtensionHelper::afterRefund`· πληρωμή δύο φορές με τη μία πίσω: καμία |
| «Refunded» χωρίς καμία πληρωμή | σημείωση πίστωσης όλου του ποσού |
| `tblinvoices.credit` | πληρωμή από πίστωση στο τιμολόγιο (`credit_out` + `payment`), με ημερομηνία το «Credit Applied» του `tblcredit` (μόνο μέρα: η ώρα της πληρωμής του τιμολογίου αν είναι η ίδια μέρα) |
| υπερπληρωμή | `credited_amount` στις νεότερες πληρωμές + `credit_in` (`overpayment`), πριν από τις επιστροφές: όσο λέει το «Invoice #N Overpayment» του `tblcredit`, αλλιώς ό,τι δεν πήραν πίσω οι επιστροφές |
| Mass Pay | η πληρωμή, το `credited_amount` της και τα allocations, ομαδοποιημένα `transaction:<id>` όπως το `spendCredit` |
| Add Funds πληρωμένο | `credit_in` (`purchase`) ανά γραμμή, και `applied_at` στη γραμμή |
| αρνητικό τιμολόγιο | `credit_in` (`invoice_reduced`) |
| `tblcredit` χωρίς τιμολόγιο (staff, refund σε πίστωση, downgrade) | `credit_in`/`credit_out` (`staff_adjustment`, `refund_to_credit`, `downgrade`), η περιγραφή στο `money_event_notes` |

- **Δεν έρχονται:**
  - γραμμή του `tblaccounts` χωρίς χρήματα (0/0: «mark paid» του WHMCS)·
  - πληρωμή χωρίς τιμολόγιο εδώ (το WHMCS την αφήνει, ή σβήστηκε το τιμολόγιο)·
  - χρήματα που βγήκαν χωρίς πληρωμή εδώ (chargeback, ή επιστροφή που γράφτηκε με το χέρι).

  Ο importer τις ονομάζει με το id τους, και το parity λέει πόσο λείπουν από τα «Money in» / «Money out».
- Ο πελάτης μιας πληρωμής είναι του τιμολογίου της (στο WHMCS είναι μερικές φορές 0). Πληρωμή με ημερομηνία
  `0000-00-00` παίρνει την ημερομηνία του τιμολογίου της, ποτέ τη μέρα του import.
- **Σειρά:** το `tblcredit` κρατά μόνο μέρα. Μέσα σε μια μέρα, μια τέτοια κίνηση πηγαίνει πριν από τα χρήματα με ώρα
  αν φέρνει πίστωση, μετά αν παίρνει.
- Αν μια ανάγνωση της πηγής αποτύχει για άλλο λόγο εκτός από πίνακα ή στήλη που λείπει, το import σταματά: ένα άδειο
  ιστορικό θα γινόταν μία προσαρμογή ανά πελάτη.
- **Υπόλοιπο ≠ ιστορικό:** κρατιέται το υπόλοιπο του WHMCS, γιατί αυτό βλέπει ο πελάτης σήμερα (απόφαση, 2026-10-29).
  Μπαίνει μία κίνηση `opening_balance` ανά πελάτη με τη διαφορά, και ο importer τους βγάζει σε λίστα, πρώτους τους
  πιο πρόσφατους.
  - Θετική διαφορά (πίστωση που το WHMCS δεν κατέγραψε ποτέ ότι μπήκε, π.χ. τιμολόγιο πληρωμένο από πίστωση χωρίς
    γραμμή στο `tblcredit`): μπαίνει **πρώτη**, με την ημερομηνία της πρώτης κίνησης.
  - Έτσι το υπόλοιπο στο ιστορικό δεν πέφτει κάτω από το μηδέν. Εξαίρεση οι 3 πελάτες του myip όπου το ίδιο το WHMCS
    ξόδεψε πίστωση πριν την καταγράψει και αργότερα μηδένισε το υπόλοιπο. Μία προσαρμογή δεν τα εξηγεί και τα δύο.
  - Αρνητική (πίστωση που χάθηκε χωρίς ίχνος): τώρα, στο τέλος.
- **Αποτέλεσμα (2026-10-29):**
  - Πριν: 4.846 ευρήματα στο import της nixpal.
  - Μετά, nixpal:
    - `mycs:credits-check` καθαρό, 2 προσαρμογές·
    - το `mycs:parity` ίδιο στην πίστωση· διαφέρουν μόνο οι προμήθειες, τα domains, και η 1 πληρωμή χωρίς τιμολόγιο.
  - Μετά, myip (μόνο τα βήματα των χρημάτων):
    - `mycs:credits-check` καθαρό, 38 προσαρμογές.
    - Από αυτές, 13 είναι πελάτες όπου ούτε το ιστορικό του ίδιου του WHMCS δεν βγάζει το υπόλοιπο.
    - Οι υπόλοιπες είναι πίστωση σε τιμολόγια (κυρίως 2009–2012) χωρίς γραμμή στο `tblcredit`.
    - 5 χρήματα που βγήκαν χωρίς πληρωμή ονομάζονται για έλεγχο.
- **Ευρήματα του `mycs:money-check` που μένουν, και είναι δεδομένα του WHMCS (τα βλέπει το staff):**
  - nixpal:
    - 8 αρνητικά τιμολόγια, «paid over»: το υπόλοιπό τους μένει κάτω από το μηδέν·
    - 2 ακυρωμένα με πίστωση πάνω τους·
    - 3 ανοιχτά για υπηρεσίες που έληξαν.
  - myip:
    - 17 «Paid» χωρίς τα χρήματα (ή με λιγότερα)·
    - 6 με περισσότερη πίστωση από το ποσό τους·
    - 6 ακυρωμένα με πίστωση πάνω τους.
- **Τι σταματούσε το import του myip (λύθηκε 2026-10-29):**
  - **Δύο τιμές στα δεδομένα του WHMCS, όχι λάθη του importer.** Ο υπεύθυνος τις διόρθωσε στο ίδιο το WHMCS, στο rigel
    (2026-10-29), ώστε να μη χρειάζεται ειδική περίπτωση στον importer:
    - 8 ομάδες προϊόντων με `created_at` `0000-00-00`, παλιότερες από τη στήλη: πήραν το `updated_at` τους
      (ids 1, 3, 5, 8, 10, 18, 19, 37)·
    - 18 configurable options «CSR Domain» του SSL module με `order` −1, η καθεμία μόνη στην ομάδα της: έγιναν 0
      (ids 187–255).

    Αν το SSL module φτιάξει νέο προϊόν πριν από το cut-over, βάζει πάλι −1. Το import σταματά, και ξανατρέχει το ίδιο
    `UPDATE`.
  - **Το `validateDate`** έδινε σε μια σκέτη ημερομηνία την **τωρινή ώρα** (`createFromFormat` χωρίς `!`): κάθε τιμολόγιο
    έπαιρνε την ώρα του run. Ένα run στις 03:00 σταμάτησε σε μέρα αλλαγής ώρας (2011-03-27, η ώρα δεν υπάρχει).
    Τώρα: μεσάνυχτα. Οι πραγματικές ημερομηνίες με ώρα δεν πέφτουν ποτέ στο κενό της θερινής ώρας: τις έγραψε ο
    ίδιος server, στην ίδια ζώνη.

## Χώρα του πελάτη (2026-10-29, #524)
- Ο importer γράφει μια χώρα που ξέρει η εφαρμογή (`EuVat::iso`): κωδικός, όνομα σε οποιαδήποτε κεφαλαία ή πεζά,
  `EL` → GR. Αλλιώς κενό, ποτέ τα δύο
  πρώτα γράμματα ενός ονόματος («Germany» → GE, η Γεωργία).
- Όσοι μένουν χωρίς χώρα μπαίνουν σε λίστα για το staff, πρώτοι όσοι έχουν ενεργές υπηρεσίες. Με το #524 δεν
  παραγγέλνουν μέχρι να βάλουν χώρα, και τα τιμολόγιά τους παίρνουν τον συντελεστή «All», αλλιώς της χώρας της
  εταιρείας.
- Το `mycs:parity` τους μετρά και στις δύο πλευρές.
- Τα δεδομένα είναι καθαρά: nixpal 1 χωρίς χώρα (με 1 ενεργή υπηρεσία), myip 0. Πουθενά ονόματα, πεζά ή `EL`.
- Οι συντελεστές ΦΠΑ και η χώρα της εταιρείας δεν έρχονται από τον importer: τα λύνει το session των EU VAT rates.

## Πρώτο πλήρες import του myip (2026-10-29)
- **Έλεγχοι:**
  - `mycs:credits-check` καθαρό, 37 προσαρμογές.
  - `mycs:money-check` 30: όλα δεδομένα του WHMCS. Τα 29 του «Ημερολογίου», συν 1 ανοιχτό τιμολόγιο για υπηρεσίες που
    έληξαν.
- **`mycs:parity`, με γνωστή αιτία:**
  - «Money in» λιγότερα, κατά τις 394 πληρωμές σε τιμολόγια που σβήστηκαν·
  - «Money out» λιγότερα, κατά τα 5 χωρίς πληρωμή·
  - προμήθειες· domains (1.887).
- **Θέλουν διερεύνηση (επόμενο PR):** υπηρεσίες cancelled +2, tickets +12, ποσό πληρωμένων τιμολογίων −190,00,
  ακυρωμένων −107,00.

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
  για τη βάση και ένα backup της (`df -h`), και ένα ζεστό `/sysadmin` μετά το import (το πρώτο request μεταγλωττίζει).
- Κάθε αριθμός της λίστας parity βγαίνει **και από τις δύο πλευρές** με μία εντολή (π.χ. `mycs:parity --whmcs-copy=…`)
  και τυπώνει diff. Cut-over μόνο με μηδενικό diff ή με κάθε διαφορά εξηγημένη και καταγεγραμμένη.
- Tests του importer σε fixture βάση WHMCS (ανωνυμοποιημένη, CLAUDE.md), όπως τα `WhmcsSchema`/`BlestaSchema` του
  `lib/nixpal-licensing/tests`.
- Η nixpal πρώτη (31 ενεργοί, ~330 άδειες), μετά 2–3 δοκιμαστικά runs του myip πριν το cut-over.

## Logins και πρόσβαση (`tblusers`, `tblusers_clients`, 2026-10-23)
- **Logins:** κάθε `tblusers` γίνεται user με **το ίδιο id**, το δικό του email (όχι του λογαριασμού), τον κωδικό του
  (bcrypt του WHMCS 8, ίδιος) και το `email_verified_at`. Έρχονται και όσοι δεν έχουν λογαριασμό (108 στο myip), ώστε να
  μη χαθεί κανένα login. Οι απαντήσεις των tickets βρίσκουν έτσι τον ίδιο user (`WhmcsTicketImport::userOf`).
- **Πρόσβαση:** κάθε `tblusers_clients` γίνεται `client_user`: ο owner με `owner_of_client_id`, οι άλλοι με τα
  δικαιώματα που είχαν (ίδια ονόματα με το WHMCS, `ClientUser::PERMISSIONS`· ένα άγνωστο μένει έξω). Λογαριασμός χωρίς
  owner παραλείπεται, όπως πριν (στο WHMCS κάθε λογαριασμός έχει ακριβώς έναν).
- **2FA (security audit AUTHN-11):** το WHMCS 8 κρατά το `second_factor_config` ως `<iv hex>.<ciphertext hex>`:
  AES-256-CBC με κλειδί τους πρώτους 32 χαρακτήρες του `sha256(cc_encryption_hash)`, και μέσα JSON `{"secret": <base32>,
  "backupcode": <hash>}`. Επιβεβαιώθηκε σε test login του myip (2026-10-23): το secret που βγαίνει είναι ακριβώς αυτό
  της εφαρμογής. Το API του WHMCS **δεν** το διαβάζει (`DecryptPassword` είναι το παλιότερο σχήμα). Γι' αυτό ο importer
  θέλει το `cc_encryption_hash` του WHMCS (από το `configuration.php`) στο `WHMCS_CC_ENCRYPTION_HASH` του `.env`,
  **μόνο για το run του import**: σβήνεται μετά, και ξανά `php artisan config:cache` (αλλιώς μένει στο
  `bootstrap/cache/config.php` μέχρι το επόμενο). Το secret κρατιέται κρυπτογραφημένο με το κλειδί του MyCS, και ο ίδιος
  authenticator συνεχίζει να δουλεύει. Χωρίς αυτό, ή για ένα που δεν διαβάζεται, ο user έρχεται χωρίς 2FA και ο importer
  τυπώνει τα ids τους (10 στο myip, 1 στη nixpal), για να ειδοποιηθούν. Μόνο `totp` (το μόνο που χρησιμοποιείται). Τα
  backup codes δεν μεταφέρονται: το WHMCS κρατά μόνο το hash τους.

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

**Ο server κάθε λογαριασμού (AUD4-1):** το `tblhosting.server` γίνεται `services.server_id` μέσω του `whmcs_id` των
servers του καταλόγου (γι' αυτό ο κατάλογος εισάγεται πρώτος). Κρατιέται και όπως ήταν, στο `services.whmcs_server_id`
(0 = κανένας στο WHMCS). Μια υπηρεσία που ο λογαριασμός της είναι σε server του WHMCS που δεν υπάρχει εδώ (σβησμένος
server, ή module χωρίς extension) **δεν** πέφτει στον server του προϊόντος, γιατί εκεί το ίδιο username μπορεί να ανήκει
σε άλλον πελάτη (`Service::serverLeftInWhmcs`). Κάθε ενέργειά της, και το create, αποτυγχάνει και πάει στο staff
(Pending Module Actions, «set its server first»). Ποτέ δεν παραλείπεται σιωπηλά: ούτε το terminate στη διαγραφή ή στην
ακύρωση, ούτε το upgrade (`hasServerActions`). Το ίδιο ισχύει για το sync του cPanel, του mailcow και για τα bulk emails
ανά server (`Service::scopeOnServers`). Δύο περιπτώσεις παίρνουν τον server του προϊόντος, όπως πριν: μια pending
υπηρεσία, που δεν έχει ακόμη λογαριασμό και τη δημιουργία της τη διαλέγει server, και μια με server 0 στο WHMCS. Αν
μια pending παραγγελία ήταν σε server του WHMCS και το προϊόν της δεν έχει server εδώ (π.χ. Proxmox, Hetzner), η
δημιουργία της πάει στο staff, δεν «ενεργοποιείται» χωρίς τίποτα. Όταν μια pending ξεκινά εδώ χωρίς server (accept χωρίς
create, ή active από το staff), το `whmcs_server_id` σβήνεται: ο λογαριασμός της έγινε εδώ. Ο
importer δείχνει στο τέλος τις ζωντανές υπηρεσίες χωρίς server εδώ. Το staff τους ορίζει server πριν τρέξει οποιοδήποτε
cron.

**Suspended σε Centova και mailcow (AUD4-2):** όταν το WHMCS κάνει suspend, το module του απενεργοποιεί τον σταθμό ή
κλειδώνει τον admin του domain. Τα modules εδώ ξανανοίγουν μόνο ό,τι έκλεισαν τα ίδια, γι' αυτό ο importer βάζει το mark
τους σε κάθε υπηρεσία Suspended αυτών των modules: `centova_disabled_by_us` = `down` και `mailcow_locked_by_us` = `1`.
Το module βρίσκεται από τον server της υπηρεσίας ή, αν δεν έχει, από τον server του προϊόντος. Έτσι η πληρωμή ανοίγει
πάλι τον λογαριασμό. Το stream δεν ξεκινά αυτόματα· το ξεκινά ο πελάτης από το panel, όπως θα το άφηνε το WHMCS.
Ο importer τυπώνει τη λίστα τους. **Πριν το cut-over**, για καθεμία: ο λογαριασμός πρέπει να είναι πράγματι
disabled ή locked στον server. Αν τον είχε κλείσει το staff με το χέρι (π.χ. για abuse), το mark σβήνεται για να μείνει
κλειστός.

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

## Τύποι γραμμών τιμολογίου (2026-10-28)
Ο importer κρατά τι είναι κάθε γραμμή (`tblinvoiceitems.type`, `relid`), ώστε τα έσοδα ανά προϊόν (#504) να πηγαίνουν
στο προϊόν τους και όχι στο «Άλλα». Γράφει με `DB::table()->insert`, χωρίς observers.

| WHMCS `type` | `reference_type` / `reference_id` | `kind` |
|---|---|---|
| Hosting | `Service`, relid (οι υπηρεσίες κρατούν τα WHMCS ids) | κενό: ανανέωση, η περίοδος δίνεται όταν πληρωθεί |
| Setup, PromoHosting | `Service`, relid | `charge` |
| Addon | `Service`, η υπηρεσία του addon (`tblhostingaddons.hostingid`) | `charge` |
| Upgrade | `Service`, η υπηρεσία του upgrade (`tblupgrades.relid`, μόνο `package`/`configoptions`) | `charge` |
| AddFunds | `Credit`, κενό | κενό |
| οτιδήποτε άλλο (Domain*, PromoDomain, LateFee, GroupDiscount, Item, …) | κενό, το relid όπως ήταν | κενό |

- **`kind = charge` σε υπηρεσία:** χρέωση που **δεν** είναι περίοδος. Η πληρωμή της δεν ανανεώνει την υπηρεσία
  (`ProcessPaidInvoiceService`), η ακύρωση του τιμολογίου δεν της δίνει περίοδο ως «forgone» (`InvoiceObserver`), το
  upgrade δεν της αλλάζει την τιμή (`ServiceUpgradeService`), δεν μετρά ως τιμολογημένη περίοδος (`IncomePlan`), δεν
  γίνεται συνδρομή Stripe. Μετρά όμως ως γραμμή της υπηρεσίας (ακύρωση τιμολογίων της, affiliates, έσοδα ανά προϊόν).
- **Addon/Upgrade → η υπηρεσία (απόφαση):** στη MyCS τα addons γίνονται επιλογές της υπηρεσίας (`service_configs`,
  παρακάτω) και ένα upgrade αλλάζει την ίδια την υπηρεσία, άρα τα έσοδά τους είναι του προϊόντος της υπηρεσίας. Το
  «System Maintenance», που θα γίνει ξεχωριστή υπηρεσία όταν εισαχθούν τα addons, μένει ως τότε στη γονική.
- **Υπηρεσία που δεν υπάρχει** (σβήστηκε στο WHMCS, ή addon/upgrade χωρίς γραμμή): χωρίς τύπο, ποτέ με «μάντεμα».
- **Domains:** οι γραμμές τους πάνε στο domain τους (D6, επόμενη ενότητα). Μια γραμμή domain που δεν υπάρχει εδώ μένει
  χωρίς τύπο, όπως πριν.

## Αποθηκευμένες μέθοδοι πληρωμής (`WhmcsPayMethodImport`)
Οι αποθηκευμένες κάρτες και τα PayPal του WHMCS (`tblpaymethods`) γίνονται billing agreements, ώστε οι αυτόματες χρεώσεις
να συνεχίσουν μετά το cut-over χωρίς ο πελάτης να ξαναβάλει την κάρτα του. Ισχύει επειδή κάθε εταιρεία κρατά τους δικούς
της λογαριασμούς Stripe και PayPal (S0-6), οπότε οι αναφορές μένουν έγκυρες.
- **Η αναφορά** είναι για το Stripe ο customer και το payment method, για το PayPal το vault token. Βρίσκεται στο
  `tblcreditcards.card_data`, κρυπτογραφημένη με το κλειδί του WHMCS, που δεν είναι στη βάση. Τη διαβάζει το **API του
  WHMCS** (`GetPayMethods` → `remote_token`). Το κλειδί δεν το διαβάζει κανείς. Τρέχει με `--whmcs-api-url=` (μόνο https)
  και τα credentials στο περιβάλλον (`WHMCS_API_IDENTIFIER`, `WHMCS_API_SECRET`), ποτέ ως όρισμα. Το `WhmcsApi` κάνει μόνο
  τις αναγνώσεις που χρειάζεται. Χωρίς το API δεν εισάγεται καμία μέθοδος, και το βήμα το λέει. Είναι το τελευταίο βήμα,
  μετά το ημερολόγιο των χρημάτων: μια μέθοδος που αποτυγχάνει παραλείπεται, ποτέ ολόκληρο το import.
- **Όνομα, τύπος και λήξη** όπως τα γράφουν τα gateways εδώ, π.χ. «Visa **** 4242», ο τύπος της κάρτας, η τελευταία
  μέρα του μήνα λήξης. Για SEPA: «SEPA Direct Debit **** 1234».
- **Stripe:** ο customer γίνεται το `stripe_id` του πελάτη, όπως το κρατά το gateway, και το payment method γίνεται η
  αναφορά της μεθόδου. Μια αναφορά χωρίς τα δύο αναφέρεται, δεν μαντεύεται.
- **Default του πελάτη και αυτόματη χρέωση** (ο κανόνας του migration, `billing-cursor.md`, απόφαση 6): το WHMCS χρεώνει
  μια αποθηκευμένη μέθοδο μόνο για τιμολόγιο του δικού της gateway. Γι' αυτό default γίνεται η μέθοδος του gateway με το
  οποίο πληρώνουν οι περισσότερες ενεργές ή σε αναστολή υπηρεσίες και domains του πελάτη. Μέσα στο ίδιο gateway παίρνεται
  η πρώτη με τη σειρά του WHMCS· ανάμεσα σε δύο gateways με ίσες υπηρεσίες, η νεότερη. Αν καμία υπηρεσία του δεν πληρώνει
  με gateway αποθηκευμένης μεθόδου, π.χ. πληρώνει με τραπεζική μεταφορά και έχει μια παλιά κάρτα, δεν μπαίνει default και
  η «Αυτόματη χρέωση» γίνεται off, όπως ήταν στην πράξη στο WHMCS. Στα αντίγραφα αυτό αφορά περίπου 39 πελάτες στο myip και
  6 στη nixpal.
- **Δεν εισάγονται ως χρεώσιμες:**
  - μέθοδος δεύτερου Stripe customer του ίδιου πελάτη: η χρέωση πάει με τον ένα `stripe_id` του, άρα αυτή δεν θα
    χρεωνόταν ποτέ. Αναφέρεται, και ο πελάτης την ξαναβάζει·
  - η ίδια αναφορά δύο φορές·
  - η κάρτα στο vault του PayPal (`paypal_acdc`, `paypalcheckout`): δεν είναι PayPal wallet.
  Δύο λογαριασμοί σε έναν Stripe customer αναφέρονται, με τα δύο ids.
- **Λήξη:** «06/27», «1227» (MMYY) ή ημερομηνία· του PayPal καμία (το WHMCS γράφει μία 30 χρόνια μετά).
- **Πριν το cut-over (PayPal):** η σύνδεση του PayPal Commerce Platform στο WHMCS γίνεται μέσω του partner του WHMCS. Πρέπει να
  επιβεβαιωθεί ότι ένα vault token του WHMCS χρησιμοποιείται από την εφαρμογή PayPal της MyCS: ένα `GET` του token, χωρίς
  χρέωση. Αν όχι, οι PayPal μέθοδοι δεν εισάγονται και οι πελάτες τις ξαναβάζουν.
- **Δεν εισάγονται καθόλου:** σβησμένες μέθοδοι, άλλα gateways (π.χ. Mollie), πελάτες που δεν ήρθαν, αναφορές χωρίς ό,τι
  χρειάζεται η χρέωση (π.χ. ένα σκέτο `cus_` παλιού WHMCS). Μετρώνται και αναφέρονται.
  Ένα token δεν τυπώνεται ποτέ.
- **Τοπικά αντίγραφα:** nixpal 52 Stripe (36 πελάτες) και 15 PayPal· myip 51 Stripe, 49 PayPal και 4 Stripe SEPA.

## Domains (D6, `WhmcsDomainImport`)
Τρέχει μετά τους πελάτες, τις παραγγελίες και τις υπηρεσίες, πριν από τις γραμμές τιμολογίων.
- **Registrars:** μία σύνδεση για κάθε module του WHMCS που έχει driver εδώ (`grepp`, `openprovider`, `cnic`),
  **χωρίς credentials, off**: τα βάζει το staff (Domains → Registrars). Ένα module χωρίς driver (enom), ή domain χωρίς
  registrar, πάει στη σύνδεση **manual**: χωρίς API, οι ανανεώσεις του γίνονται εργασία του staff και το sync δεν το
  διαβάζει. Ποτέ δεν πέφτει στον registrar του TLD του: εκεί το sync θα το έβρισκε «όχι δικό μας» και θα το τερμάτιζε. Η σύνδεση του CNIC παίρνει «σε πληρωμένη ανανέωση: μεταφορά στο Openprovider»
  (`renew_transfer_to_id`, απόφαση ιδιοκτήτη 2026-10-22): η μετακίνηση συνεχίζεται στις ανανεώσεις, όπως με το hook,
  αλλά με αλλαγή registrar μόνο αφού το δεχτεί ο Openprovider. Οι
  πολυετείς (67 από τα 182 ενεργά του CNIC στο myip) γίνονται κι αυτές μεταφορά, με τα υπόλοιπα έτη να ανανεώνονται στον
  Openprovider όταν φτάσει το domain (απόφαση ιδιοκτήτη 2026-10-29, `domains.md` → βήμα 4).
- **Κοινοί λογαριασμοί registrar:** το myip και η nixpal μοιράζονται τους λογαριασμούς του Openprovider και του CNIC. Η
  λίστα ενός registrar δείχνει και domains της άλλης εταιρείας (στο myip, 11 της nixpal). Τα domains έρχονται **μόνο** από
  το WHMCS της κάθε εταιρείας. Το «Add existing domain» και η ανάθεση αδέσποτου σε πελάτη **ποτέ** δεν βάζουν domain της
  άλλης εταιρείας σε πελάτη αυτής.
- **TLDs και τιμές:** όπως τα φέρνει ο κατάλογος (`CatalogImport::tldsForFullImport`). Το καθένα με αυτόματη καταχώριση
  στη σύνδεση του module του.
- **Κάθε domain:**
  - με το WHMCS id του, το νόμισμα του πελάτη του, ημερομηνίες εγγραφής και λήξης, έτη, recurring amount, addons (DNS,
    email forwarding, ID protection), DNSSEC (`tbldomains_extra`), IDN script·
  - το «do not renew» γίνεται `auto_renew` ανάποδα, και οι σημειώσεις του έρχονται·
  - το TLD είναι το μακρύτερο γνωστό (`com.gr` πριν από `gr`)·
  - status όπως στο WHMCS: ένα pending γίνεται register ή transfer ανάλογα με τον τύπο του. Ένα «Pending» με λήξη (μια
    παραγγελία δεν έχει λήξη πριν καταχωριστεί) που λήγει στο μέλλον, και έχει πληρωμένες ανανεώσεις ή καταχωρίστηκε πάνω από
    έναν χρόνο πριν, είναι δικό μας. Έρχεται active και αναφέρεται, ώστε να μην το ακυρώσει το cron ως απλήρωτη παραγγελία.
    Μια μεταφορά σε εξέλιξη, με τη λήξη του registrar που φεύγει, μένει pending. Status που δεν το ξέρουμε έρχεται cancelled και αναφέρεται. Από δύο live με το ίδιο
    όνομα μένει live το τρέχον (active πριν από pending, grace, redemption, expired· μετά η μεταγενέστερη λήξη)· τα άλλα
    έρχονται cancelled και αναφέρονται·
  - registrant και admin η κύρια επαφή του πελάτη, όπως στο `domains:adopt`·
  - τα additional fields (`tbldomainsadditionalfields`, 1.567 στο myip) στο `meta.whmcs_fields`, **κρυπτογραφημένα**,
    γιατί έχουν ημερομηνίες γέννησης, αριθμούς ταυτότητας και ΑΦΜ·
  - τα δωρεάν (recurring 0) ανανεώνονται αυτόματα, με γραμμή 0,00, και τα πληρώνει η εταιρεία στον registrar. Στο myip
    είναι τα 6 domains της ίδιας της εταιρείας (απόφαση ιδιοκτήτη 2026-10-29).
- **Handles του Openprovider** (`wDomain_handle` → `wHandles`, μόνο handles του Openprovider και μόνο σε domains που το
  WHMCS έχει στον Openprovider): ένα handle γίνεται του ρόλου (registrant, admin)
  **μόνο** αν η επαφή του στο WHMCS έχει το email της κύριας επαφής, όπως ορίζει ο κανόνας του D4b. Όλα τα άλλα (άλλο
  πρόσωπο, tech, billing) κρατιούνται στο `meta.whmcs_handles` για το staff. Από το αντικείμενο του module διαβάζεται
  **μόνο** το email· τίποτα άλλο δεν κρατιέται (π.χ. αριθμός ταυτότητας, ημερομηνία γέννησης).
- **Η μετακίνηση CNIC → Openprovider** (`mod_domain_migration`): το hook του WHMCS άλλαζε τον registrar *πριν* τη
  μεταφορά. Η τελευταία πραγματική απόπειρα κάθε domain (όχι dry run) κρατιέται στο `meta.whmcs_migration` για το staff,
  ποτέ το EPP code της. **Δεν** υπερισχύει του registrar του WHMCS. Οι λίστες των ίδιων των registrars (2026-10-29,
  read-only) έδειξαν ότι τα 25 από τα 26 που το log γράφει failed ή triggered είναι πια στον Openprovider: είναι από την
  εποχή που γραφόταν το hook και ολοκληρώθηκαν με το χέρι. Το 26ο έχει σβηστεί από το WHMCS. Όλα έχουν nameservers, και
  κανένα δεν έχει τους default του Openprovider.
- **Ειδοποιήσεις λήξης** (`tbldomainreminders`): όσες έστειλε το WHMCS για την **τρέχουσα** λήξη του domain (±7 μέρες)
  μπαίνουν ως σταλμένες. Ως σταλμένες μπαίνουν και όλες οι μέρες ειδοποίησης εδώ που είναι στην ίδια ή μεγαλύτερη
  απόσταση από τη λήξη με την πιο κοντινή του WHMCS. Παράδειγμα: αν το WHMCS έστειλε «10 μέρες», το «30 μέρες» δεν
  ξαναφεύγει· τα 7, 1 και −3 φεύγουν κανονικά. Οι ειδοποιήσεις παλιότερων λήξεων είναι ιστορικό και δεν εισάγονται.
- **Οι γραμμές τους** (`WhmcsDomainImport::line`):
  - η ανανέωση (`Domain`) με την ιστορική της περίοδο: από τις ημερομηνίες της περιγραφής (στο `DateFormat` του
    WHMCS), που ορίζουν και τα έτη. Αλλιώς από το due date της, για τα έτη που λέει («Year/s», «Έτος/η», και το
    παλιότερο «Χρόνια»). Το τέλος της είναι η επόμενη λήξη (μία μέρα μετά από το τέλος που γράφει το WHMCS)·
  - μια πληρωμένη ανανέωση που η λήξη του WHMCS **δεν** τη φτάνει (±31 μέρες) σε ζωντανό domain δεν μετρά ως γινωμένη.
    Είτε δεν έγινε ποτέ στον registrar, είτε η μεταφορά του hook πρόσθεσε ένα έτος από τα δύο. Μένει «προς εφαρμογή» και
    αναφέρεται. Η ανανέωση διαβάζει πρώτα τον registrar: αν έχει την περίοδο, την υιοθετεί χωρίς χρέωση· αν όχι,
    αγοράζει μόνο τα έτη που λείπουν. Όσα έτη της δείχνει ήδη η λήξη του WHMCS γράφονται ως γινωμένα στο log του registrar
    («renew», `source: whmcs`). Έτσι ποτέ δεν αγοράζεται ολόκληρη ξανά: π.χ. 2 πληρωμένα με το 1 από τη μεταφορά του hook
    → αγοράζεται 1·
  - μια ανανέωση ακυρωμένη στο WHMCS για την περίοδο που χρωστιέται τώρα, χωρίς να έχει ανανεωθεί (η λήξη στην αρχή της),
    γίνεται «παραιτημένη» (`forgone_at`), όπως η ακύρωση εδώ (Δ3a). Ποτέ μια εναλλακτική που ο πελάτης δεν διάλεξε (π.χ. η
    διετής, ακυρωμένη όταν πλήρωσε την ετήσια), ούτε μία δίπλα σε πληρωμένη ή ανοιχτή ανανέωση της ίδιας περιόδου: θα
    μετρούσε ως τιμολογημένη και θα σταματούσε την επόμενη ανανέωση·
  - μια πληρωμένη παραγγελία (register / transfer) domain που είναι ακόμη pending στο WHMCS **δεν** μετρά ως γινωμένη:
    στέλνεται στον registrar εδώ μόλις στηθεί η σύνδεση, με αναφορά·
  - κάθε γραμμή με την ημερομηνία του τιμολογίου της, ώστε να ξέρει το cron ποιο τέλος σταδίου ανήκει σε ποια ανανέωση·
  - εγγραφή και μεταφορά με `kind` register / transfer·
  - redemption και grace fee με το `kind` του σταδίου τους (`redemption_fee`, `grace_fee`), ώστε το cron να μην τα
    προσθέτει δεύτερη φορά. Τα addons με `kind = charge`·
  - πληρωμένη ή επιστραμμένη γραμμή: με `applied_at` (η ημερομηνία πληρωμής), γιατί έγινε ήδη στον registrar. **Ποτέ**
    δεν ξαναγίνεται εδώ (`DomainRenewalService::waitingLines`, review F11 του #174). Μια απλήρωτη ανανέωση,
    πληρωμένη μετά το cut-over, ανανεώνεται τότε, όπως θα έκανε το WHMCS.
- **Γραμμές τιμολογίων που το WHMCS έσβησε** (η γραμμή μένει, το τιμολόγιο όχι), ή τιμολογίων που το import δεν έφερε:
  δεν εισάγονται, με αναφορά. Στο myip
  είναι 1.344 (77 από αυτές domains), στη nixpal 2.
- **Parity:** domains, ενεργά domains, recurring amount των ενεργών, γραμμές domain.
- **Σύγκριση με τους registrars (myip, 2026-10-29, read-only):** οι λίστες του Openprovider (289) και του CNIC (189)
  συγκρίθηκαν με το import:
  - 458 ενεργά είναι εκεί όπου τα βάζει το import·
  - 66 έχουν άλλη λήξη: 56 κατά ±1 μέρα, 8 ως έναν μήνα και 2 κατά έναν χρόνο ή παραπάνω, πάντα αργότερα στον registrar
    (ανανεώθηκαν εκεί). Το πρώτο sync τις πάει μπροστά·
  - 1 ενεργό είναι στον Openprovider ενώ το WHMCS γράφει CNIC, χωρίς log μετακίνησης·
  - 2 ενεργά που το WHMCS βάζει στον Openprovider δεν είναι σε κανέναν από τους δύο·
  - 1 cancelled βρίσκεται στον Openprovider·
  - 14 domains των registrars δεν υπάρχουν στο WHMCS (10 Openprovider, 4 CNIC)·
  - nameservers: κανένα χωρίς. Ένα έχει τους default του Openprovider (το σημάδι μεταφοράς χωρίς nameservers). Από τα
    26 της μετακίνησης, ένα είναι σε nameservers του afternic (παρκαρισμένο ή προς πώληση) και ένα σε flipconfig.
  Αυτά τα διορθώνει το staff πριν το cut-over. Η λίστα δεν είναι στο repo (στοιχεία πελατών).
  Τα .gr δεν ελέγχθηκαν: το registry δεν έχει λίστα, τα επιβεβαιώνει το sync.
- **Στο cut-over, μετά το import:**
  1. Το staff βάζει τα credentials στις συνδέσεις και τις ανοίγει.
  2. `php artisan domains:sync --dry-run`: η αναφορά διαφορών με τον κάθε registrar, χωρίς να γράψει τίποτα. Ένα domain
     που δεν βρίσκεται στη σύνδεσή του (π.χ. μια μεταφορά «submitted» που δεν ολοκληρώθηκε) πάει στη σωστή σύνδεση με το
     χέρι.
  3. Μετά, το κανονικό sync.

## Product addons του WHMCS → επιλογές της υπηρεσίας (2026-10-21, `provisioning.md`)

`tblhostingaddons` (Active/Suspended) → `service_configs` της γονικής υπηρεσίας:
- **Αντιστοίχιση:** με το `addonid` (`tbladdons`), ή με το όνομα όταν `addonid = 0` (addons φτιαγμένα με το χέρι):
  - «Dedicated IP …» → `dedicated_ip` = Yes·
  - «+10gb …», «10Gb addon …», «20GB Extra Space» → `extra_disk_gb` με τα GB του ονόματος·
  - «System Maintenance» → ξεχωριστή υπηρεσία του προϊόντος «System Maintenance».
- **Τιμή:** μετατρέπεται στον κύκλο της υπηρεσίας (π.χ. 24 €/έτος σε μηνιαίο hosting → 2 €/μήνα), όχι στον δικό της κύκλο. Η διαφορά της τρέχουσας περιόδου δεν τιμολογείται ξανά: το addon είναι ήδη πληρωμένο ως τη λήξη του.
- **Χωρίς αλλαγή στον server:** η IP και το quota διαβάζονται από τον WHM, ώστε το import να μην αλλάξει τίποτα. Χωρίς εργασία για το staff, επειδή η IP υπάρχει ήδη, και χωρίς `editquota` κατά το import.
- **Το extra που έχει ήδη ο λογαριασμός:** γράφεται στην property `cpanel_extra_disk_gb` της υπηρεσίας, όπως το καταγράφει το cPanel module όταν το βάζει. Έτσι μια μελλοντική αλλαγή προϊόντος χωρίς την επιλογή το αφαιρεί.
- **Parity:** πλήθος addons = πλήθος επιλογών «on», και άθροισμα των ετήσιων ποσών ανά πελάτη, με ανοχή στρογγυλοποίησης.


## Domains: από το WHMCS, όχι από τους registrars (ο υπεύθυνος, 2026-10-21)

Ο importer κάθε instance παίρνει τα domains από το **δικό του** WHMCS (`tbldomains`). Τα συνδέει με τη σύνδεση του registrar τους στο MyCS (`registrar` cnic, openprovider, grepp → η αντίστοιχη `DomainRegistrarConnection`). Δεν εισάγει ποτέ τη λίστα ενός λογαριασμού registrar: ο λογαριασμός του CNIC είναι κοινός για myip (198) και nixpal (4), και θα έφερνε τα domains της άλλης εταιρείας. Η λίστα του registrar (`listDomains`) χρησιμεύει μόνο για έλεγχο parity, δηλαδή για όσα υπάρχουν στο ένα σύστημα και όχι στο άλλο.

## Sweep του WHMCS της nixpal (2026-10-24, μόνο ανάγνωση)

WHMCS 8.13.7: 189 πίνακες, οι 107 με δεδομένα· access logs 30/09–24/10/2026. Εδώ μόνο ό,τι **λείπει** ή **θέλει απόφαση**.
Ό,τι καλύπτεται ήδη (πελάτες, υπηρεσίες, άδειες, τιμολόγια, πληρωμές, πιστώσεις, tickets, admins) είναι στο ROADMAP,
γραμμή 9.

### Ρυθμίσεις αυτοματισμού (2026-10-29)
Τις φέρνει το import, αλλιώς το cron θα τερμάτιζε υπηρεσίες της myip στις 60 ημέρες, ενώ το WHMCS τις κρατά ως τις
120 (pre-launch audit, ροή 1· reviews του #518).
- **Διακόπτες και ημέρες:**
  - `AutoSuspension`/`AutoTermination` → `cronjob_suspend_enabled`/`cronjob_terminate_enabled`· «on», «1», «yes», «true»
    σημαίνουν on.
  - `AutoSuspensionDays`/`AutoTerminationDays` → `cronjob_order_suspend`/`cronjob_order_terminate`.
  - `CreateInvoiceDaysBefore` → `cronjob_invoice`.
  - `DailyCronExecutionHour` → `cronjob_time` (ποτέ 03:xx).
  - `CloseInactiveTickets`, σε ώρες → `cronjob_close_ticket`, σε ημέρες, με στρογγυλοποίηση προς τα πάνω (48 → 2). Κενό
    ή 0 σημαίνει ποτέ.
- **Υπενθυμίσεις και πίστωση:**
  - `SendInvoiceReminderDays` → `cronjob_payment_reminder` (με το `SendReminder` off: 0).
  - Οι τρεις υπενθυμίσεις overdue → `cronjob_overdue_reminders`, ως λίστα (myip 1,3,5). Στη nixpal ισχύει η απόφαση
    του υπευθύνου, **1,3,7**, που ορίζεται με το χέρι στο cut-over της.
  - `NoAutoApplyCredit` → `credits_auto_use`, ανάποδα. Η myip έχει την αυτόματη χρήση πίστωσης **κλειστή**.
  - Η ίδια η πίστωση, `credits_enabled`: on όπου το WHMCS επιτρέπει «Add Funds» ή κάποιος πελάτης έχει υπόλοιπο. Και
    στις δύο εταιρείες είναι on. Κλειστή, η αυτόματη χρήση δεν θα έκανε τίποτα και τα υπόλοιπα δεν θα ξοδεύονταν
    (round 3 του #518).
  - Με το «Payment Reminder Emails» (`SendReminder`) off, σταματούν και οι υπενθυμίσεις overdue, όπως στο WHMCS.
- **Τιμές εκτός ορίων:** ό,τι λείπει ή ξεπερνά τις 3.650 ημέρες κρατά την τιμή της MyCS, και το import το λέει.
- **Ημέρες τιμολόγησης ανά κύκλο** (myip: μήνας 10, τρίμηνο 15, εξάμηνο και έτος 20): η MyCS κρατά μία τιμή
  (`cronjob_invoice`), και το import το λέει. **Απόφαση του υπευθύνου (2026-10-29): 14 για τη myip**, όσο και η ενιαία
  τιμή του WHMCS, που την φέρνει ήδη το import. Με το 20 οι μηνιαίοι πελάτες θα έπαιρναν το επόμενο τιμολόγιο λίγες
  μέρες μόνο μετά την πληρωμή του τωρινού.
- **Ανά υπηρεσία, «Override Auto-Suspend»** (myip: 36 ενεργές, από αυτές 32 «ποτέ»):
  - με ημερομηνία → `suspend_hold_until` σε αυτή·
  - χωρίς ημερομηνία → `9999-12-31`.

  Το cron δεν τις αναστέλλει και δεν τις τερματίζει.
- **Υπηρεσία suspended από το WHMCS για «Overdue on Payment»:** το marker της αναστολής παίρνει τη λήξη + Suspend Days
  (ποτέ μετά το «τώρα»), ώστε ο τερματισμός να μην αργεί μία ημέρα.
- Οι 12 suspended υπηρεσίες της myip (2026-10-29) **δεν** έχουν override (`overideautosuspend` = 0), και καμία δεν είναι
  «Overdue on Payment» (11 χωρίς λόγο, 1 με άλλον): το cron του WHMCS αφήνει τον λόγο κενό. Μια suspended
  **χωρίς λόγο** που έχει απλήρωτη γραμμή Hosting/PromoHosting με ληγμένο `duedate` έρχεται ως suspend για χρέος
  (AUD9-3, απόφαση του υπεύθυνου 2026-10-29): η πληρωμή κάνει unsuspend, και το cron μπορεί να την τερματίσει. Οι
  υπόλοιπες (χωρίς τίποτα ληγμένο, ή με άλλον λόγο) μπαίνουν ως κράτηση του staff, που ο τερματισμός του cron δεν
  αγγίζει.
- **Όρια:**
  - Το `CloseInactiveTickets` σε ώρες ως δέκα χρόνια.
  - Οι υπενθυμίσεις overdue ως τις 365 ημέρες, όσες στέλνει το cron. Μια πιο αργή, ή μια που δεν είναι αριθμός, μένει
    εκτός, και το import το λέει.
- **Να ελεγχθεί πριν από το cut-over** (round 2 του #518, όχι επιβεβαιωμένο): πιθανόν το AutoTermination του WHMCS
  παρακάμπτει κάθε υπηρεσία με `overideautosuspend`, όποια κι αν είναι η ημερομηνία. Αν ναι, μια υπηρεσία με override
  που έχει λήξει και την κρατούσε το WHMCS εδώ τερματίζεται. Στις 2026-10-29 η myip δεν έχει καμία τέτοια.

### Λείπουν από τον importer
- **Ετικέτες υπηρεσιών του πελάτη:** `mod_clientproductdescriptions` (type `tblhosting`, pid = service id), 310 εγγραφές,
  124 σε ενεργές → `services.label`. Το addon είναι ακόμα ενεργό, ο φάκελός του όμως δεν υπάρχει πια.
- **Τύποι γραμμών τιμολογίου:**
  - PromoHosting (3.871, relid = υπηρεσία): μπαίνει ως `Service` με `kind = charge` (2026-10-28, «Τύποι γραμμών»).
  - GroupDiscount (498, relid 0): χωρίς υπηρεσία.
  - Γραμμές domain (18): ανήκουν στο D6.
  - **2 ορφανές γραμμές**, χωρίς τιμολόγιο: θα σπάσουν το FK, άρα παραλείπονται με αναφορά.
- **Χώρα του τιμολογίου:** `tblinvoicedata` (6.116) → `invoice_snapshots.tax_country`. Χρειάζεται ως στοιχείο για το
  ΦΠΑ ΕΕ (OSS, reverse charge).
- **Συναίνεση marketing:** `tblclients.emailoptout` (76), `marketing_emails_opt_in` (156), `tblmarketing_consent`
  (269) → το opt-out του `announcement_news` (`NotificationPreference`). Χωρίς αυτό, το πρώτο μαζικό email θα πάει σε
  όσους έχουν πει όχι (GDPR).
- **Κατάσταση υπενθυμίσεων:** των τιμολογίων **έγινε** (review του #531): κάθε απλήρωτο με `due_at` παίρνει ως «στάλθηκε»
  κάθε overdue ημέρα ως σήμερα, και το «due soon» όταν λήγει μέσα στις `SendInvoiceReminderDays` του WHMCS· οι επόμενες
  είναι του cron. Των domains έγινε με το D6 (`domain_reminders`, ενότητα «Domains»).
- **Spam filters των tickets:** `tblticketspamfilters` (54, όλα sender) → `TicketBlockedSender`.
- **Announcements:** 4 δημοσιευμένα → extension Announcements, και τα URLs τους στον χάρτη 301.
- **Handles του Openprovider:** έγινε με το D6 (ενότητα «Domains»): μόνο όσα είναι της επαφής τους.
- **Ακραίες περιπτώσεις:**
  - 48 πληρωμένα τιμολόγια του 2019–2020 **χωρίς αριθμό** (σύνολο 23 €): γίνονται δεκτά.
  - 1 πληρωμή Stripe με ημερομηνία `0000-00-00` και ποσό 0.
  - Ο μετρητής αρίθμησης είναι σήμερα **6845** (`SequentialInvoiceNumberValue`, ο **επόμενος**). Ο importer τον
    διαβάζει μόνος του (AUD9-1): με το sequential numbering του WHMCS, ο μετρητής εδώ = `SequentialInvoiceNumberValue` − 1,
    και έρχονται `invoice_proforma`, `invoice_number_format` (`{YEAR}` → `{year}` κ.λπ.) και `invoice_number_is_id` = off.
    Χωρίς αυτό (myip): αριθμός = id, ο μετρητής στο μεγαλύτερο id.
- **Ημερομηνία λήξης τιμολογίου:** `tblinvoices.duedate` → `invoices.due_at` (AUD9-2): οι υπενθυμίσεις overdue και το
  dunning μετρούν από αυτήν.

### Χρειάζονται απόφαση
- **3η υπενθύμιση overdue την ημέρα λήξης:** το `SendThirdOverdueInvoiceReminder` είναι κενό, και το WHMCS το διαβάζει
  ως ημέρα 0. Στην πράξη ο πελάτης παίρνει 3 emails, τις ημέρες 0, 1 και 2 (118/97/92 σε 12 μήνες). Το
  `settings-map` λέει 1/2/—.
- **Αυτόματη ακύρωση απλήρωτων στις 60 ημέρες:** στη nixpal είναι ενεργή (2 τιμολόγια σε 12 μήνες) και η MyCS δεν έχει
  αντίστοιχη ρύθμιση. Πιθανότατα δεν χρειάζεται, γιατί συμπίπτει με το terminate στις 60.
- **Ιστορικό emails πελατών:** `tblemails`, 13.059. Είτε οι τελευταίοι 12–24 μήνες στα `email_logs`, είτε μόνο αρχείο.
- **Webhooks (ελέγχθηκαν στα dashboards, υπεύθυνος + Claude, 2026-10-27).** Τίποτα δεν αλλάζει πριν το cut-over: ένα
  δεύτερο webhook από τώρα θα έστελνε live events σε διεύθυνση που ακόμα δεν εξυπηρετεί κανείς, ή αργότερα σε ένα
  δοκιμαστικό MyCS που θα κατέγραφε πραγματικές πληρωμές.
  - **PayPal** (app «MyApp_WHMCS»): ένα webhook, `clients.nixpal.com/modules/gateways/callback/paypal_ppcpv.php`, με
    Payment capture completed/declined/denied/pending/refunded/reversed και Vault payment-token created/deleted.
    **Στο cut-over:** νέο webhook στην ίδια app προς `https://<host του MyCS>/extensions/paypal/webhook`, με τα ίδια
    events **και** `CUSTOMER.DISPUTE.CREATED`, `CUSTOMER.DISPUTE.RESOLVED` (τα disputes, `payments.md`)· το Webhook ID
    του μπαίνει στο gateway PayPal της MyCS (υποχρεωτικό: με αυτό ελέγχεται η υπογραφή). Μετά σβήνεται το παλιό.
  - **Stripe** (λογαριασμός Nixpal): ένα endpoint, `clients.nixpal.com/modules/gateways/callback/stripe.php`, API
    2019-09-09, μόνο 4 events αλλαγής κάρτας (`customer.card.updated`, `customer.source.updated`,
    `customer.bank_account.updated`, `payment_method.updated`). Γι' αυτό 0 deliveries: το WHMCS επιβεβαιώνει τις
    πληρωμές σύγχρονα. **Στο cut-over:** τίποτα με το χέρι· η MyCS φτιάχνει το δικό της endpoint
    (`/extensions/stripe/webhook`, 14 events μαζί με τα disputes) όταν σωθεί το gateway Stripe με τα live κλειδιά,
    και κρατά το signing secret. Μετά σβήνεται το παλιό.
  - Host: η MyCS παίρνει το `clients.nixpal.com` (παρακάτω, «Host και gateways»).

### Για το runbook (γραμμή 10)
- **Πάγωμα:** σταματούν **πρώτα** τα cron του clnixpal, `crons/cron.php` και `crons/pop.php`, και τα δύο κάθε 5 λεπτά.
  Αλλιώς το WHMCS συνεχίζει να χρεώνει, και τα δύο συστήματα διαβάζουν το ίδιο POP3.
- **Χάρτης 301:**
  - όλα τα `tblproducts_slugs` (25), τα `/cart.php?a=add&pid=` και τα `?billingcycle=`·
  - τα `/store/...` στα οποία παραπέμπουν τα nixpal.com και zonecloud.io·
  - τα `/announcements/*` και `/knowledgebase/*`.
- **zlic.php:** ~9.000 ελέγχοι την ημέρα από 330 IPs (GET 95%). Τα 6.806 HTTP 400 χρειάζονται κι αυτά parity, γιατί
  είναι ελέγχους με `license` και `license_PSK`: να υπάρχει fixture γι' αυτή την περίπτωση.
- **API του WHMCS:** κανένα σύστημα σε παραγωγή δεν το καλεί. Υπάρχει μόνο ένα credential, του δικού μας εργαλείου
  (84.54.49.6), που ανακαλείται μετά το cut-over.
- Πριν από το πάγωμα: καθάρισμα των 12 ανοιχτών to-dos, μεταφορά με το χέρι του 1 αρχείου πελάτη, και port του welcome
  email «ZoneCloud» στα προϊόντα licensing.
- Δεν χρειάζονται: usage billing (δεν τιμολογήθηκε ποτέ), OAuth SSO, logs, caches των vendors, ο νεκρός
  `nixpal_license`, τα κενά quotes/affiliates/downloads.

#### Host και gateways την ημέρα του cut-over (υπεύθυνος, 2026-10-27)
Η MyCS στήνεται στον orion ως `mycs.nixpal.com`, γίνεται το import εκεί, και μετά **παίρνει το `clients.nixpal.com`**.
Ο λόγος: όλοι οι agents, nodes και controllers του ZoneCloud ελέγχουν την άδειά τους στο
`clients.nixpal.com/zlic.php`, γραμμένο μέσα τους· η διεύθυνση δεν αλλάζει, αλλάζει μόνο τι απαντά. Έτσι και όλα τα άλλα παλιά URLs (`zlic.php`, IPN του PayPal στο `/modules/gateways/callback/paypal.php`, knowledgebase, store)
φτάνουν κατευθείαν στη MyCS, χωρίς forwarders. Με τη σειρά:

1. **Πριν:** η MyCS στο `mycs.nixpal.com` (ίδιος server), πάγωμα του WHMCS (πρώτα τα cron), import και parity.
2. **Host:** στο WHM «Modify Account» το `clients.nixpal.com` γίνεται `old-clients.nixpal.com` (το WHMCS μένει εκεί ως
   αρχείο, χωρίς cron). Το `mycs.nixpal.com` γίνεται `clients.nixpal.com`: vhost/alias, SSL (AutoSSL), `APP_URL` στο
   `.env`, `php artisan config:cache`. Έλεγχος: `clients.nixpal.com/health`, ένα `zlic.php` με fixture.
3. **Μετά τον host, ποτέ πριν:** τα webhooks παίρνουν το `APP_URL` της στιγμής· με το παλιό θα έδειχναν στο
   `mycs.nixpal.com`.
4. **Μαζί μέσω Chrome (υπεύθυνος + Claude):**
   - **PayPal** — developer.paypal.com → Apps & Credentials (Live) → «MyApp_WHMCS»:
     1. Add webhook: `https://clients.nixpal.com/extensions/paypal/webhook`, events: Payment capture
        completed/declined/denied/pending/refunded/reversed, Vault payment-token created/deleted, **Customer dispute
        created/resolved**.
     2. Το **Webhook ID** του νέου → MyCS, Gateways → PayPal → «Webhook ID». Client ID/Secret της ίδιας app (τα βάζει ο
        υπεύθυνος στη φόρμα). Save.
     3. Delete το παλιό webhook (`.../callback/paypal_ppcpv.php`).
     4. Settings → «PayPal accounts of the WHMCS era» (`paypal_legacy_receivers`): email και merchant id του PayPal
        της nixpal (για τα IPN των παλιών συνδρομών).
   - **Stripe** — λογαριασμός Nixpal:
     1. MyCS, Gateways → Stripe: τα live κλειδιά (ο υπεύθυνος στη φόρμα), Save. Η MyCS φτιάχνει μόνη της το endpoint
        `https://clients.nixpal.com/extensions/stripe/webhook` (14 events, με τα disputes) και κρατά το secret.
     2. Workbench → Webhooks: έλεγχος ότι το νέο είναι εκεί και Active· Delete το παλιό (`.../callback/stripe.php`).
5. **Δοκιμή:** μία μικρή πραγματική πληρωμή με κάθε gateway σε δοκιμαστικό τιμολόγιο (και refund), με έγκριση του
   υπευθύνου· στο gateway log της MyCS φαίνονται `recorded` και το webhook.
6. **Μετά:** μήνυμα στους 3 πελάτες με παλιές συνδρομές PayPal (γραμμή 7 του ROADMAP).

## Sweep του WHMCS του myip (2026-10-25, μόνο ανάγνωση)

WHMCS 8.13.7, 204 πίνακες με δεδομένα· logs 30/09–25/10/2026. Μόνο οι διαφορές από τη nixpal (παραπάνω).

### Σημαντικά
- **Bug του importer:** το gateway του Eurobank λέγεται `eurobanklib` και δεν είναι στο `ImportFromWhmcs::GATEWAYS`.
  Αφορά 3.338 πληρωμές, τον τρόπο πληρωμής 167 ενεργών υπηρεσιών και 474 domains. Χαρτογραφείται ως Cardlink. Το
  `paypalpaymentspro` (2 πληρωμές του 2007) μένει χωρίς gateway.
- **Hook `ExpiredDomains.php` στο WHMCS:** σβήνει **οριστικά** κάθε μέρα τα domains που είναι Expired, Cancelled ή
  Transferred Away. Η αναμονή των 90 ημερών δεν δουλεύει, γιατί συγκρίνει string με int. **Απενεργοποιήθηκε
  2026-10-25** (με έγκριση του υπευθύνου): το `add_hook` έγινε σχόλιο, το αρχείο μένει, αντίγραφο
  `ExpiredDomains.php.bak-20261025`. Από τότε δεν χάνονται άλλα.
  - 20 διαγραφές από 19/09 ως 21/10.
  - 6.615 γραμμές τιμολογίων δείχνουν σε domains που δεν υπάρχουν πια. Μόνο τα 809 βρίσκονται στο `tbldomains_backup`.
- **Νεκρό `auto-cancellation.php`** (`mysql_*`): η ακύρωση του add funds στις 30 ημέρες, των απλήρωτων παραγγελιών στις
  31 και των τιμολογίων στο terminate **δεν γίνονται σήμερα**. Πρόταση για το myip: `cronjob_add_funds_cancel = 0`, εκτός
  αν ο υπεύθυνος το θέλει ενεργό.
- **Το Ekdosi του devbox (84.54.49.31) διαβάζει το WHMCS της παραγωγής** μέσω `ekdosi_bridge/resolve.php`: 21.034 κλήσεις
  σε 25 ημέρες.

### Λείπουν ή θέλουν απόφαση (μόνο του myip, ή με άλλο όγκο)
- **Ίδια κενά με τη nixpal, άλλος όγκος:**
  - χώρα τιμολογίου: 16.581 τιμολόγια, ~810 εκτός GR·
  - opt-out marketing: 344 `emailoptout`, `tblmarketing_consent` 829 (366 όχι). Το myip στέλνει campaigns: 24 έχουν
    σταλεί, η μία σε 518 παραλήπτες·
  - ιστορικό emails: 55.379·
  - `tbldomainreminders`: 34.617·
  - spam filters: 849, από τα οποία 1 με subject (δεν υπάρχει αντίστοιχο στη MyCS)·
  - announcements: 5, συν 7 στον custom πίνακα `tblannouncementsEN`·
  - αρχεία πελατών: 18·
  - to-dos: 619 ανοιχτά.
- **Τύποι γραμμών τιμολογίου:**
  - Addon 378, Upgrade 324, Setup 7 και PromoHosting 117 → `Service` με `kind = charge` (2026-10-28, «Τύποι γραμμών»).
  - DomainAddonIDP 7, DomainAddonDNS 1, PromoDomain 1: με τα domains, στο D6.
  - Επίσης υπάρχουν manual 1.714, AddFunds 619 και mass pay 233.
- **Ορφανά:**
  - 1.344 γραμμές σε τιμολόγια που σβήστηκαν (~30,4 χιλ. €): παραλείπονται, με αναφορά.
  - **340 πληρωμές σε τιμολόγια που σβήστηκαν** (19.892 €) και **63 χωρίς τιμολόγιο** (10.345 €): εισάγονται ως μη
    κατανεμημένες, με σημείωση, αλλιώς τα σύνολα ανά gateway δεν βγαίνουν.
  - Γραμμές σε υπηρεσίες ή domains που σβήστηκαν: εισάγονται με κενή αναφορά, ποτέ με «μάντεμα».
- **Πίστωση:** το υπόλοιπο στον πελάτη είναι 2.687,52 € (23 πελάτες), το ledger αθροίζει 7.879,44 € και διαφωνούν σε
  22 πελάτες. Πηγή αλήθειας είναι το υπόλοιπο, συν μια εγγραφή «άνοιγμα» στο ledger.
- **Αρίθμηση:**
  - number = id, max id **32.598** (τα docs έλεγαν 32.453/32.476)·
  - ~11,7 χιλ. κενά από διαγραμμένα τιμολόγια, φυσιολογικά.
- **Ακραίες τιμές:**
  - 4 Paid χωρίς `datepaid`·
  - 2 πληρωμές με ημερομηνία `0000-00-00`·
  - 59 ενεργές δωρεάν υπηρεσίες με μηδενικό `nextduedate` → null.
  - Status Fraud: 2 υπηρεσίες και 2 παραγγελίες → cancelled, με σημείωση.
- **Client groups:**
  - 1 ομάδα με ξεχωριστά τιμολόγια, 32 μέλη·
  - 3 ομάδες με εξαίρεση από suspend/terminate·
  - η MyCS έχει σήμερα μόνο έκπτωση ανά ομάδα, οπότε χρειάζονται flags στην ομάδα ή σε κάθε πελάτη.
- **Flag ανά πελάτη «χωρίς υπενθυμίσεις overdue»** (`overideduenotices`): 18 πελάτες → `clients.no_overdue_reminders`.
  Τα flags αυτά υπάρχουν πλέον και στις ομάδες (`client_groups.separate_invoices`, `dunning_exempt`,
  `no_overdue_reminders`), και ο importer τα παίρνει από το `tblclientgroups`.
- **Ekdosi:**
  - `mod_ekdosi_contacts` 178, `mod_ekdosi_routing` 185, `mod_ekdosi_invoice_marks` 147: εισάγονται στη γέφυρα
    (`ekdosi-bridge.md` → «Importer»): οι επαφές γίνονται κανονικές `contacts`, το routing η σχετική επαφή της υπηρεσίας
    ή του domain, τα marks `ekdosi_documents`·
  - `mod_timologia*`: αρχείο.
- **Δεν έχουν extension στη MyCS:**
  - TheSSLStore: 12 ενεργές υπηρεσίες. Ο importer τις βάζει σε server «TheSSLStore (offline)» του module **Offline
    SSL**, μαζί με τα προϊόντα τους (`importOfflineSsl`), από όποιον server κι αν τις είχε αφήσει το `tblhosting.server`
    (τυπώνονται όσες μετακινήθηκαν). Κάθε ενέργεια (έκδοση, suspend, terminate, αλλαγή) γίνεται εργασία του staff στα
    Pending Module Actions, ποτέ σιωπηλό «τίποτα». Το ίδιο και η **πληρωμένη ανανέωση**: εργασία «renew», για να αγοραστεί
    πάλι το πιστοποιητικό. Τα προϊόντα έρχονται **hidden και retired** (απόφαση ιδιοκτήτη 2026-10-29): οι υπάρχουσες
    υπηρεσίες ανανεώνονται, οι νέες παραγγελίες πάνε στο Openprovider SSL. Τα στοιχεία της παραγγελίας μπαίνουν στην
    υπηρεσία: πάροχος, αριθμοί παραγγελίας (provider, CA), τύπος, common name όταν διαφέρει από το domain, κατάσταση και
    λήξη. Παίρνεται ολόκληρη η τελευταία γραμμή του module (`mod_sslstore_orders_data`), ποτέ ανακατεμένη με παλιότερη
    παραγγελία. Τα κενά της συμπληρώνει το `tblsslorders`, μόνο όταν είναι η ίδια παραγγελία (και ποτέ γραμμή άλλου
    module ή addon). Ποτέ το CSR, τα κλειδιά ή το `authdata`. Στο myip υπάρχουν στοιχεία για 4 από τις 12· στις άλλες τα
    συμπληρώνει το staff. Περνούν στο Openprovider SSL στη λήξη τους (απόφαση ROADMAP).
  - Hetzner Cloud: 1 υπηρεσία. Γίνεται Offline VM.
  - ngm: 1 υπηρεσία. Γίνεται Offline VM.
- **Centova:** custom fields `port`, `username`, `sourcepassword` (μυστικό, κρυπτογραφείται), `servertype`, `sourcetype`,
  `transferlimit`. Ο mailcow έχει 10 ενεργές.
- **Domains, για το D6:**
  - 1.565 additional fields: IDN script, entity types, CNIC EU/ES, grepp·
  - 10 με DNSSEC·
  - 1 ενεργό στον **enom**, 1 χωρίς status, 1 Grace χωρίς registrar: διορθώνονται με το χέρι·
  - το migration domains (197 υποβλήθηκαν, 26 απέτυχαν) πρέπει να τελειώσει πριν από το cut-over.
- **Ειδοποιήσεις Mattermost:** 5 κανόνες στο WHMCS → Setup → Chat notifications.
  - Ένα incoming webhook ανά κανόνα, για νέο ticket, απάντηση πελάτη, παραγγελία, πληρωμή, suspend, unsuspend,
    terminate και ακύρωση.
  - Στήνονται με το χέρι στο cut-over, με τα webhooks του Mattermost. Το «Payment auto matched» θα έρθει με το
    matching των τραπεζών.
- **Templates:** από τα custom, σε χρήση είναι μόνο το «Domain Transfer Complete» (54 τον χρόνο), που μεταφέρεται.
- **Ιστορικό του `payments_tracking`** (2.318): εισάγεται ως history ή μένει μόνο σε αρχείο;
- Αρχείο μόνο: quotes 14, CRM (mgCRM2) 555 leads, affiliates, ExpenseTracker.

### Ρυθμίσεις που διαφέρουν από το settings-map
- **Σαββατοκύριακο:** κρατιούνται μόνο τα **suspend** (Σάββατο, Κυριακή, Δευτέρα 00–09). Τα terminate τρέχουν κανονικά.
- **ΦΠΑ:**
  - δύο κανόνες, GR 24% και «All other» 24%·
  - `TaxCustomInvoices` κλειστό, οπότε τα χειροκίνητα τιμολόγια δεν έχουν φόρο·
  - `TaxDomains` ανοιχτό.
- Επίσης:
  - `CreditOnDowngrade` κλειστό·
  - add funds από 1 ως 5.000 €, μέγιστο υπόλοιπο 10.000 €·
  - `DomainSyncNextDueDate` ανοιχτό·
  - 2FA υποχρεωτικό για τους admins·
  - `AllowDomainsTwice` ανοιχτό.

### Τι σπάει στο cut-over
- **Cron του myipgr:**
  - `cron.php`, κάθε 5 λεπτά·
  - `pop.php`, κάθε 3 λεπτά·
  - `bank-payments-logging.php`, κάθε 3 λεπτά.
- **Ekdosi:** η παραγωγή (`resolve.php` 22.197 κλήσεις, `inbound.php` 82) γυρίζει στη MyCS. Του devbox σταματά.
- **Site:**
  - το `/api/domain-check.php` κάνει `require` το `init.php` του WHMCS, για GetTLDPricing και DomainWhois·
  - `/clients/mydomainsearch.php`: 345 κλήσεις, από άγνωστο caller·
  - `/clients/dl.php`: 206·
  - `grwhoisapi/consumer.php` καλείται από τον ίδιο τον rigel.
- **Gateways:**
  - Eurobank: confirm/cancel μόνο για όσες πληρωμές βρίσκονται σε εξέλιξη τη στιγμή του cut-over·
  - ppcpv webhook, όπως στη nixpal·
  - Stripe webhook: 5 κλήσεις.
- **Χάρτης 301:** το clients.myip.gr κάνει redirect στο `/clients/…`, και `/store/cpanel-hosting/*`, `cart.php`,
  `/knowledgebase/tag/*` έχουν ακόμα κίνηση.
- **API:** καμία κλήση από παραγωγή. Τρία credentials (ALL-ROLES, ekdosi, invoicer), που ανακαλούνται μετά.
- **`/home/myipgr/eurobank_iris_debug.txt`:** debug log του gateway, 942 KB. Να ελεγχθεί τι κρατά και να σβηστεί.
