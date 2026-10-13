# Audit sweep του κληρονομημένου κώδικα (2026-10-13)

> Πλήρης έλεγχος του Paymenter που κάναμε fork, πριν χτίσουμε τα μεγάλα features της Φάσης 1. 8 ανεξάρτητοι reviewers
> (agents), ένας ανά περιοχή, **μόνο ανάγνωση** του `main` @ `b67f57e` (static analysis, χωρίς tests ή βάση). Είχαν
> διαβάσει πρώτα τα `audit-*.md`, `billing-cursor.md`, `payments.md`, `money-trail.md` και το git log, ώστε να μην
> ξαναβγάλουν γνωστά/διορθωμένα. Σύνολο ~165 ευρήματα· εδώ ενοποιημένα (πολλά τα βρήκαν 2–4 reviewers ανεξάρτητα).
>
> **Σημάνσεις:** ✔ = επιβεβαιώθηκε και από τον συντονιστή στον κώδικα· χωρίς σήμανση = «confirmed by reading» από τον
> reviewer· (;) = υποψία, θέλει επιβεβαίωση με test. Κάθε διόρθωση γράφει **πρώτα failing test** (κανόνας του repo για
> ροές χρημάτων). Τα IDs (BIL/PAY/CART/SVC/INV/AUTH/EVT/DATA) παραπέμπουν στις πλήρεις αναφορές στο τέλος.

## Ετυμηγορία

