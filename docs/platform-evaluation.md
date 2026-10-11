# Αξιολόγηση βάσης: Blesta 6.0.3 vs Paymenter (2026-10-10)

> Ανάγνωση κώδικα, τίποτα δεν εκτελέστηκε. Paymenter στο `5b69d45` (v1.5.8+41, master, Laravel 13, Filament 5,
> Livewire 4). Blesta: το security report της 2026-10-09 (εκτός repo) και η δουλειά του repo.
> Σημάνσεις: **[V]** ελέγχθηκε στον κώδικα · **[D]** από docs/web · **[G]** εκτίμηση.

## Το ερώτημα
Όχι «πόσο θα γράψουμε» (θα γράψουμε πολλά έτσι κι αλλιώς: .gr EPP, Cardlink/IRIS, licensing, `ekdosi_bridge`),
αλλά **ποια βάση δεν θα μετανιώσουμε όταν θα είναι αργά** (myip.gr μέσα).

## Σύνοψη

| Κριτήριο | Blesta 6.0.3 | Paymenter |
|---|---|---|
| Έλεγχος πυρήνα | ⚠ 6 αρχεία ionCube, ανάμεσά τους `app_controller`, `app_model`, Router (ACL, CSRF, API) | ✅ MIT, όλος ανοιχτός, fork χωρίς άδεια |
| Μηχανή χρέωσης | ✅ ώριμη (20 χρόνια edge cases) | ⚠ workable, με τρύπες (παρακάτω) |
| Domains | ✅ Domain Manager, TLDs, sync (με λειψά modules) | ❌ **καμία** έννοια domain στον πυρήνα [V] |
| WHMCS importer | ✅ περνάει κάρτες Stripe, domains, modules | ❌ demo-grade, σβήνει τη βάση σε κάθε run [V] |
| Ασφάλεια (client → σύστημα) | ❌ IDOR, file delete, XSS προς staff, CSRF σε GET | ✅ καμία αντίστοιχη εύρεση [V] |
| Ασφάλεια (gateways) | ❌ unsigned webhooks σε πολλά | ✅ όλα επαληθεύουν υπογραφή ή ρωτάνε τον provider [V] |
| Ασφάλεια (staff/API) | ❌ API key χωρίς ACL | ⚠ ίδιο θέμα + templates σε Blade = εκτέλεση PHP |
| Stack | ξένο | ✅ Laravel, όπως το Ekdosi |
| Διακυβέρνηση | εταιρεία, πληρωμένη υποστήριξη | ⚠ bus factor ≈ 1 (72% των commits ένας developer) [V] |
| Ρυθμός αλλαγών | αργός, σταθερός | ⚠ πολύ γρήγορος (Laravel 13, Filament 5, μεγάλες αλλαγές στο master) |

## Paymenter — λεπτομέρειες

### Μηχανή χρέωσης: workable, με αιχμηρά σημεία
- Ένα ημερήσιο `app:cron-job`, **όλο μέσα σε ένα DB transaction**: μία εξαίρεση ακυρώνει όλη τη δουλειά της ημέρας
  (`app/Console/Commands/CronJob.php:48,221-235`) [V].
- Ανανέωση = μετάβαση του τιμολογίου σε paid → `RenewServiceService` → `expires_at = calculateNextDueDate()`.
  **Δεν υπάρχει περίοδος χρέωσης ανά τιμολόγιο**. Δύο πληρωμένα τιμολόγια για την ίδια υπηρεσία = δύο ανανεώσεις [V].
- Πληρωμή ακυρωμένου τιμολογίου: ούτε το `addPayment` ούτε το Stripe webhook ελέγχουν το status. Υπηρεσία που έχει
  γίνει terminate γυρίζει σε active χωρίς provisioning [V/G].
- Υπηρεσία σε suspend: η νέα περίοδος μετράει από **τώρα**, όχι από την παλιά λήξη (σκόπιμα, με test) [V].
- Suspend/terminate: το status αλλάζει **πριν** τρέξει το job στον server (`tries = 1`). Αν αποτύχει, μένει στο
  `failed_jobs` και βάση και server διαφωνούν [V].
