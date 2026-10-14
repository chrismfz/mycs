# Paymenter: μηχανή χρέωσης (audit 2026-10-10)

> Ανάγνωση κώδικα στο `5b69d45`. [V] = ελέγχθηκε στον κώδικα, [G] = συμπέρασμα. Οι διαδρομές είναι σχετικές με τη ρίζα του Paymenter.

## Μοντέλο δεδομένων: workable
- Πίνακες: products, plans (free / one-time / recurring, `billing_unit` × `billing_period`), prices ανά νόμισμα με setup fee,
  config_options, services, service_configs, invoices, invoice_items (polymorphic `reference`: Service, ServiceUpgrade,
  Credit), invoice_transactions, credits (ένα υπόλοιπο ανά χρήστη και νόμισμα, **χωρίς ledger**), coupons, tax_rates,
  adjustment_notes, service_upgrades, service_cancellations, billing_agreements, invoice_snapshots.
- Το σύνολο του τιμολογίου **υπολογίζεται**, δεν αποθηκεύεται. Ο φόρος βγαίνει από τη χώρα ή από το snapshot.

## Κύκλος ζωής: workable με αιχμηρά σημεία
- Ένα ημερήσιο `app:cron-job` (`routes/console.php:12`, `app/Console/Commands/CronJob.php`), **όλο σε ένα DB
  transaction** (:48, :221-235). Μια εξαίρεση ακυρώνει όλη τη δουλειά της ημέρας και στέλνει email στον admin.
- Τιμολόγιο ανανέωσης `cronjob_invoice` ημέρες (default 7) πριν το `expires_at`. Παραλείπεται όταν υπάρχει pending
  τιμολόγιο ή ακύρωση (:51-114). Αυτόματη χρήση πιστώσεων, και χρέωση του billing agreement μετά το commit.
- Pending orders ακυρώνονται μετά από 7 ημέρες (:116-133). Suspend στο expires_at + 2 ημέρες (`suspend_hold_until`),
  terminate στο + 14 (:170-206).
- Πληρωμή → `InvoiceTransactionCreatedListener` (paid όταν remaining ≤ 0) → `InvoiceObserver::updated` (afterCommit)
  → `ProcessPaidInvoiceService` → `RenewServiceService` → `expires_at = calculateNextDueDate()`.
- Υπηρεσία σε suspend: η νέα περίοδος μετράει **από τώρα**, όχι από την παλιά λήξη (σκόπιμα, με test).
- Server jobs (Create, Suspend, Unsuspend, Terminate): queue `after_commit`, `$tries = 1`, retry με το χέρι από το
  `FailedJobResource`. **Το status της υπηρεσίας αλλάζει πριν τρέξει το job** (`CronJob.php:180`). Αν το job
  αποτύχει, βάση και server διαφωνούν και δεν υπάρχει reconciliation.
- Ακύρωση «immediate» → terminate αμέσως. Η «end of period» απλώς σταματάει τα τιμολόγια ανανέωσης, άρα η υπηρεσία
  γίνεται suspend και μετά terminate όπως μια απλήρωτη [G].

## Edge cases: αδύναμο
- **Δουλεύουν:** μερικές και πολλαπλές πληρωμές (με tests), πληρωμή από πιστώσεις, refunds με row lock και credit
  note, credit note σε ακύρωση τιμολογίου, upgrade/downgrade με proration ημέρας **στον ίδιο κύκλο**.
- **Υπερπληρωμή:** το τιμολόγιο γίνεται paid, αλλά το επιπλέον **δεν** γίνεται πίστωση.
- **Διπλή αναφορά πληρωμής:** ασφαλής μόνο όταν το gateway δίνει `transaction_id` (`updateOrCreate` με row lock,
  `ExtensionHelper.php:475-506`). Δεν υπάρχει unique constraint.
- **Πληρωμή ακυρωμένου τιμολογίου:** κανένας έλεγχος status. Οι ακυρώσεις γίνονται με `->update()` χωρίς observers
  (`CronJob.php:122,197`, `CancellationCreatedListener.php:26`). Μια καθυστερημένη πληρωμή ανανεώνει, και μπορεί να
  ξαναενεργοποιήσει υπηρεσία που έχει γίνει terminate χωρίς provisioning [G].
