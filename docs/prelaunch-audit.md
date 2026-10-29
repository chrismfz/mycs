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
| 4 | Provisioning | 1 (2026-10-29, `main` @ 1390a389) | 2 (AUD4-1, AUD4-2: του importer) | 0 | #530 (session του importer) | **έτοιμο** για ό,τι φτιάχνει η MyCS· τα AUD4-1/2 στο #530, **πύλη του cut-over** |
| 5 | Παραγγελίες, ΦΠΑ, promotions | 1 (2026-10-29, `main` @ 1390a389) | 1 (AUD5-1) | 0 | #524 | **έτοιμο** (#524, 2 γύροι review) |
| 6 | Domains | 1 (2026-10-29, `main` @ c94aa7bf) | 0 | 1 (AUD6-1) | #526 | **έτοιμο** (#526) |
| 7 | `zlic.php`, bridge | 1 (2026-10-29, `main` @ c94aa7bf) | 1 (AUD7-1) | 0 | #527 | **έτοιμο** (#527) |
| 8 | Ops, ρυθμίσεις | 1 (2026-10-29, `main` @ 279a5a69) | 1 (AUD8-1) | 6 (AUD8-2…7) | #529 | **έτοιμο** (#529) |
| 9 | Importer | 1 (2026-10-29, `main` @ 67ad3c09, import nixpal ×2 και myip σε scratch βάσεις) | 3 (AUD9-1…3) | 0 | #PRNUM | διορθώσεις στο #PRNUM· ό,τι γραφτεί ακόμα (D6 κ.λπ.) ελέγχεται μόνο του |

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

### Ροή 5: σημειώσεις (2026-10-29)
Ελέγχθηκαν: τιμή ξανά στον server υπό lock (`PlaceOrderService`/`OrderPricing`, BigDecimal), `plan_id`, config options,
κρυφές επιλογές, νόμισμα, ποσότητα και stock, κουπόνια υπό lock (ημερομηνίες, προϊόντα, κύκλοι, όρια), προσφορές, ομάδες
πελατών, VIES (fail closed), καλάθι (καμία IDOR), provisioning μόνο μετά από πληρωμή ή αποδοχή, domains στο checkout.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD5-1 | High | Λογαριασμός χωρίς χώρα ταίριαζε μόνο σε συντελεστή «All»· χωρίς τέτοιον, τιμολόγιο **0% χωρίς αιτία** (εγγραφή χωρίς χώρα, λογαριασμός από το καλάθι, χώρα που σβήνεται) | **#524** (απόφαση υπευθύνου): χώρα υποχρεωτική στην εγγραφή και στα στοιχεία· παραγγελία χωρίς έγκυρη χώρα απορρίπτεται· τιμολόγιο για λογαριασμό χωρίς χώρα παίρνει «All», αλλιώς τη νέα ρύθμιση **«Company country»** (υποχρεωτική με φόρο, εκτός αν υπάρχει EU VAT home country· `/health` κόκκινο όσο λείπει), αλλιώς τον μοναδικό συντελεστή. Τα reviews βρήκαν το myip χωρίς home, χώρα που δεν είναι χώρα ('XX', `EuVat::iso('')`), και τη ρύθμιση που μπλόκαρε κάθε save (3 Medium): κλειστά |

Αποφάσεις και σημειώσεις:
- Η χώρα που δηλώνει ο πελάτης **δεν** κλειδώνει (υπεύθυνος, 2026-10-29): μια χώρα εκτός ΕΕ δίνει 0% (`outside_eu`), όπως
  στο WHMCS χωρίς «Locked Client Profile Fields».
