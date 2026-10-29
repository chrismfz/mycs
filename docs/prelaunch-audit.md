# Pre-launch audit (από 2026-10-29)

> Ένας ακόμα ανεξάρτητος έλεγχος πριν από την παραγωγή και τον importer, μετά το squash (#510). Ψάχνουμε μόνο
> **μεγάλα ψάρια**: λάθη που κοστίζουν χρήματα, χαλάνε δεδομένα, κάνουν κάτι μη αναστρέψιμο ή ανοίγουν τρύπα ασφαλείας.
> Ο έλεγχος γίνεται **ανά ροή, στον τωρινό κώδικα**, όχι ανά PR: τα παλιά diffs έχουν ξαναγραφτεί, και τα bugs κρύβονται
> συχνά εκεί που ενώνονται δύο PRs που ήταν σωστά το καθένα μόνο του.

## Τι μετράει

| Σοβαρότητα | Παραδείγματα |
|---|---|
| **Critical / High** | Λάθος ποσό· διπλή χρέωση ή πίστωση· πληρωμή που χάνεται· suspend/terminate σε λάθος πελάτη ή υπηρεσία, ή σε υπηρεσία που πληρώθηκε· μη αναστρέψιμη ενέργεια σε server ή registrar που δεν έπρεπε να γίνει· παράκαμψη auth ή δικαιωμάτων (staff ή πελάτης σε ξένα δεδομένα)· διαρροή δεδομένων ή secrets· σπασμένο contract (`zlic.php`, bridge Ekdosi, αρίθμηση)· απώλεια ή αλλοίωση δεδομένων στο import |
| **Medium** | Ροή που τρέχει μόνη της (cron, queue, webhook) και αποτυγχάνει **σιωπηλά**, χωρίς log ή ειδοποίηση στο staff· κάτι που μπλοκάρει και ξεμπλοκάρει μόνο με χειρουργική στη βάση |
| **Εκτός** | Εμφάνιση και καταστάσεις στο UI (π.χ. αν ένα μερικώς πληρωμένο φαίνεται σωστά), ονόματα, στυλ, μικρά κείμενα, θεωρητικά edge cases χωρίς ρεαλιστικό σενάριο |

- Κάθε εύρημα έχει **συγκεκριμένο σενάριο αποτυχίας** (τι κάνει ποιος, με ποια δεδομένα, τι βγαίνει λάθος)· αλλιώς δεν
  μετράει.
- Στις ροές χρημάτων και lifecycle ο reviewer γράφει **failing test** για κάθε Critical/High (`../CLAUDE.md`, «Reviews»).
- **Μια ροή είναι έτοιμη** όταν ένας ανεξάρτητος γύρος δεν βρίσκει κανένα Critical ή High. Τα Medium που μένουν
  γράφονται εδώ και αποφασίζει ο υπεύθυνος.

## Τι έχει γίνει ήδη (δεν ξαναγίνεται)
- **Squash:** ο έλεγχος ισοδυναμίας (βάση από τα παλιά migrations + seed απέναντι σε βάση από το dump + seed, MariaDB και
  SQLite, και η παραγωγή απέναντι στο dump) βγήκε ίδιος: `HANDOFF.md` → «Σχήμα της βάσης».
- **Audit sweep του κληρονομημένου κώδικα** (2026-10-13, `audit-sweep.md`): κάλυψε όλα τα PRs ως το ~#39. Γι' αυτό τα
  PRs #1–#30 χωρίς ίχνος review (το fork, Φάση 0) δεν είναι κενό από μόνα τους.
- **Sweep χρέωσης** (`sweeps/billing.md`), **modules** (`sweeps/modules.md`), **domains** (`domains.md`, «Sweep
  2026-10-22»).

## Η εικόνα των PRs (main στο #512)

506 PRs με merge. Μέτρηση από τα commits του κάθε PR (ένα commit που λέει review/adversarial/`ADV-`) και από τα σχόλια
στο GitHub: είναι ένδειξη, όχι απόδειξη. Ένα PR μετράει σε όσες ροές αγγίζει.

| Ροή | PRs | με review | με adversarial | χωρίς ίχνος review |
|---|---:|---:|---:|---:|
| Cron χρέωσης, κύκλος ζωής υπηρεσίας | 126 | 111 | 73 | 6 |
| Τιμολόγια, πιστώσεις, αρίθμηση | 90 | 78 | 57 | 7 |
| Πληρωμές, gateways, τράπεζες | 65 | 54 | 44 | 7 |
| Auth, δικαιώματα, ασφάλεια | 101 | 83 | 54 | 11 |
| Provisioning (cPanel, mailcow, Proxmox, Centova, SSL) | 64 | 54 | 36 | 6 |
| Domains, registrars | 73 | 69 | 56 | 2 |
| Ekdosi bridge, API, MCP | 46 | 37 | 26 | 2 |
| Importer | 49 | 44 | 33 | 2 |
| Παραγγελίες, καλάθι, ΦΠΑ, promotions | 38 | 31 | 21 | 4 |
| Licensing, `zlic.php` | 10 | 8 | 3 | 1 |
| Mail, tickets, ειδοποιήσεις | 56 | 47 | 30 | 6 |
| Deploy, ops, σχήμα | 157 | 142 | 92 | 8 |
| **Σύνολο** | **506** | **366** | **217** | **100** |

**Μετά το audit sweep, σε επικίνδυνη ροή, χωρίς ίχνος review:** #39 (races στις αγορές πίστωσης και στη διαγραφή
τιμολογίου), #104 (μετονομασία namespace, 89 αρχεία), #152 (κείμενο του email αναστολής), #344 (2FA υποχρεωτικό για
κάθε staff). Τα #39 και #344 θέλουν ματιά μέσα στις ροές τους.

