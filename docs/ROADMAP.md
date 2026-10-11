# MyCS: Roadmap

> Ενιαία λίστα δουλειάς. Πηγές: φάσεις (`ekdosi-vs-paymenter.md`), parity backlog (`whmcs-usage.md`), ρυθμίσεις
> (`settings-map.md`), upstream (`upstream-triage.md`), audits (`audit-*.md`), αποφάσεις (`decisions.md`).
> Οι εκτιμήσεις είναι για έναν developer με βοήθεια: εκτιμήσεις, όχι δέσμευση. Ενημέρωσε τα κουτάκια σε κάθε PR.

## Παράλληλα, από τώρα (ανεξάρτητα από φάση)
- [x] **.gr EPP:** η IP του devbox (84.54.49.31) δηλώθηκε στο Μητρώο .GR (2026-10-11).
- [ ] .gr EPP: login/hello/check στο UAT από το devbox, ώστε να κλείσουν τα `[ΕΠΙΒΕΒΑΙΩΣΗ]`
      (`history/blesta-modules/HANDOFF.md` §6). Είναι το μακρύτερο κομμάτι· χωρίς αυτό το myip δεν μεταφέρεται.
- [x] DNS δοκιμών: `mycs.myip.gr` + `*.mycs.myip.gr` → devbox (2026-10-11).
- [x] Πρώτη εγκατάσταση στο devbox (`mycs.myip.gr`)· tests (66) και Pint περνάνε εκεί (2026-10-11).
- [ ] Παρακολούθηση των security advisories του Paymenter (`upstream-triage.md`).

## Φάση 0: Καθάρισμα και βάσεις (2–3 ημέρες)
- [x] Αφαίρεση telemetry: `TelemetryCommand`, schedule, `TELEMETRY_ENABLED`, `Settings::getTelemetry()`.
- [x] Αφαίρεση `app:logs` (ανέβαζε το τελευταίο error log στο `log.paymenter.org:99`).
- [x] Αφαίρεση update checker (`CheckForUpdates`, κλήση στο τέλος του `CronJob`), self-updater (`Upgrade`,
      `Admin/Pages/Updates`, `update.sh`), marketplace και ελέγχου ενημερώσεων extensions. Η σελίδα extensions
      κρατάει μόνο «διαθέσιμα» + upload zip.
- [ ] Δικός μας updater από GitHub releases/tags του `chrismfz/mycs` (μοτίβο: `UpdateChecker` / `ekdosi:self-update`
      του Ekdosi). Ο παλιός δεν κρατήθηκε: δούλευε με το `api.paymenter.org` και τα releases του upstream, άρα θα
      **αντικαθιστούσε το fork με το upstream**.