- **Δύο τιμολόγια για μία υπηρεσία:** και τα δύο ανανεώνουν, γιατί **δεν υπάρχει περίοδος ανά τιμολόγιο**.
- **Δεν υπάρχει** κατάσταση «ανανεώθηκε πριν την πληρωμή».

## Domains: λείπουν εντελώς
Δεν υπάρχει registrar, TLD, EPP ή λήξη domain σε `app`, `database`, `extensions`. Το `app/Rules/Domain.php` είναι μόνο
validator. Ο importer γράφει `null` στο reference των γραμμών Domain (`ImportFromWhmcs.php:958`).

## Αρίθμηση: workable
- Ρυθμίσεις `invoice_number`, `_padding`, `_format` (`{number}{year}{month}{day}`) στο `Settings.php:559-590`, με
  locking (`InvoiceNumberListener.php:110-123`).
- **Η ρύθμιση κρατάει τον τελευταίο αριθμό, όχι τον επόμενο.** Για συνέχεια μετά το 32453, `invoice_number = 32453`.
- Proforma mode: ο αριθμός δίνεται στην πληρωμή.

## Λοιπά
- Πολλαπλά νομίσματα: ναι, χωρίς ισοτιμίες.
- ΦΠΑ: ένας συντελεστής ανά χώρα, χωρίς ΑΦΜ και χωρίς reverse charge.
- Email: templates σε DB (12 κλειδιά, μία γλώσσα), email log. **Δεν υπάρχουν υπενθυμίσεις πληρωμής.**
- Tickets: βασικά (`audit-security.md`, `ekdosi-vs-upstream.md` §12).
- Admin: Filament 5. Client area: Livewire. Admin REST API. Δεν υπάρχουν ελληνικά.

## Μοντέλο επέκτασης: workable
- Base classes `App\Classes\Extension\{Extension,Server,Gateway}`. Οι server μέθοδοι είναι duck-typed (`hasFunction`).
  Τα gateways υλοποιούν pay/charge/refund. Κάθε extension έχει `boot()`.
- 69 events: Invoice (+ Paid, Finalized, GeneratePdf), InvoiceItem, InvoiceTransaction, Service, ServiceUpgrade,
  ServiceCancellation, Order, Cart, User, Ticket, TicketMessage, Notification, Setting, AdjustmentNote, Auth\Login.
- **Όριο:** cron, ανανέωση και suspend είναι hard-coded services, χωρίς hooks. Για αλλαγή εκεί πειράζεις τον πυρήνα
  (σε hard fork αυτό είναι αποδεκτό).

## Ποιότητα
- 15 αρχεία tests, 66 μέθοδοι. Καλύπτουν cron (9), πληρωμές (10), ανανέωση (3) και αρίθμηση (2). Τίποτα για
  upgrades, refunds, coupons, ακυρώσεις ή gateways.
- `lockForUpdate` χρησιμοποιείται 15 φορές.
- Αδυναμίες: ένα τεράστιο transaction στο cron, μαζικά `->update()` που παρακάμπτουν τους observers, floats για
  χρήματα, και `invoices.user_id` με cascadeOnDelete.

## Top 5 ρίσκα → τι κάνουμε στο fork
1. Δεν υπάρχουν domains → port από το Ekdosi.
2. Η ανανέωση δεν δένεται με περίοδο → cursor και ανανέωση ανά τιμολόγιο από το Ekdosi.
3. Το status αλλάζει πριν πετύχει το job → αλλαγή μόνο μετά την επιτυχία, και reconciliation.
4. Λείπουν υπερπληρωμή → πίστωση, υπενθυμίσεις και ledger πιστώσεων → `PaymentAllocator` και υπενθυμίσεις από το Ekdosi.
5. Λίγα tests και bugs στον importer → tests για κάθε ροή χρημάτων και νέος importer.
