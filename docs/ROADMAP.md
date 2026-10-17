# MyCS: Roadmap

> Ενιαία λίστα δουλειάς. Πηγές: φάσεις (`history/ekdosi-vs-upstream.md`), parity backlog (`whmcs-usage.md`), ρυθμίσεις
> (`settings-map.md`), upstream (`history/upstream-triage.md`), audits (`audit-*.md`), αποφάσεις (`decisions.md`).
> Οι εκτιμήσεις είναι για έναν developer με βοήθεια: εκτιμήσεις, όχι δέσμευση. Ενημέρωσε τα κουτάκια σε κάθε PR.

## Σειρά εργασιών (2026-10-14, μετά το 3a· κουτάκια ενημερωμένα 2026-10-16 μέχρι το #150)
Τα χρήματα (MT-x, 2b, 2c) έκλεισαν. Έγιναν επίσης: οι αποφάσεις Δ1–Δ4 (#95· εγκρίθηκαν 2026-10-14, Δ4 εν μέρει), αφαίρεση του theme system (#93), Σ4 (#96, #98, #99),
λογαριασμοί PR 1 (staff, #100), 2a (`clients`, #101), 2b (πολλοί users, #102), 3a (επαφές, #103) και το rename σε
MyCS (#104). Η σειρά ακολουθεί τις εξαρτήσεις: ό,τι αλλάζει το μοντέλο πάνω στο οποίο πατάνε τα επόμενα μπαίνει πρώτο.

| # | Τι | Γιατί εδώ |
|---|---|---|
| 1 | **Σ7: ένα `PlaceOrderService`**, σε δύο PRs: **Σ7a** ένα σημείο τιμολόγησης σε decimal (+ CART-1/2/4/5/6/7/8), **Σ7b** το σχήμα του Δ1 (εγκρίθηκε 2026-10-14). **Έγινε** (#107, #109· notes παραγγελίας μετά) | Σήμερα τα τιμολόγια φτιάχνονται σε 5 σημεία που διαφωνούν. Αν το «ανά αποδέκτη» (3) μπει πριν την ενοποίηση, γράφεται 5 φορές και αποκλίνει. |
| 2 | **SVC-14** (αναλογική χρέωση upgrade στην πραγματική τιμή) μαζί με **αλλαγή κύκλου και πακέτου**. **Έγινε** (#113, #115) | Πατά στο ενιαίο σημείο τιμολόγησης (1) και στο Δ1. |
| 3 | **Λογαριασμοί 3b, απλοποιημένο (2026-10-14):** μόνο η «Σχετική επαφή» της υπηρεσίας (`services.contact_id`, πληροφορία)· αποδέκτης παραστατικού, snapshot και παραλήπτες mails **στον πάγο** ως το integration myip ↔ Ekdosi (`accounts.md`). Μετά, **Δ3** (ομαδοποίηση ανά λογαριασμό/λήξη, ακύρωση = η περίοδος δεν χρεώνεται) και μικρό PR **Δ4**: `completed` υπηρεσιών (τα status παραγγελίας και το «Accept Order» πάνε με το Auto Setup του Σ1). **Έγινε:** 3b (#116), Δ3 (#123, #126)· το Δ4 `completed` δεν γίνεται (3+) | Χρειάζεται το 1. Οι ρόλοι επαφών των domains (registrant/admin/tech/billing) **δεν** είναι εδώ: μπαίνουν με τα domains (Φάση 2). |
| 3+ | **Μετά το sweep χρέωσης (2026-10-15, `sweeps/billing.md`):** η γραμμή 3 χωρίζεται σε PRs A–H (Δ3a ακύρωση = η περίοδος δεν χρεώνεται, locks στις γραμμές από το staff, Δ3b ομαδοποίηση, pro rata προϊόντος, merge/split, Mass Pay, EU VAT, gateways). Οι αποφάσεις 1–12 πάρθηκαν 2026-10-15· το Δ4 `completed` δεν γίνεται. **Έγιναν:** A (#123), B (#122), C1 (ομαδοποίηση, #126), C2 (μέθοδος πληρωμής ανά πελάτη, #129), D (pro rata προϊόντος, #132), E (merge/split, #136)· F σε τρία: F1 (πληρωμή σε πολλά τιμολόγια και χρήση πίστωσης από το staff, #138), F2 (Mass Pay πελάτη μέσω gateway, #141), F3 (Mass Pay του WHMCS στον importer, #143). G1 (EU VAT/VIES, reverse charge, λόγος 0% ανά γραμμή, `eu-vat.md`, #145)· το G2 (ανοιχτά τιμολόγια όταν αλλάζει το καθεστώς ΦΠΑ) δεν γίνεται (2026-10-15). H: H1 (αυτόματη χρέωση τη μέρα λήξης, retries, idempotency/PAY-3, #148), H2a (gateway eurobank/Cardlink, #149), H2b (ειδοποίηση λήξης κάρτας, «να μη χρεωθεί αυτόματα» ανά τιμολόγιο, δίχτυ για το PayPal IPN του WHMCS, #150). **Η γραμμή 3+ έκλεισε** (A–H, χωρίς το G2). | Η σειρά και οι εξαρτήσεις στο `sweeps/billing.md`. |
| 4 | **EU VAT / VIES**, reverse charge, ομάδες πελατών με έκπτωση. **Έγινε** το EU VAT/VIES/reverse charge (G1, #145)· μένουν οι ομάδες πελατών με έκπτωση | Χρειάζεται ΑΦΜ και χώρα στην επαφή (3a) και ΦΠΑ ανά γραμμή (Δ1). Απαραίτητο για τη nixpal (διεθνείς πελάτες). |
| ‖ | **Licensing** (`nixpal_licensing`, `zlic.php` byte-for-byte) και **Σ1** (provisioning, Pending Module Actions· **ανά προϊόν**: πότε στήνεται η υπηρεσία, όπως το `autosetup` του WHMCS· custom fields προϊόντος, όπως το `tblcustomfields` type=product). **Έγιναν:** licensing L1 (#106), L2 (#128), L3 (#131)· Σ1-a (#114), Σ1-b1 (#117), Σ1-b2 (#119), server ανά υπηρεσία και server groups (#112). Μένει το Σ1-c (custom fields προϊόντος) | Από την άλλη session, παράλληλα με τα 1–4 (δεν αγγίζουν τα ίδια αρχεία). Πριν συνεχίσει: merge του `main` (namespace `MyCS\Extensions`, `client_id`, guard του staff, docs στο `docs/history/`). Το Σ1 πριν από cPanel/domains της nixpal. |
| 5 | **Importer νέου σχήματος + `mycs:parity`**· myip «αριθμός = id» (Δ2) | Μετά το σχήμα (1–4). Σε άδεια εγκατάσταση, όταν είμαστε έτοιμοι (`accounts.md`, απόφαση 4). |
| 6 | Gateways της nixpal (Stripe auto-charge με retries, PayPal vault, legacy συνδρομές), αρίθμηση `{YEAR}-{NUMBER}` (Δ2: `sequences`, αριθμός μόνο στο paid), υπενθυμίσεις. **Έγιναν:** Stripe auto-charge τη μέρα λήξης με retries (H1, #148), υπενθυμίσεις (#99), απόφαση για τις legacy συνδρομές (Π7.9, #150). **Μένουν:** PayPal vault, αρίθμηση | Πατάνε σε λογαριασμούς και στο ενιαίο σημείο τιμολόγησης. |
| 7 | Λειτουργία (hosting, email, branding PDF, backups, παρακολούθηση, scheduler), **runbook cut-over**, έλεγχος σε browser, cut-over | Τελευταία, πάνω σε σταθερό σχήμα και δεδομένα. |
| – | Domains (Φάση 2, με τους ρόλους επαφών ανά domain)· bridge προς Ekdosi (λογαριασμοί PR 5) | Τα domains μετά το cut-over της nixpal στα παραπάνω· το bridge μόνο για το myip, πολύ αργότερα. |

**Notes του WHMCS που λείπουν** (2026-10-14, από τη session του audit sweep· κανένα δεν υπάρχει σήμερα ούτε το φέρνει ο
importer):
- Σημειώσεις παραγγελίας `tblorders.notes` (myip 570, nixpal 37) → `orders.notes`, γράφεται από το `PlaceOrderService`
  (πεδίο στο checkout και στο «Create Order»), και ο importer τις φέρνει. **Έγινε** (`orders.md`).
- Σημειώσεις τιμολογίου `tblinvoices.notes` (27 / 66, τυπώνονται στο τιμολόγιο) → `invoices.notes` στη σελίδα και στο
  PDF, με το Σ8. Αυτή η session.
- Admin notes πελάτη `tblclients.notes` (127 / 3) και `tblnotes` (13 / 2, με συντάκτη και sticky) → σημειώσεις
  λογαριασμού (staff μόνο), με τον importer των λογαριασμών (5). Αυτή η session.
- Admin notes υπηρεσίας `tblhosting.notes` → `services.notes` (έγινε, session του audit sweep). Εσωτερικά notes των
  tickets (228 / 7) → με το support, session του audit sweep.

**Ποιος πιάνει τι** (2026-10-14): αυτή η session: Σ7, SVC-14, 3b, EU VAT. Η session του audit sweep: licensing, Σ1.
Πριν από PR που αγγίζει κοινά αρχεία, ενημέρωση της άλλης πλευράς (μέσω του χρήστη όταν τα μηνύματα δεν φτάνουν).

### Τι δεν ήταν γραμμένο (προστέθηκε 2026-10-14)
- **Συντονισμός με την άλλη session** για το PR 2 (βλ. 3 παραπάνω).
- **Συνθετικό σύνολο δοκιμών «σαν nixpal/myip»**: φτιαγμένο από τα αθροίσματα της απογραφής (`accounts.md`:
  multi-user, πολλές επαφές, αποδέκτες ανά υπηρεσία, users χωρίς λογαριασμό), **χωρίς** πραγματικά δεδομένα. Ώστε το
  νέο σχήμα να δοκιμάζεται από το PR 2 και όχι πρώτη φορά στον importer.
- **Runbook cut-over με επιστροφή:** πάγωμα του WHMCS, import και parity, DNS και webhooks, έλεγχοι πρώτης μέρας, πώς
  γυρνάμε πίσω (πρότυπο: `go-live-runbook.md` του Ekdosi).
  Οι ρυθμίσεις κάθε εταιρείας από το `docs/settings-map.md` (π.χ. myip: υπενθύμιση 1 μέρα, ληξιπρόθεσμο 1,3,5, suspend 15, terminate 120, invoice 14) περνούν στο instance της πριν το cut-over· τα defaults του κώδικα είναι της nixpal.
- **Webhooks στη μεταβατική περίοδο:** Stripe/PayPal στέλνουν σε ένα endpoint ανά λογαριασμό· τι γίνεται με γεγονότα που
  αφορούν ακόμα το WHMCS, ειδικά οι 5 ενεργές legacy συνδρομές PayPal (PAY-10/PAY-11 στο Σ3).
- **Ενημέρωση πελατών της nixpal:** νέο URL/login, ίσως reset κωδικών αν δεν περάσουν τα bcrypt· email και template.

## Παράλληλα, από τώρα (ανεξάρτητα από φάση)
- [x] **.gr EPP:** η IP του devbox (84.54.49.31) δηλώθηκε στο Μητρώο .GR (2026-10-11).
- [ ] .gr EPP: login/hello/check στο UAT από το devbox, ώστε να κλείσουν τα `[ΕΠΙΒΕΒΑΙΩΣΗ]`
      (`history/blesta-modules/HANDOFF.md` §6). Είναι το μακρύτερο κομμάτι· χωρίς αυτό το myip δεν μεταφέρεται.
- [x] DNS δοκιμών: `mycs.myip.gr` + `*.mycs.myip.gr` → devbox (2026-10-11).
- [x] Πρώτη εγκατάσταση στο devbox (`mycs.myip.gr`)· tests (66) και Pint περνάνε εκεί (2026-10-11).
- [x] Παρακολούθηση του upstream: routine «Upstream triage» κάθε Τρίτη 08:45 (issues, PRs, commits, security
      advisories → PR στο `history/upstream-triage.md`, χωρίς αυτόματο cherry-pick· 2026-10-11).

## Φάση 0: Καθάρισμα και βάσεις (2–3 ημέρες)
- [x] Αφαίρεση telemetry: `TelemetryCommand`, schedule, `TELEMETRY_ENABLED`, `Settings::getTelemetry()`.
- [x] Αφαίρεση `app:logs` (ανέβαζε το τελευταίο error log στο server του upstream).
- [x] Αφαίρεση update checker (`CheckForUpdates`, κλήση στο τέλος του `CronJob`), self-updater (`Upgrade`,
      `Admin/Pages/Updates`, `update.sh`), marketplace και ελέγχου ενημερώσεων extensions. Η σελίδα extensions
      κρατάει μόνο «διαθέσιμα» + upload zip.
- [x] Updater: **όχι μέσα από την εφαρμογή** (απόφαση 2026-10-11, `decisions.md`)· ενημέρωση μόνο με
      `deploy/update.sh`. Ο παλιός δεν κρατήθηκε: δούλευε με το το API του upstream και τα releases του upstream, άρα
      θα **αντικαθιστούσε το fork με το upstream**.
- [x] Αφαίρεση Gravatar (avatar = τοπικό SVG με αρχικά) και της ρύθμισης `gravatar_default`.
- [x] Workflows του upstream (`build-api-docs`, `release`, `beta`, `docker`) αφαιρέθηκαν (PR #9).
- [x] Branding/footer links (Powered by, Sponsor, Star on GitHub, docs του upstream): το admin footer δείχνει
      «MyCS · commit», `APP_NAME` = MyCS (2026-10-11).
- [x] Upload extensions από το web: αφαιρέθηκε. Τα extensions μπαίνουν ως κώδικας με git και `deploy/update.sh`
      (απόφαση 2026-10-11).
- [x] Κρυπτογραφημένα credentials σε όλα τα extensions (cPanel, Plesk, Convoy, Enhance, Virtualizor) **και** στη
      δημιουργία server/gateway, που τα αποθήκευε καθαρά· migration για όσα υπάρχουν (2026-10-11).
- [x] Templates email/ειδοποιήσεων, `mail_header`/`mail_footer` και snippet προϊόντος χωρίς Blade: renderer
      `packages/mail-template` (`templates.md`, `history/audit-security.md` H1–H2, 2026-10-11).
- [x] «Κανείς δεν δίνει ό,τι δεν έχει» (`PermissionGuard`): API keys, ρόλοι, ανάθεση ρόλου, επεξεργασία/διαγραφή/
      impersonation άλλου admin· το key δεν ξεπερνά ποτέ τον δημιουργό του (2026-10-11).
- [x] CI: tests σε sqlite + MariaDB 10.11 (PHP 8.4) και `pint --test` σε κάθε PR (PR #9, 2026-10-11).
- [x] CI: phpstan (larastan, level 5) σε `app/`, `extensions/`, `packages/mail-template` με baseline για τα 672 του
      upstream: σπάει μόνο σε νέα σφάλματα (2026-10-11).
- [ ] Μείωση του `phpstan-baseline.neon`: όποιο αρχείο αγγίζουμε, καθαρίζουμε και τις εγγραφές του. 672 → 404 με
      τύπους στις σχέσεις και `@property-read` στα accessors των models, → 390 με τα «ύποπτα» (2 πραγματικά bugs), → 349 με νεκρό κώδικα και περιττά `?->` (2026-10-11).
- [x] `lib/*` του blesta-modules → `packages/` (nixpal-licensing, cardlink-eurobank, paypal-vault, domain-tools,
      myopenprovider, mailcow), composer `path` repositories, 215 tests και phpstan στο CI (2026-10-11). Έμειναν εκτός
      ό,τι ήταν μόνο για το Blesta (repository/plugin endpoint/importer του licensing, tests των modules). Μένουν
      ονόματα του Blesta στο API (π.χ. `VposReturn::blestaStatus()`, `ServiceLifecycle`): αλλάζουν όταν γραφτούν τα
      adapters του MyCS (Φάση 1/3). Το `gr-epp` ήταν άδειο (Φάση 4).
- [x] `corwindev/laravel-themer` → `packages/laravel-themer` (2026-10-11)· καταργήθηκε μαζί με το theme system
      (2026-10-14, βλ. πιο κάτω).
- [x] Ελληνικά στο client area: `lang/el` (όλα τα αρχεία), `lang/el.json` για τα 53 μηνύματα (toasts κ.λπ.) που ήταν hardcoded,
      ξεχωριστή γλώσσα για το admin (`Admin Language`, default English), γλωσσάρι `docs/glossary-el.md` και test
      που δεν αφήνει κλειδί/μήνυμα χωρίς ελληνικά (2026-10-11). Μένουν τα templates των emails (Φάση 1).
- [x] **Μενού admin «σαν WHMCS»:** δικό μας top menu (Clients/Orders/Billing/Support/Utilities/Setup) πάνω, το
      sidebar μένει· ίδια permissions. Το Topbar Menu (vaslv) απορρίφθηκε: links στη βάση, χωρίς permissions
      (`docs/admin-ux.md`, 2026-10-11).
- [ ] **Dashboard «σαν WHMCS»:** μετρητές (Pending Orders, Tickets Waiting, Pending Cancellations, Pending Module
      Actions), Billing, Income Forecast, Upcoming Renewals (`docs/admin-ux.md`). Έγιναν οι μετρητές Pending Orders
      (#119) και Pending Module Actions (#114)· μένουν οι υπόλοιποι.
- [x] `deploy/update.sh` (από το Ekdosi): ενημέρωση με snapshot/maintenance/queue drain/migrate/build (2026-10-11).
      Χωρίς `deploy/rollback.sh` (απόφαση 2026-10-11: προσεκτικές ενημερώσεις + το snapshot του `update.sh`·
      χειροκίνητη επαναφορά στο `docs/install.md`).
- [x] Installer: `mycs:install` (migrations, seed, νόμισμα, εταιρεία/URL/γλώσσες/ζώνη ώρας, passport keys, πρώτος
      admin) αντί για το `app:init` του upstream, **μόνο CLI** (χωρίς `/install` wizard), κωδικός ποτέ ως flag
      (`docs/install.md`, 2026-10-11).
- [x] Cherry-picks: #1338 (captcha), από το #1295 το guard για αρνητικό υπόλοιπο και το enforcement του
      `credits_enabled` (και στο cron)· όχι το «cap at max credit» (PR #24, 2026-10-11).
- [x] **Spike domains:** port του πυρήνα των domains του Ekdosi (registrar, sync, transfer, renewal), branch
      `spike/domains` (δεν γίνεται merge· βάση της Φάσης 2), αποτελέσματα στο `docs/domains-spike.md` εκεί. Ο κώδικας
      μεταφέρεται σχεδόν αυτούσιος· εξαρτάται από το «ανανέωση δεμένη με περίοδο» της Φάσης 1. Live μεταφορά του
      nixpal.com CNIC → Openprovider: ολοκληρώθηκε, βρήκε και διόρθωσε 3 bugs του driver (2026-10-11).

## Φάση 1a: Σταθεροποίηση του κληρονομημένου κώδικα (audit sweep 2026-10-13)
Μετά τα MT-x, **πριν** από τα υπόλοιπα της Φάσης 1. Λεπτομέρειες, IDs και σειρά: `docs/audit-sweep.md`.
- [x] **Κρίσιμα ΚΡ-1..ΚΡ-8** (2026-10-13: #45–#52, #57· μένει το AUTH-12, `audit-sweep.md` → «Πρόοδος») (μικρά PRs, failing test πρώτα): regression `afterCommit` → δεν ακυρώνονται συνδρομές
      Stripe/PayPal· PayPal API χωρίς έλεγχο σφαλμάτων (refund «πετυχαίνει» χωρίς χρήματα)· cPanel create πάντα
      `true`· η σελίδα Settings γυρίζει πίσω τους μετρητές αρίθμησης· admin API χωρίς PermissionGuard (κλιμάκωση σε
      `*`)· `Settings::tax()` memoised ανά object· secrets σε καθαρό στο cache· σιωπηλά `catch` στο boot.
- [x] **Αποφάσεις Δ1–Δ4** (2026-10-14: Δ1–Δ3 όπως προτάθηκαν· Δ4 χωρίς εκτίμηση απάτης) στο `decisions.md`: καθαρά ποσά και ΦΠΑ ανά γραμμή· αρίθμηση (number = id, proforma μόνο
      στο paid, `sequences`)· ένα τιμολόγιο ανά πελάτη/ημερομηνία και τι σημαίνει «ακύρωση τιμολογίου»· status
      υπηρεσιών/τιμολογίων/παραγγελιών. Και Δ5–Δ8 (μερική πίστωση, θέσεις στο schema, ζώνη ώρας, ownership helper).
- [ ] Σ1 Contract των server adapters και provisioning (Module Log, status μόνο μετά την επιτυχία, idempotent create).
      **Pending Module Actions / Module Queue όπως στο WHMCS** (ζητήθηκε 2026-10-13· SVC-2, EVT-9): λίστα ενεργειών
      server/registrar που απέτυχαν (create, suspend, unsuspend, terminate, **domain renew** όταν π.χ. ο λογαριασμός στο
      Openprovider/FORTH δεν έχει υπόλοιπο), με Retry και μετρητή στο dashboard. Να δείχνει και τα «πληρωμένα που δεν
      εφαρμόστηκαν» του BIL-9 (`paid_applied`, σήμερα μόνο email). Απαραίτητο πριν από τα domains.
      **Σ1-a έγινε** (`provisioning.md`): `module_actions`, retries 3, Pending Module Actions, active μόνο μετά το create.
      **Σ1-b1 έγινε:** Auto Setup ανά προϊόν, ημερομηνίες όταν ξεκινά. **Σ1-b2 έγινε:** status παραγγελίας, Accept/Cancel/Cancel &
      Refund/Fraud, Pending Orders. (#114, #117, #119· cPanel change package, idempotent create, επιλογή server #125·
      Test Connection του cPanel #147.) Μένει: Σ1-c (custom fields προϊόντος), τα domains στη λίστα (με τα domains) και
      τα «πληρωμένα που δεν εφαρμόστηκαν» του BIL-9 στην ίδια λίστα (`provisioning.md`).
- [ ] **Pending Orders:** μαζικό Accept από τη λίστα, και στο modal του Accept πόσα απλήρωτα τιμολόγια έχει η
      παραγγελία/ο πελάτης (ζητήθηκε 2026-10-16).
- [x] Σ2 Κύκλος ζωής: ακύρωση «end of period» (δεν εκτελείται σήμερα), ακυρώσεις ανά γραμμή αντί για ολόκληρα
      τιμολόγια, αναστολή μόνο με ληξιπρόθεσμο τιμολόγιο, όχι αναστολή+τερματισμός στο ίδιο run, upgrades.
      Έγιναν: end of period (#61), αναστολή/τερματισμός «the WHMCS way» με ρυθμίσεις (#63), καρτέλα Automation (#66),
      πληρωμένο χωρίς εφαρμογή BIL-9 (#68), upgrades SVC-6/BIL-11 (#71), διαγραφές plan/προϊόντος BIL-6 (#73), BIL-12/BIL-16. Με τον scheduler: οι 30 ημέρες
      προστασίας του BIL-9 (`PAID_NOT_APPLIED_DAYS`) γίνονται ρύθμιση, και το `paid_applied` τρέχει πιο συχνά.
- [ ] Σ3 Gateways: idempotency keys, refund μόνο σε succeeded, ιδιοκτησία αποθηκευμένης μεθόδου, webhooks κοινού
      λογαριασμού με το WHMCS, πληρωμές χωρίς τιμολόγιο. **Έγιναν** (2c-1/2c-2): refund μόνο σε succeeded (PAY-4),
      idempotency στα refunds (Stripe `Idempotency-Key`, PayPal request id); H1: idempotency/lock στη χρέωση αποθηκευμένης
      μεθόδου (PAY-3, `charge_attempts`); ιδιοκτησία μεθόδου (PAY-8) υπάρχει ήδη στο `ExtensionHelper::charge`. **Μένουν:** webhooks κοινού λογαριασμού και prefix αναφοράς
      (PAY-10/11· για το PayPal IPN του WHMCS υπάρχει δίχτυ που ειδοποιεί το staff, H2b #150), χρήματα χωρίς τιμολόγιο
      (PAY-6), Stripe signature (PAY-14), floats/fees (PAY-16).
- [x] **Αφαίρεση του theme system του upstream** (2026-10-14): τα views του `themes/default` στο `resources/views`,
      τα assets στο `resources/css|js`, ένα `vite.config.js` (build στο `public/build`). Έφυγαν ο επιλογέας theme, το
      `app:theme:create`, το `vite.js`, οι μεταφράσεις ανά theme, το `packages/laravel-themer` και τα αρχεία Docker του
      upstream. Οι ρυθμίσεις του theme έγιναν η καρτέλα **Appearance** (`theme_<όνομα>`, `theme('<όνομα>')`· migration
      από `theme_default_<όνομα>`). Νέα πεδία, για να μη γράφεται τίποτα της εταιρείας μέσα στα views: custom CSS,
      HTML στο `<head>`, header πριν από το μενού, footer αντί του ενσωματωμένου και αρχεία (`/storage/brand/…`). Το
      HTML του staff βγαίνει όπως είναι, **δεν** γίνεται compile ως Blade· το γράφει όποιος έχει `admin.settings.update`.
- [x] Σ4 Ειδοποιήσεις μετά το commit (όχι `afterResponse`), PDF εκτός ροής πληρωμής, retries στο mail (#96, #98, #99).
- [ ] Σ5 Δικαιώματα staff: ονόματα permissions που δεν υπάρχουν, Filament «χωρίς μέθοδο = allow», upgrade/invoice
      items χωρίς validation, drafts ορατά στον πελάτη. Έγιναν: permissions που δεν υπάρχουν (AUTH-2, #84), drafts
      κρυφά και options upgrade με validation (AUTH-5/6, #86), γραμμές τιμολογίου (AUTH-8, #55), admin API (#90)· να
      ελεγχθεί το Filament «χωρίς μέθοδο = allow» (`audit-sweep.md` Σ5).
- [ ] Σ6 Ακεραιότητα: cascade deletes (παραγγελία → υπηρεσίες, staff → απαντήσεις tickets, config value), unique στη
      βάση, κρυπτογραφημένα credentials υπηρεσιών. Έγιναν: unique keys (#77), config values/νομίσματα/μέθοδοι σε χρήση
      (#79), παραγγελίες με υπηρεσίες δεν σβήνονται (#82), απαντήσεις tickets με `staff_id` restrict (#100),
      `services.password` encrypted (#108, #121) και μόνο στο «Show» (#134)· τα υπόλοιπα στο `audit-sweep.md` Σ6.
- [x] Σ7 Ένα `PlaceOrderService` (τιμολόγηση σε 5 σημεία που διαφωνούν· κουπόνια, admin Create Order, διπλό checkout).
      **Σ7a:** `PlaceOrderService` και `OrderPricing`/`packages/pricing` σε decimal για καλάθι, checkout, admin Create Order
      και επανυπολογισμό· CART-1/2/4/5/6/7/8/10/18/19 (`orders.md`). **Σ7b (Δ1):** καθαρά ποσά, ΦΠΑ ανά γραμμή με
      συντελεστή, `taxable` ανά προϊόν, `tax_exempt` ανά λογαριασμό, CART-3/14 (`orders.md`).
- [ ] Σ8 Τιμολόγιο ως έγγραφο: Mark Paid με συναλλαγή, κλείδωμα πελάτη/νομίσματος, adjustment notes append-only, PDF.
      **Έγιναν:** Mark Paid μέσω πληρωμής (MT-1c), κλείδωμα γραμμών του paid (MT-1c/2b), όχι ακύρωση με χρήματα
      (2c-1), adjustment notes append-only (MT-1c), route binding (#65). **Μένουν:** αλλαγή πελάτη/νομίσματος σε
      τιμολόγιο με χρήματα (INV-10), σύνολα/«Πληρώθηκε/Υπόλοιπο» στο PDF (INV-8), «Issued At» (INV-17).
- [ ] Parity για το go-live της nixpal (στα αντίστοιχα items παρακάτω): auto-charge στη λήξη με retries (PR H1), refunds/
      chargebacks από τα gateways, υπενθυμίσεις, `FetchEmails` και ειδοποιήσεις staff για tickets, cPanel adapter,
      requirements του importer. Έγιναν: H1 (#148), refunds/chargebacks (#83, #85), υπενθυμίσεις (#99), διορθώσεις του
      cPanel adapter (#125, #147). Μένουν: `FetchEmails` (υπάρχει, να ελεγχθεί) και ειδοποιήσεις staff για
      tickets, έλεγχος cPanel στο devbox, importer.

## Φάση 1: nixpal (3–5 εβδομάδες)
**Πυρήνας χρημάτων και ανανεώσεων**
- [ ] `addPayment`: guards για ποσό, νόμισμα, status τιμολογίου, ιδιοκτησία, μεταβάσεις μόνο προς τα εμπρός· unique
      `transaction_id`.
      Και (`docs/billing-cursor.md` PR 2): τιμολόγιο που δημιουργείται paid να ανανεώνει· τιμολόγιο να γίνεται paid όταν η
      τιμή μιας γραμμής πέφτει κάτω από ό,τι πληρώθηκε (ή στα 0 €)· περίοδος με πλήρη επιστροφή να μη μετράει ως πληρωμένη.
      Έγιναν: guards ποσού/νομίσματος/idempotency (2a, #37), Mark Paid μόνο με πληρωμή (#53), τιμολόγιο που καλύπτεται
      ή πέφτει στα 0 € κλείνει (2b, #74), η περίοδος refunded τιμολογίου είναι τρύπα (#97). Να επιβεβαιωθούν: unique
      `transaction_id` στη βάση (σήμερα idempotency με lock) και το «δημιουργείται ήδη paid».
- [x] Ανανέωση δεμένη με **τιμολόγιο και περίοδο** (cursor, μία ανανέωση ανά τιμολόγιο), PR #32 (2026-10-12,
      `docs/billing-cursor.md`). Η «όχι ανανέωση από ακυρωμένο τιμολόγιο» είναι στα guards του `addPayment` (PR 2).
- [ ] Status υπηρεσίας μόνο μετά την επιτυχία του server job· reconciliation (#1186). Το create έγινε (Σ1-a, #114:
      active μόνο μετά το create· τα άλλα ακολουθούν τη χρέωση, απόφαση 2 του `provisioning.md`). Μένει το reconciliation.
- [ ] Ακυρώσεις τιμολογίων μέσω observers (όχι μαζικό `->update()`)· ακύρωση προϊόντος όταν ακυρώνεται το τιμολόγιό του.
      Μέσω μοντέλου και ανά γραμμή: MT-2b (#67)· ακυρωμένη ανανέωση = η περίοδος δεν χρεώνεται (Δ3a, #123).
- [x] Υπερπληρωμή → πίστωση· ledger πιστώσεων· αυτόματη ή χειροκίνητη εφαρμογή (ρύθμιση)· add/remove από admin.
      (#37, CreditLedger #42, αυτόματη εφαρμογή με `credit_auto_apply` ανά λογαριασμό #56, «Apply credit» του staff #138.)
- [x] **Money trail και παρατηρησιμότητα** (σχέδιο και αποφάσεις: `docs/money-trail.md`, PRs MT-1..MT-3, έγιναν· έγινε και το 2b (τιμολόγιο που καλύπτεται κλείνει, 0 € αυτόματα)· έγινε και το 2c-1 (επιστροφή πίσω ή σε πίστωση, status refunded, όχι ακύρωση με χρήματα)· έγινε και το 2c-2 (επιστροφές και chargebacks από το gateway, PAY-5, #83/#85)): append-only ημερολόγιο κινήσεων χρήματος
      (πληρωμή, πίστωση, χρέωση πίστωσης, επιστροφή, με αιτία, πηγή και ποιος), audit και στους queue workers, χωρίς
      μαζικά `->update()` σε τιμολόγια/πιστώσεις, ιστορικό audit στο admin των τιμολογίων, μεγαλύτερη διατήρηση logs
      για πληρωμές, καθημερινός έλεγχος συνέπειας (`mycs:money-check`) με ειδοποίηση στο staff.
- [ ] **GDPR και πελάτες χρόνια μετά** (μετά το cut-over, `docs/money-trail.md` §9): ανωνυμοποίηση αντί για διαγραφή
      (κρατά ποσά και κινήσεις), export των δεδομένων του πελάτη (admin και client area), πολιτική διατήρησης με
      αυτόματη απενεργοποίηση και ανωνυμοποίηση μετά τη νόμιμη περίοδο (να επιβεβαιωθεί με τον λογιστή).
- [ ] **Λογαριασμοί: staff, multi-user, προφίλ πελάτη** (`docs/accounts.md`: απογραφή #87, σχέδιο με αποφάσεις 2026-10-14). **Αμέσως μετά τα
      MT-x και πριν τα migrations/cut-over της nixpal** (απόφαση 2026-10-12), όσο δεν υπάρχουν ζωντανά δεδομένα:
      (α) **staff χωριστά από πελάτες** (πίνακας/guard για το admin, όπως WHMCS/Blesta/Ekdosi· σήμερα admin = user με
      `role_id`)· (β) **ένας χρήστης, πολλοί λογαριασμοί πελάτη** (Users του WHMCS 8, μεταφέρθηκε εδώ από τη Φάση 3)·
      (γ) **προφίλ πελάτη** «σαν WHMCS/Ekdosi»: ιδιώτης/επιχείρηση, επωνυμία, ΑΦΜ/VAT, ΔΟΥ, δραστηριότητα, «Θέλω
      τιμολόγιο» (για αργότερα, myip), στοιχεία χρέωσης, σημειώσεις staff. **Parity με το WHMCS** (`tblclients`,
      `tblusers`, `tblusers_clients`, `tblcontacts`, custom fields): κάθε πεδίο έχει σπίτι εδώ, ώστε το migration να
      είναι αντιστοίχιση 1:1. Ο importer και το bridge προς Ekdosi χτίζονται πάνω σε αυτό το σχήμα.
      **Έγιναν:** (α) staff χωριστά (PR 1, #100), (β) λογαριασμοί και πολλοί users (2a #101, 2b #102), (γ) επαφές με
      τα πεδία του προφίλ (3a, #103), σχετική επαφή υπηρεσίας (3b, #116). **Μένουν:** σημειώσεις staff στον λογαριασμό
      (`tblclients.notes`, `tblnotes`, βλ. «Notes του WHMCS που λείπουν»), importer νέου σχήματος (PR 4), bridge (PR 5).
- [ ] **Σελίδα πελάτη στο admin «σαν WHMCS» με Summary** (ζητήθηκε 2026-10-16): σήμερα υπάρχουν οι σελίδες Edit,
      Services, Invoices, Credits, Tickets, Billing Agreements και οι επαφές/users· λείπει η σύνοψη του WHMCS (στοιχεία,
      υπόλοιπα και πίστωση, υπηρεσίες/domains ανά status, απλήρωτα, πρόσφατα emails και tickets, γρήγορες ενέργειες).
- [x] **Mass Pay** (allocator του Ekdosi, #1137)· το **merge/split** τιμολογίων είναι το PR E (`docs/payments.md`).
      (Merge/split #136· πληρωμή σε πολλά τιμολόγια από το staff F1 #138· Mass Pay πελάτη μέσω gateway F2 #141· Mass Pay
      του WHMCS στον importer F3 #143.)
- [x] **Αλλαγή κύκλου χρέωσης** και πακέτου μαζί, με proration, από πελάτη και admin· ακύρωση εκκρεμών upgrades (#1266).
      (SVC-14a #113, SVC-14b #115· εκκρεμή upgrades ακυρώνονται με αίτημα ακύρωσης, CR-10 #73.)
      **SVC-14a έγινε:** η αναλογία πάνω στο πραγματικό ποσό της υπηρεσίας, ανά μονάδα, στις μέρες της πραγματικής
      περιόδου· όχι απλήρωτες μέρες μετά τη λήξη· όλη η προπληρωμένη περίοδος (`decisions.md` §7). **14b έγινε:** αλλαγή
      κύκλου από staff (τώρα ή από την επόμενη ανανέωση) και πελάτη (από την επόμενη ανανέωση).
- [ ] Πακέτο που γίνεται free/one-time ενώ η υπηρεσία έχει τιμή: χρεώνεται μία φορά ακόμα και μένει για πάντα· να
      κλειδώνει ή να ζητά απόφαση του staff (`billing-cursor.md` → «PRs», 5).
- [ ] **Φόρμα υπηρεσίας «σαν WHMCS»** (admin): το staff αλλάζει συχνά Next Due Date, Recurring Amount, Billing Cycle,
      Payment Method, Promotion Code. Τα περισσότερα υπάρχουν με άλλο όνομα (`Expires At`, `Price`, `Plan`, `Coupon`):
      μετονομασία στους όρους του WHMCS. Λείπουν: First Payment Amount, Payment Method ανά υπηρεσία
      (κατάθεση/κάρτα/PayPal, όχι μόνο billing agreement), Registration και Termination Date, Auto-Terminate End of
      Cycle, Admin Notes (το Subscription ID υπάρχει). Οι αλλαγές με το χέρι είναι ήδη συμβατές με τον δρομέα
      χρέωσης (`docs/billing-cursor.md`). Το ίδιο αργότερα για τα domains.
      Έγιναν: domain, username/password, IPs, Admin Notes και λόγος αναστολής (#108)· η μέθοδος πληρωμής είναι του
      λογαριασμού, με auto-charge (C2 #129, απόφαση 6)· ημερομηνίες όταν ξεκινά η υπηρεσία (#117). Μένουν τα υπόλοιπα.
- [ ] **«Μεταφορά σε άλλο πελάτη»** (admin, υπηρεσία και αργότερα domain, όπως το «Move Product/Service» του WHMCS):
      π.χ. agency/reseller που λέει «ο πελάτης θα πληρώνει μόνος του, ανοίξτε του καρτέλα». Μία ενέργεια μεταφέρει την
      υπηρεσία **και τα απλήρωτα τιμολόγιά της** στον νέο πελάτη, με επιβεβαίωση και καταγραφή (ποιος, πότε, από ποιον
      σε ποιον). Τα πληρωμένα μένουν σε όποιον πλήρωσε: τιμολόγιο με πληρωμή κρατά πελάτη και νόμισμα
      (`docs/money-trail.md` §8)· για μερικώς πληρωμένο, η ενέργεια προτείνει τον σωστό δρόμο (επιστροφή ή πίστωση στον
      παλιό, νέο τιμολόγιο στον νέο). Σήμερα γίνεται με το χέρι: αλλαγή πελάτη στη φόρμα υπηρεσίας και σε κάθε απλήρωτο
      τιμολόγιο. Ταιριάζει με τους «Λογαριασμούς» (`docs/accounts.md`).
- [ ] **Products «σαν WHMCS»** (admin). Οι *Categories* του upstream είναι ήδη τα product groups (υποκατηγορίες,
      σειρά)· τα products έχουν ήδη `hidden`, stock και τύπο πληρωμής ανά plan (free/one-time/recurring). Λείπουν:
      κρυφή ομάδα (εκτός σελίδας παραγγελίας, παραγγέλνεται με direct link)· λίστα products ανά ομάδα με στήλες Type
      (module), Pay Type, Stock, Auto Setup, σειρά με drag και «Duplicate Product». **Auto Setup ανά προϊόν**: σήμερα
      ο server στήνεται πάντα στην πρώτη πληρωμή (ή αμέσως αν είναι δωρεάν)· όπως στο WHMCS: με την παραγγελία / με την
      πρώτη πληρωμή / όταν το staff αποδεχτεί την παραγγελία (θέλει «Accept Order» στα Pending Orders) / ποτέ. Μαζί με τα
      status παραγγελίας του Δ4 (`decisions.md`: πότε γίνεται `active` η παραγγελία).
      Αγγίζει την ενεργοποίηση μετά την πληρωμή, άρα code και adversarial review. Τα server groups: Φάση 3.
      Έγιναν: Auto Setup ανά προϊόν (Σ1-b1, #117), status παραγγελίας και Accept Order (Σ1-b2, #119), pro rata
      προϊόντος (D, #132), server group ανά προϊόν (#112). Μένουν: κρυφή ομάδα, στήλες της λίστας.
- [ ] «Days before» τιμολογίου **ανά κύκλο**· «continuous invoice generation» ως ρύθμιση.
- [x] Υπενθυμίσεις (port από Ekdosi): πριν τη λήξη και 1η/2η/3η ληξιπρόθεσμου, ρυθμιζόμενες ημέρες ανά instance.
      (Σ4 μέρος 3, #99· ημέρες στην καρτέλα Automation, #66.)
- [ ] Ποσά σε decimal/minor units· Stripe: `round()` και helper για minor units (#1258). Η τιμολόγηση είναι σε decimal
      (Σ7a/Σ7b, #107, #109)· μένουν τα floats/fees των gateways (PAY-16) και ο helper.

**Φόροι, τιμές, αρίθμηση**
- [x] **EU VAT:** κανόνες ανά χώρα, ΑΦΜ πελάτη, VIES (`ViesLookup` του Ekdosi), reverse charge, εξαιρέσεις πελατών.
      (G1 #145, `eu-vat.md`, `packages/vies`, `vat:recheck`· `tax_exempt` ανά λογαριασμό από το Σ7b #109.)
- [ ] Ομάδες πελατών με έκπτωση· recurring promotions.
- [ ] Αρίθμηση `{YEAR}-{NUMBER}` + proforma (υπάρχει ως ρύθμιση· έλεγχος με τα νούμερα της nixpal).
- [ ] Ρυθμίσεις: Group Similar Line Items, PDF συνημμένο στο email, σημειώσεις στο checkout.

**Πληρωμές**
- [ ] Stripe με αποθηκευμένες κάρτες και auto-charge (υπάρχει)· fallback επιβεβαίωσης στην επιστροφή (#1260). Έγιναν:
      μέθοδος ανά λογαριασμό (C2 #129), χρέωση τη μέρα λήξης με retries και μία χρέωση τη φορά (H1 #148), ειδοποίηση
      λήξης κάρτας και «να μη χρεωθεί αυτόματα» ανά τιμολόγιο (H2b #150). Μένει το fallback (#1260).
- [ ] PayPal ppcpv vault (`packages/paypal-vault`)· PayPal legacy subscriptions (77 υπηρεσίες, 5 ενεργές):
      **απόφαση 2026-10-15 (Π7.9):** οι 5 ενεργές μεταφέρονται στο vault πριν το cut-over· το IPN του WHMCS
      (`/modules/gateways/callback/paypal.php`) μένει ως δίχτυ που ειδοποιεί το staff και δεν καταγράφει (PR H2b).

**Licensing**
- [~] `nixpal_licensing` ως Server extension πάνω στο `packages/nixpal-licensing`· `zlic.php` byte-for-byte με τα
      fixtures· cache/rate-limit (588k checks/έτος). **L1** (πίνακες, endpoint, extension, PSK κρυπτογραφημένο, σημεία
      ζωής) έγινε (#106)· **L2** (οθόνες πελάτη και staff, κονσόλα αδειών, #128· τελευταίο check στην άδεια, #130)
      έγινε· **L3** (εργαλείο import από το WHMCS, `app:import-whmcs-licensing`, #131) έγινε, το import στο live με το
      cut-over. Access log: τα καλά checks μία φορά τη μέρα (#133)· κλειδί = domain της υπηρεσίας (#127). Cache
      απαντήσεων δεν μπήκε (δεν χρειάζεται, `docs/licensing.md`)· μένει το rate-limit του `zlic.php`.

**Hosting και domains της nixpal** (λίγα, αλλά υπάρχουν· orion, 2026-10-11)
- [ ] cPanel: **1 server** (όριο 20), **12 ενεργοί λογαριασμοί**, χωρίς server groups. Αρκεί το cPanel extension
      που υπάρχει (ένας server ανά προϊόν)· έλεγχος create/suspend/unsuspend/terminate/αλλαγή πακέτου στο devbox,
      `encrypted` credentials. Ο importer κρατά server και username κάθε υπηρεσίας. (+1 ενεργή υπηρεσία χωρίς module.)
      Στον κώδικα: change package, idempotent create, επιλογή server (#125), Test Connection που λέει γιατί (#147),
      `services.server_id` (#112). Μένει ο έλεγχος στο devbox.
- [ ] **cPanel: sync χρήσης** (disk/bandwidth ανά λογαριασμό από το WHM, όπως το «Update Usage» του WHMCS), στη σελίδα
      υπηρεσίας και στον πελάτη.
- [ ] Domains: **11** (Openprovider 7, CNIC 3 + 1 σε Grace). Θέλουν το Openprovider της Φάσης 2 και είτε driver CNIC
      είτε μεταφορά των 4 CNIC στο Openprovider πριν το cut-over (απόφαση).

**Λειτουργία της nixpal (παραγωγή)**
- [ ] Πού ζει το instance (server, domain π.χ. `mycs.nixpal.com` ή `clients.nixpal.com`)· TLS, cron, queue.
- [ ] Αποστολή email (SMTP/SPF/DKIM) και templates στα αγγλικά για τα γεγονότα της nixpal (τιμολόγιο, πληρωμή,
      υπενθυμίσεις, λήξη, αναστολή).
- [ ] Branding ανά instance: logo, χρώματα, στοιχεία εταιρείας στο PDF του τιμολογίου.
- [ ] Backups βάσης και `storage/` εκτός server, με δοκιμή επαναφοράς.
- [ ] Παρακολούθηση: error log, failed jobs, cron που σταμάτησε. Έγινε το `mycs:health` (scheduler heartbeat, cron,
      queue, failed jobs, mail, module actions, licenses· #139, #140) και το `health` του MCP (#142)· μένει η
      ειδοποίηση (ποιος το τρέχει και ποιον ενημερώνει) και ένα health endpoint (`ops.md`).
- [ ] **Χρονοπρογραμματιστής σαν του Ekdosi:** σήμερα system cron → `schedule:run` κάθε λεπτό + worker
      (`mycs-queue`), αλλά όλη η χρέωση είναι **ένα** `CronJob` ημερησίως, 7 δουλειές σε ένα transaction (αν σκάσει η
      μία, αναιρούνται όλες· κρατά locks). Σπάσιμο σε ξεχωριστές εργασίες (τιμολόγια ανανέωσης, ακυρώσεις
      παραγγελιών, upgrades, αναστολές, τερματισμοί, tickets, logs· αργότερα domains sync/pending transfers,
      υπενθυμίσεις, καταθέσεις τράπεζας), καθεμία με δικό της cron/ώρα και on/off από το admin, `withoutOverlapping`,
      και κατάσταση τελευταίας εκτέλεσης στο dashboard.
- [ ] **2FA:** υπάρχει (TOTP, ίδιο για πελάτες και staff· ο admin το απενεργοποιεί από τον χρήστη). Λείπουν:
      υποχρεωτικό για staff (στη myip το έχουν και οι 4 admins του WHMCS), backup/recovery codes. Port από Ekdosi.
      Τα secrets του WHMCS (10 πελάτες myip, 1 nixpal) δεν μεταφέρονται: ξανά ενεργοποίηση.
      Με το PR 1 (#100) το staff έχει δικό του 2FA με recovery codes· υποχρεωτικό για staff: όχι (απόφαση 3,
      `accounts.md`). Μένουν τα recovery codes των πελατών.

**Εργαλεία υποστήριξης και audit (έγιναν 2026-10-15, δεν ήταν στο roadmap)**
- [x] **Mail audit** (`mail.md`, #137): κάθε mail με παραλήπτες, template, «για τι» και «ποιος», η πορεία του
      (pending → sending → sent/failed, μία αποστολή το πολύ), `app:mail-sweep`, Resend από το staff,
      `app:mail-resend-failed`. Και: ειδοποίηση αναστολής στο staff, «ακυρώθηκε όπως ζητήσατε» (#135).
- [x] **Ops, μόνο ανάγνωση** (`ops.md`, #139, #140): `mycs:health`, `mycs:service`, `mycs:client`, `mycs:invoice`,
      `mycs:zlic` (probe χωρίς εγγραφή), `mycs:logs`, με `--json` και χωρίς secrets.
- [x] **MCP server για το staff** (`mcp.md`): read-only εργαλεία πάνω στο Ops (#142), OAuth για τον connector του
      claude.ai με έγκριση στο admin (#144), προτάσεις ενεργειών (retry/resolve module action, resend mail) που
      εγκρίνει το staff, και `server_test` (#146). Καταγραφή κλήσεων στο Other → MCP Calls.
- [x] **Licensing, σημεία ζωής:** τελευταίο check στην άδεια του πελάτη (#130), τα καλά checks στο access log μία φορά
      τη μέρα (#133).

**Importer και cut-over nixpal**
- [ ] Importer (`importer.md`): clients, επαφές, custom fields, υπηρεσίες, τιμολόγια με ποσά, πληρωμές, πιστώσεις,
      promotions/ομάδες, κάρτες Stripe, vault tokens, άδειες, ρυθμίσεις από το `tblconfiguration`· re-runnable, id map.
      Σήμερα το `app:import-from-whmcs` είναι ο importer του upstream με διορθώσεις (status παραγγελιών, Mass Pay F3
      #143, άδειες L3 #131), όχι ακόμα ο importer νέου σχήματος.
- [ ] **Importer: το κομμάτι του provisioning ως εργαλείο** (όπως το L3 των αδειών): servers, server groups, ο server
      και το username κάθε υπηρεσίας (`tblhosting.server`), re-runnable και μόνο του (βλ. και Φάση 5).
- [ ] `mycs:parity` με μηδενικό diff.
- [ ] **Σενάριο κύκλου ζωής στο staging** (συνθετικός πελάτης): παραγγελία → πληρωμή → create στον server → ανανέωση
      (auto-charge) → ληξιπρόθεσμο → αναστολή → πληρωμή → unsuspend → αλλαγή πακέτου/κύκλου → ακύρωση → terminate, με
      `mycs:health`/`mycs:money-check` καθαρά και τα mails στο mail audit μετά από κάθε βήμα.
- [ ] Export εσόδων/εξόδων ανά έτος (CSV/XLSX) για τον λογιστή.
- [ ] **Login links από την κονσόλα** (για δοκιμές και support): `mycs:login-link {user} [--ttl=15]` βγάζει link που
      συνδέει ως πελάτης ή ως staff (με τον ρόλο του). Το link είναι μιας χρήσης και λήγει σύντομα: token σε hash στη
      βάση, ποτέ σε log. Κάθε έκδοση και χρήση γράφεται στο audit (ποιος, πότε, από κονσόλα). Μόνο από CLI: όποιος την
      τρέχει έχει ήδη πρόσβαση στον server. Κλειστό εξ ορισμού, ανοίγει ανά instance από το `.env`. Βάση το υπάρχον
      «Login as Client» (`ImpersonateMiddleware`). Ανοιχτή απόφαση: αν το link παρακάμπτει το 2FA (χρειάζεται για
      αυτοματοποιημένο browser), μόνο σε devbox/staging.
- [ ] **Έλεγχος σε πραγματικό browser πριν το cut-over** (money trail, παραδείγματα χρημάτων, edge cases), με το Chrome
      extension ή τα browser tools, πάνω στα login links. Σκοπός: να ξέρουμε ότι ό,τι περνά στα tests ισχύει και στην
      πράξη. Μόνο στο devbox/staging (`mycs.myip.gr`), με συνθετικούς πελάτες, gateways σε test mode (Stripe test,
      PayPal sandbox) και email σε mail catcher. Ποτέ σε παραγωγή ή με πραγματικά δεδομένα.
      - Σενάρια από πελάτη και staff: πληρωμή, μερική πληρωμή, υπερπληρωμή → πίστωση, πληρωμή με πίστωση, refund
        (μερικό και ολικό), Mark Paid / Add Payment, διαγραφή συναλλαγής, ακύρωση τιμολογίου και υπηρεσίας με χρήματα
        πάνω τους, ανανέωση, webhook που ξαναστέλνεται, λάθος νόμισμα ή ποσό.
      - Μετά από κάθε σενάριο: `mycs:money-check` χωρίς ευρήματα· καρτέλα «Movements», Log πύλης, κανάλι `payments`
        και emails συμφωνούν με αυτό που έγινε· τι βλέπει ο πελάτης και τι το staff.
      - Τα αποτελέσματα σε `docs/audit-browser.md` (σενάριο, αναμενόμενο, αποτέλεσμα, PR διόρθωσης)· screenshots
        χωρίς προσωπικά δεδομένα. Ό,τι βρεθεί γίνεται failing test πριν διορθωθεί.
- [ ] **Υπενθύμιση πριν το cut-over: πέρασμα UI/UX «σαν WHMCS»** (admin και client area, για nixpal και myip). Το
      upstream έχει έτοιμη λειτουργικότητα αλλά γυμνό UI. Πρώτα λίστα από ό,τι χρησιμοποιούμε καθημερινά στο WHMCS
      (screenshots, `whmcs-usage.md`), μετά αποφασίζουμε τι μπαίνει πριν και τι μετά. Ενδεικτικά: σελίδα πελάτη με
      tabs (Summary, Profile, Users/Contacts, Products, Domains, Invoices, Transactions, Credit, Tickets, Notes, Log)·
      πυκνές λίστες με φίλτρα, αναζήτηση παντού (πελάτης, domain, τιμολόγιο, IP)· όροι και διάταξη του WHMCS στις
      φόρμες· γρήγορες ενέργειες (Mark Paid, Add Payment, Login as Client, Send Email)· dashboard με εκκρεμότητες·
      client area με καθαρή αρχική (υπηρεσίες, απλήρωτα, tickets) και branding ανά εταιρεία. Ελληνικά/αγγλικά.
      Πλοήγηση client area (2026-10-14): η Home, ο κατάλογος και το καλάθι δεν έχουν το μενού αριστερά (design του
      upstream: «βιτρίνα» ίδια με ή χωρίς login, ανά σελίδα `'sidebar' => true`). Να αποφασιστεί μαζί με το
      header/footer του myip.gr (Appearance → Custom code): μετά το login η Home με μενού ή κατευθείαν στο Dashboard
      (όπως το clientarea του WHMCS).
- [ ] Shadow-run του licensing → cut-over της nixpal, **μετά τα domains της Φάσης 2** (Openprovider, CNIC).

## Φάση 2: Domains (2–4 εβδομάδες, μετά το spike)
> Σχέδιο, αποφάσεις D-1…D-22 και σειρά PRs (D1–D8): [`domains.md`](domains.md) (2026-10-16).
- [x] **D1** σχήμα, μοντέλα, admin read-only (#157)· **D2** registrars, Openprovider, συνδέσεις (#159)· **D3** TLDs και
      τιμές, import και sync κόστους (#160)· **D4** sync, κύκλος ζωής, adopt (#161)· **D4b** διαχείριση από το admin:
      nameservers, lock, privacy, επαφές, EPP code, registrar log (#164)· **D4c** σύνδεση handles του registrar με
      επαφές, επεξεργασία επαφής από το domain (#165). Δοκιμασμένα live στο nixpal.com (sync, nameservers, «Link here»).
- [x] Κατάλογος της nixpal από το WHMCS (#162) και οι admins της (#166).
- [x] **D5** χρέωση και ανανέωση: D5a ημέρες grace/redemption (#168), D5b-1 τιμολόγηση (#169), D5b-2 πληρωμή →
      registrar (#170).
- [x] **D7a** «Invoice selected items» (υπηρεσίες και domains, ένα τιμολόγιο ανά πελάτη και νόμισμα, `domains.md` §6)
      και ακύρωση πριν τη χρεώσει το cron = η περίοδος χρεώνεται στην ώρα της (ADV-4) (#171).
- [x] **D7-1** ειδοποιήσεις λήξης 30/7/1/−3 με το κόστος καθυστέρησης, τιμολόγιο 15 μέρες πριν (#172).
- [x] **D7** τιμολόγια χαμένων domains (#175).
- [ ] **D7-2** portal (domains του πελάτη, NS, EPP, auto renew, ανανέωση).
- [ ] **Layout του portal** (ιδιοκτήτης 2026-10-17, για αργότερα): top bar με κύριο μενού (Αρχική, Υπηρεσίες, Domains,
      Τιμολόγηση, Υποστήριξη), ειδοποιήσεις, καλάθι και αναζήτηση· **μόνιμο αριστερό sidebar** με φίλτρα και ενέργειες
      ανά σελίδα· dashboard με tiles (υπηρεσίες, domains, αιτήματα, τιμολόγια) και κάρτες (ενεργές υπηρεσίες,
      ληξιπρόθεσμα, πρόσφατα αιτήματα, κατοχύρωση domain), όπως το θέμα του myip στο WHMCS.
- [ ] **Widget staff** για ανανεώσεις και σπασμένες
      εγγραφές· μετά D8a, D8b και **grepp** (.gr EPP, myip).
- [ ] **Ταχύτητα με πολλά domains/υπηρεσίες** (ιδιοκτήτης 2026-10-17: στο WHMCS πελάτης με ~50+ domains περιμένει πάνω
      από 60″ τη σελίδα). Αρχή στο MyCS: **καμία κλήση σε registrar ή server κατά το φόρτωμα σελίδας**· ό,τι δείχνει
      η σελίδα έρχεται από τη βάση, που την ενημερώνει το sync (κάθε 4 ώρες) και οι ενέργειες. Ζωντανά μόνο ό,τι ζητά ο
      χρήστης με κλικ (EPP code, αλλαγή NS) και με χρονικά όρια. Να γίνει: μέτρηση με fixture 50/500 domains και 200
      υπηρεσιών (χρόνος σελίδας, queries, N+1) σε λίστες πελάτη και admin· **Redis** για cache/sessions/queue στην
      παραγωγή (σήμερα file/database)· έλεγχος **OPcache** (και preload) στο PHP-FPM· pagination παντού· jobs στο queue για
      ό,τι αργεί (sync, bulk ενέργειες), με πρόοδο αντί για αναμονή. Πρώτο εύρημα: η αναζήτηση domain (D8a) κάνει πλέον
      μία κλήση ανά registrar με όριο 5″ και προϋπολογισμό χρόνου.
- [x] **Widget staff** (ιδιοκτήτης 2026-10-17, όπως το hack της myip στο WHMCS): επερχόμενες ανανεώσεις domains (με το
      τιμολόγιο ανανέωσής τους) και υπηρεσιών, 7/30/60/90 μέρες· «για έλεγχο»: domain χωρίς λήξη, μεταφορά που κόλλησε,
      Active αλλά ληγμένο (sync), υπηρεσία χωρίς ημερομηνία πληρωμής (`admin.widgets.renewals`). Μένει: υπόλοιπο
      registrar (χρειάζεται κλήση του Openprovider που δεν έχουμε ακόμα).
- [x] D8a (αναζήτηση, whois, τιμοκατάλογος), D8b-1 (παραγγελία), D8b-2 (εγγραφή/μεταφορά μετά την πληρωμή,
      `docs/domains.md` §6). EPP code μεταφοράς: 30 μέρες (ιδιοκτήτης 2026-10-17). Πριν το πρώτο order: Settings →
      Domains → «Default Nameserver 1–5» ανά instance (το συμπληρώνει ο ιδιοκτήτης).
- [x] **Tab «Domains» στα Settings + έτη ανανέωσης** που αλλάζει ο πελάτης/staff (ιδιοκτήτης 2026-10-17,
      `docs/domains.md` §6). Μετά: **grepp** μόνο του.
- [ ] **grepp** (.gr EPP, myip). Πλάνο (2026-10-18): G0 υλικό του μητρώου από το Ekdosi (έγινε) · G1 `packages/gr-epp`
      transport/session · G2 εντολές και parsers (XSD) · G3 driver ανάγνωσης · G4 οι 5 αλλαγές των υπηρεσιών για .gr
      (ζυγά έτη στη grace, NS μεταφοράς χωρίς RDAP, DACoR με email, χωρίς restore, μεταφορά άμεση) · G5 εγγραφές (χρήματα)
      · G6 whois .gr και import · G7 UAT με τον ιδιοκτήτη. Απαντήσεις ιδιοκτήτη 2026-10-18 στο `docs/domains/grepp.md` §0
      (χωρίς UAT: παραγωγή με δικά μας domains και recall· πρόθεμα c60· IPs δηλωμένες· NS από το WHOIS API). Τα 1.388
      στο migration από το WHMCS (importer D6). Ζυγά έτη 2–10 (FAQ του Μητρώου, `docs/domains/grepp/faq.md`).
      **Sync σε παρτίδες, για όλους τους registrars** (ιδιοκτήτης 2026-10-18, για ασφάλεια: Openprovider και όποιος έρθει,
      όχι μόνο .gr· στο G3 ή αμέσως πριν): σήμερα το `domains:sync` περνά όλα κάθε 4 ώρες, σειριακά· γίνεται N ανά εκτέλεση (τα παλιότερα `last_synced_at` πρώτα), συχνότερη εκτέλεση, παύση ανάμεσα στις
      κλήσεις, ρυθμίσεις στο Settings → Domains (ανά εκτέλεση, ελάχιστες ώρες ανά domain), με όριο ανά σύνδεση registrar
      (`DomainRegistrarConnection`)· τα pending πιο συχνά· «Sync now»
      και ο έλεγχος πριν τη χρέωση πάντα άμεσα.
- [ ] **Gateways της nixpal από το WHMCS** (ιδιοκτήτης 2026-10-17, στο τέλος, μαζί με τη δοκιμή πληρωμής ως πελάτης):
      PayPal, Stripe, Cardlink από το `tblpaymentgateways` της `clnixpal_nixcldb` (orion, μόνο ανάγνωση)· οι τιμές
      κατευθείαν κρυπτογραφημένες στη βάση, ποτέ σε αρχείο, log ή οθόνη. Πριν το go-live: αρίθμηση `{YEAR}-{NUMBER}` με
      proforma στις ρυθμίσεις (το πρώτο τιμολόγιο βγήκε `INV-1`).
- [ ] **D6** importer domains· **D7** υπενθυμίσεις και portal· **D8a/b** αναζήτηση, whois,
      τιμοκατάλογος, παραγγελίες (`domains.md` §6).
- [ ] Driver **CNIC** (CentralNic Reseller): myip 226, nixpal 4. Αρχικά σύνδεση `manual`· module μόνο αν προλάβουμε
      πριν μεταφερθούν στο Openprovider (`domains.md` D-10).
- [ ] Πολιτική ανανέωσης: on-paid (default) / on-issue ανά πελάτη / «Ανανέωση τώρα» με prepaid.
- [ ] Ρυθμίσεις domains (`settings-map.md`): auto renew on payment, default auto-renew, grace/redemption fee στο
      υπάρχον τιμολόγιο, default nameservers, IDN, to-do για αποτυχίες, renewal orders.
- [ ] Ειδοποιήσεις λήξης 60/30/15/10/5· do-not-renew· EPP code· additional fields.
- [ ] Αναζήτηση/whois/suggestions (`packages/domain-tools`)· domains στο portal· block existing domains. Μαζί δημόσιος τιμοκατάλογος TLDs και JSON API του για το site (D8a, `docs/domains.md` §6).
- [ ] Import domains από WHMCS + sync με τους registrars.
- [ ] On-demand renewals (πρόωρη ανανέωση από πελάτη, #1167).
- [ ] **Πολλοί registrars** ταυτόχρονα (Openprovider, CNIC, αργότερα .gr EPP), registrar ανά domain και ανά TLD.
- [x] **Τιμές TLD:** import από το WHMCS (`tbldomainpricing`, #162) και sync με τις τιμές κόστους των registrars (#160).
- [ ] **Grace / redemption:** status, χρεώσεις και ανάκτηση (μαζί με τις ρυθμίσεις παραπάνω).
- [x] **Sync ημερομηνιών** με τους registrars, με αναφορά διαφορών πριν αλλάξει κάτι (`domains:sync --dry-run`, D4).
- [ ] **Πολλοί WHM servers (myip 6–7):** ένας server χωρίς ρυθμίσεις δεν παίρνει πια τις ρυθμίσεις άλλου (closeout πριν
      το D5)· να δοκιμαστεί με δύο WHM πριν το cut-over του myip.

## Φάση 3: Τα απαραίτητα του myip.gr (4–6 εβδομάδες)
- [ ] ~~**Ένας χρήστης, πολλοί λογαριασμοί πελάτη**~~ → μεταφέρθηκε στη Φάση 1, «Λογαριασμοί» (2026-10-12).
      (Users του WHMCS 8): στη myip 101 προσκεκλημένοι χρήστες, 33 χρήστες
      με πρόσβαση σε >1 λογαριασμό, 93 λογαριασμοί με >1 χρήστη (+432 παλιά contacts)· στη nixpal 14 / 2.
      Το upstream δεν το έχει (χρήστης = πελάτης). Μοτίβο του Ekdosi (χρήστης ↔ εταιρείες με ρόλο/δικαιώματα),
      επιλογέας λογαριασμού στο client area, importer από `tblusers_clients` (2026-10-11).
- [x] **Eurobank/Cardlink + IRIS** (`packages/cardlink-eurobank`, με server-to-server returns): το 42% των πληρωμών. PR H2a
  (#149, `docs/payments.md` «Cardlink / Eurobank»)· μένει μία πραγματική πληρωμή στο test τερματικό.
- [ ] Έμβασμα + `payments_tracking` (αντιστοίχιση καταθέσεων από IMAP τράπεζας) πάνω στον allocator: ο πελάτης
      γράφει τον αριθμό προτιμολογίου στην αιτιολογία· αν ταιριάζει, mark paid το συγκεκριμένο τιμολόγιο (port του
      δικού μας κώδικα από το WHMCS).
- [ ] **Reports** στο admin (τα χρησιμοποιούμε συχνά): ιδέες από τα reports του WHMCS και του Ekdosi· μενού «Reports»
      στο `App\Admin\TopMenu`.
- [ ] Stripe SEPA.
- [ ] **Tickets** (port από Ekdosi) με τις ρυθμίσεις του `settings-map.md` → Support: guest tickets, piping ανά τμήμα,
      CC, αξιολογήσεις, canned replies, merge, σημειώσεις, spam, auto-close, όριο email, μορφή αριθμού.
- [ ] Knowledgebase (97 άρθρα) και announcements.
- [ ] Custom fields πελάτη πέρα από το βασικό προφίλ (το ΑΦΜ, η ΔΟΥ, το «Θα ήθελα τιμολόγιο» και το multi-user πάνε στη
      Φάση 1, «Λογαριασμοί»)· επαφές.
- [ ] Modules: TheSSLStore, Proxmox, Centova, mailcow, ngm, Hetzner· DirectAdmin (υπάρχει, + #1186). Κανένα ακόμα
      (στο `extensions/Servers` δεν υπάρχουν Proxmox/Centova)· πρώτα **Centova** και **Proxmox**.
- [x] **Server groups και κατανομή** (όπως το WHMCS, #112): το upstream έχει **ένα** `server_id` ανά προϊόν και καμία
      στήλη server στην υπηρεσία (το `ExtensionHelper` πάει πάντα στο `$service->product->server`). Χρειάζεται:
      `server_groups` (fill type: least full / fill until full), προϊόν → ομάδα **ή** server, `services.server_id` που
      κρατά πού ζει κάθε λογαριασμός (και όλες οι ενέργειες πάνε εκεί), όριο λογαριασμών και enable/disable ανά server,
      μεταφορά υπηρεσίας σε άλλο server από admin, sync/μέτρηση λογαριασμών. Ο allocator ως καθαρή κλάση με tests.
      Σήμερα: ομάδες ApacheServers / Greece-LiteSpeed / LiteSpeed-Germany / PVE Nodes / Centova, «least full».
      Έγινε (#112): server groups με «least full» / «in order» και `max_accounts`, `services.server_id`, στήλη Accounts·
      η αλλαγή server στην υπηρεσία καταγράφει μόνο πού ζει ο λογαριασμός (η μεταφορά γίνεται στο WHM).
- [ ] **Bridge προς Ekdosi** (billing source για το MyCS στο Ekdosi, ίδιο HMAC/ops, τιμολόγια σε τρίτους).
- [ ] Portal: ο πελάτης βλέπει τα **φορολογικά παραστατικά** που στέλνει πίσω το Ekdosi (`decisions.md` §2)·
      σύγκριση στην οθόνη portal Ekdosi vs upstream πριν αποφασίσουμε ποιες σελίδες κρατάμε.
- [ ] Business rules από τα ~20 hooks του myip (`history/blesta-modules/HANDOFF.md` §4.4).
- [x] Templates email el/en για όλα τα γεγονότα (#1306)· retry και ιστορικό emails (#1204, #1033). (Templates του
      WHMCS σε en/el #98, retries #96, mail audit και Resend #137.)
- [ ] Εύκολη επεξεργασία από admin: ημερομηνίες domain, ποσά υπηρεσίας, υποσύνολα τιμολογίου.
- [x] MCP server (μοτίβο του Ekdosi). (#142, #144, #146, `mcp.md`· βλ. Φάση 1, «Εργαλεία υποστήριξης».)
- [ ] **Ειδοποιήσεις Mattermost** (incoming webhook, ρύθμιση ανά instance) για τα ίδια events με το WHMCS του myip
      σήμερα (provider «Mattermost», 2026-10-11): ticket opened / reply πελάτη / reply staff, invoice paid, service
      suspended, service terminated, «payment auto matched» (σήμερα από API call). Υπάρχει το extension
      `DiscordNotifications` του upstream ως πρότυπο. Η nixpal δεν έχει ειδοποιήσεις.

## Φάση 4: .gr EPP
- [ ] `packages/gr-epp` (HTTPS `/epp/proxy` + `JSESSIONID`) ως driver του `DomainRegistrar`· UAT· owner change/transfer
      ως προϊόντα· 1.383 domains.

## Φάση 5: myip.gr cut-over
- [ ] Πλήρης importer (domains, addons, custom fields, tickets με συνημμένα, αρίθμηση = id, συνέχεια από τον
      τελευταίο αριθμό του WHMCS).
- [ ] Importer: servers, server groups και ο server κάθε υπηρεσίας (`tblhosting.server`), όχι μόνο του προϊόντος. Το
      σχήμα υπάρχει (#112)· ο importer δεν τα φέρνει ακόμα (βλ. «το κομμάτι του provisioning ως εργαλείο», Φάση 1).
- [ ] 2–3 δοκιμαστικές μεταφορές με `mycs:parity`· validation domains απέναντι στους registrars.
- [ ] Ekdosi: «Εισερχόμενα» από το MyCS χωρίς διπλά παραστατικά.
- [ ] Freeze, import, DNS/rewrites, forwarder IPN για PayPal, dump του WHMCS για αρχείο.

## Δεν γίνονται (απογραφή: δεν χρησιμοποιούνται)
Late fees, billable items, affiliates, escalation rules, network issues, fraud modules, φόρος δεύτερου επιπέδου,
premium domains, δωρεάν domain με hosting.