- Κουπόνι σταθερού ποσού αφαιρεί το ίδιο νούμερο σε κάθε νόμισμα (δεν μετατρέπεται): μετράει μόνο με δεύτερο νόμισμα.
- **Cut-over:** «Company country» myip = GR, nixpal (ή το `eu_vat_home_country` EE). Ο importer μετρά στα dumps τους
  πελάτες χωρίς χώρα ή με χώρα που δεν αναγνωρίζεται, και περνά τη χώρα από το `EuVat::iso()` (όχι `substr(…, 0, 2)`).
  Προ-συμπλήρωση των συντελεστών της ΕΕ και εβδομαδιαίος έλεγχος: σε δικό του session.

### Ροή 6: σημειώσεις (2026-10-29)
Μόνο τα αλλαγμένα μετά το sweep της 22/10. Ελέγχθηκαν: μαζικές ενέργειες στο portal (μόνο του λογαριασμού, ένα τιμολόγιο
ανά νόμισμα, κανένα διπλό με το cron), μεταφορά σε άλλο πελάτη (`MoveToClient`), G8-5b, `DomainRecordCorrections`,
εισαγωγή τιμών TLD, Registrar Log (χωρίς EPP code), η ροή ανανέωσης. Πέρασαν τα 1.018 tests των domains.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD6-1 | Medium | Αλλαγή κατόχου .gr «με μεταφορά στον νέο κάτοχο»: γινόταν στο Μητρώο ενώ η μεταφορά αρνιόταν, και έμενε «done» με το domain (EPP code, NS) στον πωλητή και κάτοχο τον αγοραστή, χωρίς κανείς να το μάθει | **#526:** η μεταφορά ελέγχεται πριν (`MoveToClient::domainRefusal`, χωρίς εγγραφή): στο αίτημα και ξανά πριν σταλεί (refused, τίποτα στο Μητρώο)· αν αποτύχει αφού έγινε εκεί (ΕΕΤΤ αργότερα), email στο staff |

Low, ανοιχτά: «staff gone» σε αλλαγή με μεταφορά (ακύρωση και νέο αίτημα· ένα πληρωμένο τιμολόγιο θέλει refund ή πίστωση,
ο υπάρχων κανόνας)· προσωρινές αρνήσεις της μεταφοράς γίνονται refused και θέλουν «Send again»· η μεταφορά μέσα από την
αλλαγή κατόχου θέλει μόνο το `admin.domains.owner_change`, όχι και το `move`. **Cut-over:** τα 8 ερωτήματα του sweep της
22/10 μένουν (κυρίως τα domains του CNIC σε AUTORENEW)· η τιμή `domain_gr_owner_change_price`.

### Ροή 7: σημειώσεις (2026-10-29)
Ελέγχθηκαν: LocalKey και md5hash απέναντι στον αυθεντικό κώδικα, input (POST πριν από GET), σειρά ελέγχων status, IP
binding υπό race, rate limit του zlic, HMAC της γέφυρας (`hash_equals` στο raw body, replay idempotent), ops και error
codes, feed (ignore, επαφές), `add_payment`, «issued is final», E5. Πέρασαν τα 295 tests του licensing και του Ekdosi.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD7-1 | High (contract) | Το αυθεντικό `zlic.php` διαβάζει το verify.php με `/<(.*?)>([^<]+)<\/\1>/`: κενό tag δεν ταιριάζει, λείπει από JSON και localkey. Το δικό μας έστελνε `companyname` (ιδιώτες), `billingcycle`, `validdirectory` κενά | **#527:** τα κενά tags φεύγουν πριν από το md5hash και το localkey |

Low, ανοιχτό: τιμή με `<` (π.χ. εταιρεία «A<B»): το WHMCS την αποθηκεύει ως `&lt;` και το verify.php τη στέλνει έτσι· το
MyCS στέλνει `<`. Κανένας client δεν διαβάζει το `companyname`. **Cut-over:** shadow-run με άδεια ιδιώτη και με όνομα με
`&`/`'`· ίδια DNS records του `clients.nixpal.com` (όχι νέο AAAA, αλλιώς οι dual-stack clients έρχονται από IPv6 και παίρνουν
«IP Address Invalid»)· τα ops του plugin `resellers`, `invoiced_flags`, `legacy_invoice_links`, `custom_fields` δεν υπάρχουν
στη MyCS (400 `unknown_op`): να επιβεβαιωθεί ότι το K1 δεν τα καλεί.