- Λείπουν: υπερπληρωμή → πίστωση, υπενθυμίσεις πληρωμής (η ρύθμιση υπάρχει αλλά δεν χρησιμοποιείται), ιστορικό
  πιστώσεων (ledger), κατάσταση «ανανεώθηκε πριν την πληρωμή» [V].
- Λειτουργούν: μερικές/πολλαπλές πληρωμές (με tests), refunds με row lock, upgrades/downgrades με proration στον ίδιο
  κύκλο, credit note σε ακύρωση, αρίθμηση με format και locking. Για να συνεχίσει η αρίθμηση μετά το 32453, η
  ρύθμιση `invoice_number` = **32453** (κρατάει τον τελευταίο αριθμό, όχι τον επόμενο) [V].
- Tests: 15 αρχεία, 66 μέθοδοι. Καλύπτουν cron, πληρωμές, ανανέωση και αρίθμηση. Τίποτα για upgrades, refunds,
  coupons, ακυρώσεις ή gateways [V].

### Domains: λείπουν εντελώς
Δεν υπάρχει registrar, TLD, EPP, nameservers ή λήξη domain πουθενά σε `app`, `database` και `extensions` [V].
Τύποι extension: μόνο `Gateway`, `Server`, `Other`. Οι registrars του marketplace είναι πληρωμένα «server-type»
extensions (π.χ. «Domain Registration for Paymenter»: 23,99 $, EULA, από 03/2026, μία αξιολόγηση) [D].
Για Openprovider, Centova, mailcow, .gr ή licensing δεν βρέθηκε τίποτα [D].
Χρειάζεται δικό μας υποσύστημα: πίνακες domains, τιμών TLD και επαφών, τύπος extension registrar, sync, ανανεώσεις,
checkout/lookup, client area, import. Εκτίμηση **4–8 εβδομάδες developer** πριν δουλέψει ένας registrar [G].
Η λογική του `lib/myopenprovider` (`RenewalPlan`, `Client`, `DnsRecord`, `GlueHost`) και οι υπηρεσίες domains του
Ekdosi μεταφέρονται.

**Ενημέρωση (Ekdosi `3dd87fe`):** το Ekdosi έχει ήδη ολόκληρο το υποσύστημα, στο **ίδιο stack με το Paymenter**
(PHP 8.4, Laravel 13, Filament 5): contract `App\Contracts\DomainRegistrar` (register, renew, transferIn, restore,
EPP code, updateDomain, sync, τιμές TLD, import portfolio), models `Domain`/`DomainTld`/`DomainTldPrice`/`DomainContact`/
`DomainNameserver`/`DomainRegistrarLog`, `OpenproviderRegistrar` (884 γρ.), υπηρεσίες Renewal/Registration/Transfer/
Sync/Management/Import, πόρους Filament, και περίπου 180 tests για domains και ανανεώσεις. Το `DomainRenewalService` δένει την
ανανέωση με **τιμολόγιο και περίοδο** (μία φορά ανά τιμολόγιο, adopt όταν η περίοδος έχει ήδη καλυφθεί, CAS claim).
Επίσης: `PaymentAllocator` (υπερπληρωμή → πίστωση, FIFO, locks) και `InvoiceReminder`. **Δεν** υπάρχει provisioning
(μόνο `NullProvisioningModule`). Τα κομμάτια αυτά είναι δεμένα με models του Ekdosi (`company_id`, `ServiceContract`,
`Invoice` = φορολογικό παραστατικό, ανανέωση «on-issue»), άρα θέλουν προσαρμογή, όχι αντιγραφή. Αναθεωρημένη
εκτίμηση για domains σε fork του Paymenter: **1–3 εβδομάδες** [G], και όχι 4–8.

### WHMCS importer: δεν αρκεί για τη μετάβασή μας
`app/Console/Commands/ImportFromWhmcs.php` (1000 γρ., 3 commits) [V]:
- **Περνάει:** currencies, clients (με id και bcrypt hash), product groups/products/pricing/config options, tickets
  (χωρίς συνημμένα), orders, services (χωρίς server και credentials), invoices (μόνο status και currency, χωρίς ποσά,
  φόρο, due date), invoice items, payments (χωρίς gateway).
