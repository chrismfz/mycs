# Ekdosi vs Paymenter: τι υπερτερεί, τι λείπει, πώς το πιάνουμε (2026-10-11)

> Ανάγνωση κώδικα, τίποτα δεν εκτελέστηκε. Ekdosi στο `3dd87fe`, Paymenter στο `5b69d45` (v1.5.8+).
> Συνέχεια του `platform-evaluation.md`.

## Με μια ματιά

| | Ekdosi | Paymenter |
|---|---|---|
| Stack | PHP 8.4, Laravel 13, Filament 5 | PHP 8.3+, Laravel 13, Filament 5, Livewire 4 |
| Μέγεθος `app/` | 1.241 αρχεία, ~173k γρ. | 514 αρχεία, ~30k γρ. (+7,4k extensions) |
| Tests | ~563 αρχεία, **~4.300 tests**, CI σε MariaDB, test ταυτόχρονης αρίθμησης | 14 αρχεία, **~66 tests**, phpstan απενεργοποιημένο στο CI |
| Μοντέλο | multi-tenant (`company_id` παντού), Invoice = **φορολογικό** παραστατικό | single-tenant, Invoice = **προτιμολόγιο**/εμπορικό |
| Ταυτότητα | τιμολογιέρα με επεκτάσεις hosting | hosting commerce: πώληση, provisioning, αυτοματισμός |

**Συμπέρασμα:** είναι **σχεδόν τέλεια συμπληρωματικά**. Το Ekdosi είναι βαθύτερο μηχανικά (tests, χρήματα, domains,
tickets). Το Paymenter έχει ό,τι χρειάζεται για να *πουλάς και να αυτοματοποιείς* hosting (portal, cart, provisioning,
auto-charge). Το «Ekdosi υπερτερεί σε πολλά» ισχύει στο βάθος. Στο εμπόριο hosting υπερτερεί καθαρά το Paymenter.

## Πίνακας ανά περιοχή

✅ πλήρες · 🟡 μερικό · ❌ λείπει · ★ = υπερτερεί