**Μεγάλα PRs σε ροές χρημάτων/lifecycle με review αλλά χωρίς adversarial:** #32 (ανανέωση δεμένη με τιμολόγιο και
περίοδο, ο δρομέας), #99 (υπενθυμίσεις, overdue), #67 (ακυρώσεις ανά γραμμή), #68 (BIL-9), #71 (upgrades και η ζωή της
υπηρεσίας), #63 (αναστολή με ληξιπρόθεσμο), #75 (ένα cron τη φορά), #82 (διαγραφή υπηρεσίας), #78 (status «unpaid»),
#221 (email αναστολών), #66 (Automation Settings). Τα περισσότερα είναι παλιά και έχουν ξαναγραφτεί από επόμενα: γι'
αυτό ελέγχεται η ροή, όχι το diff τους.

### Hotspots: αρχεία με τα περισσότερα «fix» commits
Πού μαζεύτηκαν τα bugs ως τώρα (fix commits / όλα τα commits). Από εδώ ξεκινά ο reviewer κάθε ροής.

| Αρχείο | fix / όλα | Ροή |
|---|---|---|
| `app/Console/Commands/CronJob.php` | 52 / 109 | Cron |
| `app/Models/Service.php` | 29 / 73 | Cron, κύκλος ζωής |
| `app/Classes/Settings.php` | 29 / 81 | Ops (ρυθμίσεις που διαβάζει το cron) |
| `app/Admin/Resources/ServiceResource.php` (+ `EditService.php` 21) | 28 / 58 | Κύκλος ζωής, staff |
| `app/Services/Domains/Registrars/OpenproviderRegistrar.php` | 25 / 37 | Domains |
| `app/Services/Domains/Registrars/GrEppRegistrar.php` | 22 / 40 | Domains |
| `app/Helpers/NotificationHelper.php` | 22 / 43 | Mail |
| `app/Observers/InvoiceObserver.php` | 21 / 42 | Τιμολόγια |
| `extensions/Servers/CPanel/CPanel.php` | 20 / 38 | Provisioning |
| `app/Admin/Resources/InvoiceResource.php` (+ `EditInvoice.php` 14) | 20 / 43 | Τιμολόγια, staff |
| `app/Console/Commands/ImportFromWhmcs.php` | 20 / 53 | Importer |
| `app/Helpers/ExtensionHelper.php` | 20 / 58 | Provisioning, gateways |
| `app/Services/Domains/DomainSyncService.php`, `DomainRenewalService.php` | 20 / 31, 20 / 27 | Domains |
| `app/Services/Billing/RenewalBilling.php` | 15 / 21 | Cron |
| `app/Observers/ServiceObserver.php` | 13 / 27 | Κύκλος ζωής |
| `app/Models/ModuleAction.php`, `ModuleActionResource.php` | 12 / 17, 12 / 18 | Provisioning |
| `extensions/Gateways/PayPal/PayPal.php`, `Stripe/Stripe.php` | 11 / 34, 7 / 31 | Πληρωμές |
| `app/Services/Mcp/McpOauth.php`, `McpOauthController.php` | 10 / 12, 8 / 10 | Auth (MCP) |
| `app/Services/Ekdosi/EkdosiFeed.php` | 9 / 12 | Bridge |