- **Δεν περνάει:** domains, contacts, addons, custom fields, promotions, tax, servers/module settings, notes, credit
  log, **gateway tokens**. Οι admins δεν εισάγονται (bug: το `$data` δεν γίνεται insert).
- Κάνει `migrate:fresh` σε κάθε εκτέλεση. Δεν έχει dry-run, transaction ή id map. Γράφει status `'unpaid'`, που η
  εφαρμογή δεν αναγνωρίζει. Ο μετρητής αρίθμησης μένει ένα νούμερο μπροστά.
- Κάρτες Stripe: το `stripe_id` και τα `billing_agreements.external_reference` (pm) μπορούν να γεμίσουν με δικό μας
  script, στον ίδιο λογαριασμό Stripe [G].
- PayPal vault (`paypal_ppcpv`): τα tokens είναι του partner app του WHMCS. Πιθανότατα **δεν** περνάνε σε άλλο REST
  app, άρα νέα έγκριση από τους πελάτες. Να επιβεβαιωθεί με την PayPal [G].
→ Ο importer γράφεται από την αρχή, με δικά του tests.

### Ασφάλεια: καλύτερη από το Blesta στη μεριά του πελάτη
- **Πελάτης:** policies ιδιοκτησίας (`InvoicePolicy`, `ServicePolicy`, `TicketPolicy`) και κλειδωμένα Livewire
  properties. Τα tickets αποδίδονται με `html_input => escape`, τα συνημμένα είναι έξω από το docroot. Login και 2FA
  με rate limit, το 2FA secret κρυπτογραφημένο, email verification με signed URL. Δεν βρέθηκε IDOR, file deletion,
  XSS από πελάτη προς staff, CSRF σε GET ή bypass του email verification [V].
- **Gateways:** Stripe με `hash_equals`, PayPal webhooks μέσω `verify-webhook-signature`, Mollie και IPN μέσω API του
  provider [V].
- **Πυρήνας πληρωμών (`ExtensionHelper::addPayment`, :469-510):** transaction, row lock, de-dup με `transaction_id`.
  **Δεν** ελέγχει ποσό/νόμισμα, status τιμολογίου, ιδιοκτησία ή μεταβάσεις status (Succeeded → Failed επιτρέπεται).
  Ίδια κατηγορία με το C1 του Blesta, αλλά ηπιότερο: τα ποσά έρχονται από τον provider [V].
- **Staff:** τα email/notification templates και το `mail_header`/`mail_footer` γίνονται compile ως Blade. Όποιος
  έχει αυτά τα δικαιώματα εκτελεί PHP. Τα API keys έχουν δική τους λίστα δικαιωμάτων, ανεξάρτητη από το role αυτού
  που τα δημιουργεί. Το `users.update` αλλάζει password/email άλλων admins [V].
- Τα credentials των cPanel, Plesk, Virtualizor, Convoy και Enhance αποθηκεύονται **χωρίς κρυπτογράφηση** [V].
- **Ιστορικό:** CVE-2025-58048 (RCE, < 1.2.11) [D]· Stripe webhook με κενό secret δεκτό μέχρι το v1.5.7 (`93738a3`,
  07/2026) [V]. Το project διορθώνει γρήγορα ό,τι αναφέρεται, αλλά η ορθότητα των πληρωμών σφίγγει ακόμα μέσα στο 2026.

## Συμπέρασμα

**Κανένα από τα δύο δεν είναι «έτοιμη σωστή βάση». Είναι δύο διαφορετικά στοιχήματα:**

- **Blesta:** χαμηλό αρχικό κόστος, ώριμη χρέωση και domains, καλός importer. Δομικό πρόβλημα που **δεν διορθώνεται**:
  ο κώδικας ACL/CSRF/API είναι κλειστός και ο πελάτης έχει πρόσβαση σε τρύπες του πυρήνα. Ζούμε με local patches και
  περιμένουμε τον vendor. Είναι το ίδιο πρόβλημα με το WHMCS, σε μικρότερη δόση.
