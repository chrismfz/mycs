# Αποφάσεις ανά περιοχή (2026-10-11)

Η αρίθμηση ακολουθεί τον πίνακα του `ekdosi-vs-paymenter.md`.

## 1. Πελάτες
- Χρειαζόμαστε **επαφές** και **τιμολόγια σε τρίτους** (routing), ώστε να κουμπώσει καθαρά το bridge προς το Ekdosi
  (σήμερα `timologia` + `ThirdPartyStore` του `ekdosi_bridge`).
- Από το Ekdosi: ΑΦΜ lookup ΑΑΔΕ (`AadeRegistryLookup`), VIES (`ViesLookup`), μοντέλο επαφών. Στο Paymenter ο πελάτης
  είναι σήμερα μόνο όνομα/email + custom properties.

## 2. Client portal
- Ίδια λογική με το 1: ο πελάτης βλέπει και τα **φορολογικά παραστατικά** που στέλνει πίσω το Ekdosi (όπως σήμερα το
  `ekdosi_bridge` γράφει MARK/ΤΠΥ πίσω στο WHMCS).
- Οι σελίδες τιμολογίων, καρτέλας και tickets του portal του Ekdosi είναι υποψήφιες να αντικαταστήσουν τις αντίστοιχες
  του Paymenter. Θέλει πρώτα σύγκριση στην οθόνη: το portal του Ekdosi λειτουργεί, αλλά σε «νηπιακό» στάδιο εμφάνισης.

## 5. Επαναλαμβανόμενες υπηρεσίες: ορθότητα
- Ναι, «στρώνουμε» το Paymenter με τη λογική του Ekdosi. Τα κενά του (`audit-billing.md`) είναι ακριβώς όσα λύνει το
  Ekdosi:
  - ανανέωση δεμένη με **τιμολόγιο και περίοδο**: cursor `next_due_date`, `last_renewal_invoice_id`, idempotent
    staging (`StageServiceRenewal`)·
  - μία ανανέωση ανά τιμολόγιο (το «once per invoice» του `DomainRenewalService`)·
  - adopt/prepaid για ανανέωση πριν την πληρωμή.
- Το «on-issue» του Ekdosi εδώ γίνεται πολιτική ανά πελάτη. Default: on-paid (`platform-evaluation.md`).

## 7. Κύκλος ζωής: αλλαγή πακέτου και κύκλου χρέωσης
**Τι έχει το Paymenter [V]:**
- Upgrade/downgrade πακέτου από τον πελάτη, με **proration ανά ημέρα**· το downgrade γίνεται πίστωση
  (`app/Models/ServiceUpgrade.php:48-92`, `app/Livewire/Services/Upgrade.php`).
- **Μόνο μέσα στον ίδιο κύκλο χρέωσης:** `Service::productUpgrades()` κρατάει μόνο plans με ίδιο `billing_unit` και
  `billing_period` (`app/Models/Service.php:204-221`, «Only get the upgrades that have the exact same billing cycle»).
- **Αλλαγή κύκλου (μήνας → τρίμηνο / έτος / διετία) δεν υπάρχει.** Ο admin μπορεί να αλλάξει plan στη φόρμα της
  υπηρεσίας, αλλά χωρίς proration ή πίστωση [G].
- Κάθε πότε χρεώνει: ανά plan (`billing_unit` day/week/month/year × `billing_period`). Τιμολόγιο ανανέωσης
  `cronjob_invoice` ημέρες πριν το `expires_at`.

**Απόφαση (απαραίτητο, γίνεται συχνά: έτος → διετία, μήνας → τρίμηνο κ.λπ.):** γράφουμε **αλλαγή κύκλου χρέωσης** στη **Φάση 1**, με τον ίδιο μηχανισμό: proration του υπολοίπου της τρέχουσας
περιόδου ως πίστωση ή χρέωση, νέο plan από την επόμενη ανανέωση ή αμέσως. Επίσης αλλαγή πακέτου **και** κύκλου μαζί.
Tests για κάθε συνδυασμό.

## 9. Πληρωμές
- «Την φάγαμε παντού»: τα κάνουμε εμείς όπου κι αν πάμε.
- Κρατάμε από το Paymenter: Stripe/PayPal με billing agreements, αυτόματη χρέωση, refunds, με τις διορθώσεις του
  `audit-security.md` (guards στο `addPayment`).
- Από εμάς:
  - Cardlink/IRIS (`lib/cardlink-eurobank`, με τα server-to-server returns)·
  - έμβασμα/τράπεζα·
  - `PaymentAllocator` και προκαταβολές από το Ekdosi·
  - `payments_tracking` (αντιστοίχιση καταθέσεων από IMAP τράπεζας)·
  - PayPal vault (`lib/paypal-vault`), αν δεν μεταφέρονται τα tokens.

## 10. Υπενθυμίσεις
- Όπως στο WHMCS: πριν τη λήξη (π.χ. 10 και 5 ημέρες) και μετά (1η, 2η, τελική).
- Από το Ekdosi: `Services/Reminders/*` + `InvoiceReminder` (στάδια pre_due/first/second/final, review ή auto, πρότυπα
  el/en, opt-out ανά πελάτη, όριο υπολοίπου). Το Paymenter δεν έχει τίποτα (η ρύθμιση `cronjob_invoice_reminder` δεν
  χρησιμοποιείται).