| # | Περιοχή | Ekdosi | Paymenter | Ποιο υπερτερεί / σχόλιο |
|---|---|---|---|---|
| 1 | Πελάτες | 🟡 ΑΦΜ/ΑΑΔΕ, VIES, επαφές, sub-logins portal, merge | 🟡 όνομα/email + custom properties, χωρίς VAT/επαφές | ★ Ekdosi |
| 2 | Client portal | 🟡 τιμολόγια, καρτέλα, πληρωμή, tickets· **όχι** υπηρεσίες/domains/εγγραφή | ✅ dashboard, υπηρεσίες, upgrade, cancel, credits, κάρτες, 2FA | ★ Paymenter |
| 3 | Κατάλογος | 🟡 προϊόντα, κύκλοι, setup fee· χωρίς config options/addons | 🟡 categories/plans/τιμές ανά νόμισμα, **config options**, upgrades· χωρίς addons | ★ Paymenter |
| 4 | Cart/checkout/coupons | ❌ | ✅ με captcha | ★ Paymenter |
| 5 | Επαναλαμβανόμενες υπηρεσίες | ✅ `ServiceContract`, draft ανανέωσης idempotent, cursor | ✅ βασικό· ανανέωση **χωρίς** δέσιμο σε περίοδο | ★ Ekdosi (ορθότητα) |
| 6 | Provisioning | ❌ μόνο η υποδοχή (`ProvisioningModule`, Null) | ✅ 8 modules (cPanel, DirectAdmin, Plesk, …), queue, retry με το χέρι | ★ Paymenter |
| 7 | Κύκλος ζωής | 🟡 `ServiceDunning` (opt-in), χειροκίνητα actions, χωρίς αιτήματα ακύρωσης | ✅ suspend/terminate, ακύρωση end-of-period/immediate | ★ Paymenter (status πριν το job: διόρθωση) |
| 8 | Τιμολόγηση | ✅ myDATA, gapless, πιστωτικά, proforma→φορολογικό, UBL | ✅ αρίθμηση, PDF, credit/debit notes, proforma, snapshots | ★ Ekdosi (ελληνικό, μένει εκεί) |
| 9 | Πληρωμές | 🟡 **Cardlink** (κάρτα, Apple/Google Pay, IRIS), έμβασμα, `PaymentAllocator`, προκαταβολές· χωρίς Stripe/PayPal/αποθηκευμένες κάρτες | 🟡 **Stripe, PayPal**, Mollie, billing agreements, **auto-charge**, refunds· χωρίς τράπεζα/έμβασμα | Συμπληρωματικά |
| 10 | Υπενθυμίσεις/dunning | ✅ στάδια, review/auto, πρότυπα el/en, opt-out | ❌ (η ρύθμιση υπάρχει αλλά δεν χρησιμοποιείται) | ★ Ekdosi |
| 11 | Domains | ✅ contract, Openprovider, adopt, ~150 tests | ❌ | ★ Ekdosi |
| 12 | Tickets | ✅ τμήματα με IMAP, νέα tickets από email, **canned replies**, εσωτερικά μηνύματα, CC, αξιολόγηση, blocked senders | 🟡 τμήματα σε λίστα, IMAP μόνο για απαντήσεις· **χωρίς canned replies**, εσωτερικές σημειώσεις, ειδοποίηση staff | ★ Ekdosi |
| 13 | Email templates | 🟡 τιμολόγιο + υπενθυμίσεις επεξεργάσιμα· τα υπόλοιπα σταθερά | 🟡 12 templates γεγονότων υπηρεσίας (νέα υπηρεσία, suspend, …) επεξεργάσιμα, email log· μία γλώσσα· **Blade = εκτέλεση PHP** | ★ Paymenter (κάλυψη), διόρθωση ασφάλειας |
| 14 | Ειδοποιήσεις | 🟡 bell, mail alerts | ✅ in-app, Web Push, Discord, προτιμήσεις | ★ Paymenter |
| 15 | Admin | 🟡 spatie roles, 50 policies, activity log· χωρίς impersonation | ✅ ~219 δικαιώματα, audit, **impersonation**, log viewers, failed jobs | ★ Paymenter |
| 16 | API | 🟡 **MCP** (~36 tools, OAuth), webhooks WHMCS· χωρίς REST | 🟡 Admin REST API (API keys), OAuth server· χωρίς webhooks προς τα έξω | Συμπληρωματικά |
| 17 | Multi-tenancy | ✅ | ❌ | Ekdosi (για 2 εταιρείες: δύο instances στο Paymenter) |
| 18 | Γλώσσες | 🟡 portal el/en· admin μόνο ελληνικά (hard-coded) | 🟡 24 γλώσσες, **όχι** ελληνικά | Ισοπαλία· ελληνικά στο Paymenter = μετάφραση ~700 γρ. |
| 19 | Αναφορές | ✅ λογιστικές, ληξιπρόθεσμα, ΦΠΑ | 🟡 βασικά widgets | ★ Ekdosi (μένει εκεί) |
| 20 | Ασφάλεια | ✅ MFA, throttles, policies, κρυπτογράφηση secrets | ✅ 2FA, rate limits, captcha, policies· ευρήματα στο `platform-evaluation.md` | Ισοπαλία |
| 21 | «Τηλεφωνεί σπίτι» | update check (GitHub), AI βοηθός (Anthropic) | **telemetry ON by default**, update check, self-updater `curl \| bash`, marketplace, Gravatar | Βλ. «Αφαίρεση» |
| 23 | WHMCS | ✅ bridge, inbox, sync πληρωμών | 🟡 importer επιπέδου demo | ★ Ekdosi |

### Client portal: βάθος vs πλάτος
Το portal του Ekdosi (Blade + Flux, `/user`, ~128 tests) πάει **βαθύτερα** σε όσα κάνει: καρτέλα, αποδείξεις,
πληρωμή υπολοίπου ή τιμολογίου, χρήση προκαταβολής, tickets με αξιολόγηση, el/en, host ανά εταιρεία. Το portal του
Paymenter (Livewire, ένα theme, σχεδόν χωρίς tests) πάει **πλατύτερα**: υπηρεσίες, upgrade, ακύρωση, cart/checkout,
αποθηκευμένες κάρτες, 2FA, sessions, ειδοποιήσεις. Την ποιότητα UX ο κώδικας δεν τη δείχνει· θέλει σύγκριση στην οθόνη.
Στο fork (A), οι σελίδες τιμολογίων/καρτέλας/tickets του Ekdosi είναι υποψήφιες να αντικαταστήσουν τις αντίστοιχες
του Paymenter.