### Ροή 8: σημειώσεις (2026-10-29)
Ελέγχθηκαν: save του Settings (μετρητές, secrets, `permission`), cache των settings (κρυπτογραφημένα), `config:cache`,
`BootFailure` (κανένα run με defaults), defaults, `mycs:install`, schedule και lock του cron, `/health`, `build.json`,
guard για APP_DEBUG και dev packages, ERR trap και snapshot (umask 077), migrate πάνω στο squashed schema.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD8-1 | High | Πληρωμή Cardlink στη διάρκεια deploy: το return του vPOS έπαιρνε 503, η τράπεζα είχε χρεώσει, τίποτα δεν καταγραφόταν | **#529:** το `extensions/cardlink/return` περνά το maintenance, όπως το `zlic.php` (τα webhooks των Stripe/PayPal/Mollie ξαναστέλνονται) |
| AUD8-2 | Medium | `app:settings:change … false` άφηνε ένα boolean ανοιχτό (`(bool) 'false'`) | **#529:** `filter_var(FILTER_VALIDATE_BOOLEAN)` |
| AUD8-3 | Medium | `flushCache()` αγνοούσε ένα `Cache::forget` που απέτυχε (αρχείο του root): οι workers και το cron διάβαζαν τις παλιές ρυθμίσεις | **#529:** γράφεται από πάνω με τις τωρινές τιμές· αλλιώς `report()` |
| AUD8-4 | Medium | Το `/health` μετρούσε μόνο τον πίνακα `jobs`: με redis «0 queued, ok» ενώ ο worker ήταν νεκρός | **#529:** διαβάζει την ουρά που χρησιμοποιεί η εφαρμογή· fail όταν δεν απαντά |
| AUD8-5 | Medium | `deploy/update.sh`: ο worker ξανασηκωνόταν στη διάρκεια του maintenance με τον παλιό κώδικα, ως και μία ώρα | **#529:** `queue:restart` και μετά το `up` |
| AUD8-6 | Medium | «✓ Deployed» με exit 0 ενώ το site απαντούσε 5xx· `APP_URL` με εισαγωγικά | **#529:** exit 1 χωρίς 200 στην αρχική· εισαγωγικά έξω· προειδοποίηση για το `/health` |
| AUD8-7 | Medium (doc) | Το rollback του `install.md`: η επαναφορά του snapshot σβήνει ό,τι μπήκε μετά και γυρίζει τους μετρητές | **#529:** «μόνο αμέσως, με το site down· αργότερα διόρθωση προς τα εμπρός» |

**Cut-over (orion):**
- **Το schema dump έχει 136 `DROP TABLE`:** ποτέ `migrate` ή `mycs:install` σε βάση με δεδομένα και **άδειο** `migrations`
  (π.χ. restore που κόπηκε).
- **`.env`:** ρητά `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` (το example και τα defaults είναι redis). Κοινό redis
  χωρίς κωδικό στον orion το διαβάζει κάθε χρήστης του cPanel (sessions, cache με serialized τιμές): file/database, ή redis με
  κωδικό.
