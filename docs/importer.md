# MyCS: WHMCS importer με 1:1 parity (απαίτηση, 2026-10-11)

> Πρότυπο: το `migrate:firebird` του Ekdosi (`app/Console/Commands/MigrateFromFirebird.php`, 1264 γρ.), που έφερε
> περίπου 7.000 παραστατικά από το Ekdosi Legacy σε λεπτά και χωρίς απώλειες, και το `ekdosi:go-live-check`. Ο
> importer του Paymenter **δεν** χρησιμοποιείται (`audit-importer-health.md`: σβήνει τη βάση σε κάθε run, δεν
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
- **Ό,τι άλλο βγάλει η απογραφή χρήσης του WHMCS** (`whmcs-usage.md`): mass pay/merge, πιστώσεις, promotions και εκπτώσεις ομάδων, κανόνες ΦΠΑ/VIES, custom fields, suspend overrides, guest tickets.

## Status τιμολογίων (2026-10-13)
Το MyCS λέει ό,τι και το WHMCS, στη βάση και στις οθόνες: το «Unpaid» είναι `unpaid` (μέχρι 2026-10-13 ήταν `pending`,
λέξη του Paymenter· το migration `rename_invoice_status_pending_to_unpaid` μετέτρεψε τα παλιά). Οι οθόνες γράφουν
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

Ο importer του Paymenter (`ImportFromWhmcs.php`) γράφει πλέον τις λέξεις του πίνακα για τα τιμολόγια. Δεν
χρησιμοποιείται· ο νέος importer ακολουθεί τον πίνακα. Οι υπηρεσίες (Terminated ≠ Cancelled) ακολουθούν σε ξεχωριστό PR.

## Έλεγχος
- Κάθε αριθμός της λίστας parity βγαίνει **και από τις δύο πλευρές** με μία εντολή (π.χ. `mycs:parity --whmcs-copy=…`)
  και τυπώνει diff. Cut-over μόνο με μηδενικό diff ή με κάθε διαφορά εξηγημένη και καταγεγραμμένη.
- Tests του importer σε fixture βάση WHMCS (ανωνυμοποιημένη, CLAUDE.md), όπως τα `WhmcsSchema`/`BlestaSchema` του
  `lib/nixpal-licensing/tests`.
- Η nixpal πρώτη (31 ενεργοί, ~330 άδειες), μετά 2–3 δοκιμαστικά runs του myip πριν το cut-over.