### Τι λείπει και από τα δύο
Αιτήματα ακύρωσης στο Ekdosi (υπάρχουν στο Paymenter) · addons · mass mail · webhooks προς τα έξω · .gr EPP ·
licensing (υπάρχει στο `lib/nixpal-licensing` εδώ) · mailcow/ngm/Centova/Proxmox (mailcow, ngm: `lib/` εδώ) ·
αντιστοίχιση καταθέσεων από IMAP τράπεζας (`payments_tracking`) · πλήρης WHMCS importer.

## Αφαίρεση από το Paymenter (fork)
1. **Telemetry:** `app/Console/Commands/TelemetryCommand.php`, `routes/console.php:15-18`, `config/app.php:27`
   (default ON), `Settings::getTelemetry()`. Στέλνει ημερησίως στο `api.paymenter.org/statistics` UUID, εκδόσεις,
   πλήθη τιμολογίων/υπηρεσιών/χρηστών, νομίσματα και extensions.
2. **Update checker:** `CheckForUpdates.php` (τρέχει μέσα στο ημερήσιο cron, `CronJob.php:259-261`).
3. **Self-updater:** `Upgrade.php:85-86` (`curl … update.sh | bash`), `Admin/Pages/Updates.php`, `update.sh`.
4. **Marketplace / extension updates:** `Admin/Pages/Extension.php:69-90`, `Updates.php:240-260`,
   `extension-card.blade.php` (hotlink εικόνων), tab marketplace. Το upload zip extension: αφαίρεση ή μόνο CLI.
5. **Gravatar** (`User.php:88`: MD5 του email σε τρίτο), branding/footer links, workflow `build-api-docs.yaml`.
6. **Σκλήρυνση:** templates σε sandbox (όχι Blade `render` → π.χ. απλό placeholder renderer όπως το
   `MailTemplateRenderer` του Ekdosi), credentials server modules σε `encrypted` cast, όρια σε `addPayment`.
Εκτίμηση: 1–2 ημέρες.

## Κατεύθυνση: προς τα πού μεταφέρουμε

| Επιλογή | Υπέρ | Κατά |
|---|---|---|
| **A. Fork Paymenter = control plane, Ekdosi μένει τιμολογιέρα** (bridge όπως με το WHMCS) | κρατάει την απόφαση §9.1b· το Paymenter φέρνει έτοιμο ό,τι λείπει στο Ekdosi (portal, cart, provisioning, auto-charge)· τα αυτοματοποιημένα προτιμολόγια ταιριάζουν στο hosting | port των domains, tickets, υπενθυμίσεων και allocator από το Ekdosi, με νέο money flow (όχι `company_id`, όχι φορολογικό Invoice)· δύο εφαρμογές |
| B. Ekdosi = όλα (port του portal/cart/provisioning του Paymenter μέσα) | μία εφαρμογή, tests 4.300, domains/tickets/πληρωμές ήδη εκεί | αντιστρέφει την §9.1b· κάθε ανανέωση = φορολογικό draft που θέλει έγκριση (όχι hands-off)· το portal, το cart και ο κατάλογος στήνονται σχεδόν από την αρχή μέσα σε multi-tenant λογιστική εφαρμογή |

**Απόφαση (2026-10-11): A, ως hard fork** (`README.md`). Το upstream έχει bus factor 1 και 66 tests. Δεν το «παρακολουθούμε»: το παίρνουμε
ως βάση και από εκεί και πέρα ισχύει η κουλτούρα tests του Ekdosi. Παίρνουμε από το upstream μόνο ό,τι θέλουμε
(security fixes, extensions), με cherry-pick. Η ροή χρημάτων μένει όπως σήμερα με το WHMCS: το Paymenter εισπράττει, το
Ekdosi εκδίδει φορολογικό παραστατικό μέσω bridge (`PaymenterBillingSource`, ίδιο σχήμα με το `BlestaBillingSource`).