- [x] Αφαίρεση Gravatar (avatar = τοπικό SVG με αρχικά) και της ρύθμισης `gravatar_default`.
- [x] Workflows του upstream (`build-api-docs`, `release`, `beta`, `docker`) αφαιρέθηκαν (PR #9).
- [x] Branding/footer links (Powered by, Sponsor, Star on GitHub, docs του Paymenter): το admin footer δείχνει
      «MyCS · commit», `APP_NAME` = MyCS (2026-10-11).
- [x] Upload extensions από το web: αφαιρέθηκε. Τα extensions μπαίνουν ως κώδικας με git και `deploy/update.sh`
      (απόφαση 2026-10-11).
- [x] Κρυπτογραφημένα credentials σε όλα τα extensions (cPanel, Plesk, Convoy, Enhance, Virtualizor) **και** στη
      δημιουργία server/gateway, που τα αποθήκευε καθαρά· migration για όσα υπάρχουν (2026-10-11).
- [x] Templates email/ειδοποιήσεων, `mail_header`/`mail_footer` και snippet προϊόντος χωρίς Blade: renderer
      `packages/mail-template` (`templates.md`, `audit-security.md` H1–H2, 2026-10-11).
- [x] «Κανείς δεν δίνει ό,τι δεν έχει» (`PermissionGuard`): API keys, ρόλοι, ανάθεση ρόλου, επεξεργασία/διαγραφή/
      impersonation άλλου admin· το key δεν ξεπερνά ποτέ τον δημιουργό του (2026-10-11).
- [x] CI: tests σε sqlite + MariaDB 10.11 (PHP 8.4) και `pint --test` σε κάθε PR (PR #9, 2026-10-11).
- [x] CI: phpstan (larastan, level 5) σε `app/`, `extensions/`, `packages/mail-template` με baseline για τα 672 του
      upstream: σπάει μόνο σε νέα σφάλματα (2026-10-11).
- [ ] Μείωση του `phpstan-baseline.neon`: όποιο αρχείο αγγίζουμε, καθαρίζουμε και τις εγγραφές του. 672 → 404 με
      τύπους στις σχέσεις και `@property-read` στα accessors των models (2026-10-11).
- [ ] `lib/*` του blesta-modules → `packages/` (nixpal-licensing, cardlink-eurobank, paypal-vault, domain-tools,
      myopenprovider, mailcow), με composer `path` repositories και τα tests τους στο CI.
- [x] `corwindev/laravel-themer` → `packages/laravel-themer` (MIT) με διορθώσεις για PHP 8.4 (implicit nullable):
      τέλος τα `PHP Deprecated` σε κάθε `artisan` (2026-10-11). Το χρησιμοποιεί **μόνο το client area** (`themes/default`,
      ρύθμιση `theme`, `app:make-theme`)· το admin είναι Filament. Υποψήφιο για αφαίρεση αν μείνουμε σε ένα theme:
      μεταφορά του `themes/default` στο `resources/views` και κατάργηση του package.
- [ ] Σκελετός ελληνικής μετάφρασης (`lang/el`).
- [x] `deploy/update.sh` (από το Ekdosi): ενημέρωση με snapshot/maintenance/queue drain/migrate/build (2026-10-11).
      Μένουν: `deploy/rollback.sh` (restore snapshot + checkout), updater από το admin.
- [ ] Installer και updater όπως του Ekdosi: `/install` wizard + `mycs:install` (πρώτος admin, ρυθμίσεις, seed)
      αντί για τα `app:init`/`app:user:create` του Paymenter (μόνο όνομα εταιρείας + URL, χωρίς web installer,
      upstream #1011)· `mycs:self-update` με git checkout/composer/migrate/caches και rollback (μοτίβο
      `ekdosi:install`, `ekdosi:self-update`, `deploy/update.sh`, `deploy/rollback.sh`, `INSTALL.md`).
- [ ] Cherry-picks: #1338 (captcha), το enforcement του `credits_enabled` από το #1295.
- [ ] **Spike 1–2 ημερών:** port ενός μικρού κομματιού των domains του Ekdosi, για να μετρηθεί το πραγματικό κόστος
      (από `company_id` και φορολογικό Invoice σε προτιμολόγιο).

## Φάση 1: nixpal (3–5 εβδομάδες)
**Πυρήνας χρημάτων και ανανεώσεων**
- [ ] `addPayment`: guards για ποσό, νόμισμα, status τιμολογίου, ιδιοκτησία, μεταβάσεις μόνο προς τα εμπρός· unique
      `transaction_id`.
- [ ] Ανανέωση δεμένη με **τιμολόγιο και περίοδο** (cursor, μία ανανέωση ανά τιμολόγιο)· όχι ανανέωση από
      ακυρωμένο τιμολόγιο.
- [ ] Status υπηρεσίας μόνο μετά την επιτυχία του server job· reconciliation (#1186).
- [ ] Ακυρώσεις τιμολογίων μέσω observers (όχι μαζικό `->update()`)· ακύρωση προϊόντος όταν ακυρώνεται το τιμολόγιό του.
- [ ] Υπερπληρωμή → πίστωση· ledger πιστώσεων· αυτόματη ή χειροκίνητη εφαρμογή (ρύθμιση)· add/remove από admin.
- [ ] **Mass Pay** και **merge τιμολογίων** (allocator του Ekdosi, #1137).
- [ ] **Αλλαγή κύκλου χρέωσης** και πακέτου μαζί, με proration, από πελάτη και admin· ακύρωση εκκρεμών upgrades (#1266).
- [ ] «Days before» τιμολογίου **ανά κύκλο**· «continuous invoice generation» ως ρύθμιση.
- [ ] Υπενθυμίσεις (port από Ekdosi): πριν τη λήξη και 1η/2η/3η ληξιπρόθεσμου, ρυθμιζόμενες ημέρες ανά instance.
- [ ] Ποσά σε decimal/minor units· Stripe: `round()` και helper για minor units (#1258).

**Φόροι, τιμές, αρίθμηση**
- [ ] **EU VAT:** κανόνες ανά χώρα, ΑΦΜ πελάτη, VIES (`ViesLookup` του Ekdosi), reverse charge, εξαιρέσεις πελατών.
- [ ] Ομάδες πελατών με έκπτωση· recurring promotions.
- [ ] Αρίθμηση `{YEAR}-{NUMBER}` + proforma (υπάρχει ως ρύθμιση· έλεγχος με τα νούμερα της nixpal).
- [ ] Ρυθμίσεις: Group Similar Line Items, PDF συνημμένο στο email, σημειώσεις στο checkout.

**Πληρωμές**
- [ ] Stripe με αποθηκευμένες κάρτες και auto-charge (υπάρχει)· fallback επιβεβαίωσης στην επιστροφή (#1260).
- [ ] PayPal ppcpv vault (`packages/paypal-vault`)· PayPal legacy subscriptions (77 υπηρεσίες, 5 ενεργές):
      μετάβαση σε vault ή forwarder του IPN.

**Licensing**
- [ ] `nixpal_licensing` ως Server extension πάνω στο `packages/nixpal-licensing`· `zlic.php` byte-for-byte με τα
      fixtures· cache/rate-limit (588k checks/έτος).

**Hosting και domains της nixpal** (λίγα, αλλά υπάρχουν· orion, 2026-10-11)
- [ ] cPanel: **1 server** (όριο 20), **12 ενεργοί λογαριασμοί**, χωρίς server groups. Αρκεί το cPanel extension
      που υπάρχει (ένας server ανά προϊόν)· έλεγχος create/suspend/unsuspend/terminate/αλλαγή πακέτου στο devbox,
      `encrypted` credentials. Ο importer κρατά server και username κάθε υπηρεσίας. (+1 ενεργή υπηρεσία χωρίς module.)
- [ ] Domains: **11** (Openprovider 7, CNIC 3 + 1 σε Grace). Θέλουν το Openprovider της Φάσης 2 και είτε driver CNIC
      είτε μεταφορά των 4 CNIC στο Openprovider πριν το cut-over (απόφαση).

**Λειτουργία της nixpal (παραγωγή)**
- [ ] Πού ζει το instance (server, domain π.χ. `mycs.nixpal.com` ή `clients.nixpal.com`)· TLS, cron, queue.
- [ ] Αποστολή email (SMTP/SPF/DKIM) και templates στα αγγλικά για τα γεγονότα της nixpal (τιμολόγιο, πληρωμή,
      υπενθυμίσεις, λήξη, αναστολή).
- [ ] Branding ανά instance: logo, χρώματα, στοιχεία εταιρείας στο PDF του τιμολογίου.
- [ ] Backups βάσης και `storage/` εκτός server, με δοκιμή επαναφοράς· `deploy/rollback.sh`.
- [ ] Παρακολούθηση: error log, failed jobs, cron που σταμάτησε.

**Importer και cut-over nixpal**
- [ ] Importer (`importer.md`): clients, επαφές, custom fields, υπηρεσίες, τιμολόγια με ποσά, πληρωμές, πιστώσεις,
      promotions/ομάδες, κάρτες Stripe, vault tokens, άδειες, ρυθμίσεις από το `tblconfiguration`· re-runnable, id map.
- [ ] `mycs:parity` με μηδενικό diff.
- [ ] Export εσόδων/εξόδων ανά έτος (CSV/XLSX) για τον λογιστή.
- [ ] Shadow-run του licensing → cut-over της nixpal, **μετά τα domains της Φάσης 2** (Openprovider, CNIC).

## Φάση 2: Domains (2–4 εβδομάδες, μετά το spike)
- [ ] Port του υποσυστήματος του Ekdosi: contract `DomainRegistrar`, models, Openprovider, Renewal/Registration/
      Transfer/Sync/Management, adopt, tests.
- [ ] Driver **CNIC** (CentralNic Reseller): myip 209, nixpal 4. Το Ekdosi έχει μόνο Openprovider.
- [ ] Πολιτική ανανέωσης: on-paid (default) / on-issue ανά πελάτη / «Ανανέωση τώρα» με prepaid.
- [ ] Ρυθμίσεις domains (`settings-map.md`): auto renew on payment, default auto-renew, grace/redemption fee στο
      υπάρχον τιμολόγιο, default nameservers, IDN, to-do για αποτυχίες, renewal orders.
- [ ] Ειδοποιήσεις λήξης 60/30/15/10/5· do-not-renew· EPP code· additional fields.
- [ ] Αναζήτηση/whois/suggestions (`packages/domain-tools`)· domains στο portal· block existing domains.
- [ ] Import domains από WHMCS + sync με τους registrars.
- [ ] On-demand renewals (πρόωρη ανανέωση από πελάτη, #1167).

## Φάση 3: Τα απαραίτητα του myip.gr (4–6 εβδομάδες)
- [ ] **Eurobank/Cardlink + IRIS** (`packages/cardlink-eurobank`, με server-to-server returns): το 42% των πληρωμών.
- [ ] Έμβασμα + `payments_tracking` (αντιστοίχιση καταθέσεων από IMAP τράπεζας) πάνω στον allocator.
- [ ] Stripe SEPA.
- [ ] **Tickets** (port από Ekdosi) με τις ρυθμίσεις του `settings-map.md` → Support: guest tickets, piping ανά τμήμα,
      CC, αξιολογήσεις, canned replies, merge, σημειώσεις, spam, auto-close, όριο email, μορφή αριθμού.
- [ ] Knowledgebase (97 άρθρα) και announcements.
- [ ] Custom fields πελάτη (ΑΦΜ, ΔΟΥ, «Θα ήθελα τιμολόγιο», σημειώσεις, σήμανση) και επαφές/multi-user.
- [ ] Modules: TheSSLStore, Proxmox, Centova, mailcow, ngm, Hetzner· DirectAdmin (υπάρχει, + #1186).
- [ ] **Server groups και κατανομή** (όπως το WHMCS): το Paymenter έχει **ένα** `server_id` ανά προϊόν και καμία
      στήλη server στην υπηρεσία (το `ExtensionHelper` πάει πάντα στο `$service->product->server`). Χρειάζεται:
      `server_groups` (fill type: least full / fill until full), προϊόν → ομάδα **ή** server, `services.server_id` που
      κρατά πού ζει κάθε λογαριασμός (και όλες οι ενέργειες πάνε εκεί), όριο λογαριασμών και enable/disable ανά server,
      μεταφορά υπηρεσίας σε άλλο server από admin, sync/μέτρηση λογαριασμών. Ο allocator ως καθαρή κλάση με tests.
      Σήμερα: ομάδες ApacheServers / Greece-LiteSpeed / LiteSpeed-Germany / PVE Nodes / Centova, «least full».
- [ ] **Bridge προς Ekdosi** (billing source για το MyCS στο Ekdosi, ίδιο HMAC/ops, τιμολόγια σε τρίτους).
- [ ] Portal: ο πελάτης βλέπει τα **φορολογικά παραστατικά** που στέλνει πίσω το Ekdosi (`decisions.md` §2)·
      σύγκριση στην οθόνη portal Ekdosi vs Paymenter πριν αποφασίσουμε ποιες σελίδες κρατάμε.
- [ ] Business rules από τα ~20 hooks του myip (`history/blesta-modules/HANDOFF.md` §4.4).
- [ ] Templates email el/en για όλα τα γεγονότα (#1306)· retry και ιστορικό emails (#1204, #1033).
- [ ] Εύκολη επεξεργασία από admin: ημερομηνίες domain, ποσά υπηρεσίας, υποσύνολα τιμολογίου.
- [ ] MCP server (μοτίβο του Ekdosi).

## Φάση 4: .gr EPP
- [ ] `packages/gr-epp` (HTTPS `/epp/proxy` + `JSESSIONID`) ως driver του `DomainRegistrar`· UAT· owner change/transfer
      ως προϊόντα· 1.383 domains.

## Φάση 5: myip.gr cut-over
- [ ] Πλήρης importer (domains, addons, custom fields, tickets με συνημμένα, αρίθμηση = id, συνέχεια από τον
      τελευταίο αριθμό του WHMCS).
- [ ] Importer: servers, server groups και ο server κάθε υπηρεσίας (`tblhosting.server`), όχι μόνο του προϊόντος.
- [ ] 2–3 δοκιμαστικές μεταφορές με `mycs:parity`· validation domains απέναντι στους registrars.
- [ ] Ekdosi: «Εισερχόμενα» από το MyCS χωρίς διπλά παραστατικά.
- [ ] Freeze, import, DNS/rewrites, forwarder IPN για PayPal, dump του WHMCS για αρχείο.

## Δεν γίνονται (απογραφή: δεν χρησιμοποιούνται)
Late fees, billable items, affiliates, escalation rules, network issues, fraud modules, φόρος δεύτερου επιπέδου,
premium domains, δωρεάν domain με hosting.