## Σειρά ελέγχου
Μία ροή ανά session, με τη σειρά, όσο φτάνουν τα limits. Ο reviewer δεν έχει το context του συγγραφέα: παίρνει το brief
παρακάτω, αυτό το doc, τα docs της ροής και τον κώδικα στο `main`.

| # | Ροή | Τι ψάχνουμε (μεγάλα ψάρια) | Από πού | Docs |
|---|---|---|---|---|
| 1 | **Cron χρέωσης και κύκλος ζωής** | Διπλό τιμολόγιο ανανέωσης· περίοδος που χάνεται ή χρεώνεται δύο φορές· suspend/terminate πελάτη που πλήρωσε ή σε hold· terminate χωρίς να σταματήσει η συνδρομή στο gateway· δύο cron μαζί· ένα σφάλμα σε μία υπηρεσία που σταματά όλο το run· upgrade/downgrade και ακυρώσεις σε σύγκρουση με πληρωμή | `CronJob.php`, `RenewalBilling.php`, `Service.php`, `ServiceObserver.php`, `CancelOpenInvoices.php`, `ServiceUpgradeService.php`, `RenewServiceService.php`, `CancellationCreatedListener.php` | `billing-cursor.md`, `sweeps/billing.md`, `money-trail.md` |
| 2 | **Πληρωμές, πιστώσεις, τιμολόγια** | Webhook/IPN χωρίς σωστό έλεγχο υπογραφής ή με replay· διπλή χρέωση κάρτας ή vault· refund που καταγράφεται ενώ απέτυχε· πληρωμή σε λάθος τιμολόγιο ή πελάτη· πίστωση που γεννιέται ή χάνεται· matching τράπεζας που εφαρμόζει μόνο του σε λάθος τιμολόγιο· `add_payment` του Ekdosi· αλλαγές γραμμών από το staff σε πληρωμένο· σειρά locks | `RecordPaymentService`, `CreditLedger`, `MoneyTrail`, `extensions/Gateways/*`, `InvoiceObserver.php`, `InvoiceResource` + `EditInvoice`, `app/Services/Invoice`, payments tracking | `payments.md`, `payments-tracking.md`, `money-trail.md`, `eu-vat.md` |
| 3 | **Auth, δικαιώματα, εξωτερική επιφάνεια** | Πελάτης σε ξένα δεδομένα (IDOR) στο portal και στο API· staff που κάνει ό,τι δεν του επιτρέπεται, ή δίνει σε άλλους ό,τι δεν έχει· MCP OAuth και proposals· SSO προς cPanel/mailcow/WHM· κονσόλα noVNC (relay)· uploads· templates του staff (όχι Blade)· endpoints χωρίς throttle· secrets σε logs, cache, Livewire state | `app/Http/Middleware`, `config/permissions.php`, `Policies`, `app/Livewire/Auth`, `McpOauth`, `Proposals`, `TemplateRenderer`, `routes/*.php`, `Redact.php`, `StaffSessions.php` | `history/audit-security.md`, `mcp.md`, `decisions.md` |
| 4 | **Provisioning και μη αναστρέψιμες ενέργειες** | Terminate/delete σε λάθος λογαριασμό, VM ή mail domain· ενέργεια που «πετυχαίνει» ενώ ο server απέτυχε· retry του ModuleAction που ξανατρέχει μια καταστροφική ενέργεια· create δύο φορές· suspend που δεν γίνεται και κανείς δεν το ξέρει | `ExtensionHelper.php`, `ModuleAction`, `Jobs/Server`, `extensions/Servers/*`, `packages/proxmox`, `packages/mailcow`, `GuestLifecycle.php` | `provisioning.md`, `proxmox.md`, `sweeps/modules.md` |
| 5 | **Παραγγελίες, καλάθι, ΦΠΑ, promotions** | Λάθος τιμή ή ΦΠΑ στο τιμολόγιο της παραγγελίας· κουπόνι/promotion/ομάδα πελάτη που δίνει περισσότερα ή άπειρα· VIES που ανοίγει reverse charge χωρίς έλεγχο· παραγγελία που ξεκινά provisioning χωρίς έγκριση/πληρωμή | `app/Classes/Cart.php`, `app/Livewire/Cart.php`, `PlaceOrderService.php`, `packages/pricing`, `packages/vies` | `orders.md`, `eu-vat.md` |
| 6 | **Domains και registrars** | Ανανέωση/μεταφορά που χρεώνει το υπόλοιπο στον registrar χωρίς πληρωμή· διπλή ανανέωση· auto-renew που δεν έπρεπε· λάθος κάτοχος/επαφές προς το μητρώο. Ήδη έγινε sweep (2026-10-22): εδώ ένας γύρος στα αλλαγμένα από τότε | `DomainRenewalService.php`, `DomainSyncService.php`, `OpenproviderRegistrar.php`, `GrEppRegistrar.php`, CNIC | `domains.md`, `domains/grepp.md` |
| 7 | **Contracts: `zlic.php` και bridge Ekdosi** | `md5hash`/`localkey` byte-for-byte απέναντι στα fixtures· rate limit που κόβει νόμιμους· HMAC (timing-safe σύγκριση, replay, ίδια ops και error codes)· feed που στέλνει ό,τι δεν πρέπει | `public/zlic.php`, `packages/nixpal-licensing`, `app/Services/Licensing`, `app/Services/Ekdosi`, `/api/ekdosi` | `licensing.md`, `ekdosi-bridge.md` |
| 8 | **Ops και ρυθμίσεις** | `Settings.php` και `SettingsProvider`: defaults που ενεργοποιούν κάτι επικίνδυνο, cache με secrets, worker που τρέχει με λάθος ρυθμίσεις· `deploy/update.sh` (αποτυχία που μοιάζει με επιτυχία, βλ. MariaDB OOM)· `/health` που λέει «εντάξει» ενώ το cron ή η ουρά έχουν σταματήσει | `app/Classes/Settings.php`, `SettingsProvider.php`, `deploy/`, `app/Services/Ops` | `ops.md`, `install.md` |
| 9 | **Importer** | Όταν τελειώσει (`ROADMAP.md` γραμμή 9): απώλεια ή διπλά στο re-run, ποσά και υπόλοιπα που δεν βγαίνουν ίδια με το WHMCS, αρίθμηση, κωδικοί/secrets. Εκτός από review, **dry-run σε αντίγραφο και `mycs:parity`** | `ImportFromWhmcs.php`, `app/Services/Import` | `importer.md` |