Η υπόθεση που πρέπει να επιβεβαιωθεί πρώτη: πόσο κοστίζει το port των domains/tickets από το `company_id` + φορολογικό
Invoice σε προτιμολόγιο Paymenter. Αυτό κρίνει την εκτίμηση της Φάσης 2.

## Φάσεις (εκτιμήσεις [G], για έναν developer με βοήθεια)

**Φάση 0: Fork και καθάρισμα (2–3 ημέρες)**
Hard fork· αφαίρεση όσων λέει η ενότητα «Αφαίρεση»· σκλήρυνση templates και credentials· phpstan στο CI· σκελετός
ελληνικής μετάφρασης· απόφαση για τα δύο instances (nixpal, myip).

**Φάση 1: nixpal MVP (2–3 εβδομάδες)**
- `nixpal_licensing` ως Server extension πάνω στο `lib/nixpal-licensing`· `zlic.php` με τα fixtures byte-for-byte.
- cPanel (υπάρχει)· Stripe/PayPal (υπάρχουν)· έλεγχος μεταφοράς vault tokens με την PayPal.
- Διορθώσεις πυρήνα: guards στο `addPayment` (ποσό, νόμισμα, status, μεταβάσεις)· ανανέωση δεμένη με τιμολόγιο και
  περίοδο· status μετά την επιτυχία του job· πληρωμή ακυρωμένου τιμολογίου.
- Port των **υπενθυμίσεων** του Ekdosi.
- **Αλλαγή κύκλου χρέωσης** (μήνας → τρίμηνο, έτος → διετία κ.λπ.) και πακέτου μαζί, με proration (`decisions.md` §7).
- Importer nixpal (clients, services, invoices με ποσά, κάρτες Stripe, **PayPal vault tokens** (13/15 ελεγμένα), id map, re-runnable).
- **Export εσόδων ανά έτος** (CSV/XLSX) για τον λογιστή της nixpal· **χωρίς** bridge προς Ekdosi στη Φάση 1.
- Shadow-run του licensing → cut-over nixpal.

**Φάση 2: Domains (2–4 εβδομάδες)**
Port του υποσυστήματος domains του Ekdosi (contract, models, Openprovider, adopt)· πολιτική ανανέωσης on-paid /
on-issue ανά πελάτη / «Ανανέωση τώρα» με prepaid (`platform-evaluation.md`)· αναζήτηση και whois με το
`lib/domain-tools`· domains στο portal· import domains από WHMCS· το stock-free `myopenprovider` (`lib/`) ως πηγή
για ό,τι λείπει.

**Φάση 3: Τα απαραίτητα του myip.gr (3–5 εβδομάδες)**
- Cardlink/IRIS gateway (`lib/cardlink-eurobank`, μαζί με τα server-to-server returns που λείπουν από το Ekdosi).
- **Tickets:** port του συστήματος του Ekdosi (canned replies, εσωτερικά, νέα tickets από email, ειδοποιήσεις staff)
  στη θέση του απλού του Paymenter.
- `PaymentAllocator` + `payments_tracking` (αντιστοίχιση καταθέσεων από IMAP τράπεζας).
- mailcow, ngm (`lib/` εδώ), Proxmox, Centova, και ό,τι από SSL χρειαστεί.
- Bridge προς Ekdosi (`PaymenterBillingSource`) για τα φορολογικά.
- Επεξεργάσιμα πρότυπα el/en για όλα τα γεγονότα.

**Φάση 4: .gr EPP (το μακρύτερο κομμάτι, ανεξάρτητο από πλατφόρμα)**
`lib/gr-epp` ως driver του `DomainRegistrar`· UAT του Μητρώου· 1381 domains. Χωρίς αυτό, το myip.gr δεν μεταφέρεται
σε καμία πλατφόρμα. **Μπορεί και πρέπει να ξεκινήσει παράλληλα από τώρα** (whitelist IP στο UAT).

**Φάση 5: Importer myip και cut-over**
Πλήρης importer (domains, addons, custom fields, tickets με συνημμένα, αρίθμηση > 32453)· 2–3 δοκιμαστικές μεταφορές·
validation· cut-over.

## Ανοιχτά πριν τη Φάση 0
Απαντήθηκαν όλα (`open-questions.md`). Η ενημερωμένη λίστα δουλειάς είναι στο `ROADMAP.md`.