- **Worker:** χωρίς systemd ανά χρήστη στον orion: `QUEUE_STOP_CMD`/`QUEUE_START_CMD`, ή το `queue:restart` του script.
- **CageFS:** `mariadb-dump` και `curl` μέσα στο cage, αλλιώς το deploy σταματά στο snapshot.
- **Το cron του WHMCS σβήνει τη μέρα του cut-over** (αλλιώς suspend, terminate, χρεώσεις και emails παράλληλα).
- **Ρυθμίσεις πριν από το πρώτο cron:** από τη σελίδα Settings. `find storage -not -user <χρήστης>` πριν από το cut-over.
- **`cronjob_time`:** όχι 03:00–04:00 (αλλαγή ώρας: δύο runs ή κανένα).
- **Νέα email templates** δεν φτάνουν σε υπάρχοντα instances με το deploy (δεν τρέχει seed): θέλουν migration.
- Αν το vPOS του Cardlink ξαναστέλνει μετά από σφάλμα μένει άγνωστο (`payments.md`): το matching της τράπεζας πιάνει την
  πίστωση στον λογαριασμό.

### Ροή 9: σημειώσεις (2026-10-29)
Ελέγχθηκαν: η ίδια η εντολή (λάθος βάση ή του live site: η πληκτρολογημένη επιβεβαίωση, ο έλεγχος «όχι πάνω στον εαυτό
της» και των πινάκων του WHMCS κρατούν), secrets (ο κωδικός της πηγής μόνο από το περιβάλλον· PSK και 2FA
κρυπτογραφημένα· τα credentials των servers δεν έρχονται), Mass Pay, refunds, το ημερολόγιο των χρημάτων, οι ρυθμίσεις
αυτοματισμού, τα promotions. Import σε scratch βάσεις από τα αντίγραφα `whmcs_copy_nixpal` και `whmcs_copy_myip` (μόνο
ανάγνωση):
- **nixpal** (1′54″): όλα ίδια (πελάτες, υπηρεσίες, τιμολόγια ανά status και ποσό στο λεπτό, πίστωση, tickets 643 = 643).
  Μένουν τα γνωστά: 1 πληρωμή χωρίς τιμολόγιο (−15,80), προμήθειες, 14 domains. Το re-run βγαίνει ίδιο (`migrate:fresh`).
- **myip** (7′18″): οι διαφορές με γνωστή αιτία (`importer.md`). Οι 4 «προς διερεύνηση» εξηγήθηκαν: cancelled +2 = οι 2
  «Fraud»· πληρωμένα −190,00 και ακυρωμένα −107,00 = τιμολόγια με σύνολο στο WHMCS χωρίς γραμμές (και 1 γραμμή taxed χωρίς
  φόρο στο WHMCS)· tickets +12 = merged σε ticket που σβήστηκε.

| ID | Σοβαρότητα | Τι | Κατάσταση |
|---|---|---|---|
| AUD9-1 | High | Ο μετρητής έπαιρνε το μεγαλύτερο **id** (nixpal 7269), όχι τον αριθμό του WHMCS (6860 ο επόμενος): το πρώτο πληρωμένο μετά το cut-over θα ήταν `2026-7270`, ~410 αριθμοί κενό | **#PRNUM:** μετρητής = `SequentialInvoiceNumberValue` − 1, και έρχονται proforma, format και «αριθμός = id» |
| AUD9-2 | High | Το `tblinvoices.duedate` δεν ερχόταν: κανένα τιμολόγιο overdue, καμία υπενθύμιση, ενώ το cron έκανε suspend (myip: 117 απλήρωτα, 14.020,32 €) | **#PRNUM:** `due_at` από το `duedate` |
| AUD9-3 | High (cut-over) | Το cron του WHMCS αφήνει κενό το `suspendreason` (myip: 0 από 123 «Overdue on Payment»): ως κράτηση του staff, η πληρωμή δεν έκανε unsuspend και το cron δεν τερμάτιζε | **#PRNUM** (απόφαση του υπεύθυνου): κενός λόγος + απλήρωτη ανανέωση ληγμένη → suspend για χρέος· αλλιώς κράτηση |

**Cut-over:** σταματά το `schedule:run` όσο τρέχει το import (ένα import που κόπηκε αφήνει μισή βάση, και το cron τρέχει
πάνω της). Στο runbook (`ROADMAP.md` γραμμή 10).