## 11–12. Domains και tickets
- **Παίρνουμε τη λογική από το Ekdosi.**
- **Domains:** contract `DomainRegistrar`, Openprovider, Renewal/Registration/Transfer/Sync/Management, adopt, τα tests
  (~150)· αναζήτηση/whois από το `lib/domain-tools`· ο .gr EPP ως driver.
- **Tickets:**
  - από το Ekdosi: τμήματα με IMAP (και νέα tickets από email), canned replies, εσωτερικά μηνύματα, CC/watchers,
    αξιολόγηση, blocked senders, ειδοποιήσεις staff·
  - αντικαθιστούν το απλό σύστημα του Paymenter (χωρίς canned replies, εσωτερικές σημειώσεις, ειδοποίηση staff).
- **Προσοχή:** και τα δύο είναι δεμένα με `company_id` και με φορολογικό `Invoice`. Το port σημαίνει νέα ροή χρημάτων,
  όχι αντιγραφή αρχείων.

## 16. API / MCP
- **Παίρνουμε το MCP από εμάς** (μοτίβο του `EkdosiMcpServer`: OAuth/Passport, throttle, εργαλεία ανάγνωσης,
  γραφές μόνο ως πρόταση που επιβεβαιώνει ο χειριστής). Το Paymenter έχει ήδη Passport.
- Κρατάμε το Admin REST API του Paymenter, αλλά με **API keys περιορισμένα στο role** αυτού που τα δημιουργεί
  (`audit-security.md` H5).

## 18. Γλώσσες
Κρατάμε τις γλώσσες του Paymenter (24) και **προσθέτουμε ελληνικά** (~15 αρχεία, ~700 γραμμές) + πρότυπα email el/en
(σήμερα μία γλώσσα, χωρίς στήλη locale).

## 21. «Τηλεφωνεί σπίτι»
**Αφαιρούμε όλο** (λίστα με file:line στο `ekdosi-vs-paymenter.md` → «Αφαίρεση»):
- telemetry (ON από προεπιλογή)·
- update checker·
- self-updater `curl | bash`·
- marketplace και έλεγχο ενημερώσεων extensions·
- Gravatar και branding.

## 23. WHMCS / Ekdosi
- **Δικό μας plugin** που στέλνει τιμολόγια, αποδείξεις και παραστατικά σε τρίτους στο Ekdosi, όπως σήμερα το WHMCS
  στα «Εισερχόμενα». Ο κώδικας υπάρχει: `whmcs-plugin/ekdosi_bridge` και `Services/WhmcsInbox/*`, `Services/Billing/Sources/*`
  στο Ekdosi.
- Στο Ekdosi μπαίνει ένα νέο billing source (π.χ. `PaymenterBillingSource`) με το ίδιο HMAC σχήμα και τα ίδια ops
  (HANDOFF §2.2).
- Ο WHMCS importer του Paymenter (demo-grade) **ξαναγράφεται** (`audit-importer-health.md`).

## Περιοχές χωρίς ρητή απόφαση ακόμα
- **3. Κατάλογος:** addons (δεν υπάρχουν· config options checkbox ως υποκατάστατο;)
- **4. Cart/coupons:** κρατάμε ως έχει;
- **6. Provisioning:** κρατάμε τα modules του Paymenter, και το status αλλάζει μόνο μετά την επιτυχία του job.
- **13. Email templates:** sandbox αντί για Blade.
- **14. Ειδοποιήσεις:** κρατάμε Web Push/Discord;
- **15. Admin:** κρατάμε RBAC, audit και impersonation.
- **17. Multi-tenancy:** δύο instances (nixpal, myip) ή multi-company;

## Extensions μόνο ως κώδικας (2026-10-11)
Το upload extension (zip) από το admin αφαιρέθηκε: ανέβαζε PHP που έτρεχε αμέσως στον server (`audit-security.md` H1).
Τα extensions μας είναι δικός μας κώδικας, άρα μπαίνουν όπως ο υπόλοιπος: commit στο repo, review, `deploy/update.sh`.
Η σελίδα «Available Extensions» μένει για εγκατάσταση όσων βρίσκονται ήδη στο `extensions/`.

## Ενημερώσεις μόνο με script, όχι μέσα από την εφαρμογή (2026-10-11)
Δεν υπάρχει updater στο admin. Στο Ekdosi το «by the book» self-update (δικαιώματα αρχείων, composer/npm από τον
χρήστη του web server, maintenance, αποτυχίες στη μέση) κόστισε πολύ για λίγο όφελος, και ένα κουμπί στο web που
τρέχει git/composer έχει τα ίδια ρίσκα με έναν web installer. Ενημέρωση: `deploy/update.sh` από ssh (snapshot βάσης
πριν, χειροκίνητη επαναφορά στο `install.md`). Ούτε `rollback.sh`: προσεκτικές ενημερώσεις + backup.