- **Paymenter:** καθαρή, ανοιχτή βάση Laravel, πιο ασφαλής εκεί που μετράει περισσότερο (πελάτης και gateways).
  Όμως στην πράξη **γινόμαστε συντηρητές ενός fork** (bus factor 1, γρήγορο upstream), και πρέπει να χτίσουμε domains,
  importer και να σκληρύνουμε τη μηχανή ανανέωσης. Τα κενά της χρέωσης (ανανέωση δεμένη με περίοδο, κατάσταση
  «prepaid», πληρωμή ακυρωμένου) είναι ακριβώς αυτά που έχουμε ήδη σχεδιάσει στο `RenewalPlan`.

### Πρόταση: απόφαση με pilot, όχι με χαρτιά
Η nixpal είναι **ιδανικό pilot για το Paymenter**, γιατί σχεδόν δεν έχει domains (7 Openprovider, 4 CNIC).
Χρειάζεται licensing, cPanel (υπάρχει extension), Stripe και PayPal (υπάρχουν).
Time-box **2 εβδομάδες** σε fork του Paymenter:
1. Importer για το αντίγραφο της nixpal (clients, services, invoices με ποσά, κάρτες Stripe), re-runnable, με id map.
2. `nixpal_licensing` ως Server extension πάνω στο `lib/nixpal-licensing`, με το `zlic.php` και τα fixtures byte-for-byte.
3. Patches στο `addPayment` (ποσό/νόμισμα/status/μεταβάσεις) και στην ανανέωση (περίοδος ανά τιμολόγιο).
4. Σχέδιο (μόνο σχέδιο) του υποσυστήματος domains, με Openprovider και .gr ως drivers.

Αν στο τέλος τα 1–3 στέκουν και το 4 φαίνεται ρεαλιστικό → Paymenter fork. Αλλιώς → Blesta με local patches και
την πύλη της HANDOFF §12. Το WHMCS της nixpal είναι month-to-month, άρα η αναμονή κοστίζει ελάχιστα. Ό,τι μπαίνει
στο `lib/` ισχύει και για τις δύο επιλογές.

## Πότε ανανεώνουμε σήμερα (WHMCS myip.gr, read-only SQL, 2024-10 → 2026-10)
- **1607** γραμμές ανανέωσης domain με relid, πληρωμένες: ανανέωση **με την πληρωμή** (Mark Paid → registrar),
  κατά μέσο όρο 5 ημέρες μετά την έκδοση.
- **144** γραμμές «Ανανέωση Domain» με **μηδενισμένο relid** (~8%): ανανέωση **πριν την πληρωμή**.
  - **126** από αυτές ανήκουν σε **2 πελάτες-συνεργάτες** με πολλά domains (περίπου 35–90 ενεργά ο καθένας): μαζεμένο
    τιμολόγιο ~5 γραμμών, πληρωμή περίπου 15 ημέρες μετά.
  - **18** σε 13 πελάτες: μεμονωμένες χειροκίνητες ανανεώσεις (π.χ. .gr με το χέρι· το hook `domain_manual_renewal.php`
    μηδενίζει το relid στο `AfterRegistrarRenewal`, με `mysql_query`).
- Μηχανισμοί: `relid_remover` (2020) και «Έλεγχος/Μηδενισμός relid» στο `ekdosi_bridge` (v0.21.0), με κόκκινη
  σήμανση όταν η γραμμή έχει ήδη ανανεωθεί.

**Συμπέρασμα για τον σχεδιασμό:** οι δύο τρόποι πρέπει να είναι **πολιτική της βάσης**, όχι hack:
1. Default: ανανέωση **με την πληρωμή** (on-paid).
2. Ανά πελάτη (συνεργάτες): ανανέωση **στην έκδοση** του τιμολογίου (on-issue, όπως το Ekdosi §6.1), με όριο
   ανεξόφλητου ποσού.
3. Χειροκίνητο «Ανανέωση τώρα» πριν από τιμολόγιο ή πληρωμή, που αφήνει εγγραφή **prepaid**.
4. Κοινός φύλακας και για τα τρία: το adopt του `DomainRenewalService` του Ekdosi ή το `RenewalPlan` του
   `myopenprovider`. Μία ανανέωση ανά περίοδο, ό,τι κι αν πυροδοτήσει.