Mail/tickets και reports μένουν έξω: κυρίως ανάγνωση και εμφάνιση. Μπαίνουν μόνο ό,τι στέλνουν σε πελάτη (μέσα στις ροές
1–2) και η αποθήκευση των εισερχόμενων emails (ροή 3).

## Brief για κάθε reviewer
> Είσαι ανεξάρτητος reviewer του MyCS (Laravel 13, Filament 5) πριν την παραγωγή. Ροή: **‹όνομα›**. Διάβασε
> `CLAUDE.md`, `docs/prelaunch-audit.md` (κριτήρια, «Τι μετράει») και τα docs της ροής, και μετά τον κώδικα στο `main`,
> ξεκινώντας από τα αρχεία της γραμμής της. Δούλεψε σε δικό σου clone (ποτέ στο `/var/www/mycs`· `bin/test`).
> Ψάχνεις **μόνο Critical/High/Medium** όπως ορίζονται εκεί· όχι UI, στυλ, ονόματα, θεωρητικά edge cases. Για κάθε
> εύρημα: σοβαρότητα, αρχείο:γραμμή, **συγκεκριμένο σενάριο αποτυχίας**, και failing test όπου είναι χρήματα ή
> lifecycle (σε branch `adv/audit-‹ροή›`). Μην διορθώνεις. Αν δεν βρεις Critical/High, πες το καθαρά: είναι αποδεκτό
> αποτέλεσμα. Επέστρεψε πίνακα ευρημάτων.

