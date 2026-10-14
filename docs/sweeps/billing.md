# Sweep χρέωσης: Blesta 6.0.3 ↔ MyCS (2026-10-15)

> Μόνο έρευνα, χωρίς αλλαγές κώδικα. 7 agents, ένας ανά περιοχή. Διάβασαν read-only τον κώδικα του Blesta 6.0.3
> (staging `cs.nixpal.com` στον orion, κώδικας vendor: εδώ περιγράφεται μόνο η συμπεριφορά του, με file:line) και τον
> κώδικα του MyCS στο `main`. Για να φανεί τι μας αφορά, χρησιμοποίησαν και αθροίσματα από το WHMCS των δύο εταιρειών
> (μόνο SELECT με COUNT/GROUP BY). Το αντίστοιχο για το Σ1 και τα modules είναι στο `docs/sweeps/modules.md` (#118).
>
> **Επιφύλαξη:** μερικά αρχεία του Blesta στον orion (`transactions.php`, `gateway_payments.php`, `invoices.php`, με
> ημερομηνία 2026-09-29) έχουν μεγάλα σχόλια για locks και deadlock retries. Πριν τα επικαλεστούμε ως «το Blesta κάνει
> X», να συγκριθούν με καθαρό tarball του 6.0.3: μπορεί να είναι τοπικές αλλαγές της παλιάς δουλειάς στο Blesta.

## Πού είμαστε, με μια ματιά

Ο **πυρήνας των χρημάτων** του MyCS είναι πιο αυστηρός από του Blesta: πληρωμές, πιστώσεις, refunds, τι κάνει ένα
τιμολόγιο paid, ΦΠΑ ανά γραμμή σε decimal, κλειδώματα και ημερολόγιο χρημάτων (money trail). Το Blesta δεν παίρνει locks
στο merge και στο split. Στις ανανεώσεις μετακινεί τον «δρομέα» της περιόδου έξω από το transaction του τιμολογίου. Ξαναϋπολογίζει
τον φόρο εκδομένων τιμολογίων σε float. Ο κώδικας του MyCS, δηλαδή, δεν χρειάζεται να «διορθωθεί προς το Blesta» στα
βασικά.

Αυτό που λείπει είναι **λειτουργίες γύρω από τον πυρήνα** που χρησιμοποιούν οι δύο εταιρείες κάθε μέρα:

| Τι λείπει ή είναι λάθος | Γιατί μετράει (WHMCS, 12μ) | Ευρήματα |
|---|---|---|
| Ακυρωμένο τιμολόγιο ανανέωσης **ξαναβγαίνει** την επόμενη μέρα (το Δ3 το αποφάσισε, δεν υλοποιήθηκε) | myip 238 ακυρωμένα τιμολόγια ανανέωσης | Π1.2 |
| Ομαδοποίηση ανανεώσεων σε ένα τιμολόγιο | nixpal: ~400 τιμολόγια με 2–24 υπηρεσίες, 14 με 33–116 | Π1.3 |
| **Pro rata προϊόντος** (ευθυγράμμιση στην 1η του μήνα) | nixpal: **336 από 350** ενεργές υπηρεσίες σε προϊόντα prorata (μέρα 1, μετά τις 20 χρεώνει και τον επόμενο μήνα). Χωρίς αυτό, οι νέες παραγγελίες «φεύγουν» από την 1η και η ομαδοποίηση χαλάει | Π1.4 |
| Mass Pay και μία πληρωμή σε πολλά τιμολόγια (και από το staff) | nixpal 32, myip 22· και ο importer: γονικά με τις πληρωμές, παιδιά «paid» χωρίς πληρωμή | Π2.7, Π2.8, Π2.9, Π3.1 |
| Merge / split για το staff | myip 61 merge, 6 split | Π2.1–Π2.6 |
| Το staff δεν μπορεί να **εφαρμόσει πίστωση** σε τιμολόγιο | myip: NoAutoApplyCredit, 2.626 τιμολόγια πληρωμένα με πίστωση | Π3.2 |
| Αυτόματη χρέωση κάρτας: **την ημέρα που βγαίνει το τιμολόγιο, μία φορά, χωρίς retry** | WHMCS χρεώνει τη μέρα λήξης και ξαναδοκιμάζει κάθε μέρα· nixpal Stripe 434 declined / 272 success | Π7.1, Π7.2 |
| Διπλή χρέωση κάρτας: cron και «πλήρωσε με αποθηκευμένη» χωρίς idempotency key ή lock (PAY-3) | κάθε πελάτης με αποθηκευμένη κάρτα | Π7.4 |
| Staff αλλάζει/σβήνει γραμμές σε μερικώς πληρωμένο τιμολόγιο από το Filament **χωρίς τα locks πληρωμής** | λάθος σε ποσά | Π2.11 |
| Επανενεργοποίηση με πληρωμή ενώ υπάρχει ακόμα ληξιπρόθεσμο → αναστολή ξανά στο επόμενο cron | διπλά emails, και ο server πάει-έρχεται | Π4.1 |
| EU VAT / VIES / reverse charge | γραμμή 4 του roadmap | Π5.7, Π6.1–Π6.6 |

## Αποφάσεις (ο ιδιοκτήτης, 2026-10-15)

| # | Απόφαση |
|---|---|
| 1 | Δ3: **σημαδεμένη γραμμή**. Η ακυρωμένη γραμμή ανανέωσης μένει και μετράει ως «τιμολογήθηκε, όχι ξανά»· η ακύρωση γραμμής τη σημαδεύει, δεν τη σβήνει· «Επαναφορά» τιμολογίου = η περίοδος ξαναχρεώνεται. |
| 2 | Refunded περίοδος: **δεν ξαναχρεώνεται** (όπως το cancelled). Κλείνει το Α6. |
| 3 | Pro rata της nixpal: **ναι**, ρύθμιση ανά προϊόν (μέρα ευθυγράμμισης, «μετά τις N χρέωσε και τον επόμενο»), πριν το cut-over της nixpal. |
| 4 | Ανοιχτά τιμολόγια σε ακύρωση/τερματισμό: **ρύθμιση**, default «ακύρωση» (όπως το WHMCS σήμερα). |
| 5 | Retries κάρτας: **ρύθμιση**, default «κάθε μέρα» από τη μέρα λήξης ως πληρωμή ή αναστολή· προαιρετικά σταθερές μέρες και όριο αποτυχιών. |
| 6 | Αποθηκευμένη μέθοδος: **ανά πελάτη**. Οι συνδρομές gateway μένουν εξαίρεση, σε δικό τους τιμολόγιο. |
| 7 | Mass Pay: **μία πληρωμή σε N τιμολόγια** (`group_reference`), μόνο ολόκληρο υπόλοιπο, περίσσευμα σε πίστωση· ο importer μετατρέπει τα γονικά του WHMCS. |
| 8 | Merge με πληρωμές: **άρνηση**. Τα αρχικά κλείνουν με σημείωση «συγχωνεύτηκε στο #X», δεν σβήνονται. |
| 9 | Έδρα ΦΠΑ της nixpal: **Εσθονία (EE)**, όπως σήμερα. Ο πελάτης με εσθονικό ΑΦΜ και exempt ελέγχεται πριν το import. |
| 10 | Τιμές «με ΦΠΑ»: **μόνο εμφάνιση**· ο κατάλογος μένει σε καθαρές τιμές. |
| 11 | «Price Override»: **νέος τύπος κουπονιού** (σταθερή τιμή), και ο importer τα φέρνει 1:1. |
| 12 | Δ4 `completed`: **δεν γίνεται** τώρα (συμφωνεί και η session του Σ1). |

## Οι αποφάσεις όπως τέθηκαν

1. **Δ3, πώς «θυμάται» ότι μια περίοδος δεν χρεώνεται:** (α) η ακυρωμένη γραμμή ανανέωσης μένει και μετράει ως «τιμολογήθηκε,
   όχι ξανά» στο `nextPeriodStart` (όχι στο «πληρώθηκε ως»), και η ακύρωση γραμμής την **σημαδεύει** αντί να τη σβήνει· ή
   (β) ρητός δρομέας `next_invoice_at` δίπλα στο `expires_at`, όπως το `nextinvoicedate` του WHMCS. Και στις δύο εταιρείες
   `nextinvoicedate = nextduedate` σε όλες τις ενεργές υπηρεσίες, οπότε ο importer μπορεί να γεμίσει και τα δύο.
   **Πρόταση: (α)**. Μένει μέσα στο μοντέλο «η περίοδος είναι γραμμή» που ήδη προστατεύει από διπλή χρέωση (Π1.1).
2. **Refunded περίοδος** (Α6, ανοιχτό): σήμερα είναι «τρύπα» και ξαναχρεώνεται. Blesta και WHMCS δεν την ξαναχρεώνουν.
   Πρόταση: όπως το cancelled, δεν ξαναχρεώνεται.
3. **Pro rata της nixpal:** οι νέες παραγγελίες συνεχίζουν να ευθυγραμμίζονται στην 1η (και μετά τις 20 χρεώνεται και ο επόμενος
   μήνας); Οι 2 υπηρεσίες σε προϊόντα με μέρα 5 είναι παλιές;
4. **Ανοιχτά τιμολόγια στην ακύρωση και στον τερματισμό:** το WHMCS σήμερα τα ακυρώνει (24μ, τιμολόγια υπηρεσιών Terminated:
   nixpal 12 cancelled / 1 unpaid, myip 57 / 1). Το MyCS κάνει το ίδιο. Το Blesta κρατά όσα είναι ληξιπρόθεσμα πάνω από N μέρες
   (το χρέος μένει). Πρόταση: ρύθμιση `all` / `none` / «όχι τα ληξιπρόθεσμα πάνω από N μέρες», με default το σημερινό
   (`all`), ώστε να αλλάξει όποτε το θελήσει η εταιρεία.
5. **Retries αυτόματης χρέωσης:** «κάθε μέρα μέχρι να πληρωθεί ή να ανασταλεί» (WHMCS σήμερα) ή όριο, π.χ. μέρες 0, 1, 3, 5, 7 και
   μέτρημα αποτυχιών (Blesta);
6. **Αποθηκευμένη μέθοδος πληρωμής:** ανά πελάτη (WHMCS, Blesta) ή ανά πελάτη με προαιρετική εξαίρεση ανά υπηρεσία; Με την
   ομαδοποίηση του Δ3, η σημερινή «ανά υπηρεσία» δεν στέκει (Π7.3).
7. **Mass Pay:** (α) «container» τιμολόγιο όπως το WHMCS, με γραμμές που δείχνουν στα αρχικά (ο importer αντιστοιχεί 1:1,
   μικρότερη αλλαγή στα gateways), ή (β) πληρωμή μοιρασμένη σε N τιμολόγια όπως το Blesta (`group_reference`, χωρίς ψεύτικο
   τιμολόγιο). **Πρόταση: (β) για ό,τι νέο**, και ο importer μετατρέπει τα WHMCS γονικά σε ομάδα πληρωμής. Ποσό ανά τιμολόγιο:
   μόνο ολόκληρο το υπόλοιπο. Αν ένα τιμολόγιο κλείσει στο μεταξύ, πληρώνονται τα υπόλοιπα και το περίσσευμα γίνεται πίστωση
   (το Blesta κάνει όλη την πληρωμή πίστωση). Να ελεγχθεί πριν: ποιο από τα δύο (γονικό ή παιδιά) έστειλε το WHMCS στο Ekdosi.
8. **Merge με πληρωμές:** άρνηση (όπως το Blesta), ή μεταφορά των πληρωμών; Πρόταση: άρνηση. Τα αρχικά κλείνουν με σημείωση
   «συγχωνεύτηκε στο #X», δεν σβήνονται (Blesta: void· WHMCS: διαγραφή).
9. **Home country της nixpal για ΦΠΑ:** το WHMCS της nixpal έχει `TaxEUHomeCountry=EE` και 27 κανόνες ανά χώρα. Μετά το
   cut-over: Εσθονία/OSS ή Ελλάδα 24%; Από αυτό εξαρτώνται ο πίνακας συντελεστών, το reverse charge και τι υποβάλλει το Ekdosi.
   Ένας ενεργός πελάτης με εσθονικό ΑΦΜ είναι tax exempt στη χώρα της έδρας: σκόπιμο ή λάθος ρύθμισης του WHMCS;
10. **Τιμές με ΦΠΑ:** μόνο εμφάνιση (αποθηκεύεται καθαρό, δείχνεται μικτό) ή ο κατάλογος κρατά μικτές τιμές; Το πρώτο αποφεύγει
    την απόκλιση 1 λεπτού και το πρόβλημα του tax exempt (Π5.2, Π5.3) χωρίς νέο κώδικα.
11. **Κουπόνι «Price Override»** του WHMCS (οι δύο πιο χρησιμοποιημένες επαναλαμβανόμενες προσφορές της nixpal, 212 και 83
    χρήσεις): νέος τύπος κουπονιού ή μετατροπή στην τιμή της υπηρεσίας στο import;
12. **Δ4 `completed`:** κανένα από τα δύο WHMCS δεν το χρησιμοποιεί (0 υπηρεσίες) και το Blesta δεν το έχει. Πρόταση: **δεν το
    φτιάχνουμε** τώρα· ξανά, αν το ζητήσει η δουλειά.

## Πρόταση σειράς (αντικαθιστά τη γραμμή 3 μετά τη «Σχετική επαφή»)

Κάθε PR με χρήματα περνά code review και adversarial review.

| PR | Τι | Ευρήματα | Εξαρτάται από |
|---|---|---|---|
| A | **Δ3a, ακύρωση = η περίοδος δεν χρεώνεται:** σημαδεμένη γραμμή αντί για διαγραφή· «Επαναφορά» τιμολογίου· refunded· ανοιχτά τιμολόγια σε ακύρωση/τερματισμό ως ρύθμιση· επανενεργοποίηση μόνο χωρίς ληξιπρόθεσμο | Π1.2, Π4.1, Π4.2 | αποφάσεις 1, 2, 4 |
| B | **Locks στις αλλαγές γραμμών από το staff** σε τιμολόγιο με πληρωμή (μικρό, γρήγορο) | Π2.11 | – |
| C | **Δ3b, ομαδοποίηση:** κλειδί (λογαριασμός, ημερομηνία λήξης, νόμισμα), εξαιρούνται συνδρομές gateway· «ξεχωριστά τιμολόγια» ανά πελάτη· «ένωση όμοιων γραμμών»· επιλογές στην περιγραφή της γραμμής· αποθηκευμένη μέθοδος ανά πελάτη | Π1.3, Π1.10, Π7.3 | A, απόφαση 6 |
| D | **Pro rata προϊόντος** (μέρα ευθυγράμμισης, «μετά τις N χρέωσε και τον επόμενο») | Π1.4 | απόφαση 3 |
| E | **Merge / split** για το staff: μετακίνηση γραμμών σε ένα transaction με locks πελάτη → τιμολογίων, άρνηση με πληρωμές | Π2.1–Π2.6 | A, C |
| F | **Mass Pay** και μία πληρωμή ή πίστωση σε πολλά τιμολόγια από το staff | Π2.7–Π2.9, Π3.1, Π3.2 | απόφαση 7 |
| G | **EU VAT / VIES** (γραμμή 4): fail-closed όταν το VIES δεν απαντά, `EL`↔`GR`, απόδειξη ελέγχου και επανέλεγχος, σημείωση reverse charge, ανοιχτά τιμολόγια όταν αλλάζει το καθεστώς | Π5, Π6 | αποφάσεις 9, 10 |
| H | **Gateways (γραμμή 6):** χρέωση τη μέρα λήξης, retries, idempotency/lock (PAY-3), μετρητής αποτυχιών, ειδοποίηση λήξης κάρτας | Π7 | C, απόφαση 5 |

Ο **importer** (γραμμή 5) πρέπει να ξέρει: Mass Pay γονικά/παιδιά (Π2.8), Price Override (απόφαση 11), recurring κουπονιών
(`recurfor`), κρυφές επιλογές που χρεώνονται (modules §3), `nextinvoicedate`, και να μην αντιγράψει το exempt του εσθονικού
ΑΦΜ χωρίς απόφαση.

## Για την άλλη session (modules)

- **Τα σημεία 1–4 της ενότητάς σας** επιβεβαιώθηκαν και μπαίνουν εδώ:
  - Ανοιχτά τιμολόγια στον τερματισμό (1): απόφαση 4, PR A. Το WHMCS σήμερα τα ακυρώνει, άρα το default μένει όπως είναι.
  - Μερική πληρωμή και unsuspend (2): PR A. Ο κανόνας γίνεται «επανενεργοποίηση μόνο όταν δεν μένει ληξιπρόθεσμο τιμολόγιο»,
    όπως το Blesta. Αν το unsuspend καλεί το module, η ενεργοποίηση έρχεται πιο σπάνια, όχι σε άλλο σημείο.
  - Κρυφές επιλογές που χρεώνονται (3): αφορούν την τιμή (`OrderPricing`, `Service::calculatePrice`) και τον importer. Τα
    παίρνουμε εμείς μαζί με το PR C ή τον importer, εκτός αν τα θέλετε στο Σ1 (πείτε μας).
  - Επιλογές σε μία γραμμή (4): PR C (περιγραφή «Επιλογή: Τιμή»). Η ρύθμιση `config_options.invoice_display` ακολουθεί, αν
    χρειαστεί.
  - Upgrade cPanel (5): δικό σας, στο «provisioning fixes» (συμφωνήθηκε).
- **Δ4 `completed`:** προτείνουμε να μη γίνει τώρα (απόφαση 12). Αν ο ιδιοκτήτης συμφωνήσει, το `refreshStatus` δεν χρειάζεται
  να το ξέρει.
- **Αποθηκευμένη μέθοδος ανά πελάτη** (PR C) και **χρέωση τη μέρα λήξης** (PR H): αν κάποιο module ή ενέργεια διαβάζει
  `services.billing_agreement_id`, θα το ενημερώσουμε πριν αλλάξει.
- **Cascade σε add-ons στην ακύρωση και χρέωση ακύρωσης** (Π4.12): λείπουν και στα δύο μέρη· όταν έρθουν τα add-ons, η
  ακύρωση του γονικού πρέπει να ακυρώνει και τα δικά τους (module + γραμμές).
- **Fraud υπηρεσίες του WHMCS** (2 στο myip): ο importer τις κάνει `cancelled` και το `fraud` ζει στην παραγγελία. Συμφωνούμε.

## Παράρτημα: τα ευρήματα ανά περιοχή

Όπως τα έγραψαν οι agents (στα αγγλικά), με file:line. Π1 δημιουργία τιμολογίων, Π2 merge/split/mass pay, Π3 πληρωμές,
Π4 ζωή της υπηρεσίας, Π5 φόροι, Π6 ΦΠΑ ΕΕ/κουπόνια/νομίσματα, Π7 gateways. Σοβαρότητα: high / medium / low.

### Π1. Invoice generation and grouping (Δ3)

MyCS has stronger safeguards than Blesta against billing the same period twice. Each service is billed in its own transaction with a row lock. The cursor comes from the period lines on invoices that are not cancelled, and a service is skipped while it has an open invoice or a payment that has not been applied yet. Blesta moves services.date_renews forward only after the invoice is committed, outside that transaction, and takes no locks. So a crash in between bills the period again.
Grouping, which Δ3 approved but nobody has built yet, is missing in MyCS. Blesta groups by client only and gives the invoice the latest renew date in the group as its due date. It converts every line into the client's default currency at today's rate. Δ3's key (account, recipient, due date) is better, but it also needs currency, and in MyCS the saved payment method or subscription too.
The most important gap: Blesta never rewinds date_renews when an invoice is voided, so the period is not billed again. MyCS still bills it again the next day, because livePeriodLines leaves out cancelled and refunded invoices and line-level cancellation deletes the lines. To build Δ3, MyCS needs a record that a period was given up, not just a grouping change. myip had 238 cancelled renewal invoices in the last 12 months.
New finding, not in the docs: 336 of nixpal's 350 live services are on WHMCS prorata products (pro rata day 1, charge next month after day 20). Their renewals fall on the 1st, which is why nixpal invoices carry up to 116 service lines. MyCS has no product pro rata, so new nixpal orders would drift to anniversary dates and grouping would stop working for them.
MyCS also has a single global lead time (cronjob_invoice). Blesta has one per client group. myip needs one per cycle and one for domains.

**Π1.1 What prevents double billing of a period (cursor model, atomicity, concurrency)** (high · διαφέρει, καλύτερο το MyCS · Δ3)
- Blesta: Cursor = services.date_renews. Services::getAllRenewing (app/models/services.php:541-618) selects active (and, if the group setting allows, suspended) services whose date_renews <= today + inv_days_before_renewal, non-onetime, not cancelled before renew. CreateInvoices::invoiceServices (core/Automation/Tasks/Task/CreateInvoices.php:305-341) calls Invoices::createFromServices, which commits the invoice in its own transaction (Invoices::add, invoices.php:162-263). It then advances date_renews/date_paid_through/date_last_renewed with separate Services::edit calls. There is no service row lock and no shared transaction, so a crash or error between the two leaves the invoice and the old date_renews, and the next run bills the period again. There is no 'already has an open invoice' check: the date cursor is the only guard.
- MyCS: CronJob::billRenewal (app/Console/Commands/CronJob.php:589-725): one DB::transaction per service with lockForUpdate (591). Skips a service that has any open or draft invoice or a cancellation request (603), or a paid line not yet applied (BIL-9, 607). The cursor is derived from live period lines (Service::nextPeriodStart/livePeriodLines, app/Models/Service.php:359, 595-606), so the invoice and its cursor are written atomically. The whole run is under Cache::lock plus withoutOverlapping (CronJob.php:71, routes/console.php:11).
- Μας αφορά: Yes. myip ~2.2k and nixpal ~900 renewal invoices a year. A duplicate period is a money bug.
- Πρόταση: Keep this model. When grouping (Δ3) is built, keep one transaction per invoice group. Lock the client row first, then the group's services in id order (the order payment paths use: customer, then invoice, then service), and re-check each service's guards under the lock. A service that fails a guard (no plan, open invoice) leaves the group instead of failing it.

**Π1.2 Voided/cancelled renewal invoice: is the period billed again?** (high · διαφέρει, καλύτερο το Blesta · Δ3)
- Blesta: Voiding goes through Invoices::edit with status void (invoices.php:483 onwards; no date logic around 600-700) and never touches services.date_renews. The period stays 'invoiced' and is not billed again. The service then falls to the suspend/cancel rules. Services::voidInvoices (services.php:3639-3720) voids open invoices of a cancelled service. It is gated by void_invoice_canceled_service and a days-past-due limit, and skips invoices that have payments.
- MyCS: livePeriodLines leaves out invoices in status cancelled AND refunded (Service.php:600-601), so the period becomes a hole and billRenewal bills it again on the next run. Line-level cancellation (CancelOpenInvoices with onlyFor, app/Services/Invoice/CancelOpenInvoices.php:95-108) deletes the service's lines, which also erases the period. Δ3 (docs/decisions.md:206-218) decided 'cancelled = not billed again' but it is not implemented (ROADMAP row 3).
- Μας αφορά: Yes, strongly. myip had 238 cancelled renewal invoices in the last 12 months (rigel, aggregate). Today each would come back the next day with a new reminder. nixpal had 10.
- Πρόταση: Build Δ3 as a cursor decision, not as a grouping change. Either (a) keep cancelled renewal lines and count them as 'billed, not to re-bill' in nextPeriodStart (but not in paidThrough), or (b) add an explicit next_invoice_at cursor next to expires_at (WHMCS nextinvoicedate). Line-level cancel must mark the line cancelled (or write a tombstone) instead of deleting it. 'Restore invoice' (cancelled → unpaid) puts the period back. Decide refunded separately: Blesta and WHMCS do not bill a refunded period again either. Write failing tests first: cancel → cron → no new invoice; restore → payment renews; refunded period stays as decided.

**Π1.3 Grouping of services into one renewal invoice** (high · λείπει στο MyCS · Δ3)
- Blesta: Per client group run: services are bucketed by client_id only (CreateInvoices.php:130-137). With inv_group_services on (the default), one invoice per client per pass. Its due date is the LATEST date_renews in the bucket (194-200), so an earlier-renewing service gets extra grace. With grouping off, one invoice per service, but addons stay on their parent's invoice and use the parent's renew date (158-192). The setting is per client group only; the cron does not read a per-client override (100-104).
- MyCS: One invoice per service (CronJob.php:673-688). No grouping and no per-client or per-group switch.
- Μας αφορά: Yes. nixpal (12 months): 409 renewal invoices with 1 service, about 400 with 2-24, and 14 with 33-116 service lines. myip: 1885 single-service, about 170 multi-service. separateinvoices: nixpal 0 clients, myip 3.
- Πρόταση: Build Δ3's key (client account, recipient, due date) and add currency to it. Use an exact due date, as WHMCS does, not Blesta's max date. Also leave out of the group, or give their own key to, services with a gateway subscription (subscription_id: payInvoiceWithCredits only pays them in full, CronJob.php:727-736) and services with a different billing_agreement_id (CronJob.php:703-722 charges per invoice). Keep 'separate invoices' as a per-client flag (myip's 3 clients). Keep the addon/domain-with-parent rule in mind for Phase 2 domains.

**Π1.4 Pro rata to a common date (package pro rata / align renewals)** (high · λείπει στο MyCS · Δ3)
- Blesta: A package can have a pro rata day. Invoices::getLinesForServices (invoices.php:2280-2366) uses Packages::getProrataDates to prorate the first invoice to that day and moves date_renews to it (2464-2467). Addons can sync to the parent's renew date (Services::canSyncToParent, 2291-2314). Services::getNextRenewDate (services.php:4671-4720) honours the pro rata day for month/year periods.
- MyCS: Missing at order time. MyCS only prorates holes and cycle changes (Service::priceForPeriod, Service.php:382-387; billRenewal 666-670). PlaceOrderService/OrderPricing have no product pro rata day; a new service's period starts on the order date.
- Μας αφορά: Yes for nixpal (new finding, not in settings-map/whmcs-usage). 6 products have proratabilling=1. Live services on them: 20+254+61 on pro rata day 1, 'charge next month' after the 20th, plus 2 on day 5. That is 336 of nixpal's 350 live services. This alignment is what produces nixpal's large grouped invoices on the 1st. myip: 0 live services on prorata products.
- Πρόταση: Add the product-level fields (prorata_enabled, prorata_day, charge_next_month_after_day) to the catalog and to the importer, and use them in OrderPricing and the first period at checkout. The prorated first line gets period_start/period_end, so the cursor then lands on the day. Without this, Δ3 grouping slowly stops working for nixpal as new orders get anniversary dates. Add it to settings-map.md as N (nixpal).

**Π1.5 Invoice days before renewal (lead time)** (medium · διαφέρει, ισοδύναμο · Δ3)
- Blesta: inv_days_before_renewal per client group (with a company default), read in Services::getAllRenewing (services.php:546-565) up to 23:59:59 company time of today+N (595-600). The same setting drives recurring invoices (Invoices::getAllRenewingRecurring, invoices.php:3428-3446).
- MyCS: One global setting: config settings.cronjob_invoice, default 7 (CronJob.php:144; app/Classes/Settings.php:516). It is compared against now()+N, not end of day.
- Μας αφορά: Yes. nixpal 7 days (one value). myip 14 overall, per cycle per settings-map (month 10, quarter 15, semi/annual 20) and domains 15 (CreateDomainInvoiceDaysBefore=15).
- Πρόταση: Make the lead time per billing cycle, with a default plus overrides, and a separate value for domains when they arrive (settings-map already marks this N). Compare against the end of day today+N in the instance timezone so the cron hour does not shift which services are picked up. No need for Blesta's per-client-group scope.

**Π1.6 Currency of the renewal invoice** (low · διαφέρει, καλύτερο το MyCS · Δ3)
- Blesta: The invoice is created in the client's default_currency (CreateInvoices.php:149-155). Each line is converted from the package or override currency at the current exchange rate (getLinesForServices, invoices.php ~2420-2445). The renewal amount can change with exchange rates.
- MyCS: The invoice takes the service's own currency_code (CronJob.php:678). No conversion.
- Μας αφορά: Little today. nixpal has 2 currencies, 1 client in the second one, and all 350 live services in currency 1. myip has 2 currencies.
- Πρόταση: Keep it. Add currency to the Δ3 grouping key so two services in different currencies never share an invoice.

**Π1.7 Catch-up of several missed periods** (low · διαφέρει, καλύτερο το MyCS · Δ3)
- Blesta: The cron loops (CreateInvoices.php:109-215), fetching renewing services again after each pass. A service several periods behind gets one invoice per missed period in a single run, because date_renews moves forward each time.
- MyCS: One period per run per service. An open invoice blocks the next one (CronJob.php:603). A suspended service is not billed again except to fill a hole before a paid period (614-617, decision A2).
- Μας αφορά: Yes. Both WHMCS installs run with ContinuousInvoiceGeneration off, so one unpaid invoice per service, as MyCS does.
- Πρόταση: Keep it. When grouping, a service with an open invoice simply drops out of the group, as Blesta's failed_services list does (CreateInvoices.php:116, 320).

**Π1.8 Due date of a renewal invoice billed late** (low · διαφέρει, ισοδύναμο · Δ3)
- Blesta: createFromServices sets date_due to now when the renew date is already past (invoices.php ~1858-1861).
- MyCS: due_at = periodStart even when it is in the past (CronJob.php:677). Suspension is driven by expires_at (suspendable, CronJob.php:424-437), so this only affects how the invoice looks and how reminders are timed.
- Μας αφορά: Unknown. It only happens after downtime or with a backdated expiry. WHMCS keeps the past due date, as MyCS does.
- Πρόταση: Keep WHMCS parity. When grouping, make sure the key uses the period start, not a clamped date, or a late run would merge invoices for different dates.

**Π1.9 Renewal price source** (low · διαφέρει, ισοδύναμο · other)
- Blesta: Renewals price from the package's price_renews or a per-service override_price (createFromServices sets override_price at first invoice, invoices.php ~1822-1900, with options renewal and recur in getLinesForServices). Coupons with limit_recurring are counted again on each renewal.
- MyCS: Uses the stored services.price (WHMCS recurring amount). A scheduled cycle change switches plan and price at the next renewal (CronJob.php:623-635). An N-cycle coupon reverts to full price once (636-651).
- Μας αφορά: Yes. Importer parity relies on stored per-service amounts (tblhosting.amount).
- Πρόταση: Keep the stored price (WHMCS parity). No action.

**Π1.10 Group similar line items** (medium · λείπει στο MyCS · Δ3)
- Blesta: No equivalent. inv_* settings in admin_company_billing/admin_company_groups have no line-grouping option; each service and option becomes its own line.
- MyCS: Missing (settings-map.md:20 marks it N for nixpal).
- Μας αφορά: nixpal GroupSimilarLineItems=on, on invoices with up to 116 service lines. myip off.
- Πρόταση: Implement as a display-only setting (PDF/HTML merges identical description+price into qty N). Keep the stored lines one per service and period, because the billing cursor and per-line cancellation depend on them.

**Π1.11 Suspended services and cancellation dates** (low · ίδιο · other)
- Blesta: Suspended services are billed only if the group setting inv_suspended_services=true (services.php:546-589). Services with date_canceled after date_renews keep being billed until the cancel date (611-614).
- MyCS: Suspended: not billed again except to fill a hole before a paid period (CronJob.php:614-617). Any cancellation request blocks billing (603).
- Μας αφορά: Matches both WHMCS installs (A2 decision, AutoCancellationRequests on).
- Πρόταση: No change. If an 'end of period' cancellation with a future date is ever added, bill up to that date as Blesta does.

Ανοιχτά ερωτήματα:

- Δ3 cursor design: should cancelled renewal lines stay as 'billed, not to re-bill' in nextPeriodStart, or should MyCS add an explicit next_invoice_at cursor (WHMCS nextinvoicedate)? In nixpal and myip live services, nextinvoicedate always equals nextduedate (0 of 350 and 0 of 715 differ), so the importer can seed either.
- Refunded invoices: livePeriodLines treats them as a hole to bill again. Blesta and WHMCS do not rewind the cursor on refund. Which is the intended MyCS rule (Α6 is still open)?
- nixpal pro rata: confirm with the owner that new orders should keep aligning to the 1st (charge next month after the 20th), and whether the 2 services on day-5 prorata products are legacy.
- Grouping and saved payment methods: should a grouped invoice that covers services with different billing agreements or gateway subscriptions be split, or should those services always get separate invoices?
- Blesta Date::modify month arithmetic for renewals (31st + 1 month) was not checked. MyCS's billing_day handling is documented in billing-cursor.md §5.

### Π2. Merge, split, pay many invoices, void

MyCS has no merge, split or mass pay yet (ROADMAP line 189). What it does have is safer than Blesta. MyCS has a per-customer lock (RecordPaymentService::lockInvoice), records each period per line (applied_at), refuses to cancel an invoice that still holds money, adds a cancellation credit note, and has append-only money_events with group_reference and source_transaction_id. Blesta's merge and split run with no DB transaction and no locks. They copy lines and then void the sources one by one, so a payment that arrives in between can leave the same lines billed twice. Merge also collapses service_invoices by service_id and re-taxes lines with the current rules. Blesta's multi-invoice payment is better than WHMCS's container invoice: one transaction is spread over N invoices (transaction_applied) under ordered FOR UPDATE locks. It is all-or-nothing, though: if one invoice closed in the meantime, the whole payment becomes credit. The WHMCS data shows a gap for MyCS at cut-over. Mass-pay parents (nixpal 157, myip 80 all-time) are imported as ordinary invoices that hold the transactions. Their children (389 at nixpal) are imported as paid with no payment, so MoneyCheck paid_balance will flag them, and the bridge could double-count. At nixpal every parent also used a proforma number. MyCS still lets staff edit or delete lines on an unpaid, partly paid invoice in the Filament repeater without the payment locks. Blesta refuses that. Recommendation: merge and split move lines (never copy them) inside one transaction under the customer→invoices locks, refused when there are payments. Mass pay is a pinned payment group allocated per invoice, not a numbered container invoice.

**Π2.1 Merge: atomicity and concurrency** (high · λείπει στο MyCS · merge/split/masspay)
- Blesta: Invoices::merge (app/models/invoices.php:1321-1468) validates (getMergeRules 1475-1610: >=2 ids, one currency, one client, all status active/proforma with paid==0, all same status) and then, with no Record->begin and no row locks, either add()s a new invoice from copies of all lines or edit()s the target with the copied lines appended. It then deletes and re-inserts service_invoices and finally voids each source with edit(status=void). The void goes through edit(), which refuses a status change when any amount is applied (invoices.php:566-581). So a payment applied to a source between validation and void makes that void fail silently: merge still returns the target id, the source stays open, and its lines are now also on the target (double billing). The admin controller (admin_clients.php:977-1007) only flashes Invoices->errors().
- MyCS: missing (ROADMAP.md:189). The building blocks exist: RecordPaymentService::lockInvoice (RecordPaymentService.php:573-590) locks customer then invoice and serializes every payment path of one customer, and InvoiceItemObserver (InvoiceItemObserver.php:20-75) refuses line moves on paid/refunded invoices.
- Μας αφορά: myip: 61 merges in 12 months (tblactivitylog 'Merged…'; 314 all-time), and WHMCS deletes the sources. nixpal: 0 in 12 months.
- Πρόταση: Write MergeInvoices as one DB::transaction. Client::lock, then lock all invoices (target + sources) ordered by id with FOR UPDATE, then re-check everything under the lock: same client, same currency, same recipient/account (Δ3 key), status unpaid or draft, hasPayments(lock: true) false (processing payments count too), no adjustment notes other than none. Then UPDATE invoice_items SET invoice_id=target (move rows, never copy) and cancel the sources in the same transaction. Any failure rolls back everything. Add a failing test in which a payment lands on a source between the form and the save.

**Π2.2 Merge: what happens to queued service actions (service_invoices vs per-line apply)** (medium · διαφέρει, καλύτερο το MyCS · merge/split/masspay)
- Blesta: merge keys the old service_invoices rows by service_id only (invoices.php:1441-1459) and re-inserts one row per service. The table is UNIQUE(service_id, invoice_id, type) and type is enum provisioning/renewal/suspension/unsuspension/cancelation/change (csnixpal_db schema). Two sources carrying the same service (two overdue renewal periods of a domain), or a renewal plus a change for the same service, therefore collapse to one row: the module renew/change runs once even though two periods/changes were billed. Renewals only fire through a join on invoice_lines.service_id plus service_invoices.invoice_id with the invoice closed (services.php:703-748), so the line and the row must both sit on the same invoice.
- MyCS: No service_invoices table. Each invoice line is applied on its own (InvoiceItem.applied_at; the ProcessPaidInvoiceService / apply_pending_since flow; CancelOpenInvoices.php:120 checks applied_at). A moved line carries its renewal with it.
- Μας αφορά: Two unpaid renewal invoices for the same domain is exactly the case staff merge at myip.
- Πρόταση: Keep the design and move lines, so each line keeps its reference and its own apply. Do not dedupe by service. Test: merging two renewal invoices of the same service renews it twice (two periods) when the target is paid.

**Π2.3 Merge/split and taxes** (medium · διαφέρει, καλύτερο το MyCS · EU VAT)
- Blesta: merge and split rebuild each line with a tax true/false flag and pass it to add()/edit(). edit() then re-applies the client's current tax rules (getTaxRules + addLineTax, invoices.php:704-731; taxUpdateRequired), and add() does the same. A merged or split line can therefore get a different rate or tax set than when it was issued (client moved country, VAT exempt toggled).
- MyCS: Δ1: the rate is fixed on the line when it is created (InvoiceItemObserver::creating). On update it is recomputed only when 'taxed' changes, so a change to invoice_id alone keeps tax_rate, tax_amount and line_total (InvoiceItemObserver.php:47-60).
- Μας αφορά: nixpal: 27 EU rates and reverse charge (decisions.md Δ1). A re-tax on merge would change amounts the customer already saw.
- Πρόταση: Move rows and never re-create lines, so the tax issued with each line stays. Refuse a merge across different tax regimes or recipients (reverse charge vs not, Δ3 recipient) instead of re-taxing. Add a test that tax_rate and line totals are unchanged after a merge when the client's country changed in between.

**Π2.4 Merge: due date, numbering and the trace from source to target** (medium · λείπει στο MyCS · merge/split/masspay)
- Blesta: Merging into a new invoice sets date_billed and date_due to now (invoices.php:1393-1402) and consumes a new id_value. The sources keep their numbers as void. Nothing links a source to the target except the 'Updated Invoice' logger entries from edit (invoices.php:788-789). No note and no column.
- MyCS: missing. Numbering today is the Settings counter (Setting::COUNTERS); Δ2 (sequences, number=id at myip, proforma numbered on paid at nixpal) is approved but not built.
- Μας αφορά: myip (number = id): WHMCS merges into an existing invoice and deletes the sources. nixpal proforma: an open invoice has no number, so merge and split cost nothing there.
- Πρόταση: Always merge into an existing target, chosen by staff and defaulting to the oldest. Keep the target's id/number and set due_at = the earliest due_at of the target and sources, so a merge cannot delay suspension. Mark the sources cancelled with cancellation_reason 'merged' and a merged_into_id column, plus an audit row. Do not delete them, unlike WHMCS. Check that the cancellation credit note that InvoiceObserver::updated creates for a merged source is never sent to Ekdosi and is labelled 'merged into #X'. Under Δ2 at nixpal no number is spent.

**Π2.5 Merge sources and Δ3 cancellation semantics** (medium · διαφέρει, ισοδύναμο · Δ3)
- Blesta: Blesta voids the sources. A voided renewal invoice does not undo a service's date_renews; the service's renewal row was moved to the target.
- MyCS: Δ3 (approved, not built): cancelling a renewal invoice means that period is not billed, and the cron will not issue it again.
- Μας αφορά: Applies to every merge at myip once Δ3 lands.
- Πρόταση: When Δ3 is built, base 'period not billed' on cancelled lines that still reference the service, not on cancelled invoices. A merged source has no lines left (they moved), so it must not mark the period as unbilled. Add a regression test: merge two renewal invoices, pay the target, and the service is not suspended.

**Π2.6 Split: atomicity and payment guard** (low · λείπει στο MyCS · merge/split/masspay)
- Blesta: Invoices::split (invoices.php:1616-1748) needs at least 2 lines. It edit()s the original without the selected lines, then add()s a new invoice with them, then re-points service_invoices only for services whose lines all moved. Edit refuses line changes when any amount is applied, so a partly paid invoice cannot be split. There is no DB transaction: if add() fails after the edit, the moved lines are gone from the original and on no invoice. When a submitted line has no id, it is matched by description/qty/amount (1647-1663), which is fragile when two lines are identical.
- MyCS: missing
- Μας αφορά: myip: 6 splits in 12 months (rare). nixpal: 0.
- Πρόταση: Build SplitInvoice in the same shape as merge. One transaction, locks taken in the same way. Selected line ids only (never matched by content). Refuse when there are payments (processing too), adjustment notes, or applied lines, and when all lines would move. Create the new invoice with the same client, currency and recipient snapshot and the same due_at, then move the rows. Store split_from_id on the new invoice and audit it. At myip the new invoice gets number = id; at nixpal it stays a proforma with no number.

**Π2.7 Mass Pay / paying several invoices together (client area)** (high · λείπει στο MyCS · merge/split/masspay)
- Blesta: No container invoice. The client picks several open invoices of one currency in client_pay.php:261-310 and can edit the amount per invoice (partial allowed) and add credit. The session keeps {currency, amounts[], credit}. One gateway transaction is then applied to N invoices: GatewayPayments::processPayment (gateway_payments.php:913-984) or processNotification (689-743), then Transactions::apply (transactions.php:1074-1126). writeApplyAmounts (1191-1258) takes the transaction FOR UPDATE, then the invoices FOR UPDATE ordered by id, re-validates (validateApplyAmounts 1790-1823: each invoice active/proforma and not overpaid, sum <= transaction left) and retries on deadlock 1213. Replayed notifications only apply what is still outstanding (getUnappliedAmounts 2475+). Whatever is not applied stays on the transaction as credit.
- MyCS: missing. The gateway contract pays one invoice for its remaining amount (ExtensionHelper::pay, ExtensionHelper.php:452-455). Related pieces already exist: allocateFromTransaction (RecordPaymentService.php:252-290; credit a sale left, paying other invoices, with source_transaction_id, idempotent), money_events.group_reference ('transaction:{id}', RecordPaymentService.php:636-640; MoneyTrail.php:97-110), and CreditAutoApply after an overpayment.
- Μας αφορά: WHMCS parents in 12 months: nixpal 32 (all paid), myip 22 (21 paid, 1 cancelled). All-time: nixpal 157 (150 paid, 7 cancelled), myip 80 (68 paid, 12 cancelled). 2.5-2.6 children per parent on average, max 7-8. Parents never have tax or mixed lines.
- Πρόταση: Do it Blesta-style, without a numbered container invoice. Add a payment_groups row (client, currency, invoice ids with pinned amounts = each invoice's remaining under lock, total, expiry, status). This is the Ekdosi-style payment intent. The gateway charges the group total. On success, under Client::lock: record the gateway transaction on the oldest invoice of the group through RecordPaymentService::handle (the excess becomes credited_amount), then call allocateFromTransaction for the other invoices in the same DB transaction, all with group_reference 'group:{id}'. Full remaining amounts only (WHMCS pays in full; no per-invoice partials). Things to change: (1) allocateFromTransaction's 'one cycle per service per sale' guard would block two renewal invoices of the same service, so add a group mode that allows it. (2) The CreditAutoApply afterCommit hook must run only after the group allocation, so the excess does not pay an invoice outside the group first. (3) An invoice that got paid or cancelled in the meantime only sends its own share to credit. It must not fail the whole set as Blesta does (validateApplyAmounts returns false for everything). Add money-flow tests for replay, a cancelled member, a currency mismatch, and two webhooks at once.

**Π2.8 WHMCS Mass Pay data at import (parent + children)** (high · λείπει στο MyCS · merge/split/masspay)
- Blesta: n/a (Blesta has no parent invoice). For comparison: WHMCS writes the money on the parent (nixpal: 149 parents have tblaccounts rows, only 42 children do). The children are marked Paid without their own transaction. At nixpal (proforma) every paid parent got an invoicenum (150/150) and so did every child (389/389): the parents spent numbers in the {YEAR}-{NUMBER} sequence.
- MyCS: ImportFromWhmcs.php:1046-1076 imports parent lines of type 'Invoice' as invoice_items with reference_type null and reference_id = child invoice id. importPayments (1078-1104) puts the transactions on the parent. The children arrive paid with no transaction, so MoneyCheck::paidBalance (MoneyCheck.php:120-130) will report each one as 'still to pay'. CancelOpenInvoices treats such lines as unknown imported lines (CancelOpenInvoices.php:84-90). The parent looks like a normal paid invoice, and the bridge or revenue reports could count it on top of its children.
- Μας αφορά: nixpal: 157 parents / 389+ child links. myip: 80 parents / 161+ paid children. Every one would be a false money-check alert or a double-counted sale.
- Πρόταση: In the importer, recognise type='Invoice' lines. Import the parent as a payment group (or as an invoice flagged mass_pay that is excluded from revenue, the bridge and numbering), and turn each parent transaction into allocations to the children (source_transaction_id = the parent transaction, source=import, group_reference 'whmcs-masspay:{parentid}'), so each child's remaining is 0. Cover it in importer parity: the sum of child allocations equals the parent amountin, and money-check is clean after import. Keep the WHMCS numbers already issued as they are. In MyCS nothing new is numbered as a container.

**Π2.9 Staff applying one payment to several invoices (admin)** (medium · διαφέρει, καλύτερο το Blesta · merge/split/masspay)
- Blesta: admin_clients.php recordPayment (5478+; apply at 5677) lets staff record one transaction and spread it across several open invoices through the same Transactions::apply locks.
- MyCS: recordByStaff (RecordPaymentService.php:301+) works on one invoice. The excess becomes credit and CreditAutoApply spreads it (oldest first), which staff do not choose.
- Μας αφορά: A bank transfer covering several invoices is common for myip business customers (unknown count; IRIS/transfer reconciliation later).
- Πρόταση: Reuse the payment-group service for staff: 'Add payment to several invoices' with explicit amounts and the same allocation path and group_reference. Partial amounts are allowed for staff only.

**Π2.10 Voiding / cancelling an invoice that holds money** (low · διαφέρει, καλύτερο το MyCS · Δ3)
- Blesta: Void is just edit(status=void). It is refused if any amount is applied (invoices.php:566-581), so staff unapply first. Unapplied money silently becomes client credit (transaction amount minus applied). When admin cancels queued service changes (admin_clients.php:8755-8775) it unapplies all payments, then voids, with no notice to anyone. There is no credit note. A voided invoice keeps its number.
- MyCS: InvoiceObserver::updating refuses to cancel a paid invoice or one with received money ('refund them … first'). CancelOpenInvoices (CancelOpenInvoices.php:61-140) gives credit-paid amounts back via CreditAutoApply::giveBack, leaves gateway or transfer money and delivered periods for staff with a notice, and writes a cancellation AdjustmentNote plus an audit row. Reopen voids the cancellation note.
- Μας αφορά: myip: 258 cancelled in 12 months (241 with renewal), nixpal 10 (decisions.md Δ1 table).
- Πρόταση: Keep this. For merge, note that the cancellation note on a merged source must say 'merged', and that sources with payments are refused rather than unapplied as Blesta does.

**Π2.11 Removing or repricing a line on an open invoice that has a partial payment (admin)** (medium · διαφέρει, καλύτερο το Blesta · other)
- Blesta: edit() refuses any line change (add, remove, qty, amount, taxable) when an amount is applied (lineItemsChanged + validateAmountApplied, invoices.php:566-581, 811-851). deleteLine (1261-1268) is private and only reachable through edit. A deleted line also drops its renewal implicitly, because renewal needs the line on the closed invoice (services.php:723-735).
- MyCS: The app path ChangeOpenLine (ChangeOpenLine.php:22-38) refuses when the invoice hasPayments and runs under lockInvoice. The admin path does not: the Filament Repeater 'items' (InvoiceResource.php:111-160) saves through the relationship. EditInvoice (EditInvoice.php:25, 91-122) wraps it in a DB transaction but never takes lockInvoice, and InvoiceItemObserver refuses only on paid/refunded. Staff can delete or lower lines on an unpaid, partly paid invoice. It then sits unpaid with a negative remaining until 'Close as paid'. A gateway payment computing excessCents under the invoice lock at the same time does not see the line change serialized.
- Μας αφορά: myip staff 'change invoice subtotal' 141/12m and 'delete invoice' 54/12m (whmcs-usage.md). Line edits are frequent.
- Πρόταση: Wrap admin line saves in lockInvoice (lock the customer and invoice before the repeater writes). When the invoice has payments (processing too), either refuse line money changes as Blesta does, or allow them with a confirmation and call RecordPaymentService::settle in the same transaction so any excess becomes credit at once. Add a failing test: a line is deleted while a webhook pays, and the end state is consistent (no negative remaining left on an unpaid invoice).

**Π2.12 Removing one service's line from a shared invoice (cancellation)** (low · μόνο στο MyCS · Δ3)
- Blesta: No dedicated path. Staff edit the invoice (blocked if anything is paid), or the service-change cancellation voids the whole invoice after unapplying (admin_clients.php:8755-8775).
- MyCS: CancelOpenInvoices with $onlyFor (CancelOpenInvoices.php:93-140): deletes only that service's and its upgrades' lines under the locks. Credit already applied keeps paying the rest and only the surplus goes back. It refuses when loose lines (setup or promo) make ownership ambiguous, or when the rest would come to nothing.
- Μας αφορά: Needed once Δ3 groups several services per invoice (nixpal 'Group Similar Line Items').
- Πρόταση: Keep this. Merged invoices will hit it more often, so test cancelling one service on a merged invoice that was partly paid with credit.

**Π2.13 Audit / log of merge, split, void** (low · διαφέρει, καλύτερο το MyCS · merge/split/masspay)
- Blesta: Only generic 'Updated Invoice' logger entries from edit (invoices.php:788-789) and the Invoices.editAfter events. Nothing records which invoices were merged into which.
- MyCS: owen-it Auditable on Invoice and InvoiceItem (Invoice.php:48-50; InvoiceItem.php:21-23). AuditAction shows children (items, transactions) in EditInvoice. Money moves go to the append-only money_events.
- Μας αφορά: Support needs to answer 'where did invoice X go': myip deletes merge sources in WHMCS today.
- Πρόταση: Add merged_into_id and split_from_id (nullable FKs) and show them on both invoices. The item audit will show the invoice_id change of each moved line. No money_event is needed when no money moves (merge and split refuse invoices with payments).

Ανοιχτά ερωτήματα:

- WHMCS merge with payments: does WHMCS move transactions from deleted sources to the target, or refuse? This decides whether MyCS should refuse (proposed, as Blesta does) or move payments with reversal plus new money_events.
- Mass pay contract with gateways: should ExtensionHelper::pay get an amount + group form (Stripe/PayPal/Cardlink metadata), or should a payment group be shown to gateways as a hidden, unnumbered, non-revenue invoice? The second needs fewer gateway changes but repeats WHMCS's container problems.
- Should the client area allow partial amounts per invoice in mass pay (Blesta) or only full remaining (WHMCS)? The proposal is full only.
- Ekdosi bridge: for an imported WHMCS mass-pay, which of parent and children were sent to Ekdosi as sales (whmcs_invoice_id)? Check before the importer decides how to map them.
- Merge across accounts/recipients after 3b: refuse always, or allow staff to pick the target's recipient?

### Π3. Payments, apply, credits, refunds

MyCS's single-invoice payment core is stronger than Blesta's. RecordPaymentService takes customer → invoice → credit locks and computes balances with locking reads. It applies a payment up to what is left and books the excess as credit at once (credited_amount). It handles partial refunds without reopening the invoice, deals with gateway refunds and chargebacks, keeps a money_events journal, and renews on paid (as WHMCS does), with an apply_pending_since retry. Blesta 6.0.3 differs in four ways:
- It rejects the whole apply when any invoice would be overpaid.
- Its admin partial refund reopens the invoice and overwrites the transaction amount.
- A partial refund from a gateway notification becomes a negative transaction that changes nothing.
- Rolling back a failed credit apply deletes whole applied rows.
MyCS is behind in three places. (1) There is no N:M split of one payment over several invoices (Blesta's transaction_applied, WHMCS Mass Pay), and both companies use Mass Pay heavily. (2) Staff have no way to apply credit to an invoice. That hurts most at myip, where credit is applied by hand (NoAutoApplyCredit on) and 2,626 invoices were paid with credit. (3) There is no deadlock retry, while Blesta retries the apply up to Blesta.transaction_deadlock_reattempts times. Smaller points: a fixed 2-decimal model where Blesta uses per-currency precision; no gateway-driven succeeded→declined/void path; and with proforma numbering on, the number is assigned on draft→unpaid instead of on payment.

**Π3.1 Spreading one payment over several invoices (N:M allocation / Mass Pay)** (high · λείπει στο MyCS · merge/split/masspay)
- Blesta: A transaction is applied to many invoices through transaction_applied rows (ON DUPLICATE amount+). Entry points: apply() in app/models/transactions.php:1074-1139, writeApplyAmounts 1191-1276, the gateway apply in components/gateway_payments/gateway_payments.php:983 (processPayment) and 729 (notifications), and admin recordPayment with a per-invoice applyamount (app/controllers/admin_clients.php:5478, 5671-5677). Whatever is not applied stays on the transaction as credit (getCredits 885-942).
- MyCS: A payment belongs to one invoice (invoice_transactions.invoice_id). The excess becomes credit (RecordPaymentService.php:146, 186), and CreditAutoApply::forCustomer (CreditAutoApply.php:72-91) may then pay other invoices oldest-due first, but only when credits_auto_use is on and the invoice is eligible (144-187). The only explicit allocation is the PayPal subscription allocateFromTransaction (RecordPaymentService.php:252-285, source_transaction_id). Mass Pay is open in ROADMAP.md:189.
- Μας αφορά: High. EnableMassPay is on at both companies. Per whmcs-usage.md: 94 combined invoices and 47 Mass Pay lines in 12 months at myip, 157 combined invoices and 80 Mass Pay lines at nixpal. Today, a bank transfer or one PayPal payment for several invoices can only be recorded on one invoice. At myip (auto-apply off) the rest stays as credit and the other invoices remain unpaid.
- Πρόταση: Build Mass Pay as an explicit allocation, not through auto-apply. Either make a mass-pay invoice whose lines point at the target invoices, or offer a staff/client 'pay these invoices' flow. Record the payment once, then call allocateFromTransaction-style allocations for each target with source_transaction_id. Do this under the same locks, independent of credits_auto_use and of the CreditAutoApply exclusions; the targets are chosen explicitly. Reuse the idempotent, resumable budget logic (credited_amount minus allocations). Tests: allocation in one currency, a target already paid in the meantime (the leftover stays credit), a replayed webhook, a refund of the source payment after allocation.

**Π3.2 Staff applying account credit to an invoice** (high · λείπει στο MyCS · other)
- Blesta: Admin recordPayment with payment_type=credit calls applyFromCredits(client, currency, amounts) for the invoices staff choose (app/controllers/admin_clients.php:5619-5637). The client can apply credit during checkout too (client_pay.php:1370).
- MyCS: Only the client can do it (app/Livewire/Invoices/Show.php:120-128, 176-188 → payWithCredit). Otherwise it happens automatically (CreditAutoApply, CronJob.php:737, ChangeCycle.php:233, CancelOpenInvoices.php:138). No admin action or API endpoint calls payWithCredit (the actions in app/Admin/Actions/ are MarkInvoicePaid, CloseInvoiceAsPaid and DeleteInvoiceTransaction only).
- Μας αφορά: High for myip: NoAutoApplyCredit is on (settings-map.md:122 → credits_auto_use off), there are 4,714 tblcredit rows, 2,626 invoices were paid with credit, and 22 clients hold a balance. With auto-apply off, staff have no way in MyCS to use a customer's credit on an invoice. nixpal (auto-apply on, 2,594 invoices paid with credit) mostly needs it for excluded invoices (subscription renewals, invoices with a cancellation request).
- Πρόταση: Add an admin action 'Apply credit' on an unpaid invoice (and an API endpoint) calling RecordPaymentService::payWithCredit($invoice, upToCents: staff amount). No eligibility closure: staff decide. Use the same permission as Add Payment, notify the customer, and write a money_events entry with source staff. Add a test with credits_auto_use off.

**Π3.3 Overpayment and payment to an invoice that cannot take it** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: validateApplyAmounts (transactions.php:1790-1836) rejects the whole apply set if any invoice would end up with paid > total, or is not active/proforma (void or draft). writeApplyAmounts re-validates under lock (1227). The gateway path trims repeated amounts first (getUnappliedAmounts, gateway_payments.php:2475-2530), but a genuine overpayment fails the apply. All the money then stays unapplied credit, the invoice stays open, and only the ApplyPayments cron (if auto_apply_credits is on) applies credit later (core/Automation/Tasks/Task/ApplyPayments.php).
- MyCS: excessCents (RecordPaymentService.php:611-618) applies up to what is left under locking reads. Only the excess becomes credit (credited_amount, CreditLedger, cause overpayment), staff get a notice (653-664), and auto-apply runs after commit (190-200). Cancelled, paid or refunded invoices: everything becomes credit and the status does not change.
- Μας αφορά: Yes. nixpal: about 485 invoices where payments exceed the total, and 262 invoices with more than one payment. myip: 140 and 181. These counts come from a rough SUM(amountin-amountout) > total query and may include gateway quirks; not verified row by row.
- Πρόταση: Keep this design. When building Mass Pay, do not copy Blesta's all-or-nothing apply: trim each target to what it has left, as excessCents already does.

**Π3.4 What flips an invoice to paid and back** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: Invoices::setClosed (app/models/invoices.php:2483-2570) runs after every apply and unApply. It recalculates and stores subtotal/total/paid rounded to the currency precision (updateTotals). If paid >= total and the status is active or proforma, it sets date_closed (a proforma also becomes active and gets its number); otherwise it clears date_closed. Closing also nulls services.date_paid_through (2526). Re-opening never touches services. It runs outside the apply lock.
- MyCS: InvoiceTransactionCreatedListener.php:18-58 turns unpaid/draft into paid only for a succeeded transaction, under customer → invoice locks with withLockedBalance (Invoice.php:317-324), when remaining == 0. When remaining < 0 it goes through settle (RecordPaymentService.php:453-517), which gives the excess as credit once. The InvoiceObserver guards (InvoiceObserver.php:101-118) refuse paid with something left to pay, paid over the total outside settle, and cancelled → paid. Paid → unpaid happens on transaction deletion (RecordPaymentService.php:432-438) and on a chargeback.
- Μας αφορά: Yes. It is the core of every payment.
- Πρόταση: Keep it. Optional: a money-check rule for a paid invoice whose remaining is > 0 after an import, if it does not exist yet (paid_balance appears to cover it).

**Π3.5 Locks, concurrency and deadlock retry** (medium · διαφέρει, ισοδύναμο · other)
- Blesta: writeApplyAmounts (transactions.php:1191-1276) locks the transaction row with FOR UPDATE and reads its applied total (1206), then locks the target invoices in id order (1220), re-validates (1227), and only joins an outer DB transaction if there is one. applyAmounts (1141-1189) retries on MySQL deadlock 1213/40001 up to Blesta.transaction_deadlock_reattempts, then returns an error. There is no customer-level lock. getCredits (885) is an unlocked read, but apply re-checks under the lock, so credit cannot be double-spent.
- MyCS: lockInvoice (RecordPaymentService.php:573-586) locks the customer → the invoice → (lockCredit 620) the credit row. Balances come from locking reads (withLockedBalance). The code accepts a possible deadlock when an invoice changed owner (571) and when two deletions run concurrently (375). Nothing retries: none of the DB::transaction calls passes the attempts argument, and nothing catches 40001.
- Μας αφορά: Medium. Webhooks are retried by the gateway, but a deadlock surfaces as an error to a client paying with credit in Show.php, or to staff. The daily CreditAutoApply sweep just reports it and tries again the next day.
- Πρόταση: Pass attempts (e.g. 3) to the outer DB::transaction calls in RecordPaymentService::record, payWithCredit, allocateFromTransaction, settle and deleteTransaction, and in ExtensionHelper::refund. For refund, retry only the part before the gateway call (prepareRefund); never retry after the money has left. Add a test that forces a deadlock (two processes in opposite order) and checks the second one completes.

**Π3.6 Credits per currency and their source** (low · διαφέρει, ισοδύναμο · other)
- Blesta: There is no credit table. Credit is the unapplied remainder of approved transactions, per currency (getCredits, transactions.php:885-942, HAVING applied < amount at 904), rounded to each currency's precision (getCurrencyPrecision 965). applyFromCredits (1278-1374) only spends credit in the invoice's currency (1295, 1316-1325) and uses the oldest transaction first (getCreditApplyAmounts 1376-1475). Each use of credit is traced back to the payment it came from.
- MyCS: One credits row per customer and currency (lockCredit RecordPaymentService.php:620-624), changed only through CreditLedger, with a money_events journal. Payments from credit are their own invoice_transactions (is_credit_transaction, spendCredit 627-643). The link back to the originating payment exists only for subscription allocation (source_transaction_id).
- Μας αφορά: Both companies have 2 currencies (EUR, plus 1 USD client each). Credit is used heavily (2,594 and 2,626 invoices paid with credit).
- Πρόταση: Keep the ledger model; it matches the WHMCS import (tblcredit) and the journal gives the 'why'. If Mass Pay is built on source_transaction_id, the Blesta-style trace comes for free for those cases.

**Π3.7 Refunding a payment's credited part (an overpayment) back to the card** (low · διαφέρει, ισοδύναμο · other)
- Blesta: Credit is the transaction's own remainder, so refundPayment (gateway_payments.php:1506-1687) can send any amount of it back through the gateway. As a side effect it unapplies every invoice (see the partial-refund finding).
- MyCS: refundable_amount = amount − refunded − credited (InvoiceTransaction.php:90-95). The credited part of an overpayment cannot be sent back through ExtensionHelper::refund (604). Staff must remove credit by hand (ShowCredits removeCredit, CreditLedger staff_adjustment) and refund in the gateway dashboard. The webhook then logs refund_nothing_left and emails staff (payments.md 2c-2a).
- Μας αφορά: Low. Refunds are rare: 2 refund rows ever at nixpal, 43 at myip (4 in the last 12 months).
- Πρόταση: Later: an admin action 'Refund credit to original payment'. For a transaction with credited_amount > 0 and a credit balance at least as large, under customer → invoice → transaction → credit locks: CreditLedger::remove (cause refund_from_credit), increment refunded_amount, then the gateway refund. That keeps the gateway webhook match automatic.

**Π3.8 Partial refunds and the invoice status** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: Admin refundPayment overwrites the transaction amount with the refunded amount and sets its status to refunded (gateway_payments.php:1660-1680). Transactions::edit then unApply()s every invoice for a status other than approved (transactions.php:158). The invoice reopens (setClosed 2564) and is owed in full, and the original amount is lost. A partial refund from a gateway notification (gateway_payments.php:610-635) becomes a new negative approved transaction that is never applied, so neither the invoice nor the credit changes.
- MyCS: refunded_amount accumulates on the payment (ExtensionHelper.php:1396-1412, recordRefund). A paid invoice gets a refund adjustment note and stays paid. It becomes refunded only when nothing is left. Refund to credit is supported (refundToCredit 758). Gateway refunds are recorded once per id (gateway_refunds, origin), and waiting refunds are applied when the payment completes (applyWaitingRefunds 909).
- Μας αφορά: myip has 35 Refunded invoices; refund flows must import and run cleanly.
- Πρόταση: Keep it. Do not port Blesta's refund semantics.

**Π3.9 Out-of-order and backward status changes (void, declined, returned)** (low · διαφέρει, ισοδύναμο · row 6 gateways)
- Blesta: allowsStatusTransition (gateway_payments.php:2435-2470): approved may move only to refunded, returned, void or declined; void, refunded and returned are final; pending, declined and error may move anywhere. A refused change is logged and dropped (645-657). Moving approved → declined/void/returned goes through Transactions::edit → unApply (transactions.php:158), so the invoices reopen and services are untouched. An approved notification never applies amounts if the stored transaction is no longer approved (gateway_payments.php:710-713).
- MyCS: A succeeded transaction never goes back: a later failed/processing for the same id counts as a replay (RecordPaymentService.php:111-122). Money leaving after success is modelled only as a refund (refundedAtGateway) or a chargeback (2c-2b: Stripe charge.dispute.*, PayPal PAYMENT.CAPTURE.REVERSED). Mollie: only our own refund ids; its webhook brings no refunds or chargebacks (payments.md 2c-2a).
- Μας αφορά: Low today: Stripe and PayPal are covered. It matters for the row 6 gateways: SEPA or direct-debit returns, and the Cardlink/vPOS void-after-capture.
- Πρόταση: When adding a gateway with returns (SEPA, ACH-like) or voids after capture, map them to the chargeback path (funds out; the invoice goes to unpaid when it was paid) rather than to a status change. Add a test: succeeded → returned → the replayed succeeded is still ignored.

**Π3.10 Credit auto-apply rules** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: The ApplyPayments cron task (core/Automation/Tasks/Task/ApplyPayments.php) runs for each client group with auto_apply_credits on, for every client. applyFromCredits (transactions.php:1278-1374) applies partially to all open invoices, oldest date_due first, with no exclusions. If one apply fails, the earlier ones are rolled back with unApply (1340-1350). unApply deletes the whole transaction_applied row per invoice, including amounts applied before this run (ON DUPLICATE amount+, 1243).
- MyCS: CreditAutoApply (CreditAutoApply.php): partial payments, oldest due first. It runs when renewal invoices are made, when credit comes in, and in a daily sweep before suspensions. It excludes credit purchases, upgrades, invoices with a processing payment, subscription invoices (except a renewal fully covered), invoices with a cancellation request, ended services, and invoices where staff deleted a credit payment or refunded to credit (144-187). Opt-out per client (clients.credit_auto_apply); global setting credits_auto_use. Each invoice is its own locked transaction.
- Μας αφορά: nixpal auto-apply is on; myip is off (settings-map.md:122).
- Πρόταση: Keep it. Make sure the importer maps NoAutoApplyCredit to credits_auto_use per instance. Pair this with the staff 'Apply credit' action (finding 2).

**Π3.11 Rounding and currency precision** (low · διαφέρει, ισοδύναμο · other)
- Blesta: Per-currency precision from the currencies table (default 4): getCurrencyPrecision (transactions.php:965). Applied amounts are cast to the currency (getCreditApplyAmounts via CurrencyFormat::cast). Stored invoice totals and paid are rounded (invoices.php updateTotals). Comparisons round both sides (validateApplyAmounts 1790-1836, validateAmountApplied 1838-1887).
- MyCS: Everything in integer cents: toCents does (int) round(float*100) after rejecting non-finite values and anything above 1e12 (RecordPaymentService.php:667-678; Invoice::moneyToCents Invoice.php). Columns are decimal(17,2). Stripe divides by 100 for every currency (known, payments.md).
- Μας αφορά: Low. Only EUR and USD exist at both companies.
- Πρόταση: Keep cents. Before enabling a zero-decimal or 3-decimal currency, add a minor-unit factor per currency and fix the Stripe /100.

**Π3.12 Idempotency and replays** (low · ίδιο · merge/split/masspay)
- Blesta: processNotification looks the transaction up by gateway + transaction_id (gateway_payments.php:575-583); merchant notifications fall back to a pending authorization by reference (585-597). Repeated invoice amounts are trimmed to what is outstanding and re-checked after the lock (getUnappliedAmounts 2475, 729-736). Unmatched notifications are logged (logUnmatchedNotification 2407).
- MyCS: The same gateway + id, or a staff entry without a gateway, on any invoice of the same customer is a replay (RecordPaymentService.php:101-122), read with lockForUpdate under the customer lock. There is no unique index, on purpose: WHMCS Mass Pay writes the same transid on several rows. Every request writes to the gateway log (GatewayLog). A late fee report is stored on the existing transaction.
- Μας αφορά: Yes: PayPal subscription replays and Stripe retries.
- Πρόταση: Keep it. When Mass Pay creates several allocations from one gateway id, keep the replay check keyed on the source payment, not on the allocations (source_transaction_id rows carry no transaction_id).

**Π3.13 What happens to services when the invoice is paid (renewal)** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: Renewal dates move forward when the renewal invoice is created (Invoices::createRenewalFromService, invoices.php:1945, 2043-2075): date_renews moves on, and date_paid_through marks the unpaid period until setClosed nulls it (2526). With process_paid_service_changes on, the date change is queued as a ServiceChange and applied by the ProcessServiceChanges cron once the invoice is closed and the service is active (ProcessServiceChanges.php:145-205). After cancel_service_changes_days the change expires: its payments are unapplied and the invoice is voided (234-245). Re-opening an invoice never reverts a renewal.
- MyCS: Renewal on paid, as in WHMCS. Observer paid → apply_pending_since (InvoiceObserver.php:112), then after commit ProcessPaidInvoiceService (ProcessPaidInvoiceService.php:18-53) → RenewServiceService (locks the service, once per line via applied_at, contiguous paid periods only, never revives an ended service and warns staff, RenewServiceService.php:38-55). ServiceUpgradeService runs once per upgrade. The cron retries failures (BIL-9). Paid → unpaid (deletion, chargeback) leaves services renewed; staff decide.
- Μας αφορά: Yes. WHMCS semantics (nextduedate moves on payment) make the import and parity simpler.
- Πρόταση: Keep it. Known open item from payments.md: an affiliate reward is given again on every paid → unpaid → paid cycle. Gate it with a marker like applied_at.

**Π3.14 Payments on draft invoices** (low · διαφέρει, ισοδύναμο · other)
- Blesta: apply only accepts active or proforma invoices (validateApplyAmounts, transactions.php:1809-1812). Money aimed at a draft stays as unapplied credit.
- MyCS: Draft counts as open. excessCents (RecordPaymentService.php:613) and the listener (InvoiceTransactionCreatedListener.php:36-46) let a payment land on a draft and turn it paid when remaining == 0. A draft paid over its total is left for staff. payWithCredit also accepts drafts (226).
- Μας αφορά: Low. Drafts are staff-only (27 at myip). Paying a draft would renew its services before staff finalized it.
- Πρόταση: Decide on purpose. The simplest choice is to treat a draft like a cancelled invoice: record the money, credit all of it, and leave the status alone. Alternatively, document why a draft may become paid. Add a test either way.

**Π3.15 Proforma numbering at the paid transition** (medium · διαφέρει, καλύτερο το Blesta · other)
- Blesta: setClosed turns a paid proforma into active, and Invoices::edit gives it its number (invoices.php:2499-2518, 600-611, 620-630). The number exists only once the invoice is paid in full.
- MyCS: InvoiceNumberListener.php:20-28: in proforma mode the number is assigned when the status changes to unpaid OR paid and there is no number yet. A draft finalized to unpaid therefore takes a number from the sequence while still unpaid, and that number stays even if the invoice is later cancelled. Invoices created directly as unpaid are numbered correctly, on payment.
- Μας αφορά: nixpal: proforma on, {YEAR}-{NUMBER} for paid invoices, next number 6819 (settings-map.md:12-13). A numbered unpaid or cancelled invoice leaves gaps in the paid-number sequence.
- Πρόταση: In proforma mode, assign the number only on the transition to paid (keep the creation path for non-proforma). Add a test: draft → unpaid → cancelled consumes no number; draft → unpaid → paid gets the next number.

**Π3.16 Transaction edits and deletion** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: Transactions::edit refuses to lower the amount below what is applied (validateAmountApplied, transactions.php:1838-1887), unapplies on a non-approved status (158), and logs a diff. delete (197-215) unapplies and then hard-deletes, with no credit or refund checks.
- MyCS: Staff cannot edit transactions. deleteTransaction (RecordPaymentService.php:348-440) refuses when the payment has refunds, refund-to-credit entries or downstream allocations. It reverses the journal, takes back the credited part (negative credit only when staff confirm), and reopens a paid invoice that is no longer covered.
- Μας αφορά: Yes. These are the staff correction flows.
- Πρόταση: Keep it. payments.md already lists the remaining gap: a tombstone instead of a hard delete.

Ανοιχτά ερωτήματα:

- Several Blesta files on orion (transactions.php, gateway_payments.php, invoices.php, all dated 2026-09-29) carry long explanatory comments about locks, deadlock retries, out-of-order notifications and the applied-amount cap. Is this stock 6.0.3, or was it patched locally? Compare against a clean 6.0.3 tarball before citing these behaviours as Blesta's.
- Mass Pay design: (a) a WHMCS-style mass-pay invoice whose lines point at the target invoices, so the WHMCS import maps 1:1 (both companies have Mass Pay lines), or (b) a Blesta-style N:M allocation table? Option (a) fits the 1-invoice-per-payment model and the source_transaction_id machinery.
- Should a payment on a draft invoice be allowed to turn it paid and renew its services (current MyCS behaviour), or should it all become credit as Blesta does?
- The nixpal and myip 'overpaid' counts (485 and 140) come from a rough SUM(amountin-amountout) > total query. It has not been checked whether they include add-funds or Mass Pay artefacts, or real overpayments.

### Π4. Service lifecycle: cancel, suspend, terminate, statuses

MyCS is at least as safe as Blesta 6.0.3 here, and safer in most places. Its suspend, terminate and cancellation-request steps lock each service row and check the rule again under the lock. They skip services with a payment in flight (SEPA processing, or paid but not yet applied). They tell `cancelled` (the customer asked) apart from `terminated` (non-payment or staff). Pending upgrades are cancelled explicitly. CancelOpenInvoices never voids an invoice that holds gateway or bank-transfer money, and never one with a delivered period.
Blesta has none of these guards. It also has no automatic termination for non-payment: it only suspends and runs cancellations that someone scheduled. Its staff unsuspend() also wipes a scheduled cancellation.
Blesta does three things better, and these are the real lessons:
(1) It auto-unsuspends only when the service has no open invoice that is past due. MyCS unsuspends on any payment that moves paid-until forward, even if the service is still past Suspend Days. That can make it unsuspend and then suspend again.
(2) Its void_inv_canceled_service_days setting keeps invoices that are already overdue when a service is cancelled, so the debt survives. MyCS, like WHMCS with CancelInvoiceOnCancellation=on, cancels them all, including on a service already suspended for non-payment.
(3) Clients cannot cancel a service that is suspended or has past-due invoices, and they can withdraw a scheduled cancellation themselves.
Neither WHMCS uses a Completed service status today (0 rows at both). So Δ4's `completed` is cheap but has no current users, and Blesta has no such status at all.

**Π4.1 Unsuspend on payment while still overdue (unsuspend, then suspend again)** (medium · διαφέρει, καλύτερο το Blesta · other (dunning / Σ cron))
- Blesta: UnsuspendServices (core/Automation/Tasks/Task/UnsuspendServices.php) runs from cron only. Services::getPendingUnsuspensionQuery (app/models/services.php:1290-1358) picks a suspended service only if (a) its last suspension log has staff_id NULL, so it was auto-suspended, and (b) it has NO open (active/proforma) invoice with date_due <= now. Paying one of two overdue invoices does not unsuspend it.
- MyCS: RenewServiceService::activate (app/Services/Service/RenewServiceService.php:129-165) sets status active and dispatches UnsuspendJob whenever a payment moves paid-until forward and the service is dunning-suspended. It never checks whether the new expires_at is still before the suspend cutoff that CronJob::suspendable uses (app/Console/Commands/CronJob.php:424-436). The next cron run suspends it again, which means a module unsuspend, a module suspend and two customer emails.
- Μας αφορά: Yes. myip has 'suspend until' holds on 170 services and nixpal on 14 (docs/whmcs-usage.md). When a hold ends with 2+ unpaid periods, or staff move dates, a service can be overdue by more than one period. AutoUnsuspend is on at both companies.
- Πρόταση: In activate(), lift a dunning suspension only if the new paid-until is no longer past the suspend cutoff. Use the same predicate as suspendable(), or with cronjob_suspend_requires_invoice no open invoice past it. Otherwise keep it suspended (expires_at still advances). Add a test: two unpaid periods, pay the older one, expect it to stay suspended and no UnsuspendJob.

**Π4.2 Overdue invoices wiped when a service is cancelled (debt survival)** (medium · διαφέρει, καλύτερο το Blesta · other (cancellation policy; settings-map))
- Blesta: Services::cancel calls voidInvoices only when the client setting void_invoice_canceled_service is true (services.php:3470-3489). voidInvoices (services.php:3639-3790) skips any invoice whose date_due + void_inv_canceled_service_days has passed ('any' = void all). Invoices that are already overdue therefore stay as debt, and only current or future ones are voided.
- MyCS: CancellationCreatedListener (app/Listeners/CancellationCreatedListener.php:31-71) cancels ALL open invoices of the service (CancelOpenInvoices) for both immediate and end_of_period requests. A service that is suspended for non-payment can still file a request: Service::cancellable (app/Models/Service.php:681-686) only excludes ended, free and one-time services. Its overdue invoice is cancelled. Because its expires_at is already past, cancellations_processed (CronJob.php:211-229, endAsRequested 554-583) ends it at the next run as `cancelled`, not `terminated`. The unpaid period it used is written off.
- Μας αφορά: Partly. Both WHMCS run CancelInvoiceOnCancellation=on, so today's behaviour equals WHMCS. Cancellation requests in the last 12 months: nixpal 33 (26 immediate), myip 34 (28 end of period). How many came from suspended or overdue services was not measured (it would need per-row reads).
- Πρόταση: Add an instance setting like Blesta's: 'keep invoices overdue more than N days on cancellation' (default = today's behaviour, any). Or at least keep the overdue invoice of a service that is dunning-suspended. Record the decision in docs/settings-map.md. CancelOpenInvoices already leaves invoices open for staff with a reason, so add a third reason ('overdue debt, kept') instead of a new path.

**Π4.3 Client may cancel while suspended or past due** (low · διαφέρει, καλύτερο το Blesta · other)
- Blesta: ClientServices::cancel (app/controllers/client_services.php:723-800) refuses when the service is canceled or suspended. It refuses both 'now' and 'term' when Invoices::getAllWithService(..., 'past_due') is non-empty (line 757). It requires the client's password. Allowed options are set per client group: clients_cancel_options = now / end_of_term / both (service_invoices.php:370), and clients_cancel_services turns client cancellation on or off.
- MyCS: Livewire Services\Cancel (app/Livewire/Services/Cancel.php:21-48) checks only authorize('update'), `cancellable` and the unique request. Suspended and past-due services can request either type. There is no password re-check and no per-instance choice of allowed types.
- Μας αφορά: Unknown. WHMCS allows requests on suspended services, and both companies get a few dozen requests a year. It matters together with the overdue-invoice finding above.
- Πρόταση: Decide the policy with the overdue-invoice finding. Cheapest consistent option: allow the request but do not cancel overdue invoices on it. Alternative: a setting that blocks client requests while past due or suspended. An allowed-types setting (immediate / end_of_period / both) is a cheap win.

**Π4.4 Order of status change and module call; failed module actions** (low · διαφέρει, ισοδύναμο · other (Σ1 module actions))
- Blesta: suspend(), unsuspend() and cancel() call the module FIRST (services.php:3962-3990, 4066-4093, 3420-3436). The status is written only after the module succeeds. On failure the status stays, incrementServiceAttempt bumps failed_attempts up to maximum_attempts with date_next_attempt, and the cron task mails staff (sendServiceSuspendError etc.).
- MyCS: The cron writes status suspended or terminated, then dispatches SuspendJob or TerminateJob afterCommit (CronJob.php:255-264, 285-296; endAsRequested 578). The job records a ModuleAction on failure, and staff retry it from Pending Module Actions (app/Services/Service/RetryModuleAction.php). The status says suspended while the account may still run.
- Μας αφορά: Yes, as a known item: docs/whmcs-usage.md:66 already lists 'Status μόνο μετά την επιτυχία του job'. 5 hosting modules are in use (cPanel, Proxmox, DirectAdmin…).
- Πρόταση: Keep MyCS's order. The status is the billing decision and the server must follow it, which is what holds the lock-and-recheck design together. Do not copy Blesta. Make sure a failed suspend or terminate stays visible (ModuleAction open plus the cron failure mail), and update the docs/whmcs-usage.md note to say this is a deliberate choice.

**Π4.5 Automatic termination for non-payment** (low · μόνο στο MyCS · other)
- Blesta: No such task. The cron runs suspendServices, unsuspendServices and cancelScheduledServices only (app/controllers/cron.php:239-241). Cancellation needs a date_canceled set by someone (services.php:1437-1474). A suspended unpaid service stays suspended forever.
- MyCS: services_terminated (CronJob.php:276-308, terminable 443-449) runs Termination Days after expiry, only for dunning-suspended services, at least cronjob_terminate_min_suspended_days after the suspension, not held, and with no payment in flight. It sets status `terminated`, cancels pending upgrades and the service's own open invoice lines (CancelOpenInvoices with onlyFor), and returns stock.
- Μας αφορά: Yes. AutoTermination is on at both: nixpal 60 days (606 Terminated services), myip 120 days (109).
- Πρόταση: Keep it. Blesta is not a model here.

**Π4.6 Which invoices count for suspension; guards on the suspension query** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: getPendingSuspensionQuery (services.php:1119-1214) is driven by invoices: active services with a line on an open (active/proforma, not closed) invoice whose date_due is at or before today minus suspend_services_days_after_due ('never' per client group). It skips services with a queued service change (pending_changes type 'change'). A per-client 'autosuspend' flag and 'autosuspend_date' delay are checked (SuspendServices.php). There are no row locks and no check for payments in flight.
- MyCS: suspendable (CronJob.php:424-436) is driven by date, as in WHMCS: expires_at before today minus Suspend Days, with an optional cronjob_suspend_requires_invoice (BIL-5). unheldWithNoPaymentOnItsWay (CronJob.php:457-465) adds suspend_hold_until, excludes services without a plan or with a processing payment (SEPA, PAY-9) or a paid line not yet applied (BIL-9). Each service is rechecked under lockForUpdate.
- Μας αφορά: Yes. Suspend days are 8 (nixpal) and 15 (myip). Stripe SEPA is used (4 stored mandates).
- Πρόταση: Keep it. Missing piece: a per-service or per-client 'never suspend' (Blesta: client autosuspend=false; WHMCS: 36 services at myip). It is already listed as N in docs/whmcs-usage.md:66. Implement it as a flag next to suspend_hold_until and import it.

**Π4.7 Manual hold vs automatic suspension** (low · ίδιο · other)
- Blesta: Unsuspension only considers services whose LAST 'suspended' log row has staff_id NULL (services.php:1293-1346). A staff suspension is never lifted by the cron.
- MyCS: dunning_suspended_at marks a suspension for non-payment. placeOnHold (app/Models/Service.php:427-445) and applyStaffChanges (Service.php:399-420) clear it, so a payment does not lift a staff hold (RenewServiceService.php:131). ServiceObserver clears the marker and suspend_reason on any status change away from suspended (app/Observers/ServiceObserver.php:83-91).
- Μας αφορά: Yes. All currently suspended services in both WHMCS have a non-'Overdue' suspend reason: nixpal 9/9, myip 12/12. Staff holds are the common case.
- Πρόταση: No change.

**Π4.8 Removing a cancelled service's lines from a shared invoice** (low · διαφέρει, καλύτερο το MyCS · merge/split/masspay)
- Blesta: voidInvoices (services.php:3639-3790) first UNAPPLIES every transaction on the invoice, gateway money included, which becomes client credit. If every non-late-fee line belongs to the service it voids the invoice. Otherwise it deletes the service's lines, writes a private note listing what was removed, and re-applies the same transactions up to the new total (unless reapply_payments=false, used for add-ons). Late fees do not stop a void. Lines with no service_id stop a void, so only the service's lines are removed. There are no locks.
- MyCS: CancelOpenInvoices (app/Services/Invoice/CancelOpenInvoices.php) locks the customer, then the invoice. It returns paid or cancelled invoices untouched. It LEAVES OPEN for staff any invoice holding gateway or transfer money (line 83), with a delivered period (112), with unknown imported lines, or whose remainder would be zero. Credit goes back first (giveBack, 116). On a shared invoice it deletes only the service's and its upgrades' lines, and credit keeps paying the rest up to what it paid (130-139). The removal is audited, not written as a note.
- Μας αφορά: Yes. nixpal often has many lines per invoice (Group Similar Line Items). myip has 258 cancelled invoices in 12 months.
- Πρόταση: Keep it. Optional: add a short private note on the invoice listing the removed lines, as Blesta does. Staff read invoices, not the audit log.

**Π4.9 Scheduled (end of term) cancellation and its withdrawal** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: cancel() takes date_canceled = 'end_of_term' (stored as date_renews) or a date. A future date only stores date_canceled, with no module call (services.php:3412-3418). getAllRenewing excludes it from invoicing (services.php:609-614). CancelScheduledServices then cancels it when the date comes (core/Automation/Tasks/Task/CancelScheduledServices.php). unCancel() with no vars clears a pending date_canceled (services.php:3809-3812). The client gets a 'Do not cancel' option. unsuspend() sets date_canceled = NULL (services.php:4063), so a staff unsuspend silently drops a scheduled cancellation.
- MyCS: An end_of_period ServiceCancellation row is created. Open invoices and pending upgrades are cancelled at once (CancellationCreatedListener.php:63-71). billRenewal skips services with any cancellation (CronJob.php:603). cancellations_processed ends the service on expires_at as `cancelled`, but refuses if a later period is already paid (CronJob.php:562-570). Withdrawal is staff-only, by deleting the request (app/Admin/Resources/ServiceCancellationResource.php:87). A later run then re-bills the gap, because cancelled invoices are holes (Service::livePeriodLines).
- Μας αφορά: Yes: end-of-period requests are 476 historically at myip and 28 at myip in the last 12 months.
- Πρόταση: Keep it. Optionally let the client withdraw the request until the due date (a 'keep my service' button that deletes the row), as Blesta's unCancel does. Say in the UI that the cancelled renewal invoice is re-issued.

**Π4.10 Queued service changes / pending upgrades when a service ends** (low · διαφέρει, καλύτερο το MyCS · Δ3)
- Blesta: Services::cancel never touches queued service_changes. ServiceChanges::deleteByService exists (service_changes.php:175) but nothing calls it. ProcessServiceChanges (core/Automation/Tasks/Task/ProcessServiceChanges.php) skips a change whose service is not active and only logs 'service_inactive' on every run. It never expires or voids it, so a change invoice can stay open on a cancelled service. Renewals with process_paid_service_changes are also queued as changes (app/models/invoices.php:2043-2068), and that code clears a scheduled date_canceled earlier than the next renewal date (line 2050).
- MyCS: CancelPendingUpgrades::forService runs on every way a service ends: terminate (Service::markTerminated 452-470, cron 294), immediate and end_of_period requests (listener 60-70), and endAsRequested (CronJob.php:575). The upgrades_invoices_updated step cancels pending upgrades of ended or overdue services (CronJob.php:196-209). ServiceUpgradeService refuses a cancelled service.
- Μας αφορά: Low volume: 27 package upgrades in 12 months at myip. Correctness matters, though, because it is money.
- Πρόταση: No change. Do not adopt Blesta's renewal-as-queued-change model.

**Π4.11 Status set: completed / in_review / fraud** (low · διαφέρει, καλύτερο το MyCS · Δ4)
- Blesta: Services::getStatusTypes (services.php:4415-4424): active, canceled, pending, suspended, in_review. in_review = needs manual review before it may become pending. No completed, no terminated; canceled covers both. getActions limits actions per status (4438-4470).
- MyCS: Service::STATUSES (app/Models/Service.php:48-63): pending, active, suspended, terminated, cancelled. Δ4 (docs/decisions.md:220-244) approves a services `completed` status for one-time services, and order-level pending/active/cancelled/fraud with Accept Order instead of a service in_review.
- Μας αφορά: WHMCS tblhosting today: nixpal Active 341 / Suspended 9 / Terminated 606 / Cancelled 406; myip Active 703 / Suspended 12 / Terminated 109 / Cancelled 48 / Fraud 2. ZERO Completed and zero Pending in both.
- Πρόταση: Keep terminated distinct from cancelled; the data depends on it. Δ4's `completed` has no rows to import, so it can be a small, low-priority PR. The importer must map WHMCS Fraud (2 at myip) to something, since fraud is not a service status in MyCS. Suggest cancelled plus an order fraud flag, and record it in docs/importer.md.

**Π4.12 Cascade to child services / add-ons; cancellation fee** (low · λείπει στο MyCS · other)
- Blesta: cancel() and unCancel() recurse into child (add-on) services first, without re-applying payments on the children (services.php:3381-3392, 3801-3805). addCancelInvoice issues a cancellation-fee invoice when the pricing has cancel_fee > 0 and the cancel date is not the renew date (services.php:3571-3637).
- MyCS: Missing: MyCS has no parent/child services and no cancellation fee.
- Μας αφορά: Little: 12 active WHMCS add-ons at myip, and no cancellation fees in use.
- Πρόταση: Nothing now. If add-ons are modelled later, cancellation and termination must cascade through the same CancelOpenInvoices / CancelPendingUpgrades path.

**Π4.13 Cancelling a service when its invoice is cancelled (WHMCS custom hook)** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: No such rule. Voiding an invoice does not change the service. Unpaid pending orders are not auto-cancelled either.
- MyCS: Only for unpaid orders: orders_cancelled (CronJob.php:160-193, unpaidOrders 389-395, with part-paid orders left to staff). Staff cancelling a renewal invoice does not touch the service (app/Observers/InvoiceObserver.php:165-169 only creates the credit note).
- Μας αφορά: Yes. docs/whmcs-usage.md:77 lists the custom hook CancelProductOnCancelInvoice as needed in phase 1.
- Πρόταση: Decide whether the hook's rule (staff cancel an order or renewal invoice, so the service is cancelled) is still wanted. If it is, add it as an explicit staff action ('cancel invoice and service'), not an observer side effect, reusing the listener's immediate path.

Ανοιχτά ερωτήματα:

- Should a dunning-suspended service be able to file a cancellation request, and should that request cancel the overdue invoice? Today's MyCS behaviour (and WHMCS with CancelInvoiceOnCancellation=on) turns a debt into a cancelled service.
- Does staff still want the custom WHMCS CancelProductOnCancelInvoice behaviour, and how often did it actually fire? That was not measured without per-row reads.
- How should WHMCS 'Fraud' services (2 at myip) be imported, given fraud is an order status in MyCS (Δ4)?
- Should the client be able to withdraw an end-of-period cancellation, as Blesta's unCancel / 'Do not cancel' allows?

### Π5. Taxes: inclusive/exclusive, cascade, rounding

On taxes, MyCS (Δ1, #107/#109) is already stricter than Blesta 6.0.3 on the main rules. Each line stores a net amount, a frozen rate, its tax and its total, rounded once per line in BigDecimal. That matches what WHMCS does in both companies today (TaxType=Exclusive, TaxPerLineItem=1) and the per-line amounts myDATA wants.

Blesta does it differently. It keeps no tax amounts at all, only a link from each line to a tax rule id, and works the tax out again every time in floats. Rounding happens only on the invoice total. It also reads the client's current tax_exempt setting each time it works out an existing invoice, so a later change can alter the total of an issued invoice.

Things Blesta has that MyCS lacks, and neither company uses today: two tax levels with cascade (tax2 is 0 on every WHMCS invoice at both companies), rules by state, and separate switches for taxing setup and cancellation fees.

Where Blesta is ahead and it matters to us:
1. When prices include VAT, Blesta keeps the gross price fixed and takes the tax out of it (`inclusive_calculated`). MyCS rounds the net to cents first, so the invoice can come out 1 cent off the catalog price, more with quantity > 1.
2. In that mode Blesta takes the VAT off for tax-exempt clients (`subtract`). MyCS charges them the full VAT-inclusive price as net.
3. When a client's tax status changes, Blesta's open invoices follow it. MyCS re-rates open lines only when the product changes, yet it does update the Stripe/PayPal subscription amount, so the subscription and the open invoice can disagree.

Credit notes, refunds and downgrade credits are gross-only in both systems. For myDATA that needs a split per VAT rate on the way to Ekdosi.

Blesta's EU VAT handler has two defects MyCS must not copy into its EU VAT work (ROADMAP row 4):
- If the VIES check fails with an error, the client is marked tax exempt.
- Reverse charge is decided from the country VIES returns, while the tax rate comes from the billing country.

**Π5.1 Where tax amounts live and how they are rounded** (low · διαφέρει, καλύτερο το MyCS · Δ1 (done), importer)
- Blesta: No tax amount is stored per line. invoice_line_taxes only links a line to a tax rule id, with cascade/subtract flags (invoices.php:1289 addLineTax, :3014-3030 getTaxes). The tax is worked out each time by the Pricing library in PHP floats with no rounding (TaxPrice.php:66-73 on(); ItemPrice.php:258-305 compoundTaxAmount). Only the invoice subtotal/total/paid are rounded to currency precision when stored (invoices.php:2579-2600 updateTotals). Per-line rounding survives only in the deprecated getTaxTotals (invoices.php:2818-2833).
- MyCS: Each line stores net price, taxed, tax_rate (decimal 4), tax_amount and line_total (decimal 2). LinePricer::invoiceLine works in BigDecimal: net × qty rounded half-up, then tax rounded half-up, once per line (packages/pricing/src/LinePricer.php:57-69). LineTax::apply runs it from the observer (app/Services/Invoice/LineTax.php:34-39; app/Observers/InvoiceItemObserver.php:30-37, 52-58). Invoice totals are sums of the stored line values in cents (app/Models/Invoice.php:178-230). Migration: database/migrations/2026_11_01_000000_net_amounts_and_tax_per_line.php:30-34.
- Μας αφορά: Both WHMCS installs use TaxPerLineItem=1 and TaxType=Exclusive (tblconfiguration, read 2026-10-14). The importer's totals parity and myDATA's per-line VAT both need per-line rounding. Blesta's invoice-level float rounding would differ from WHMCS by cents on multi-line nixpal invoices (Group Similar Line Items; 1,176 negative discount/promo lines in the last 12 months).
- Πρόταση: Keep Δ1 as built. Add a parity test that imports multi-line WHMCS invoices with negative GroupDiscount/PromoHosting lines and checks sum(line_total) against tblinvoices.total to the cent.

**Π5.2 Prices that include VAT: gross fixed vs net fixed (1-cent drift)** (medium · διαφέρει, καλύτερο το Blesta · EU VAT)
- Blesta: Three tax types (TaxPrice.php:12-14). `exclusive` adds tax on top. `inclusive` also computes the tax on top of the price, but line totals are presented with it included (TaxPrice.php:81-87; invoices.php:2836-2839). `inclusive_calculated` treats the stored price as gross: the tax is extracted as price/(100+r)×r and never added (TaxPrice.php:68-69; ItemPrice.php:154-163, 286-290). Line amounts are stored at 4 decimals (invoices.php:191-196), so the gross the customer saw is what they pay.
- MyCS: Storage is always net. In inclusive mode the catalog gross is turned into a unit net rounded to 2 decimals (OrderPricing::toNet, app/Services/Order/OrderPricing.php:109-118; LinePricer::tax, LinePricer.php:111-122). The invoice line then adds tax again on net × qty (LinePricer.php:57-69). Example: catalog 10.00 incl. 24% → net 8.06, tax 1.93, line total 9.99. With quantity the error grows by up to 0.5 cent per unit. CartItem charges the invoiceLine total while the price shown comes from Price::fromLine (app/Models/CartItem.php:85-92). docs/orders.md:77-78 accepts the 1-cent difference.
- Μας αφορά: Not used today: both WHMCS installs are Exclusive with TaxInclusiveDeduct empty. It matters as soon as nixpal shows prices with VAT included, and the target here says it may. Under OSS each country's rate gives a different net, so the drift varies by country.
- Πρόταση: Decide before anyone switches to inclusive. (a) Preferred: keep exclusive storage and show gross for display only (net × (1+r) for the viewer's country). Then the charged amount always equals what was shown. (b) If the catalog must be gross: add a line flag such as `price_includes_tax`. Compute tax = gross×qty − round(gross×qty/(1+r)) and net = gross×qty − tax, so line_total = gross×qty exactly. Store the unit amount at 4 decimals or keep the gross on the line. Add property tests that line_total equals catalog gross × qty for all 27 EU rates.

**Π5.3 Tax-exempt client when catalog prices include VAT** (medium · διαφέρει, καλύτερο το Blesta · EU VAT)
- Blesta: For a tax-exempt client, `exclusive` and `inclusive` rules are skipped. `inclusive_calculated` rules are kept with subtract=1, so the extracted tax is taken off the price and the exempt client pays the net (invoices.php:208-223; AbstractInvoiceItems.php:126-151; InvoiceDataItems.php:55-58; ItemPrice.php:270-276, 279-281).
- MyCS: OrderPricing::taxRate returns null for tax_exempt (OrderPricing.php:97). toNet then returns the catalog amount unchanged (OrderPricing.php:112-114), and LinePricer::price with a null rate makes gross = catalog. So in inclusive mode an exempt or reverse-charge client pays the VAT-inclusive catalog price as net, with 0 VAT.
- Μας αφορά: nixpal has 30 tax-exempt clients (tblclients.taxexempt) and EU reverse charge on (TaxEUTaxExempt=1). That only bites in inclusive mode, which neither company uses today.
- Πρόταση: If inclusive mode stays, add an instance setting like WHMCS TaxInclusiveDeduct / Blesta subtract. Default it to deducting the VAT for exempt and reverse-charge clients, using the rate the client would otherwise pay (or the home rate; document which). Add a test: inclusive 12.40 at 24% with an exempt client gives net 10.00, VAT 0.

**Π5.4 Issued invoices kept frozen vs recalculated after a client tax change** (low · διαφέρει, καλύτερο το MyCS · Δ1)
- Blesta: The invoice presenter is built with the client's current settings (invoices.php:4317-4346). If the client is now tax exempt, AbstractInvoiceItems::getTaxes drops every non-inclusive_calculated tax (AbstractInvoiceItems.php:115-134). updateTotals runs on every setClosed, i.e. on every payment applied (invoices.php:2494, 2579-2600), so an issued (even partly paid) invoice's stored total can change. The rate itself is pinned by tax id, and editing a rule deactivates it and adds a new one (taxes.php:74-96), so a rate change does not touch old invoices.
- MyCS: A line's rate is decided once when the line is created and kept (LineTax.php:17-31; InvoiceItemObserver.php:33-35). It is decided again only when `taxed` changes (InvoiceItemObserver.php:55-57) or the product changes (Service::refreshOpenPeriods with $retax, app/Models/Service.php:476-500; ServiceUpgradeService.php:120). Paid invoices refuse any money-field change (InvoiceItemObserver.php:19-26).
- Μας αφορά: An issued proforma whose total moves after the customer paid part of it breaks the money trail and the Ekdosi bridge. MyCS's freeze is the right default.
- Πρόταση: Keep the freeze for paid and partly paid invoices. See the next finding for open unpaid lines.

**Π5.5 Open unpaid invoices when tax_exempt or country changes (subscription vs invoice mismatch)** (medium · λείπει στο MyCS · EU VAT)
- Blesta: Open invoices follow a change of tax_exempt on the next total update (see above). A country change does not re-rate them, because the tax id is pinned per line.
- MyCS: ClientObserver (app/Observers/ClientObserver.php:12-17) and Contact (app/Models/Contact.php:80) fire RenewalTaxChanged. That event only updates the amount of a Stripe/PayPal subscription (app/Events/Service/RenewalTaxChanged.php). Open unpaid renewal lines keep the old rate, because refreshOpenPeriods re-rates only on a product change (Service.php:476-500). Result: the subscription charges the new gross while the open invoice expects the old one, leaving a balance or an overpayment that turns into credit.
- Μας αφορά: In WHMCS, a client who adds a valid EU VAT number after the renewal invoice was generated expects that invoice without VAT (nixpal: 30 exempt clients, EU validation on). myip has 11 exempt clients. Rare, but each case means a manual fix and possibly a wrong VAT amount sent to Ekdosi.
- Πρόταση: When tax_exempt or the primary contact's country changes, re-rate lines on OPEN invoices with no payment on them: under ChangeOpenLine's lock, run LineTax::rateFor and then apply. Leave invoices with any payment alone and show staff a warning. Fire RenewalTaxChanged after that update, so the subscription and the open invoice agree. Test: a subscribed service with an open renewal; the client becomes exempt; the invoice total equals the new subscription amount.

**Π5.6 Choosing the tax rule by client country/state** (low · διαφέρει, καλύτερο το MyCS · EU VAT)
- Blesta: Invoices::getTaxRules joins the primary contact and matches (country+state) OR (country, no state) OR (no country, no state). It then uses GROUP BY taxes.level with SELECT taxes.*, so when a country rule and a global rule share a level, MySQL may return either one; nothing makes the specific rule win (invoices.php:6026-6058, same in getTaxRulesByLocation :6067-6094). Rules are per company and can be limited to a state.
- MyCS: Settings::tax picks TaxRate where country = client country or 'all', ordering the exact country first, so the result is always the same (app/Classes/Settings.php:984-1007). Cached per country per request. No state support. The country comes from the primary contact (PR 3a).
- Μας αφορά: myip's tbltax has exactly that pattern: GR 24% plus a global 24% at level 1. Harmless only because the rates are equal. nixpal has 27 per-country EU rules (17-27%) with home country EE. States are not used.
- Πρόταση: Keep. When EU VAT lands: make sure 'all' is not configured for nixpal, so non-EU customers get 0% with the reason `outside_eu` instead of falling back. Store the rule's country on the line, or in the 0% reason, so myDATA/OSS reporting can group by country.

**Π5.7 EU VAT number check and reverse charge** (high · λείπει στο MyCS · EU VAT)
- Blesta: EuropeTax::getTaxInformation checks the VAT id with VIES. The client is exempt if the check passes and the VIES country is not the home country (EuropeTax.php:237-257). If the VIES call throws (service down), it falls through to a default that returns tax_exempt = true (EuropeTax.php:258-271). This runs only when the tax_id field is saved and is never repeated later (clients.php:1520-1535). Reverse charge appears only as a note on the invoice (EuropeTax.php:335-361); no 0% reason is stored per line. GR is mapped to EL (EuropeTax.php:183-184, 222-223).
- MyCS: Missing. There is no VIES check and no reverse_charge field. tax_exempt is a manual flag (OrderPricing.php:97). ROADMAP row 4 plans VIES through Ekdosi's ViesLookup, with the 0% reason on the line (decisions.md Δ1; orders.md 'Τι μένει').
- Μας αφορά: nixpal: TaxEUTaxValidation=1, TaxEUTaxExempt=1, TaxEUHomeCountry=EE, TaxEUHomeCountryNoExempt off. myip: a single 24% rate.
- Πρόταση: When building it: (1) fail closed. If VIES errors, keep the client taxed, mark the check 'pending' and retry later; never exempt on error. (2) Require the VIES country to equal the billing contact's country, else no reverse charge. (3) Store the VIES consultation number and date for audit, and re-check on a schedule. (4) Write rate 0 plus a reason (reverse_charge / exempt / outside_eu) on each line, which Blesta lacks. (5) Map GR to EL. (6) Home-country businesses are not exempt, matching nixpal's TaxEUHomeCountryNoExempt being off.

**Π5.8 Tax levels 1/2 and cascade** (low · λείπει στο MyCS · other)
- Blesta: Two levels (taxes.php:128-131). A company-wide cascade_tax setting computes level 2 on price + level-1 tax (ItemPrice.php:258-268; AbstractInvoiceItems.php:202-230; invoices.php:2826-2833). Rules are grouped by level.
- MyCS: Missing: one rate per line.
- Μας αφορά: Not used. tblinvoices.tax2 / taxrate2 = 0 on every invoice at both companies, and TaxL2Compound is empty. Greek VAT and EU OSS are single-rate.
- Πρόταση: Do not build. If a second tax is ever needed, add it as a separate line, not a second level.

**Π5.9 Which kinds of lines are taxed (setup fee, cancellation fee, discounts, domains, late fees, credit)** (low · ίδιο · other)
- Blesta: Package `taxable` controls the service, domain and options. Setup fees and cancellation fees have their own company switches, setup_fee_tax and cancelation_fee_tax (AbstractServiceItems.php:357-389; services.php:3610). For tax-exempt clients the line's tax flag is false (services.php:1847-1856). Coupon discounts become separate negative lines with the same taxable flag (invoices.php:5066-5113), and tax is taken after the discount unless discount_taxes is off (AbstractServiceItems.php:258). Credit is a transaction, never an invoice line, so it is never taxed.
- MyCS: Setup fee and price are taxed alike with the product's rate (LinePricer.php:24-50, a single line via firstPaymentNet). The discount comes off before tax. Negative lines get negative tax (LinePricer.php:57-69). Bought credit is never taxed (LineTax.php:22-25). Domains, late fees and billable items have no kinds or switches yet (orders.md 'Τι μένει').
- Μας αφορά: WHMCS: TaxDomains is on in both. TaxLateFee is on at nixpal, TaxBillableItems on at myip. Taxed lines in the last 12 months: myip Domain 975, DomainRegister 156, DomainTransfer 91; nixpal GroupDiscount 288 and PromoHosting 895, all with taxed=1. No WHMCS setting turns setup-fee tax off, so a setup_fee_tax switch is not needed.
- Πρόταση: When domains and late fees are added, give each line kind a taxable flag driven by settings matching TaxDomains/TaxLateFee/TaxBillableItems. Keep the setup fee taxed like the product. The importer should keep WHMCS's own `taxed` flag per line (myip has one AddFunds line with taxed=1), not recompute it.

**Π5.10 Tax on refunds, credit notes, downgrade credits and partial cancellation** (medium · ίδιο · Δ4)
- Blesta: A refund is a transaction with no tax split. A downgrade credit is an in-house credit transaction for abs(presenter total), i.e. the gross amount (client_services.php:2306, 2559-2580). No credit note per tax rate exists.
- MyCS: A downgrade credit is gross, incl. VAT (orders.md upgrade section). AdjustmentNote stores only a gross amount (app/Models/AdjustmentNote.php:23-37). CancelOpenInvoices credits the gross cents of the cancelled lines (app/Services/Invoice/CancelOpenInvoices.php:167). Refunds become gross adjustment notes. Nothing records net/VAT per rate for what was credited.
- Μας αφορά: Mixed-rate invoices exist (Δ1: myip 22, nixpal 12 in 12 months), and MyCS cancels a service's lines out of a shared invoice. Ekdosi has to issue a πιστωτικό with net and VAT per rate, or VAT is over-declared. Because MyCS is only a proforma, this matters only in what the bridge sends.
- Πρόταση: Link AdjustmentNote to the invoice_items it reverses, or store net/tax per rate on it, so the bridge can send Ekdosi an exact per-rate split. For a gross refund on a mixed-rate invoice, split pro rata by stored line totals with a rounding rule that is tested. Add a money-check that sum(credited tax) ≤ sum(invoice tax).

**Π5.11 Tax on proration and upgrade lines** (low · ίδιο · other)
- Blesta: A service change builds its presenter with the client's current rules (service_changes.php:589). Lines are built from presenter items with taxable = item has taxes (invoices.php:5060-5070).
- MyCS: The upgrade line uses the net difference with the new product's rate (ServiceUpgrade.php:98-108, 188 via toNet). Cycle changes use LinePricer::invoiceLine (ChangeCycle.php:134, 266). When the product changes, open renewal lines take the new product's rate (ServiceUpgradeService.php:120; Service.php:499).
- Μας αφορά: WHMCS Upgrade lines in 12 months: myip 23, nixpal 1, all taxed.
- Πρόταση: No change. In inclusive mode the toNet rounding drift (finding 2) applies to proration too, and the same fix covers it.

**Π5.12 Tax rule edits and the tax name on the invoice** (low · διαφέρει, ισοδύναμο · EU VAT)
- Blesta: Editing a rule deactivates the old row and adds a new one (taxes.php:74-96); deleting only deactivates (taxes.php:103-107). Old lines keep pointing at the old row, so they keep the old rate.
- MyCS: TaxRate rows can be edited in place (app/Models/TaxRate.php). Lines copy the rate (decimal 4), so they are safe. The invoice snapshot keeps one tax_name/tax_rate at decimal 2 (app/Models/InvoiceSnapshot.php:27; Invoice.php:337-355). The totals group by the line rate (Invoice::taxLines, Invoice.php:224-230).
- Μας αφορά: With OSS, or mixed 24%/0% invoices, a single snapshot tax name can be wrong for some lines.
- Πρόταση: Show the tax label per rate from taxLines() (e.g. 'VAT 24%' / 'Reverse charge 0%'), not from the single snapshot name. Raise the snapshot rate precision to 4, or drop it in favour of the line rates.

Ανοιχτά ερωτήματα:

- The task says 'Greek VAT 24% for nixpal', but WHMCS nixpal has TaxEUHomeCountry=EE, 27 per-country EU rules (17-27%) and TaxCode EE…, as docs/decisions.md also states. Which is the home country and VAT regime for nixpal after the cut-over: Estonian OSS or Greek 24%? It decides the rate table, the reverse-charge home country and what Ekdosi files.
- Is 'prices shown incl. VAT' only a display (store net, show gross), or will the catalog itself hold gross prices? The first avoids the 1-cent drift and the exempt-client problem with no new code paths.
- In inclusive mode, should tax-exempt and reverse-charge clients get the VAT deducted (Blesta subtract / WHMCS TaxInclusiveDeduct) or pay the catalog price? Both WHMCS installs have TaxInclusiveDeduct empty today, but they also run exclusive pricing.
- Should a change of tax_exempt or country re-rate open unpaid invoices automatically, or only through a staff 'recalculate tax' action?
- Side note: the read-only `tblconfiguration LIKE '%VAT%'` query also matched some *PrivateKey settings in both WHMCS databases. They were not copied into this output, but future aggregate queries should not use that pattern.

### Π6. EU VAT, VIES, reverse charge, client groups, coupons, currencies

MyCS already has the core tax pieces in better shape than Blesta: VAT is worked out per line in decimal with one half-up rounding (packages/pricing/src/LinePricer.php:59-69), the rate is stored on the line (LineTax.php:21-40), tax-rule lookup always gives the same answer (Settings.php:1001-1003), exemption is per account and taxability per product, and coupon uses are counted under a row lock. What it lacks is EU VAT logic itself (roadmap row 4). The VAT number is checked only for format (EditsContact.php:46), there is no VIES call, no reverse charge, no link between the VAT number and tax_exempt, no home country setting and no reverse-charge note. Blesta's EuropeTax provider shows what to build, and also what to avoid:
- If VIES is down while a client sets a VAT number in the client area, Blesta marks the client tax exempt (fail-open).
- A Greek company as tax home would wrongly exempt Greek businesses (VIES answers EL, the setting is GR).
- A staff-set exempt flag is overwritten whenever the VAT number is saved.
- Nothing is stored as evidence of the check, and numbers are never re-checked.
Client groups do not exist in MyCS; Blesta's groups only carry settings and give no discount. In nixpal's WHMCS, GroupDiscount is in practice one active client (10% group, 288 lines in 12 months). Coupons in MyCS cover nixpal except WHMCS's 'Price Override' type, which is nixpal's two most-used recurring promos (212 and 83 uses). Multi-currency hardly matters: nixpal has 31 active clients, all in EUR, and myip is EUR except one client. WHMCS data also shows nixpal exempts one active client with an Estonian VAT number in its home country (EE), which looks like a WHMCS misconfiguration the importer must not copy.

**Π6.1 VIES unavailable -> client becomes tax exempt (fail-open)** (high · λείπει στο MyCS · EU VAT)
- Blesta: EuropeTax::getTaxInformation (core/Util/Tax/EuropeTax.php:216-272) calls VIES through ph-7 Validator. A SOAP failure becomes an Exception (vendors/ph-7/eu-vat-validator/src/Vat/Provider/Europa.php:35,67), which is caught at :258. Execution then falls through to the default return at :264-271, which reports tax_exempt=true. Clients::setSettings (app/models/clients.php:1514-1536) writes that value. The client-area profile path (client_main.php:460-466 via setClientSettings, clients.php:1412-1505) has no VIES validation rule, so while VIES is down any EU client from another country who types a VAT number is exempted. Admin and client add/edit do validate first (clients.php:2560-2575), so there a VIES outage blocks the save instead.
- MyCS: missing (no VIES at all; tax_exempt is a manual staff flag, app/Models/Client.php:39,46)
- Μας αφορά: nixpal: 8 active EU B2B clients exempt via VIES, 2 EU consumers taxed at their country's rate; VIES outages per member state are common
- Πρόταση: Fail closed. A VIES error or MS_UNAVAILABLE must never grant exemption. Keep a vat_status (unchecked / valid / invalid / pending) separate from the outcome. Pending means VAT is charged and a queued job retries with backoff. If a previously valid number cannot be checked, keep its last known status rather than flipping it either way. Staff can override, with a reason in the audit log. Add a test that simulates VIES down and asserts the client is not exempted.

**Π6.2 Home-country comparison GR vs EL** (medium · λείπει στο MyCS · EU VAT)
- Blesta: getTaxInformation exempts when VIES returns valid and the VIES country code differs from the tax_home_eu_vat setting (EuropeTax.php:242-248). The home-country select lists alpha2 codes from Countries (:100-117), so Greece is 'GR', while VIES answers 'EL' for Greek numbers. With GR as home, every valid Greek business would be auto-exempted. validateTaxId maps GR to EL for the query (:181-189), but the home comparison does not.
- MyCS: missing; the VAT number regex requires a 2-letter prefix (app/Livewire/Traits/EditsContact.php:46,72) with no EL/GR normalisation
- Μας αφορά: myip (Greek, no EU VAT in WHMCS today) if it ever turns EU VAT on; nixpal home is EE, so not affected
- Πρόταση: Normalise in one place: the VAT prefix EL equals country GR. Compare the home country against the client's country, not the raw VIES code. Domestic B2B is never reverse charged. Unit-test GR/EL for both a Greek home company and a foreign one.

**Π6.3 Exemption derived from VAT number vs manual exempt flag** (high · διαφέρει, ισοδύναμο · EU VAT)
- Blesta: Whenever a tax_id is submitted (client add clients.php:221-226, admin edit admin_clients.php:2719-2725, client profile client_main.php:460-466), setSettings recomputes tax_exempt and overwrites whatever staff had set (clients.php:1523-1536). An empty tax_id means 'individual', which resets exempt to false (EuropeTax.php:228-236). A country change made through Contacts (not the client edit form) does not recompute it.
- MyCS: tax_exempt is a manual boolean (Client.php:39). Changing it, or changing the primary contact's country, fires RenewalTaxChanged so Stripe/PayPal subscriptions update their amounts (ClientObserver.php:15-16, Contact.php:76-81). A vat_number change fires nothing, which is correct today because the VAT number has no effect yet.
- Μας αφορά: nixpal: 30 taxexempt clients in total (accounts.md:39), 10 active; some exemptions may be manual (not VIES)
- Πρόταση: Keep two inputs: tax_exempt_manual (staff, with reason) and reverse_charge, derived from vat_status=valid plus an EU country other than home. The effective rate is 0 if either applies. Add vat_number and vat_status to the triggers of RenewalTaxChanged. Recompute on any change of the primary contact's country or VAT number, not only in one form.

**Π6.4 Evidence and periodic re-validation of VAT numbers** (medium · λείπει στο MyCS · EU VAT)
- Blesta: Stores only the tax_id and tax_exempt client settings. No VIES request date, no consultation number (the call passes no requester VAT), no stored name or address, and no cron re-check. Uses the plain http WSDL (Europa.php:19).
- MyCS: missing
- Μας αφορά: Reverse charge needs proof that the number was valid at the time of supply; nixpal invoices monthly (321 monthly services)
- Πρόταση: Store vat_checked_at, vat_status, the VIES name/address and the consultation number (checkVatApprox with the company's own VAT as requester) on the contact or a vat_checks table. Re-check before each renewal invoice, or monthly by cron, and alert staff when a number turns invalid. Reuse Ekdosi's ViesLookup as a framework-free package (packages/vies) with tests, using the https endpoint.

**Π6.5 Reverse-charge note on invoice and bridge payload** (medium · λείπει στο MyCS · EU VAT)
- Blesta: getNotes adds a 'VAT Reverse Charge Mechanism' note when the client is exempt, in the EU, not in the home country, and has a tax_id (EuropeTax.php:335-380).
- MyCS: Invoice prints the VAT number line (app/Models/Invoice.php:377); there is no reverse-charge note and no exemption reason per line
- Μας αφορά: nixpal B2B EU invoices (most of its 26 VAT-bearing invoices per year are the exception: most are reverse charge or outside the EU); Ekdosi needs the reason for myDATA / the real document
- Πρόταση: Store a tax_reason per line (taxed / reverse_charge / export_non_eu / exempt_manual / not_taxable_product) alongside tax_rate when the line is made (LineTax::rateFor). Print the reverse-charge text (el/en lang) on the proforma and send the reason over the bridge so Ekdosi picks the exemption category. Snapshot it on the invoice like tax_name.

**Π6.6 Open invoices when the VAT status changes** (medium · διαφέρει, ισοδύναμο · EU VAT)
- Blesta: Line taxes are attached at creation, filtered by the client's exempt flag at that moment (invoices.php:183-224, addLineTax :1289-1300). Taxes are re-applied from the current client state only when staff edit the invoice (invoices.php:678-726, 2144-2165).
- MyCS: The rate is decided when the line is made and kept; it is recomputed only when the price, quantity or 'taxed' flag changes (LineTax.php:13-40). Open unpaid proformas keep the old rate after an exemption change.
- Μας αφορά: nixpal invoices 7 days before renewal and charges cards automatically on the due date: a client who adds a valid VAT number right after the invoice is created is charged VAT anyway
- Πρόταση: The MyCS invoice is a proforma (the tax document comes from Ekdosi at payment), so re-rate open unpaid proformas with no payment when the effective tax status changes. Leave partly paid or paid ones alone, and write an adjustment note. Cover it with tests (money flow).

**Π6.7 Tax rule lookup and history** (medium · διαφέρει, καλύτερο το MyCS · EU VAT)
- Blesta: Taxes are country/state/level rules (two levels, cascade). Editing a rule deactivates the old one and adds a new one (taxes.php:74-96), so line taxes reference immutable rule ids. getTaxRules GROUPs BY level without deterministic precedence between country+state, country and global rows (invoices.php:6026-6056).
- MyCS: One TaxRate per country with an 'all' fallback, the specific country first (Settings.php:984-1007), cached per request. Lines store tax_rate and tax_amount (Δ1), so editing a TaxRate does not change issued lines. Editing a TaxRate does not fire RenewalTaxChanged, so Stripe/PayPal subscription amounts stay at the old rate.
- Μας αφορά: nixpal: 27 country rules (17–27%); EU rates changed in 2025 (e.g. EE, FI, SK). Mind the 'all' fallback: nixpal must not have one, or non-EU clients get taxed (WHMCS: 20 active non-EU clients, 0% because no rule exists)
- Πρόταση: Keep the MyCS model. Fire RenewalTaxChanged for the services in a country when its TaxRate is created, edited or deleted. Validate that, with EU VAT on, no 'all' rule exists, or that it means non-EU 0%.

**Π6.8 Inclusive pricing for exempt clients** (low · διαφέρει, καλύτερο το Blesta · EU VAT)
- Blesta: The 'inclusive_calculated' rule type is still applied to exempt clients, with subtract=1, so they pay the price minus the tax (invoices.php:209-223).
- MyCS: Inclusive mode: an exempt client has rate null, and toNet returns the gross catalog amount, so the exempt client pays the full inclusive price (OrderPricing.php:109-118). WHMCS has TaxInclusiveDeduct for this.
- Μας αφορά: Low: both companies use Exclusive (decisions.md Δ1; nixpal TaxType=Exclusive)
- Πρόταση: Add a setting only if someone switches to inclusive pricing; otherwise document it as unsupported.

**Π6.9 Client-entered country decides the VAT rate** (low · ίδιο · EU VAT)
- Blesta: The rate comes from the primary contact's country (invoices.php:6031-6045), editable by the client; no second piece of evidence is required.
- MyCS: Same: the primary contact's country (Client.php:163-165, Settings.php:989), editable in the client area
- Μας αφορά: nixpal B2C: 2 active EU consumers charged their country's VAT; 17 non-EU at 0%
- Πρόταση: Keep, but log country changes in the audit trail and show staff a warning when the country changes on an account with active services. Optionally compare with the card's country (Stripe) later.

**Π6.10 Client groups: settings vs discount** (medium · λείπει στο MyCS · EU VAT)
- Blesta: Client groups hold settings that inherit system -> company -> group (client_groups.php:368-452), e.g. per-group renewal invoice days (services.php:508-515), credit limits and notifications. There is no group discount in core.
- MyCS: missing (no client_groups table; planned in accounts.md:265 with discount_percent, separate_invoices, suspend_terminate_exempt)
- Μας αφορά: nixpal: 3 groups (50/10/0%); only one active client, in the 10% group, giving 288 GroupDiscount lines in 12 months, all taxed. myip: 6 groups, no discount, used for separate invoices and suspend exemption (accounts.md:48)
- Πρόταση: A small client_groups table: name, discount_percent, separate_invoices, suspend_terminate_exempt. Apply the discount in OrderPricing as a second Discount after the coupon, before VAT, at checkout and in calculatePrice. Because renewals charge the stored services.price (CronJob.php:654-659), a group change must recalculate the services' price (and fire RenewalTaxChanged/subscription update), or else emit the discount as its own line at renewal. Tests for the order with coupon, group change and cycle change.

**Π6.11 Coupon types: WHMCS 'Price Override'** (medium · λείπει στο MyCS · EU VAT)
- Blesta: Coupon amounts are percent or fixed per currency (coupon_amounts, coupons.php:150-160, 304-344). There is no override type.
- MyCS: Discount TYPES = percentage | fixed, one value for all currencies (packages/pricing/src/Discount.php:14, app/Models/Coupon.php)
- Μας αφορά: nixpal: its two main promos are 'Price Override', recurring (212 uses with recurfor=2, and 83 uses forever); 895 PromoHosting lines in 12 months
- Πρόταση: Add an 'override' type: the net price becomes the value, for the products it covers. Stored services.price already carries the effect for imported services, but new orders with these codes need it. Map tblpromotions.type in the importer, plus tests.

**Π6.12 Recurring coupon semantics and limits** (medium · διαφέρει, καλύτερο το MyCS · EU VAT)
- Blesta: There is a 'recurring' flag plus 'limit_recurring'. With limit_recurring, renewals still respect dates and max_qty and each renewal increments used_qty (services.php:4848-4935, invoices.php:1911-1917). Without it, renewals ignore the limits. Coupons can be restricted by package and by term/period (coupon_terms, coupons.php:223-293), and there is apply_package_options. There is no 'for N cycles'.
- MyCS: recurring = number of cycles (0 = all, empty = the first only; Coupon.php cycles()), counted from a stored start in periods (Service.php:545-581, CronJob.php:635-647). The discount is baked into services.price. No restriction by cycle/plan. calculatePrice counts cycles by paid invoices (Service.php:791-797), while expiry uses cycleNumber by periods.
- Μας αφορά: nixpal promos: recurfor=2 (212 uses), one restricted to some cycles ('cycles' set on 2 promos)
- Πρόταση: Keep the N-cycles model, which matches WHMCS. Add a per-plan or per-cycle restriction (coupon_plans or billing_unit/period) because nixpal uses it. Settle in the importer whether WHMCS recurfor=2 means 2 renewals after the first invoice or 2 in total. Consider computing calculatePrice's cycle from cycleNumber() so the two counters cannot disagree, e.g. for merged invoices with two lines of the same service, which the hasManyThrough count includes twice.

**Π6.13 Coupon usage counting under concurrency** (low · διαφέρει, καλύτερο το MyCS · other)
- Blesta: incrementUsage reads used_qty and writes used_qty+1 with no lock or atomic increment (coupons.php:559-588). Two concurrent orders can both pass max_qty.
- MyCS: PlaceOrderService locks the client row and the coupon row and counts units used with lockForUpdate (PlaceOrderService.php:57-82, 160-182); a use is a unit (ADV-1/2)
- Μας αφορά: nixpal 'once per client' promos; low volume (467 orders per year at myip)
- Πρόταση: Keep. Note that validateCoupon in Classes/Cart.php:174-195 counts services rather than units, so the cart and checkout can disagree (message only; checkout is authoritative).

**Π6.14 Multi-currency: rates, conversion, client currency** (low · διαφέρει, ισοδύναμο · other)
- Blesta: Currencies carry exchange rates, auto-updated by cron with optional padding (currencies.php:800-850). Package pricing can be converted from the default currency when no price exists (packages.php:1203-1250), using float arithmetic rounded to 4 decimals (currencies.php:245-258). The client has a default_currency setting (clients.php:1455-1458), and a service keeps its pricing or override_currency (services.php:1825).
- MyCS: Price rows per currency; no exchange rates or conversion (app/Models/Currency.php). A line without a price in the currency is refused rather than charged free (OrderPricing.php:32-43). The cart currency comes from the session (Classes/Cart.php:49), and a service and its credits keep currency_code. The client has no currency. A fixed coupon's value is applied in any currency.
- Μας αφορά: nixpal 31 active, all EUR (USD defined, 0 clients); myip EUR plus 1 USD client: does not matter now
- Πρόταση: Do not build conversion. Add clients.currency_code, set at the first order or imported from tblclients.currency, so renewals, credit and add-funds use it rather than the session. Restrict fixed and override coupons to the default currency, or give them per-currency values, before a second currency is enabled.

**Π6.15 WHMCS data: home-country client exempted** (medium · διαφέρει, ισοδύναμο · EU VAT)
- Blesta: n/a (Blesta would not exempt a home-country VAT number: EuropeTax.php:245-248)
- MyCS: Importer copies tax_exempt (accounts.md:316 plan)
- Μας αφορά: nixpal WHMCS: TaxEUHomeCountryNoExempt is off and 1 active EE client with a VAT number is taxexempt=1, so domestic B2B is probably being invoiced without VAT today
- Πρόταση: Importer: do not copy taxexempt blindly. Recompute from country plus VAT number, and import a manual exemption only with staff confirmation, through a parity report listing the differences. Ask nixpal's accountant about the EE client.

Ανοιχτά ερωτήματα:

- WHMCS recurfor semantics: does recurfor=2 mean the first invoice plus 2 renewals, or 2 cycles in total? It decides how Coupon.recurring is imported for nixpal's 212-use promo.
- Is the one active Estonian client with taxexempt=1 exempt on purpose (another legal ground) or a result of TaxEUHomeCountryNoExempt being off? This needs nixpal or its accountant.
- Should open unpaid proformas be re-rated automatically when VAT status changes (proposal), or only by a staff action?
- Group discount: fold it into services.price (recalculating on group change) or emit a separate negative line per invoice the way WHMCS does (GroupDiscount lines)? It affects the Ekdosi line mapping.
- Where vat_status and the VIES evidence live: on the contact (the invoice role) or on the account, given the 3b freeze on the invoice recipient.
- Does Ekdosi's ViesLookup already use checkVatApprox with a requester VAT (consultation number), and can it be extracted as a framework-free package?

### Π7. Gateways (common part): autodebit, retries, stored cards, subscriptions

MyCS's payment recording side is ahead of Blesta: RecordPaymentService handles idempotency across invoices, checks amount and currency, moves status forward only, turns over-payment into credit, locks client → invoice in a fixed order, and handles refunds, chargebacks and out-of-order webhooks. Blesta 6.0.3 has some of this (no backward status changes, lock and recheck in Transactions::apply, a currency_matches rule) but it is weaker overall.
The charging side is where MyCS is behind, and it is the part row 6 needs:
(1) MyCS charges a saved method once, at the moment the cron creates the renewal invoice. That is cronjob_invoice days before due: 7 today, 10–20 for nixpal once per-cycle days arrive. There is no retry. WHMCS at both companies charges on the due date (CCProcessDaysBefore=0) and retries every day (CCAttemptOnlyOnce off). Blesta selects every open autodebit invoice that is due or has its autodebit date today, every day, until a failure counter removes the debit account.
(2) The saved method is chosen per service, not per client. That will not survive Δ3 grouped invoices, and it misses invoices made by staff or orders.
(3) Charge calls have no idempotency key and no lock, so a client "pay with saved card" click and the cron charge can double-charge (PAY-3 is still open).
(4) There is no failure counter, no card-expiry notice, and no handling of the legacy PayPal subscr_payment IPN.
WHMCS logs show the retry behaviour matters a lot: nixpal Stripe had 434 Declined vs 272 Success in 12 months, and PayPal had 154 errors (nixpal) and 185 (myip). Most of those are daily re-attempts.

**Π7.1 When a saved method is charged (timing relative to due date)** (high · διαφέρει, καλύτερο το Blesta · row 6 gateways)
- Blesta: Autodebit task (core/Automation/Tasks/Task/Autodebit.php:79-92) calls Invoices::getAllAutodebitableInvoices (app/models/invoices.php:3277-3320) per client group with autodebit_days_before_due; the to_autodebit filter (invoices.php:3958-4030) picks invoices whose date_autodebit is today or, with no date_autodebit, whose date_due <= end of (today + days). Charge is tied to the due date, not to invoice creation.
- MyCS: CronJob::billRenewal charges right after creating the renewal invoice (app/Console/Commands/CronJob.php:702-722), i.e. cronjob_invoice days before due (CronJob.php:144, default 7; settings-map.md:105 plans per-cycle 10/15/20 for nixpal). ChangeCycle.php:235-246 does the same for cycle-change invoices.
- Μας αφορά: Yes. WHMCS at both companies: CCProcessDaysBefore=0 (charge on due date). MyCS would charge nixpal cards 7 days early today and 10-20 days early for longer cycles once per-cycle invoice days land, a visible change for customers and for refunds when they cancel in between.
- Πρόταση: Separate charging from invoice creation: add a daily 'autocharge' cron step that charges open invoices with remaining > 0 whose due_at <= today + cc_process_days_before (new setting, default 0 = WHMCS), and whose client has an active saved method. Leave billRenewal to create the invoice only. Keep card_payment_due reminder logic (PaymentReminders.php) consistent with the new date.

**Π7.2 Retry after a failed charge** (high · λείπει στο MyCS · row 6 gateways)
- Blesta: Because the to_autodebit selection is 'due on or before the window end' with no date_autodebit, an unpaid overdue invoice is selected again every daily run, so a decline is retried daily. Each decline increments client_account.failed_count via Clients::setDebitAccountFailure (app/models/clients.php:1331-1378, called from components/gateway_payments/gateway_payments.php:993-998); once autodebit_attempts (client group setting, client_groups.php:46) is reached the debit account is removed and a client note is written; a success resets the counter (clients.php:1386-1405).
- MyCS: Missing. One attempt only, at invoice creation (CronJob.php:702-722); on failure the client gets invoice_payment_failed and must pay by hand. PaymentReminders::chargedOnItsOwn (app/Services/Invoice/PaymentReminders.php:180-186) explicitly assumes 'charged once'. ROADMAP.md:159 lists 'auto-charge στη λήξη με retries' as open.
- Μας αφορά: Yes, strongly. WHMCS both companies: CCAttemptOnlyOnce off, CCRetryEveryWeekFor 0 => WHMCS retries every day while the invoice is unpaid. nixpal tblgatewaylog last 12 months: Stripe Declined 434 vs Success 272; PayPal Error 154 vs Success 70 (myip PayPal Error 185 vs Success 138). Many of the successes are likely retries of earlier declines (e.g. card topped up). Without retries nixpal would lose those automatic collections.
- Πρόταση: In the new autocharge step: retry daily (or on configurable days, e.g. cc_retry_days='1,2,3,5,7') while the invoice is open and the service not terminated. Store attempts per invoice (count plus last attempt) to cap retries and to avoid charging twice in one day if the cron runs twice. Optionally add a Blesta-style failed_count on the billing agreement that stops auto-charging after N consecutive failures and notifies staff, but do not delete the method (WHMCS keeps it).

**Π7.3 Granularity: which saved method pays which invoice** (high · διαφέρει, καλύτερο το Blesta · Δ3)
- Blesta: One debit account per client (client_account table, cc or ach) plus a per-client 'autodebit' setting and a per-invoice autodebit flag (invoices.php:3990-4005). The Autodebit task groups all due invoices of a client by currency and makes one charge per client per currency, applying amounts per invoice (Autodebit.php:102-131, 186-245).
- MyCS: Saved method chosen per service: services.billing_agreement_id (CronJob.php:703; Livewire/Services/Show.php:120-140; Admin ServiceResource.php:176). Invoices created by staff, order invoices and anything not from billRenewal/ChangeCycle are never auto-charged.
- Μας αφορά: Yes. WHMCS works per client (a default paymethod per client, tblclients.disableautocc; nixpal 52 Stripe + 15 PayPal vault methods, myip 51 + 48 + 4 SEPA). Δ3 (decisions.md:206-218) will group several services into one invoice, and then a per-service agreement is ambiguous (services with different or no agreements on one invoice). The importer (importer.md:31) imports methods per client.
- Πρόταση: Move to a per-client default saved method (client.default_billing_agreement_id) plus a per-client 'auto-charge' switch (WHMCS disableautocc). Keep the per-service override only if staff want it, with the rule 'grouped invoice → client default'. Add a per-invoice 'do not auto-charge' flag for staff (Blesta invoices.autodebit). Charge per invoice, not per client-sum like Blesta: refunds and chargebacks map 1:1 and WHMCS does the same.

**Π7.4 Idempotency and locking of merchant-initiated charges** (high · λείπει στο MyCS · row 6 gateways)
- Blesta: No idempotency key in the core either; processPayment (gateway_payments.php:831-1060) records the transaction after the gateway call. The guard is mainly timing: invoices billed less than 15 minutes ago are not picked (invoices.php:4005), the task runs once a day (Autodebit.php:249-252), and Transactions::apply rechecks amounts under FOR UPDATE locks (app/models/transactions.php around 1165-1200) so a double charge cannot over-apply. The extra money stays as an unapplied credit.
- MyCS: Stripe::charge posts /payment_intents with off_session+confirm and no Idempotency-Key (extensions/Gateways/Stripe/Stripe.php:848-882), although request() supports one (Stripe.php:194-200). PayPal::charge posts /v2/checkout/orders without PayPal-Request-Id (extensions/Gateways/PayPal/PayPal.php:125-145), although request() takes one (PayPal.php:301-312). No lock around 'read remaining, then charge' in ExtensionHelper::charge (app/Helpers/ExtensionHelper.php:457-465) or Livewire Invoices/Show::payWithSavedMethod (app/Livewire/Invoices/Show.php:190-210). A double click, or the client paying while the cron charges, charges twice. RecordPaymentService turns the second into credit (money not lost, but the customer is charged twice). Listed as PAY-3 (ROADMAP.md:136-138).
- Μας αφορά: Yes. Once daily retries exist, the overlap between cron charges and client 'pay now' clicks grows. myip already shows 23 PayPal 'No Amount Due' callbacks in 12 months, i.e. payments for invoices already settled.
- Πρόταση: Before any merchant-initiated charge: take the client → invoice lock (same order as RecordPaymentService), re-read remaining, and record a 'charge attempt' row (invoice, agreement, amount in cents, attempt key). Commit, call the gateway with Idempotency-Key / PayPal-Request-Id = attempt key, and record the outcome. A second request for an invoice with an attempt in progress in the last N minutes is refused. Send amounts in minor units ((int) round(x*100), like refund at Stripe.php:937) instead of $amount * 100 (Stripe.php:207, 862).

**Π7.5 Callback/IPN idempotency and forward-only status** (low · διαφέρει, καλύτερο το MyCS · row 6 gateways)
- Blesta: processNotification (gateway_payments.php:514-800): looks up an existing transaction by gateway transaction id and updates it instead of adding; refuses status regressions via allowsStatusTransition (gateway_payments.php:2435-2452: approved → only refunded/returned/void/declined; void/refunded/returned terminal); applies invoice amounts only if both the notification and the stored transaction are approved, and only the still-unapplied part (getUnappliedAmounts); logs unmatched notifications. PayPal Standard additionally skips a txn_id already approved (paypal_payments_standard.php ~443).
- MyCS: RecordPaymentService (docs/payments.md:15-60): same gateway + transaction id already succeeded on any invoice of the client → no-op; forward-only; locking reads for balance; over-payment → credit; gateway_refunds unique per gateway id for refunds/chargebacks (payments.md:274-327); payment_gateway_events log (app/Services/Money/GatewayLog.php).
- Μας αφορά: Yes. WHMCS logs show duplicate-transaction callbacks ('Transaction ID ... already exists', several per year on nixpal PayPal).
- Πρόταση: Keep. One gap: Blesta allows approved → declined for ACH/SEPA bounces; MyCS forward-only must still accept a late failure for SEPA (Stripe payment_intent.payment_failed after processing) once Stripe SEPA (whmcs-usage: myip 4 SEPA methods) is enabled. Add a test for processing → failed and succeeded → (charge.failed for SEPA) handling before SEPA goes live.

**Π7.6 Amount, currency and receiver checks on callbacks** (medium · διαφέρει, καλύτερο το MyCS · row 6 gateways)
- Blesta: Core trusts the gateway's validate() for amount and currency and stores them on the transaction. Currency is enforced when applying to invoices (Transactions apply rule currency_matches, app/models/transactions.php:1495-1497, validateCurrencyAmounts at :1889). Excess over what the transaction has left is dropped and logged (gateway_payments.php:712-725). PayPal Standard checks business/receiver_email and the IPN postback (paypal_payments_standard.php ~455-510).
- MyCS: Currency checked against the invoice in RecordPaymentService (payments.md:22-26, 41). PayPal IPN checks postback VERIFIED and receiver_email (extensions/Gateways/PayPal_IPN/PayPal_IPN.php:88-106). PayPal REST verifies the webhook signature (PayPal.php:478). PayPal checkout capture checks the order currency (PayPal.php:384-386). Stripe verifies HMAC but with no timestamp tolerance and only one v1 (Stripe.php:885-919, PAY-14 open). Stripe payment_failed passes no currency (Stripe.php:261).
- Μας αφορά: Yes. WHMCS nixpal PayPal Basic shows 'Invalid Receiver Email' 12 and 'IPN Handshake Invalid' 16 in 12 months: these checks fire in practice.
- Πρόταση: Close PAY-14 (5-minute tolerance, accept any v1 in the header) before go-live. Stripe Checkout shares the account with WHMCS during the transition (PAY-10/11), so also ignore payment_intent events whose metadata lacks a MyCS-specific marker (instance prefix) instead of trusting metadata.invoice_id alone (Stripe.php:243-261).

**Π7.7 Failure notifications to client and staff** (medium · διαφέρει, ισοδύναμο · row 6 gateways)
- Blesta: processPayment emails the client template payment_<type>_<status> (e.g. payment_cc_declined) on every attempt when email_receipt is set, which autodebit always sets (gateway_payments.php:1000-1055; Autodebit.php:213-218). The autodebit task only logs failures to the cron log (Autodebit.php:230-242). After autodebit_attempts failures a client note is added (clients.php:1360-1372). PaymentReminders task sends 'auto_debit_pending' N days before charge (PaymentReminders.php:118-140, 333-348). CardExpirationReminders task exists.
- MyCS: invoice_payment_failed to the client, deduplicated per invoice for 1 hour (app/Helpers/NotificationHelper.php:330-350), sent both from the cron catch (CronJob.php:715-720) and from a failed transaction event (app/Listeners/SendMailListener.php:36-66). card_payment_due before due only for gateway subscriptions (PaymentReminders.php:20-21, 180-186). No staff alert for repeated failures; no card-expiry notice.
- Μας αφορά: Yes. WHMCS both companies: CCDaySendExpiryNotices=25 (expiry notices on the 25th). With daily retries, a 1-hour dedup would mail the customer every day.
- Πρόταση: With retries: mail the client on the first failure and then only on chosen days (e.g. first and last attempt before suspension), not every daily retry. Add a staff digest of auto-charge failures (payments channel, MT-3). Add a monthly card-expiry notice for billing_agreements.expiry (field exists in app/Models/BillingAgreement.php) at day 25, like WHMCS. Restore card_payment_due for saved-method invoices once the charge is tied to the due date.

**Π7.8 Stored payment accounts: model, ownership, removal** (low · ίδιο · row 6 gateways)
- Blesta: accounts_cc/accounts_ach with status, reference_id/client_reference_id for off-site vaults (processStoredCc in components/gateways/lib/merchant_cc_offsite.php:176). processPayment verifies the account belongs to the client (gateway_payments.php:862-866) and uses the account's own gateway_id. Removal handled per gateway (removeCc).
- MyCS: billing_agreements (client_id, gateway_id, external_reference, name, type, expiry; soft delete clears services' billing_agreement_id, app/Models/BillingAgreement.php:55-65). Ownership enforced in ExtensionHelper::charge (ExtensionHelper.php:459-462, PAY-8 effectively closed) and ServiceObserver drops the agreement when a service moves account (app/Observers/ServiceObserver.php:45-51). Stripe needs client property stripe_id (Stripe.php:851-855).
- Μας αφορά: Importer: nixpal 52 Stripe + 15 PayPal vault, myip 51 Stripe + 48 PayPal vault + 4 Stripe SEPA (tblpaymethods). importer.md:31 says 13/15 PayPal vault tokens checked.
- Πρόταση: Keep. Update docs: ROADMAP.md:137 still lists PAY-8 as open although the check exists. For import: one default method per client (see the granularity finding). Map WHMCS's 'default' paymethod flag and keep expiry so the expiry notices work from day one.

**Π7.9 Recurring subscriptions (gateway-driven) vs merchant-initiated charges** (medium · διαφέρει, ισοδύναμο · row 6 gateways)
- Blesta: Non-merchant gateways can offer a recurring button (PayPal Standard _xclick-subscriptions, paypal_payments_standard.php:301-360). Recurring IPNs arrive without invoice amounts and become unapplied transactions (credit), which the ApplyPayments cron task applies to open invoices if the client group allows auto-apply (core/Automation/Tasks/Task/ApplyPayments.php:59-75). Nothing ties a subscription to a service.
- MyCS: Gateway subscriptions are tied to the service (services.subscription_id). A PayPal PAYMENT.SALE.COMPLETED is recorded once on the oldest open invoice of the first service and its leftover credit pays the other services, idempotently (payments.md:43-83; PayPal.php:418-470). The renewal is credit-only for subscription services (CronJob.php:729-735). The legacy PayPal_IPN extension only handles one-off payments keyed by item_number (PayPal_IPN.php:98-106): no subscr_payment / subscr_cancel / subscr_eot handling, and it records under the REST 'PayPal' gateway.
- Μας αφορά: Yes for nixpal: 77 services with legacy PayPal subscriptions, 5 active (whmcs-usage.md:75; ROADMAP.md:46-47, 228). WHMCS log shows PayPal Basic Successful 89/yr (nixpal) and 23/yr (myip), plus Subscription Failed/Cancelled/Suspended events. After cut-over a subscr_payment IPN would land on the old WHMCS invoice id in item_number (already paid) and become credit at best, or fail to match.
- Πρόταση: Decide before go-live. Either (a) migrate the 5 active subscriptions to the PayPal vault (cancel the legacy one after the first vault charge), or (b) add a minimal subscr_payment handler: match subscr_id to services.subscription_id (importer must carry it), record once per txn_id, and allocate via the same allocateFromTransaction path. Handle subscr_cancel/subscr_eot by clearing subscription_id and notifying staff. Prefer (a) and keep (b) as a safety net for stray IPNs (log and notify staff, never 500).

**Π7.10 Off-session charge outcome handling (SCA, pending, partial)** (medium · διαφέρει, καλύτερο το Blesta · row 6 gateways)
- Blesta: processPayment records whatever status the gateway returns (approved/declined/error/pending) immediately (gateway_payments.php:965-976) and applies amounts only on approved. Pending is completed later via processNotification with forward-only rules.
- MyCS: Stripe::charge returns true for any non-exception response, without inspecting intent status (Stripe.php:860-881). Recording relies entirely on webhooks payment_intent.succeeded/processing/payment_failed (Stripe.php:241-261). SCA (authentication_required) comes back as an exception → generic 'Could not process payment' with no link for the client to authenticate. PayPal::charge records the capture synchronously and the webhook repeats idempotently (PayPal.php:151-176), which is better.
- Μας αφορά: Probably. Greek/EU cards often need SCA for off-session charges that lack a proper mandate. Many of the 434 nixpal Stripe declines could be authentication_required (unknown, not visible in the aggregates).
- Πρόταση: Stripe charge: record the attempt synchronously from the PaymentIntent response (succeeded → addPayment, processing → addProcessingPayment, requires_action/authentication_required → failed attempt with a distinct reason). Email the client a 'confirm your payment' link to the invoice pay page instead of a generic failure. Do not retry authentication_required daily without the customer present.

**Π7.11 Gateway selection per currency and multi-currency autodebit** (low · ίδιο · row 6 gateways)
- Blesta: Autodebit charges each currency separately and picks the installed merchant gateway for that currency (Autodebit.php:189-210). processPayment prefers the stored account's gateway (gateway_payments.php:900-908).
- MyCS: Charges in the invoice currency with the agreement's gateway (ExtensionHelper.php:457-465). The Stripe currency-unsupported error is mapped to a clear message (Stripe.php:871-876). The minor-unit conversion divides/multiplies by 100 for every currency (payments.md:179, 311).
- Μας αφορά: Low: whmcs-usage.md:96 notes 1 client in USD, no zero-decimal currencies.
- Πρόταση: No change beyond minor-units helper (PAY-16).

Ανοιχτά ερωτήματα:

- What share of nixpal's 434 Stripe declines (12 months) are retries of the same invoice vs distinct invoices, and how many invoices were eventually paid by a retry? An aggregate over tblgatewaylog grouped by invoice would size the value of daily retries. It needs care to avoid printing per-customer data.
- Should nixpal keep WHMCS's 'retry every day until paid/suspended', or move to a bounded schedule (e.g. days 0,1,3,5,7) with a failure cap like Blesta's autodebit_attempts? This is a policy decision for the owner.
- Saved method granularity after Δ3: per-client default (WHMCS/Blesta) only, or per-client default plus optional per-service override?
- Legacy PayPal subscriptions (5 active): migrate to the vault before cut-over, or build a subscr_payment handler? This also depends on whether the importer carries tblhosting.subscriptionid.
- Do any of nixpal's Stripe declines come from SCA (authentication_required)? It is not visible in the WHMCS aggregates; the Stripe dashboard would tell.
- PayPal 'block duplicate invoice ID' preference on the nixpal/myip PayPal accounts: if it is on, daily retries of a vault charge with the bare invoice id (PayPal.php:133) may be rejected after a partial/pending capture. This ties in with PAY-11 prefixing.