- **Ο πυρήνας που ήδη ξαναγράψαμε στέκει:** δρομέας χρέωσης (PR #32), `RecordPaymentService` και locks (#37, #39),
  `packages/billing-period`, IDOR στο client area. Κανένας reviewer δεν βρήκε σοβαρό πρόβλημα εκεί.
- **Το αδύναμο είναι γύρω από τον πυρήνα:** adapters (gateways, servers) που «πετυχαίνουν» όταν αποτυγχάνουν, κύκλος
  ζωής υπηρεσίας (ακυρώσεις, αναστολή, τερματισμός), emails που φεύγουν ανεξάρτητα από το commit, μαζικά `->update()`
  σε τιμολόγια, cascade deletes, και η πλευρά του staff στα δικαιώματα (admin API, ονόματα permissions).
- **Ένα regression δικό μας** (ΚΡ-1): το `afterCommit` του review round 3 απενεργοποίησε σιωπηλά την ακύρωση συνδρομών
  Stripe/PayPal.
- **Τέσσερις αποφάσεις μοντέλου** (§Δ) πρέπει να παρθούν πριν το EU VAT, το bridge και τον importer, γιατί αλλάζουν τι
  σημαίνουν τα αποθηκευμένα ποσά και οι αριθμοί.

## Κατανομή με το money trail (2026-10-13, μετά το merge του #42)
- **Μένουν στο money trail, όχι στη σταθεροποίηση:** INV-4/PAY-13 (Mark Paid μέσω `RecordPaymentService`) και INV-9
  (adjustment notes append-only) στο MT-1c· PAY-5 (refunds/chargebacks από το dashboard του gateway) στο 2c· Δ5
  (μερική αυτόματη εφαρμογή πίστωσης) στο MT-1c, μόλις αποφασιστεί αν αφορά μόνο νέα ή και ήδη ανοιχτά τιμολόγια.
- **Έγιναν στο #42:** unique `credits(user_id, currency_code)` (τα υπόλοιπα unique του DATA-11 μένουν)· η πίστωση από
  downgrade περνά από το `CreditLedger` (από το AUTH-5/SVC-14 μένουν ο έλεγχος `credits_enabled` και το validation των
  config values).
- **Κανόνας για κάθε διόρθωση σε ροή χρημάτων:** μέσω `RecordPaymentService`/`CreditLedger`, κίνηση στο `money_events`
  μέσω `MoneyTrail`, σειρά locks (υπηρεσία → upgrade →) πελάτης → τιμολόγιο → συναλλαγή → γραμμές → πίστωση (`money-trail.md` §2). Και
  adversarial review (`CLAUDE.md`).

## Πρόοδος (2026-10-13)

Κάθε PR έχει failing tests πρώτα, code review, και όπου αγγίζει χρήματα και adversarial review. Τα tests των reviewers
μένουν στο PR και ο πίνακας ευρημάτων είναι σχόλιο στο PR.

| Θέμα | PR | Τι έκλεισε επιπλέον στα reviews |
|---|---|---|
| ΚΡ-1 | #45 | Η αλλαγή τιμής στο Stripe χρέωνε 1 τεμάχιο αντί για την ποσότητα· συγχρονισμός `expires_at` με το API basil· ένας listener που αποτύγχανε σταματούσε τις ακυρώσεις άλλων υπηρεσιών |
| ΚΡ-2 | #46 | Idempotency key στα refunds· η χρέωση vault καταγράφεται αμέσως· decline/αφαίρεση μεθόδου χωρίς 500· token που λήγει σε workers |
| ΚΡ-3 | #47 | Admin «Delete» δεν αφήνει ορφανό λογαριασμό· `TypeError` στο email «server created» |
| ΚΡ-4 | #48 | Μετρητής: ακέραιος με όριο· πιο χαμηλά μόνο όταν ο επόμενος αριθμός είναι ελεύθερος (ετήσιος μηδενισμός)· ειδοποίηση όταν άλλος άλλαξε κάποιο πεδίο |
| ΚΡ-5 | #49 | (B1–B6 άλλοι δρόμοι στο API: B1 στο #53/#55, τα υπόλοιπα στο Σ5) |
| ΚΡ-6 ✔ | #50 | Το memo είναι ανά χώρα (η αλλαγή χώρας μέσα στο ίδιο request φαίνεται) |
| ΚΡ-7 ✔ | #51 | Migration που σβήνει το παλιό cache μετά το deploy· το AUTH-12 (secrets στη σελίδα για `settings.view`) μένει ανοιχτό |
| ΚΡ-8 ✔ | #57 | — |
| Ακύρωση συνδρομής από staff | #52 | Το Save της παραγγελίας ξαναέγραφε το id· το Stripe ακύρωνε ξένα ids· η αυτόματη ακύρωση για παλιές υπηρεσίες `sub_` χωρίς marker |
| B1 / AUTH-8 / DATA-19 | #53 (MT-1c), #55 | Κανένα paid χωρίς πληρωμή (#53)· whitelist και ιδιοκτησία στο reference των γραμμών, έλεγχος ως ζεύγος, όχι μεταφορά τιμολογίου σε άλλο πελάτη (#55) |
| Δ5 (πίστωση σε ανοιχτά, μερικώς) | #56 (MT-1e) | Πρώτα το παλαιότερο ληξιπρόθεσμο, πριν από ανανεώσεις/αναστολές· συνδρομές μόνο ολόκληρες· επιστροφή πίστωσης όταν το cron ακυρώνει |
| Flaky fuzz | #54 | Το `Collection::random()` δεν επηρεάζεται από το `mt_srand`· κοινό `TestCase::pickOne` |
| SVC-12/13 (end of period) | #61 | Χρήματα gateway που έμεναν σε ακυρωμένο τιμολόγιο· πίστωση σε τιμολόγια που έμειναν ανοιχτά· rollback όλου του βήματος |
| BIL-4/BIL-5 | #63 | Default όπως το WHMCS, οι αυστηρότεροι κανόνες ρυθμίσεις· re-check υπό lock, SEPA, hold, υπηρεσία χωρίς plan, αρνητικές μέρες |
| Automation Settings | #66 | Κλειδί του tab· 0 στη διατήρηση email log το έσβηνε όλο· 0 στην ακύρωση παραγγελιών |
| BIL-9 | #68 | Αναστολή του πελάτη που πλήρωσε στο ίδιο run· `Paid` που χανόταν· refund που θα ανανέωνε· προστασία ανά γραμμή, το πολύ 30 ημέρες |
| SVC-6, BIL-11, R9 | #71 | Πληρωμή που χανόταν όταν το cron ακύρωνε upgrade (απόφαση πριν το lock του τιμολογίου)· upgrade ακυρωμένο ενώ το τιμολόγιό του έμενε πληρωτέο· ειδοποίηση staff για πληρωμένο upgrade νεκρής υπηρεσίας· stock δύο φορές σε ταυτόχρονη ακύρωση· cron που έπεφτε σε υπηρεσία χωρίς plan |
| BIL-6, CR-10 | #73 | Υπηρεσία χωρίς plan δεν αναστέλλεται/τερματίζεται (δεν τιμολογείται)· upgrade μετά από αίτημα ακύρωσης· σελίδες χωρίς plan· migration με ορφανές γραμμές του importer (MariaDB)· Save σε μία συναλλαγή |
| BIL-12, BIL-16 | (αυτό το PR) | — |

**Να μην ξεχαστεί:** Pending Module Actions / Module Queue (Σ1, `ROADMAP.md`), πριν από τα domains.

Τα κρίσιμα έκλεισαν: το **AUTH-12** (η σελίδα Settings έστελνε τα secrets σε όποιον έχει `settings.view`) στο #59.

Από τα 162: περίπου 18 διορθώθηκαν πλήρως, 9 μερικώς· τα reviews έκλεισαν και περίπου 45 bugs εκτός λίστας. Επόμενα κατά σειρά: Σ2 (κύκλος ζωής) ✔, Σ6 (διαγραφές/δεδομένα), Σ5 (δικαιώματα), Σ4 (ειδοποιήσεις), Σ1, Σ3, Σ7–Σ8· οι αποφάσεις Δ1–Δ4 πριν από τα Σ7/Σ8.

## Κρίσιμα: τώρα, μικρά PRs, πριν από οτιδήποτε άλλο

| # | Τι | IDs | Κόστος |
|---|---|---|---|
| ΚΡ-1 ✔ | **Regression (02bd7ed):** το `Service\Updated` στέλνεται σε `DB::afterCommit` ([ServiceObserver.php:78](../app/Observers/ServiceObserver.php)), οι listeners Stripe/PayPal ελέγχουν `isDirty()` που μετά το commit είναι πάντα false. Τερματισμός από cron, αλλαγές staff, renewals μέσα σε transaction → **η συνδρομή δεν ακυρώνεται και το gateway συνεχίζει να χρεώνει**. Διόρθωση: `wasChanged()` / changes μέσα στο event, test από τερματισμό cron. | BIL-2, SVC-5 | S |
| ΚΡ-2 ✔ | **PayPal `request()` χωρίς έλεγχο HTTP status:** `refund()`, `charge()`, `cancelSubscription()` επιστρέφουν πάντα επιτυχία. Refund που απέρριψε το PayPal καταγράφεται (πιστωτικό, `refunded_amount`) χωρίς να γυρίσουν χρήματα· αποτυχημένη χρέωση vault δείχνει «Successfully charged». Επίσης capture σε PENDING γράφεται ως Succeeded. | PAY-1, PAY-7 | S |
| ΚΡ-3 ✔ | **cPanel `createServer` επιστρέφει πάντα `true`** ακόμα κι αν το WHM απάντησε `result: 0`, και κανείς δεν ελέγχει το `false` των άλλων ενεργειών. Υπηρεσία Active χωρίς λογαριασμό, email «δημιουργήθηκε» στον πελάτη. Contract: ο adapter **πετά exception** σε αποτυχία· το `ExtensionHelper` μεταφράζει `false` σε exception. | SVC-1 | S |
| ΚΡ-4 ✔ | **Η σελίδα Settings γυρίζει πίσω τους μετρητές αρίθμησης:** γεμίζει τη φόρμα στο mount και γράφει ό,τι διαφέρει από τη βάση. Ανοιχτή σελίδα + τιμολόγια στο μεταξύ + Save = διπλός αριθμός → στη nixpal (αριθμός στην πληρωμή) **rollback της πληρωμής ενώ το gateway χρέωσε**. Ίδιο για `credit_note_number`. | INV-1 | S |
| ΚΡ-5 ✔ | **Admin API χωρίς PermissionGuard:** `PUT /api/v1/admin/users/{id}` αλλάζει `role_id` (→ `*`), password ή email admin με απλό `users.update`/`roles.update`. Χρειάζεται `outranks` + `canGrant` όπως στο Filament, και tests. | AUTH-1 | S |
| ΚΡ-6 | `Settings::tax()` με `once()` κλειδωμένο σε `spl_object_hash($user)`: σε cron/worker ο επόμενος `User` μπορεί να πάρει τον συντελεστή του προηγούμενου, και αλλαγές χώρας δεν φαίνονται ως το restart. Αδιάφορο για myip (ένας συντελεστής), λάθος ΦΠΑ στη nixpal (EU VAT). | INV-2 | S |
| ΚΡ-7 | Το cache των settings κρατά **αποκρυπτογραφημένα** τα secrets (SMTP/IMAP password, VAPID) σε `storage/framework/cache` (μπαίνει στα backups). Και η σελίδα Settings τα στέλνει στο Livewire state σε όποιον έχει `settings.view`. | EVT-11, AUTH-12 | S |
| ΚΡ-8 | Σιωπηλά `catch` στο boot (`SettingsProvider`, φόρτωμα extensions): ένα blip της βάσης όταν ξεκινά worker → τρέχει με defaults (αναστολή σε 2 ημέρες, credits off) και **χωρίς** τους listeners Stripe/PayPal ως το restart. | EVT-12 | S |

## Πακέτα σταθεροποίησης (Φάση 1a), ένα PR το καθένα

### Σ1. Contract των server adapters και provisioning
Θέμα του ROADMAP «Status υπηρεσίας μόνο μετά την επιτυχία του server job» και «Pending Module Actions», με τα εξής:
- Ο adapter πετά exception (ΚΡ-3)· το status αλλάζει **μόνο** μετά την επιτυχία (σήμερα πριν: `RenewServiceService`, cron, `CancellationCreatedListener`, `placeOnHold`). SVC-2
- Πίνακας `service_module_actions` (ενέργεια, status, attempts, redacted request/response, σφάλμα) = Module Log + Pending Module Actions του WHMCS· `failed()` στα jobs με ειδοποίηση staff· μετρητής στο dashboard. SVC-2, EVT-9
- Idempotent create (αν υπάρχει username, έλεγξε τον server πριν ξαναφτιάξεις)· `CreateJob`/`TerminateJob` ξαναελέγχουν status (retry παλιού Terminate μετά από επανενεργοποίηση σβήνει ζωντανό λογαριασμό). SVC-11, EVT-9
- `retry_after` (90) > job `$timeout` (120)· ρητά HTTP timeouts. SVC-11, EVT-9
- Κουμπιά admin (Create/Suspend/Unsuspend/Terminate) ορίζουν status μετά την επιτυχία· `$record->user` αντί `order->user` (null για υπηρεσίες admin/API)· Change Password / Change Package. Το `mayFail` του `callService` είναι ανάποδα. SVC-8
- `hasFunction` πριν το `UpgradeJob` (DirectAdmin, Plesk, Virtfusion δεν έχουν upgrade). SVC-17
- Διαγραφή server με ενεργά προϊόντα: άρνηση (σήμερα soft delete → σιωπηλά καμία ενέργεια στον server). DATA-6
- Upgrade σε προϊόν άλλου server: μπλοκάρισμα ως τα server groups. SVC-15

### Σ2. Κύκλος ζωής: ακυρώσεις, αναστολή, τερματισμός
- ✔ **Ακύρωση «end of period» δεν εκτελείται ποτέ** (ο listener λέει «handled by the cron», το cron δεν το κάνει): η υπηρεσία αναστέλλεται σαν απλήρωτη (με dunning marker και email αναστολής) και τερματίζεται μήνες μετά. myip ~476/έτος, nixpal ~57. Βήμα cron στη λήξη + ακύρωση ανοιχτών γραμμών ανανέωσης όταν κατατίθεται το αίτημα. BIL-1, SVC-13
- **Ακύρωση/τερματισμός ακυρώνει ολόκληρα τιμολόγια** με μαζικό `update()`: και γραμμές άλλων υπηρεσιών (order invoice, μελλοντικά Mass Pay/ομαδοποίηση), και μερικώς πληρωμένα, χωρίς observers/πιστωτικό. Ανά γραμμή, μέσω observers. BIL-13, SVC-4, INV-14, AUTH-4, CART-11
- Αναστολή και τερματισμός στο **ίδιο run** (π.χ. λήξη `suspend_hold_until`, cron κάτω για μέρες): ελάχιστος χρόνος σε αναστολή. BIL-4 *(#63: ρύθμιση `cronjob_terminate_min_suspended_days`, default 1)*
- Αναστολή χωρίς να υπάρχει ληξιπρόθεσμο τιμολόγιο· ξεχασμένο draft ή χειροκίνητο τιμολόγιο μπλοκάρει για πάντα την ανανέωση (ενώ η αναστολή προχωρά). Αναστολή μόνο με overdue τιμολόγιο + ειδοποίηση staff για «ληγμένη χωρίς τιμολόγιο». BIL-5 *(#63: default όπως WHMCS, από την ημερομηνία λήξης· ο κανόνας του τιμολογίου είναι ρύθμιση `cronjob_suspend_requires_invoice`, default off)*
- Πληρωμένο αλλά μη εφαρμοσμένο (crash μετά το commit, `applied_at` NULL) → ο πελάτης που πλήρωσε αναστέλλεται. Sweep στο cron + κανόνας στο `mycs:money-check`. BIL-9 (επικάλυψη MT-3) *(#68: σημάδι `invoices.apply_pending_since` και βήμα `paid_applied`· MT-3: κανόνας `paid_not_applied` στο `mycs:money-check` και φίλτρο στο admin)*
- Πληρωμές σε εξέλιξη (SEPA/processing) αγνοούνται από αναστολή/τερματισμό. PAY-9
- Εκκρεμή upgrades επιβιώνουν της ακύρωσης· πληρωμή αργότερα κάνει upgrade σε νεκρή υπηρεσία. Το cron των upgrades πειράζει paid/cancelled τιμολόγια και ακυρώνει όταν `expires_at` NULL. SVC-6, BIL-11
- **Upgrade σβήνει όλα τα μη-upgradable config options** της υπηρεσίας (τιμή πέφτει, provisioning τα χάνει). SVC-3
- Auto-cancel εκκρεμών παραγγελιών: βάση `due_at`, όχι `created_at`· όχι όταν υπάρχει πληρωμή ή όταν η υπηρεσία φτιάχτηκε από admin χωρίς τιμολόγιο. BIL-12, CART-11
- Άμεση ακύρωση από πελάτη: `cancellable` και υπάρχον αίτημα δεν ελέγχονται στον server· ρύθμιση «άμεσα / στο επόμενο cron / έγκριση staff». SVC-12, AUTH-4
- Διαγραφή plan/προϊόντος (`nullOnDelete`) σπάει το cron για όλους· `restrict` + archive. BIL-6
- Ένα transaction ανά γραμμή σε όλες τις εργασίες του cron (σήμερα μόνο στις ανανεώσεις)· `withoutOverlapping`· `chunkById` στο auto-close tickets. BIL-10, BIL-16, BIL-17, EVT-15 (επικάλυψη «Χρονοπρογραμματιστής»)
- Stock: `if (stock)` αγνοεί το 0, διπλή επιστροφή, κεντρικό σημείο. CART-17, SVC-18, BIL-22
- Το limit ανά πελάτη μετράει ακυρωμένες υπηρεσίες. CART-18

### Σ3. Gateways: ό,τι δεν κάλυψαν τα reviews του #37
- ΚΡ-2 (PayPal).
- **Idempotency keys** σε κάθε κλήση που κινεί χρήματα (Stripe `Idempotency-Key`, PayPal `PayPal-Request-Id` σταθερό, όχι `uniqid()`)· lock ανά τιμολόγιο στο «πλήρωσε με αποθηκευμένη κάρτα». Σήμερα timeout σε refund + retry = διπλό refund. PAY-3
- Refund επιτρέπεται σε **failed/processing** συναλλαγές (κουμπί και server-side) → φανταστική έκπτωση. PAY-4
- Χρέωση αποθηκευμένης μεθόδου χωρίς έλεγχο ότι ανήκει στον πελάτη του τιμολογίου (μεταφορά υπηρεσίας → χρεώνεται το vault του προηγούμενου). PAY-8
- Νεκρός κώδικας συνδρομών που αντιδρά σε webhooks κοινού λογαριασμού (WHMCS τρέχει παράλληλα στον ίδιο Stripe/PayPal): 500 και retries για μέρες, ή δέσιμο συνδρομής του WHMCS σε υπηρεσία του MyCS γιατί τα ids τιμολογίων είναι ίδια. PAY-10
- Αναφορά προς gateway = σκέτο id τιμολογίου: σύγκρουση με WHMCS στον ίδιο λογαριασμό PayPal και με «block duplicate invoice id». Prefix ανά instance/προσπάθεια. PAY-11
- Χρήματα που δεν ταιριάζουν σε τιμολόγιο: σιωπηλό `return` ή 500 για πάντα → ειδοποίηση staff και καταγραφή («η αλήθεια των χρημάτων νικάει»). PAY-6 (επικάλυψη MT-4)
- Stripe signature χωρίς ανοχή timestamp και μόνο το τελευταίο `v1`. PAY-14
- Fee Stripe σε νόμισμα settlement· float στα `refundable_amount`/`currentBalance`. PAY-16, INV-21

### Σ4. Ειδοποιήσεις ασφαλείς ως προς το transaction
- ✔ Τα emails «νέο τιμολόγιο» και «νέα παραγγελία» στέλνονται με `afterResponse()`, όχι μετά το commit: **rollback → ο πελάτης παίρνει email και PDF για τιμολόγιο που δεν υπάρχει** (και ο αριθμός ξαναδίνεται)· στο cron όλα κρατιούνται ως το τέλος της διεργασίας (crash = κανένα email)· στους workers δεν φεύγουν ποτέ. `DB::afterCommit` + queued listeners. EVT-1, CART-13, INV-18, BIL-23
- PDF και email **μέσα στη ροή πληρωμής**: μια εξαίρεση (π.χ. μορφή αριθμού με `/`) σταματά τα υπόλοιπα after-commit callbacks → πληρωμένο τιμολόγιο χωρίς ανανέωση. PDF μέσα στο mail job, notifications με try/report. EVT-2, INV-19
- Mail χωρίς tries/backoff, `SystemMail` logs μένουν pending, ολόκληρα models (με password hash) στο payload των jobs. EVT-10
- Διπλό «payment failed». EVT-14
- Κάθε αριθμός τιμολογίου κάνει `queue:restart` (ο μετρητής είναι Setting). Μετρητές σε πίνακα `sequences`. EVT-13

### Σ5. Δικαιώματα staff και API
- ΚΡ-5.
- ~38 ονόματα permissions στα policies **δεν υπάρχουν** στο `config/permissions.php` (`*.view`, `audit.viewAny`, `notification_templates.*`): μόνο ο `*` ανοίγει ViewInvoice (με immutable invoices = δεν καταχωρεί πληρωμή), audits, templates. Test που ελέγχει ότι κάθε string υπάρχει. AUTH-2
- Στο Filament **policy χωρίς μέθοδο = allow**: διαγραφή audits (σπάει το append-only του money trail), retry/delete failed jobs (ξανατρέχουν jobs πληρωμών/provisioning). `strictAuthorization()` ή ρητά `false`. AUTH-3
- Upgrade: config values χωρίς validation (τιμή από ξένο προϊόν), η τιμή που βλέπει ≠ αυτή που χρεώνεται, πίστωση downgrade χωρίς `credits_enabled` και ledger. AUTH-5, SVC-14
- Invoice items (API και admin repeater): `reference_type` → `app($class)` για οποιαδήποτε κλάση, γραμμή μπορεί να γίνει `Credit` ή να δείξει σε υπηρεσία άλλου πελάτη· το API αγνοεί τα immutable invoices. AUTH-8, DATA-19, INV-11
- Ο πελάτης βλέπει **draft** τιμολόγια (λίστα, PDF). AUTH-6, INV-13
- Μικρά: φίλτρο «Assigned To» tickets, «Email Verified», null deref στο client area, `per_page` χωρίς όριο, throttle API, sessions μετά από αλλαγή password από admin, log impersonation, back-off στο 2FA. AUTH-14..20

### Σ6. Ακεραιότητα δεδομένων και διαγραφές
- Διαγραφή παραγγελίας → **cascade σβήνει τις υπηρεσίες** χωρίς events και χωρίς terminate· διαγραφή υπηρεσίας (bulk, API, coupon/order relation managers) αφήνει ζωντανό λογαριασμό και γραμμές τιμολογίων που δείχνουν στο κενό (500 στη σελίδα τιμολογίου και στα emails). Μία υπηρεσία διαγραφής, `restrict`, null-safe `reference`. DATA-2, SVC-7, AUTH-9
- Διαγραφή μέλους staff → **σβήνονται όλες οι απαντήσεις του σε tickets** (`cascadeOnDelete`)· διαγραφή πελάτη → τιμολόγια με `user_id NULL` που σκάνε στο `RecordPaymentService`. Staff απενεργοποιείται, δεν σβήνεται. DATA-3, AUTH-7 (επικάλυψη MT-1 §8)
- Αφαίρεση τιμής config option → cascade από όλες τις υπηρεσίες (υποτιμολόγηση). DATA-8 *(restrict + μήνυμα· η διαγραφή ολόκληρου option μένει, με την προειδοποίηση)*
- Soft-deleted billing agreement/gateway με FK που μένει → `TypeError` στο cron, ο πελάτης δεν ειδοποιείται. DATA-9 *(η υπηρεσία αφήνει τη μέθοδο· το cron ειδοποιεί τον πελάτη)*
- Unique στη βάση: `credits(user_id, currency_code)`, `coupons.code`, `service_cancellations.service_id`, `invoice_snapshots.invoice_id`, global `settings.key` (τα NULL δεν είναι μοναδικά στη MySQL). DATA-11 *(credits στο MT-1d· τα υπόλοιπα στο PR του DATA-11/12)*
- `invoice_items.description` varchar(255) → `text` (WHMCS: πολλές γραμμές). DATA-12 *(μαζί με το DATA-11)*
- Διαγραφή νομίσματος αγνοεί τιμολόγια. DATA-16 *(ελέγχει και τα τιμολόγια)*
- **Credentials υπηρεσιών σε καθαρό κείμενο** (DirectAdmin, Convoy) στα properties και αντιγραμμένα στα `audits`· πού θα μπουν κρυπτογραφημένα του importer. Redaction αναδρομικά (secret, token, passwd) στο debug log· TLS verification στο Plesk/Virtualizor. DATA-4, SVC-16, EVT-16
  **Backlog (απόφαση 2026-10-14):** όχι τώρα. Το κλειδί κρυπτογράφησης είναι στο `.env`, που φεύγει με το ίδιο backup, οπότε η κρυπτογράφηση στη βάση προσθέτει λίγα· το staff θέλει να βλέπει και να δίνει τον κωδικό όπως είναι. Ξανά μαζί με τον importer και τον cPanel adapter (Σ1).

### Σ7. Καλάθι και παραγγελία: ένα `PlaceOrderService`
Η τιμολόγηση είναι αντιγραμμένη σε 5 σημεία (`Checkout`, `CartItem`, `Service::calculatePrice`, admin Create Order,
upgrades) που διαφωνούν μεταξύ τους. Ένα service, σε decimal, πάνω σε καθαρά ποσά (βλ. Δ1):
- Κουπόνι με κενό `recurring`: `null == 0` → **μόνιμη έκπτωση** μετά από upgrade/recalculate. CART-1
- Το κουπόνι γράφεται σε **όλες** τις υπηρεσίες της παραγγελίας, και σε όσες δεν αφορά (έκπτωση στο επόμενο recalculate, μετράει στα `max_uses`). CART-2
- Admin «Create Order» παρακάμπτει την τιμολόγηση: χωρίς φόρο σε exclusive, χωρίς setup fee/config options/κουπόνια/stock, 0 € τιμολόγια, και οι υπηρεσίες ακυρώνονται από το cron σε 7 μέρες. CART-4
- `max_uses` ελέγχεται μία φορά ανά checkout (5 υπηρεσίες με κουπόνι μίας χρήσης)· race με snapshot. CART-5
- Δύο ταυτόχρονα checkouts του ίδιου καλαθιού = διπλή παραγγελία. CART-6
- Μπαγιάτικο `$total` → `Error` και ανοιχτό transaction, ή κενό τιμολόγιο. CART-7
- Σταθερό κουπόνι αφαιρείται από μικτό στο καλάθι, από καθαρό στο recalculate, και διπλά με «all»· χωρίς νόμισμα. CART-8
- Config options τύπου quantity (WHMCS optiontype 4) χωρίς τιμή και χωρίς validation (αρνητικά/τεράστια φτάνουν στο provisioning). CART-9
- Νέο checkbox option σπάει υπάρχοντα καλάθια· stale options γράφονται στην υπηρεσία. CART-10
- Το άκυρο κουπόνι δεν αφαιρείται (rollback). CART-19 · Config options σε one-time plan τιμολογούνται από τον φθηνότερο κύκλο (;). CART-20 · Quantity/GET auto-add. CART-21

### Σ8. Το τιμολόγιο ως έγγραφο, και οι επεμβάσεις του staff
- «Mark Paid» = dropdown status χωρίς συναλλαγή (χωρίς ημερομηνία/μέθοδο, `remaining > 0`, και μετά διαγράφεται). Action μέσω `RecordPaymentService` με ημερομηνία πληρωμής (έμβασμα = 42,7% στη myip). INV-4, PAY-13 (επικάλυψη MT-1 «Mark Paid»)
- Αλλαγή πελάτη/νομίσματος σε τιμολόγιο με χρήματα. INV-10
- Επεξεργασία γραμμών σε πληρωμένο τιμολόγιο χωρίς επανέλεγχο status. INV-11 (επικάλυψη PR 2b). **Έκλεισε:** οι γραμμές του paid κλειδώνουν (MT-1c)· το ανοιχτό που καλύπτεται κλείνει με το «Close as paid», το 0 € αυτόματα (2b, `payments.md`)
- Paid → Cancelled επιτρέπεται και γράφει πιστωτικό ίσο με το σύνολο. INV-16 (επικάλυψη 2c). **Έκλεισε (2c-1):** paid ή ανοιχτό με πληρωμές δεν ακυρώνεται· πρώτα επιστροφή, πίσω ή σε πίστωση· status `refunded`
- Adjustment notes: επεξεργάσιμα, διαγράψιμα, αριθμημένα και ορατά στον πελάτη ως «Credit note» (μπερδεύεται με το φορολογικό πιστωτικό του Ekdosi). Append-only, εσωτερικά. INV-9 (επικάλυψη MT-1)
- PDF/σελίδα πελάτη: τα σύνολα δεν αθροίζονται (ΦΠΑ από σύνολο που περιέχει ήδη τις προσαρμογές), λείπουν «Πληρώθηκε/Υπόλοιπο» και η ημερομηνία λήξης. INV-8
- «Issued At» στη φόρμα αγνοείται (εκτός `$fillable`). INV-17
- Route binding «number ή id»: το API μπορεί να ενεργήσει σε άλλο τιμολόγιο όταν αριθμός ≠ id. INV-6, AUTH-19

## Δ. Αποφάσεις μοντέλου πριν από EU VAT, bridge και importer

**Δ1. Καθαρά ποσά και ΦΠΑ ανά γραμμή.** (CART-3, BIL-15, INV-3, DATA-1, INV-12, DATA-7, CART-8, CART-14: το βρήκαν 4
reviewers.) Σήμερα σε exclusive (myip: 24% exclusive) τα `services.price` και `invoice_items.price` αποθηκεύονται
**μικτά**, και ο ΦΠΑ του τιμολογίου βγαίνει ανάποδα από το σύνολο, ένας ανά τιμολόγιο. Συνέπειες:
- αλλαγή συντελεστή, απαλλαγή, reverse charge (VIES) δεν φτάνουν ποτέ στις ανανεώσεις·
- staff που πληκτρολογεί καθαρό (συνήθεια WHMCS) υποτιμολογεί κατά 24%·
- στρογγυλοποίηση από σύνολο ≠ ανά γραμμή (Ekdosi/myDATA δουλεύουν ανά γραμμή)·
- ο ΦΠΑ παλιών τιμολογίων μπορεί να αλλάξει (fallback στον τρέχοντα συντελεστή όταν το snapshot δεν έχει)·
- ο importer πρέπει να μετατρέψει τα καθαρά του WHMCS σε μικτά.

WHMCS: καθαρό ποσό + `taxed` ανά γραμμή, αποθηκευμένα subtotal/tax/total, `TaxPerLineItem`. **Πρόταση:** net + rate +
tax ανά γραμμή, αποθηκευμένα σύνολα, snapshot πάντα (και 0%), `paid_at`. Κόστος L, με migration δεδομένων.

**Δ2. Αρίθμηση.** (INV-5, INV-7, DATA-5, EVT-13, INV-22, AUTH-19.) Το contract «myip: αριθμός = id» **δεν υλοποιείται**:
ο αριθμός είναι ξεχωριστός μετρητής που ξεφεύγει από το id σε κάθε rollback (το auto-increment δεν είναι
transactional), και ο importer είναι κατά ένα λάθος (max+2). Στη nixpal η proforma παίρνει αριθμό ήδη στο
draft → pending (κενά στην αρίθμηση). **Πρόταση:** λειτουργία «number = id» (στο `created`, στο ίδιο transaction)·
proforma αριθμείται μόνο στο paid· μετρητές σε `sequences` (όχι Settings)· να ελεγχθεί αν το `{YEAR}-{NUMBER}` της
nixpal μηδενίζει ανά έτος. Κόστος M.

**Δ3. Ένα τιμολόγιο ανά πελάτη και ημερομηνία· τι σημαίνει «ακύρωση τιμολογίου».** (BIL-8, INV-15, BIL-7.) Το MyCS
βγάζει ένα τιμολόγιο ανά υπηρεσία· το WHMCS ομαδοποιεί ό,τι λήγει την ίδια μέρα (εκτός 3 πελατών myip με «separate
invoices»). Και στο WHMCS η ακύρωση τιμολογίου σταματά τη χρέωση της περιόδου (το `nextinvoicedate` έχει προχωρήσει)·
εδώ το cron το ξαναβγάζει την επόμενη μέρα (myip: 3.154 ακυρωμένα). Αλλάζει τον δρομέα χρέωσης, το Mass Pay και τον
όγκο των παραστατικών στο Ekdosi. Κόστος M–L.

**Δ4. Status.** (SVC-10, DATA-10, CART-12, INV-20.) Υπηρεσίες χωρίς Terminated/Fraud/Completed (ο importer τα κάνει
cancelled/pending, και τα pending τα ακυρώνει το cron)· τιμολόγια χωρίς Refunded/Collections· παραγγελίες **χωρίς
status** (Accept Order = 449/έτος στη myip). Enums με casts + CHECK, και ορισμός πριν τον importer. Κόστος M.
*Πρόοδος (2026-10-13):* τιμολόγια `unpaid` (όχι `pending`) και `refunded`· υπηρεσίες `terminated` ≠ `cancelled`·
guards στους observers για άγνωστα status (`importer.md`). Fraud: όχι status υπηρεσίας (απόφαση 2026-10-13)· η εκτίμηση απάτης ανήκει στην παραγγελία (IP, PTR, ίσως GeoIP/mmdb), μαζί
με το status παραγγελιών. Μένουν το Completed και το status παραγγελιών.

Μικρότερες αποφάσεις:
- **Δ5** Πίστωση: μερική αυτόματη εφαρμογή όπως το WHMCS (σήμερα μόνο αν καλύπτει όλο το τιμολόγιο). BIL-14, PAY-12 → στο MT-1c (βλ. «Κατανομή»).
- **Δ6** Θέση στο schema για: γλώσσα πελάτη (emails/PDF), status πελάτη (Closed δεν κάνει login), admin-only custom fields («δύσκολος πελάτης»), domain/username/IP υπηρεσίας ως στήλες με index, `nextinvoicedate`, line type/taxed, staff vs πελάτες στον ίδιο πίνακα `users`. DATA-15, AUTH-10, AUTH-11, EVT-8, DATA-14
- **Δ7** Ζώνη ώρας: κλείδωμα μετά την εγκατάσταση, ένα σημείο αλήθειας (σήμερα setting πάνω από `APP_TIMEZONE`, σιωπηλό fallback σε UTC). BIL-21, DATA-18, EVT-20
- **Δ8** Ownership helper (`ClientAccess::owns()`) πριν τα νέα features, ώστε το multi-user της Φάσης 3 να μη θέλει ξαναγράψιμο όλων των policies. AUTH-13

## Parity με WHMCS που χρειάζεται η nixpal πριν το go-live
Μπαίνουν στα υπάρχοντα items του ROADMAP:
- **Auto-charge**: σήμερα μία προσπάθεια, 7–20 ημέρες πριν τη λήξη, χωρίς retry, μόνο για τιμολόγια ανανέωσης, χωρίς ειδοποίηση staff. WHMCS: χρέωση στη λήξη + N retries. nixpal: 62% Stripe auto-charge. BIL-3, PAY-2
- **Refunds/chargebacks από το dashboard του gateway** δεν φτάνουν ποτέ (invoice μένει paid). PAY-5 (με 2c/MT-1· επιστροφές από το gateway: 2c-2a· chargebacks: 2c-2b)
- **Υπενθυμίσεις**: καμία πριν την αναστολή· η ρύθμιση `cronjob_invoice_reminder` φαίνεται στο admin αλλά δεν κάνει τίποτα. EVT-5
- **Tickets της nixpal ως το port**: το `FetchEmails` σταματά σε ένα κακό μήνυμα για όλα τα επόμενα, χάνει απαντήσεις (case-sensitive From, log πριν το create)· **καμία ειδοποίηση staff** για νέο ticket ή απάντηση πελάτη· οποιοσδήποτε γράφει σε ticket άλλου με πλαστό From. EVT-3, EVT-6, EVT-4 (σχέδιο token τώρα)
- Templates email που λείπουν (unsuspended, refund, credit, upgrade, admin notifications…)· γλώσσα ανά πελάτη. EVT-7, EVT-8
- **cPanel adapter**: username τυχαίο 16 χαρακτήρων, χωρίς password/ChangePassword, `upgradeServer` αγνοεί το package του config option, χωρίς έλεγχο ύπαρξης· ο importer δεν γράφει `cpanel_username`/domain (όλες οι ενέργειες σε εισηγμένες υπηρεσίες αποτυγχάνουν). SVC-9, SVC-10
- Promotions (κύκλοι, requires, νέοι/υπάρχοντες, νόμισμα, λήξη στο τέλος της ημέρας), retired προϊόντα, setup fee σε ξεχωριστή γραμμή, due date παραγγελίας. CART-15, CART-16, CART-22
- Proration upgrade πάνω στην πραγματική τιμή της υπηρεσίας. SVC-14
- Importer (requirements για την επανεγγραφή): πρώτο μήνυμα ticket, zip/phone, config options ανά υπηρεσία, τιμολόγια πληρωμένα με πίστωση (2.622 myip), `amountout` ως refund, `invoicenum` κενό → id, ORDER BY, charset. DATA-13
- Indexes για τις σαρώσεις του cron. DATA-17
- Mattermost: queued και after-commit (το Discord extension, το πρότυπο, τρέχει σύγχρονα μέσα σε transactions και στέλνει `tfa_secret`). EVT-17
- Verification email σε ήδη verified· new-login email χωρίς opt-out. EVT-18

## Σειρά που προτείνεται
1. **ΚΡ-1..ΚΡ-8** (τα περισσότερα S, ένα ή δύο PRs ανά θέμα, με failing test πρώτα). Το ΚΡ-1 και το ΚΡ-2 αγγίζουν
   χρήματα → και adversarial review.
2. **Αποφάσεις Δ1–Δ4** (συζήτηση, γράφονται στο `decisions.md`), παράλληλα με το MT-x.
3. **Σ1, Σ2, Σ4** (provisioning, κύκλος ζωής, ειδοποιήσεις): είναι το έδαφος για Products/Auto Setup, αλλαγή κύκλου,
   υπενθυμίσεις.
4. **Σ3, Σ5, Σ6** πριν ανοίξουν τα gateways της Φάσης 3 (Cardlink, vault, SEPA) και πριν από multi-user.
5. **Σ7, Σ8** μαζί με την υλοποίηση του Δ1 (όλα πατάνε στο ίδιο μοντέλο τιμής).

## Πλήρεις αναφορές (ανά περιοχή)
Οι αναλυτικές αναφορές των 8 reviewers (σενάριο αποτυχίας, `file:line`, συμπεριφορά WHMCS, διόρθωση, κόστος), όπως
γράφτηκαν, στο `history/audit-sweep-2026-10-13/`: `BIL` (χρέωση/cron), `PAY` (gateways), `CART` (καλάθι/παραγγελίες),
`SVC` (υπηρεσίες/provisioning), `INV` (τιμολόγια), `AUTH` (δικαιώματα), `EVT` (events/emails/tickets/queue), `DATA`
(schema/importer). Τα `file:line` αφορούν το `b67f57e`· όποιος πιάνει ένα εύρημα το ξαναελέγχει στον τρέχοντα κώδικα.