Μετά: οι διορθώσεις σε ένα PR ανά ροή (με τα tests του reviewer, `../CLAUDE.md` → «Reviews»), και ένας ακόμα γύρος μόνο
αν ο πρώτος βρήκε Critical/High.

## Πρόοδος

| # | Ροή | Γύρος | Critical/High | Medium | PR διορθώσεων | Κατάσταση |
|---|---|---|---|---|---|---|
| 1 | Cron χρέωσης, κύκλος ζωής | 1 (2026-10-29, `main` @ e298c168) | 0 | 0 | — | **έτοιμο** |
| 2 | Πληρωμές, πιστώσεις, τιμολόγια | 1 (2026-10-29, `main` @ e298c168) | 0 | 1 (AUD2-1, Mollie) | #517 | **έτοιμο** (το AUD2-1 στο #517) |
| 3 | Auth, δικαιώματα, επιφάνεια | 1 (2026-10-29, `main` @ 147b9496) | 1 (AUD3-1) | 1 (AUD3-2) | #519 | **έτοιμο** (#519, 3 γύροι review) |
| 4 | Provisioning | 1 (2026-10-29, `main` @ 1390a389) | 2 (AUD4-1, AUD4-2: του importer) | 0 | στο session του importer | **έτοιμο** για ό,τι φτιάχνει η MyCS· **πύλη του cut-over** τα AUD4-1/2 |
| 5 | Παραγγελίες, ΦΠΑ, promotions | | | | | ανοιχτό |
| 6 | Domains | | | | | ανοιχτό |
| 7 | `zlic.php`, bridge | | | | | ανοιχτό |
| 8 | Ops, ρυθμίσεις | | | | | ανοιχτό |
| 9 | Importer | | | | | όταν τελειώσει ο importer |

### Ροή 1: σημειώσεις (2026-10-29)
Καθαρός γύρος: κανένα Critical/High/Medium. Ελέγχθηκαν διπλό τιμολόγιο και περίοδος δύο φορές (`RenewalBilling`, lock
της υπηρεσίας), χαμένη περίοδος (`applied_at`, οι 30 ημέρες προστασίας), suspend/terminate πελάτη που πλήρωσε ή έχει
hold (`suspendable()`/`terminable()` ξανά υπό lock), δύο cron μαζί, μία υπηρεσία που σταματά το run, upgrade/ακύρωση
απέναντι σε πληρωμή, σιωπηλές αποτυχίες. Πέρασαν τα 60 tests του cron (Cronjob, SuspensionRules, RenewalGrouping,
BillingCursor). Για αργότερα, όχι ευρήματα:
- **Πύλη για τον importer (ροή 9):** ο importer δεν φέρνει ακόμα τις ρυθμίσεις αυτοματισμού (`importer.md` γραμμή 39
  τις έχει στο σχέδιο). Στο myip το `AutoTerminationDays` είναι 120 απέναντι στο default 60, και οι suspended υπηρεσίες
  που έρχονται παίρνουν το marker αναστολής με `now()`: χωρίς τη ρύθμιση, από τη δεύτερη νύχτα το cron θα τερμάτιζε
  υπηρεσίες που το WHMCS κρατούσε. **`cronjob_order_terminate` = 120 στο myip πριν από το πρώτο cron.** Στη nixpal 60,
  ίδιο με το default.
- Ο listener του Stripe καταπίνει τα σφάλματα του `cancelSubscription`/`updateSubscription` χωρίς `report()`
  (`extensions/Gateways/Stripe/Stripe.php:72-84`). Δεν μετράει σήμερα: η MyCS δεν φτιάχνει συνδρομές και ο importer δεν
  φέρνει `subscriptionid`. Μετράει μόνο αν μπει `subscription_id` με το χέρι.
- Ο βρόχος `domain_invoices_cancelled` δεν έχει try ανά domain: μια αποτυχία σταματά τον βρόχο, αλλά αναφέρεται.

### Ροή 2: σημειώσεις (2026-10-29)
Κανένα Critical/High. Πέρασαν 1.518 tests της ροής (ένα αποτυγχάνει από την ημερομηνία, βλ. κάτω). Ελέγχθηκαν:
`RecordPaymentService` (σειρά locks, idempotency ανά πελάτη, cents, υπερπληρωμή), `CreditLedger`/auto-apply/Mass Pay,
οι υπογραφές των webhooks (Stripe HMAC με όριο 300 s, PayPal μέσω API, Mollie ξαναδιαβάζει, digest του Cardlink, IPN με
receiver), διπλή χρέωση vault (`SavedMethodCharge`, idempotency key), refunds/chargebacks, γραμμές σε πληρωμένο
τιμολόγιο, matching τράπεζας (DKIM/DMARC, ακριβώς ένα τιμολόγιο με ίδιο υπόλοιπο), `add_payment` του Ekdosi.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD2-1 | Medium, μόνο με το Mollie ανοιχτό | `extensions/Gateways/Mollie/Mollie.php` `refund()`/`request()`: χωρίς idempotency key, και το timeout μοιάζει με άρνηση. Το staff βλέπει «refund failed» ενώ το Mollie επέστρεψε, ξαναπατά, και ο πελάτης παίρνει δύο φορές. Το Stripe και το PayPal το έχουν λύσει | **διορθώθηκε στο #517** (3 γύροι review: το Mollie κρατά το key μόνο μία ώρα, άρα πριν από κάθε refund η MyCS διαβάζει τα refunds του Mollie και αρνείται αν κάποιο δεν είναι καταγεγραμμένο με το id του). Το Mollie μένει εναλλακτικό gateway |

Σημειώσεις:
- **Test που εξαρτάται από την ημερομηνία:** `tests/Feature/CreditAutoApplyAdversarialTest.php:180` περιμένει
  `addMonth()` (31/10 → 1/12) ενώ ο κώδικας δίνει σωστά 30/11. Αποτυγχάνει στις 29–31 κάθε μήνα πριν από μικρότερο
  μήνα, και στο CI. Λάθος του test, όχι της χρέωσης. **Διορθώθηκε στο #514.**
- **Cut-over:** το IPN (PayPal Standard) της MyCS καταγράφει πληρωμή με το `item_number` του URL, χωρίς σήμανση ότι
  τη ξεκινήσαμε εμείς. Ασφαλές όσο το IPN URL της MyCS **δεν** μπαίνει ως IPN URL στο προφίλ του λογαριασμού PayPal και
  οι πληρωμές της εποχής WHMCS πάνε στο legacy URL. Έλεγχος της ρύθμισης του PayPal τη μέρα του cut-over.

### Ροή 3: σημειώσεις (2026-10-29)
Ελέγχθηκαν: IDOR σε όλα τα Livewire του portal, πληρωμές από το portal, SSO, κονσόλα noVNC (ticket 30 s), MCP OAuth
(PKCE, rotation, consent με 2FA), admin API (allowlist, δικαιώματα του δημιουργού), `PermissionGuard`, login/2FA/
recovery codes, password reset, social login, invites, impersonation και συνεδρίες staff, uploads, templates (όχι Blade),
`Redact`, κλειδιά SSL, open redirect.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD3-1 | High | Ένα staff με μόνο «Update Clients» έδινε σε μια δική του σύνδεση πελάτη όλα τα δικαιώματα σε οποιονδήποτε λογαριασμό: SSO στο panel, κονσόλα, διαχείριση υπηρεσιών | **#519:** ένα staff δίνει σε μια σύνδεση μόνο ό,τι καλύπτει ο ρόλος του στο admin (`Actor::staffMayGrant`, `GRANT_PERMISSION_AS`), στην καρτέλα Users και στη σελίδα Users του client area. Τα reviews βρήκαν και το client area (High), τα read/`managedomains` χωρίς δικαίωμα (High, Medium), invoices/SSL keys/ανανεώσεις domains (3 Medium): όλα κλειστά |
| AUD3-2 | Medium (απόφαση υπευθύνου) | Το custom code του client area τρέχει στο origin του admin· τα trusted proxies αποφασίζουν το IP σε κάθε έλεγχο | **#519:** μόνο full admin (`'*'`)· το `admin.settings.custom_code` καταργήθηκε |

Low, ανοιχτά (δεν διορθώνονται τώρα):
- Bulk delete των API keys ελέγχει μόνο το `deleteAny`, όχι το ανά key `canGrantAllApi` (διακοπή, όχι κλιμάκωση).
- System Cleanup σβήνει audits staff/ρόλων/keys χωρίς ελάχιστη ηλικία.
- `StaffResource`: όποιος έχει `staff.update` αλλάζει το δικό του email/κωδικό χωρίς τον τρέχοντα, και κάνει reset στο δικό του 2FA.
- `Domains/Show.php:93`: τα emails των επαφών με βάση τον τρέχοντα λογαριασμό (θέλει login με grants σε δύο λογαριασμούς).
- Τα tickets: μια σύνδεση με `tickets` ανοίγει/απαντά tickets, όπως και το «Login as client»· τίποτα παραπάνω.

**Cut-over:** τα trusted proxies στον orion μόνο το IP του server και `127.0.0.1`. Οι ρόλοι του staff να ξαναδούν τα
δικαιώματα που δίνουν (τώρα το «δίνω σε μια σύνδεση» ακολουθεί το admin).

### Ροή 4: σημειώσεις (2026-10-29)
Για υπηρεσίες που φτιάχνει η MyCS, καθαρός γύρος. Ελέγχθηκαν: `ExtensionHelper::serverAction` (το `false` γίνεται
exception, server που σβήστηκε δεν είναι «τίποτα να γίνει»), ModuleAction Retry/Mark resolved (ίδια job, ίδιοι έλεγχοι,
terminate μόνο σε υπηρεσία που έληξε, create υπό lock), jobs και `ServiceObserver` (ξαναδιαβάζουν την κατάσταση,
`after_commit`), cPanel (idempotent create, προστατευμένοι λογαριασμοί, `terminatereseller` με πληκτρολόγηση του
username), Proxmox `GuestLifecycle` (pool, templates, MACs, VMID που ξαναχρησιμοποιήθηκε), mailcow (`ownedAdmin`,
`lock_only`), Centova (`disable_only`), SSL, `ServerSelector`, secrets στο `last_error` και στα emails. Τα modules που
καμία εταιρεία δεν χρησιμοποιεί (Convoy, Virtualizor, Pterodactyl, Enhance, DirectAdmin, Plesk, Virtfusion) δεν
ελέγχθηκαν σε βάθος.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD4-1 | High | Ο importer δεν φέρνει το `tblhosting.server` στο `services.server_id` (το `importer.md` λέει ότι το φέρνει): κάθε υπηρεσία που έρχεται παίρνει τον server του προϊόντος. Στο myip (6 cPanel servers) ένα terminate πάει σε λάθος server και σβήνει λογαριασμό άλλου πελάτη με το ίδιο username· και το `provisioningServer()` πέφτει στον server του προϊόντος όταν ο server της υπηρεσίας λείπει | στο session του importer, με τα tests του `adv/audit-flow4` (15c91aa2). **Κανένα cron και κανένα Retry σε υπηρεσίες που εισήχθησαν πριν κλείσει** |
| AUD4-2 | High (cut-over) | Σταθμός Centova ή λογαριασμός mailcow που τον έκανε suspend το WHMCS έρχεται χωρίς το mark μας: στην πληρωμή το unsuspend λέει «έγινε» χωρίς να καλέσει τον server, ο πελάτης παίρνει email, η υπηρεσία μένει κλειστή | στο session του importer (ο importer βάζει τα marks). Πριν το cut-over: λίστα των suspended Centova/mailcow |

Όχι εύρημα: αλλαγή προϊόντος από το staff σε άλλο module (`keepServerOfProductsModule`) αφήνει τον παλιό λογαριασμό στον
παλιό server· είναι ενέργεια του staff και φαίνεται.
