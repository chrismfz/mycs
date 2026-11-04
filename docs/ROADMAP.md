# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα «Ανοιχτά» και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του (`domains.md`, `payments.md`, `sweeps/billing.md`
> κ.λπ.)· εδώ μόνο ο χάρτης. Ιστορικό: `history/roadmap-2026-10-11-to-20.md` (ως το #232).
>
> **Καθάρισμα 2026-10-23 (main στο #350):** κάθε ανοιχτό σημείο ελέγχθηκε απέναντι στον κώδικα και στα PRs· ό,τι είχε γίνει
> πέρασε στο «Έγινε». Μαζί, τι χρησιμοποιούν πραγματικά staff και πελάτες στο WHMCS του myip (access log 1–22/10,
> `whmcs-usage.md`).

## Πού είμαστε (2026-10-29, main στο #510)
- Το MyCS τρέχει στο devbox (`https://mycs.myip.gr`)· **καμία εταιρεία δεν δουλεύει ακόμα σε αυτό**. Pilot είναι η
  nixpal, μετά το myip.gr (`../CLAUDE.md`).
- **Έτοιμα:** η χρέωση και οι ροές χρημάτων (ομάδες πελατών, promotions, προσφορές, τραπεζική μεταφορά), οι λογαριασμοί,
  οι παραγγελίες, τα **tickets** (πλήρες port), το provisioning (cPanel με resellers και χρήση, mailcow, Centova Cast,
  **SSL μέσω Openprovider**, licensing της nixpal), τα domains (Openprovider, CNIC, Μητρώο .gr με δικό μας EPP), τα
  βασικά reports, το portal, el/en, σκλήρυνση ασφαλείας (2FA staff υποχρεωτικό). Σύνοψη στο «Έγινε».
- **Από τις 2026-10-28:**
  - cPanel: CloudLinux χρήση και «Differs» (#497–#499).
  - Proxmox: client area, IP pools και κονσόλα noVNC (#502, #505, #506· η κονσόλα σε cPanel μένει για τη myip).
  - Έσοδα ανά προϊόν και πελάτη (#504 κ.ά.).
  - Squash των migrations σε schema dumps (#510).
- **Τι λείπει για να μπει η nixpal:** ο importer με parity (9) και η ασφάλεια του migration (9β), η παραγωγή στον orion (8) και το runbook (10). Το «Πριν Το runbook ξεκίνησε: `cutover-runbook.md`.
  τον importer» παρακάτω έκλεισε όλο (2026-10-28).

## Πριν τον importer (υπεύθυνος, 2026-10-27)
- [ ] **Pre-launch audit** (από 2026-10-29, `prelaunch-audit.md`): ένας ακόμα ανεξάρτητος έλεγχος ανά ροή, μόνο
      Critical/High/Medium, πριν από την παραγωγή και τον importer· σειρά, κριτήρια και πρόοδος εκεί.
- [x] ~~Unique `transaction_id`~~ **όχι (υπεύθυνος, 2026-10-27):** το idempotency μένει **ανά πελάτη**
      (`payments.md` Τ2): το κλείδωμα του πελάτη αποκλείει τη διπλή εγγραφή για τον ίδιο πελάτη, και το staff μπορεί να
      γράψει την ίδια αναφορά εμβάσματος σε δύο πελάτες. Ένα global unique θα το απέρριπτε (δοκιμάστηκε: σπάνε δύο
      σκόπιμα tests). Μετρήθηκε (μόνο SELECT): nixpal **0** διπλά, myip **1** (ένα έμβασμα σε 4 τιμολόγια)· ο importer
      τα φέρνει όπως είναι.
- [x] ~~Incomplete tests χρημάτων (`BillingCursorMoneyTest`)~~ **έκλεισαν (2026-10-27):** το «downgrade ληξιπρόθεσμης»
      περνά και τρέχει πια· τα άλλα δύο έφυγαν, γιατί το σενάριό τους δεν υπάρχει πια: γραμμή τιμολογίου με πληρωμή
      κρατά την τιμή της (R9, `ChangeOpenLine`· `UpgradeLifecycleTest`) και τιμολόγιο δεν δημιουργείται ποτέ paid (MT-1c,
      `StaffCorrectionsAdversarialTest::test_an_invoice_is_never_created_paid`). Από τα 4 του
      `InvoiceNumberingAdversarialTest` το «χωρίς χαμένο αριθμό» έκλεισε στο #477 (ο importer γράφει το μεγαλύτερο id,
      όχι +1)· τα άλλα 3 (myip number = id, μετρητής nixpal χωρίς άλμα, διπλό `invoicenum`) κλείνουν **μέσα** στον importer.
- [x] ~~Webhooks PayPal ppcpv και Stripe μέσω Chrome~~ **ελέγχθηκαν (2026-10-27):** τίποτα πριν το cut-over· στο
      cut-over νέο webhook PayPal προς τη MyCS (με τα disputes, Webhook ID στο gateway), το Stripe το φτιάχνει η MyCS
      μόνη της, και σβήνονται τα παλιά. Το Stripe του WHMCS ακούει μόνο αλλαγές κάρτας: γι' αυτό τα 0 webhooks.
      `importer.md` → «Webhooks».
- [x] ~~Υπενθυμίσεις overdue της nixpal~~ **αποφασίστηκε (υπεύθυνος, 2026-10-27):** `cronjob_overdue_reminders` = `1,3,7`
      στο cut-over (σήμερα 3 emails τις ημέρες 0, 1, 2· suspend στις 8, η τελευταία λέει «αναστέλλεται αύριο»).
      `settings-map.md`.
- [x] ~~Τα δεδομένα των migrations στους seeders~~ **έγινε (chore/seed-what-migrations-write):** templates (ήταν ήδη ίδια),
      τμήματα tickets (Support, Sales), και οι μετρητές `invoice_number`/`credit_note_number` από 0 (με 1, σε νέα
      εγκατάσταση το πρώτο πιστωτικό έπαιρνε τον αριθμό 2· migration για όσες στήθηκαν πριν). Φύλακας:
      `SeedersCoverMigrationDataTest`, σε κάθε πίνακα και στήλη.
- [x] ~~**Squash των migrations**~~ **έγινε (2026-10-28):** 224 migrations έγιναν `database/schema/mariadb-schema.sql` και
      `sqlite-schema.sql`. Ο έλεγχος (βάση από τα παλιά migrations + seed απέναντι σε βάση από το dump + seed) βγήκε ίδιος
      στο σχήμα και σε κάθε γραμμή, και στις δύο βάσεις. Λεπτομέρειες στο `HANDOFF.md` → «Σχήμα της βάσης».
- Αποφασίστηκαν (2026-10-27):
  - **Ιστορικό emails:** ο τελευταίος χρόνος του `tblemails` στα `email_logs`, και στο cut-over
    `cronjob_delete_email_logs` = 365 (default 90: αλλιώς το πρώτο prune σβήνει τα 9 από τους 12 μήνες). Τα tickets
    έρχονται όλα (#256).
  - **Polling των tickets:** IMAP αντί για POP3, εντάξει.
  - **Welcome emails και αρχεία πελατών από τη βάση:** τα templates των προϊόντων (ZoneCloud, άδειες agent/node) από το
    `tblemailtemplates`, μετατροπή των Smarty μεταβλητών στις δικές μας (`templates.md`), και τα `tblclientsfiles` με τα
    αρχεία τους· στον importer, όχι με το χέρι.
  - **Αυτόματη ακύρωση στις 60 ημέρες:** δεν χρειάζεται ρύθμιση. Το terminate (60 στη nixpal) ακυρώνει τα ανοιχτά
    τιμολόγια της υπηρεσίας (`CancelOpenInvoices::onServiceEnd`, `cronjob_end_keeps_overdue` off· tests
    `ForgonePeriodTest`, `ServiceTerminatedAdversarialTest`)· όσα κρατούν χρήματα μένουν με ειδοποίηση στο staff. Μένει
    ανοιχτό ό,τι δεν ανήκει σε υπηρεσία (χειροκίνητο τιμολόγιο· τα «Add Funds» έχουν το `cronjob_add_funds_cancel`).

## Επόμενα, με σειρά (εγκρίθηκε 2026-10-20, ενημέρωση 2026-10-23)
Η σειρά οδηγεί στο cut-over της nixpal. Ο importer (9) μπαίνει στο τελικό στάδιο, για να ετοιμάζει και τα του myip
(cPanel resellers, mailcow, Centova, Proxmox)· η παραγωγή στον orion (8) μετά τις δοκιμές και τα audits· οι συνδρομές
PayPal (7) την ημέρα του migration (ο υπεύθυνος, 2026-10-22). Μετά: UI/UX polishing.

| # | Τι | Κατάσταση |
|---|---|---|
| 1–6δ | Αρίθμηση, σημειώσεις, PayPal vault, licensing/cPanel, ομάδες/promotions, reports, προσφορές, τραπεζική μεταφορά, emails domains, μαζικό email | **Έγιναν** (βλ. «Έγινε»). Από τα reports μένει μόνο σελίδα «Reports» με κατηγορίες όπως του WHMCS (Daily Performance, New Customers, Aging, Income by Product, Top 10), όταν χρειαστεί. |
| 7 | **Παλιές συνδρομές PayPal: την ημέρα του migration.** 5 υπηρεσίες, 3 πελάτες, €9/μήνα. Μήνυμα στους 3 να αποθηκεύσουν το PayPal στο portal (vault), ακύρωση της παλιάς συνδρομής από το staff· δίχτυ το H2b· στον importer `paypal` → νέο gateway PayPal με το `subscriptionid` | Στο cut-over. Χωρίς νέο κώδικα. |
| 8 | **Παραγωγή:** στον orion ως `mycs.nixpal.com`, που στο cut-over παίρνει το `clients.nixpal.com` (το WHMCS → `old-clients`· `importer.md` → «Host και gateways»)· SMTP ίδιο με το WHMCS, JetBackup στον rosso και δοκιμή επαναφοράς. Έγιναν: `/health` (#259), Automation Status (#258), στοιχεία εταιρείας/logo (#264), `deploy/private-files.sh` (#322), headers ασφαλείας (#342), 2FA staff (#344) | Μένουν deploy, SMTP, backups. |
| 9 | **Importer του νέου σχήματος + `mycs:parity`.** **Εισάγονται** (2026-10-30): πελάτες με την κύρια επαφή και τα δικαιώματα, ομάδες, κατάλογος, config options, promotions, servers, παραγγελίες, υπηρεσίες με server (AUD4-1, #530), offline SSL, domains (D6, #535/#537), licensing, ακυρώσεις, τιμολόγια με αρίθμηση και γραμμές, πληρωμές, Mass Pay, πιστώσεις με ιστορικό, κατάσταση υπενθυμίσεων, προτιμώμενος τρόπος πληρωμής ανά τιμολόγιο (#539) και ανά υπηρεσία/domain (#541), αποθηκευμένες μέθοδοι μέσω του API του WHMCS (#538), **credentials servers/registrars** (cPanel token και IP, login registrar· `ispapi` = CNIC· ανενεργά ως το cut-over, `importer.md`), mailbox των departments και SMTP των κεντρικών emails (τα κλειδιά Stripe/PayPal δεν διαβάζονται από το WHMCS: με το χέρι), tickets, knowledgebase, admins, ρυθμίσεις αυτοματισμού, φόροι (`tbltax`, το seed τους ενημερώνει), ετικέτες υπηρεσιών, spam filters· επαφές και αναθέσεις του Ekdosi (E4, #542). **Μένουν, με σειρά:** (α) ~~custom fields~~ (#544)· (β) ~~σημειώσεις~~ (#546)· (γ) ~~κωδικοί υπηρεσιών~~ (`DecryptPassword`, #547)· (δ) ~~Proxmox~~ (vmid από το `mod_pvewhmcs_vms` ως πρόταση· αντιστοίχιση με το χέρι, `proxmox.md` → Α3, #548)· (ε) ~~δευτερεύουσες επαφές~~ (`tblcontacts`, #549)· ~~αποστολή emails σε επαφές εκτός της κύριας, ανάλογα με τις προτιμήσεις τους~~ (myip: 94 επαφές σε 74 λογαριασμούς έπαιρναν τα τιμολόγια· `mail.md`)· (στ) **addons ως δικές τους υπηρεσίες, κουμπωμένες στη γονική** (`tblhostingaddons`, σχέδιο στο [`addons.md`](addons.md), 2026-11-04· αντικαθιστά το «επιλογές της υπηρεσίας» της 2026-10-21): ~~βήμα 2, addons ως υπηρεσίες με ακολουθία της γονικής~~ (#634), ~~βήμα 3, «Add addon» στο admin~~ (#637), ~~βήμα 4, importer με parity~~ (#647, `WhmcsAddonImport`). **Μένουν αποφάσεις:** 10 Dedicated IP της myip τιμολογούνται χωρίς ΦΠΑ και έρχονται έτσι, σε retired προϊόν (με ΦΠΑ, αν μετακινηθούν στο προϊόν του καταλόγου)· το «Suspend Parent Product» του System Maintenance (απλήρωτο, το WHMCS αναστέλλει και τον server) δεν μεταφέρεται, το import το λέει. myip: 17 ζωντανά (11 Dedicated IP, 5 extra χώρος, 1 System Maintenance), ~1.460 €/έτος· **nixpal: 0**, άρα όχι πύλη για τη nixpal, πύλη για τη myip. (ζ) **ρυθμίσεις reseller των cPanel products** (έλεγχος 2026-11-04): ο κατάλογος (`CatalogImport`, και στο πλήρες import από το WG-2) φέρνει μόνο το `package` (`configoption1`)· το theme `configoption9` λείπει κι αυτό (WG-16)· το «Reseller account», ACL, όρια δίσκου/bandwidth/λογαριασμών μένουν κενά, άρα ένα create ή upgrade σε τέτοιο προϊόν θα έφτιαχνε απλό λογαριασμό. myip: 6 προϊόντα, 23 ζωντανές· nixpal: 0. Τα προϊόντα mailcow (myip 5) και Centova (10) έρχονται χωρίς ρυθμίσεις του module, με σημείωση στο import για το staff· των υπηρεσιών τους τα πεδία έρχονται (custom fields, και τα marks του suspend, AUD4-2). Ο ΦΠΑ (`tbltax`) **ελέγχθηκε**: τον φέρνει το `WhmcsTaxImport`. **Συναίνεση marketing: χρησιμοποιείται** (myip `emailoptout` σε 345, 26 campaigns· nixpal 76): πριν το πρώτο news mail, **WG-9**. Τα υπόλοιπα κενά του importer: **sweep 2026-11-04**, παρακάτω. | Τελικό στάδιο. Re-runnable, με parity (`importer.md`). |
| 9β | **Ασφάλεια του migration (υπεύθυνος, 2026-10-30), πριν το cut-over και πάνω από τα «Τελευταίο» του 9.** Το `mycs:parity` και το `mycs:money-check` συγκρίνουν αριθμούς· αυτά ελέγχουν **τι θα κάνει** το σύστημα στους υπάρχοντες πελάτες. (1) ~~**Σύγκριση προβλέψεων με το WHMCS** (read-only report):~~ **έγινε** (`mycs:forecast-parity`, `importer.md` → «Πρόβλεψη»; nixpal: όλα εξηγημένα, 3 θέματα για απόφαση)· για κάθε ενεργή υπηρεσία και domain, επόμενη λήξη, ποσό, κύκλος και μέρα τιμολόγησης, αν και σε ποια μέθοδο χρεώνεται αυτόματα, πότε αναστέλλεται/τερματίζεται, πότε ανανεώνεται στον registrar· κάθε διαφορά μηδέν ή εξηγημένη. (2) ~~**Προσομοίωση 60–90 ημερών στο αντίγραφο:**~~ **το εργαλείο έγινε** (`bin/simulate`, `importer.md` → «Simulation»; πρώτο replay nixpal 31/10: 1:1) — μένουν οι μακρινές εκτελέσεις και το σύγκριση ledger↔WHMCS ως εντολή: το πραγματικό cron μέρα-μέρα με ρολόι που προχωρά, emails fake, gateways και registrars stub, χωρίς δίκτυο· τιμολόγια, χρεώσεις, αναστολές, ειδοποιήσεις, απέναντι στο (1). (3) **Shadow run 1–2 εβδομάδων:** κάθε βράδυ import από φρέσκο αντίγραφο και η μέρα «στεγνά», απέναντι σε ό,τι έκανε πραγματικά το WHMCS (τιμολόγια, χρεώσεις, αναστολές). (4) **Δίχτυ τις πρώτες μέρες:** αναστολές/τερματισμοί κλειστοί την πρώτη εβδομάδα, καθημερινή σύνοψη στο staff για ό,τι τιμολόγησε και χρέωσε το cron, runbook επιστροφής (10). Και ο έλεγχος του E4 στο cut-over: τιμολόγια με mark ακόμη ανοιχτά (`ekdosi-bridge.md` → «Cut-over»). | Μετά τα (α)–(δ) του 9· πρώτο το (1). |
| 9γ | ~~**Sweep σύγκρισης λογικής με Blesta και FOSSBilling (υπεύθυνος, 2026-10-30).**~~ **Έγινε (2026-10-30/31):** ευρήματα, αποφάσεις και PR στο `sweeps/9g-blesta-fossbilling.md` (#551–#559)· ό,τι έμεινε ανοιχτό, εκεί. Δύο συστήματα που δουλεύουν ~20 χρόνια στον χώρο: τι χειρίζονται που εμείς ίσως ξεχάσαμε (περιπτώσεις ανανέωσης, pro rata, αλλαγές πακέτου/κύκλου, πιστώσεις, υπερπληρωμές, refunds, dunning, αναστολές, domains, φόροι, νομίσματα, πολλαπλά gateways). Ανά θέμα: πίνακας «αυτοί κάνουν Χ, εμείς; test;», και ό,τι λείπει γίνεται finding με failing test, όπως στα adversarial reviews. **Μόνο ανάγνωση λογικής, ποτέ αντιγραφή κώδικα:** το FOSSBilling είναι Apache-2.0, το Blesta έχει δική του άδεια· όπως με το upstream, γράφουμε τον δικό μας κώδικα. Τα δικά τους γνωστά κενά (που αποδείχτηκαν «τρύπια») δεν είναι πρότυπο. Το WHMCS (ionCube) μόνο από τη συμπεριφορά του (9β). | Μετά το 9β, πριν το cut-over της nixpal αν χωρά· αλλιώς πριν του myip. |
| 10 | **Runbook cut-over με επιστροφή** (`cutover-runbook.md`, ξεκίνησε 2026-11-03· πρότυπο: `go-live-runbook.md` του Ekdosi): πάγωμα, import και parity, DNS/webhooks, **χάρτης 301 (βλ. «Cut-over»)**, ενημέρωση πελατών, έλεγχοι πρώτης μέρας· 2–3 δοκιμαστικές μεταφορές | Τελευταίο. |

## Ανοιχτά, ανά θέμα

### Νέα από τη χρήση του WHMCS (access log του myip, 1–22/10/2026)
Ποιο staff και ποιοι πελάτες κοιτούν τι (μόνο αθροίσματα, `whmcs-usage.md`). Το staff ζει στα **tickets** (~430
σελίδες/μέρα, 10πλάσιο από την επόμενη)· οι πελάτες σε προϊόν/υπηρεσίες/domains. Από αυτά:
- [x] ~~**Μαζικές ενέργειες domains στο portal**~~ **έγινε (feat/domain-bulk):** επιλογή έως 20 στη λίστα και
      **ανανέωση μαζί (ένα τιμολόγιο ανά νόμισμα,** όσα έχουν ανοιχτό τιμολόγιο πάνε σε αυτό), αυτόματη ανανέωση ναι/όχι,
      ίδιοι nameservers, κλείδωμα/ξεκλείδωμα· το καθένα με τους ελέγχους της σελίδας του (managedomains, και invoices για
      την ανανέωση), μόνο domains του λογαριασμού, 6 μαζικές ενέργειες ανά τέταρτο. Στο WHMCS του myip: 878 ανανεώσεις
      από το καλάθι. Οι υπηρεσίες μένουν στο «Invoice selected items» του staff (το on-demand renewal του πελάτη είναι
      κλειστό στο myip και δεν χρησιμοποιήθηκε ποτέ).
- [x] ~~**Μεταφορά domain ή υπηρεσίας σε άλλο πελάτη**~~ **έγινε (feat/move-to-client, αποφάσεις υπευθύνου 2026-10-23):**
      μόνο το staff, με δικό του δικαίωμα (`admin.services.move`, `admin.domains.move`), «Move to another client» στη σελίδα
      της υπηρεσίας και του domain. Τα απλήρωτα τιμολόγια πάνε μαζί: όποιο χρεώνει μόνο αυτό μεταφέρεται ολόκληρο (ίδιος
      αριθμός, ΦΠΑ ξανά για τον νέο πελάτη), όποιο χρεώνει και άλλα σπάει (οι γραμμές του σε νέο τιμολόγιο του νέου)· τα
      πληρωμένα μένουν· τιμολόγιο με πληρωμή αρνείται τη μεταφορά. Υπηρεσία: η σχετική επαφή σβήνεται, συνδρομή (κάρτα,
      PayPal) αρνείται. Domain: οι επαφές του αντιγράφονται στον νέο πελάτη, τα handles μένουν· τίποτα στον registrar
      (η αλλαγή κατόχου στο μητρώο είναι το G8-5). Σημείωση και στους δύο λογαριασμούς. Το πεδίο «Client» της φόρμας της
      υπηρεσίας δεν αλλάζει πια με edit.
- [x] ~~**Knowledgebase**~~ **έγινε (feat/knowledgebase, αποφάσεις υπευθύνου 2026-10-23, `knowledgebase.md`):** μέσα στο
      MyCS, δημόσια, **στα ίδια URLs με το WHMCS** (`/knowledgebase/{id}/{slug}.html`, κατηγορίες, tags)· τα
      `/clients/knowledgebase/...` και το `knowledgebase.php` κάνουν 301. RichEditor (HTML, sanitized), γλώσσα του
      instance με προαιρετική μετάφραση, αναζήτηση χωρίς τόνους, προτάσεις στο άνοιγμα ticket, μόνο προβολές. Import με
      τα ίδια ids, εικόνες στο MyCS, parity (`app:import-whmcs-knowledgebase`, και στο πλήρες import).
- [x] ~~Whois από το admin~~ **έγινε (feat/admin-whois):** «WHOIS Lookup» στα Utilities (όπως το WHMCS): οποιοδήποτε
      όνομα από το μητρώο του, με σύνδεσμο στο domain όταν είναι δικό μας, και το WHOIS όπως ήρθε· με το δικαίωμα των
      domains, 60 ανά 10 λεπτά ανά staff.

### Κενά από το sweep του WHMCS (2026-11-04, [`sweeps/whmcs-gaps.md`](sweeps/whmcs-gaps.md))
Κάθε γραμμή έχει την εξήγηση και τους αριθμούς στο sweep. Ένα PR ανά γραμμή (ή ομάδα)· όσα αγγίζουν χρήματα με
adversarial review.

**Π0, πριν τη nixpal:**
- [x] ~~**WG-1** Η έκπτωση ομάδας χάνεται στο import: η nixpal θα χρεώνει 10% παραπάνω σε 24 υπηρεσίες.~~ Ο importer
      γράφει την τιμή μείον την έκπτωση, όπως τη χρεώνει το WHMCS· το forecast parity το ίδιο (fix/import-group-discount).
- [x] ~~**WG-2** Το πλήρες import να φτιάχνει τον κατάλογο με το `CatalogImport` (package cPanel/ngm, κρυφές ομάδες,
      retired, δωρεάν τιμές, σειρά, συνδέσεις των config options).~~ Το καλεί μετά τους servers, με τα ids του WHMCS·
      parity για products, listed και package (feat/import-catalog-full).
- [x] ~~**WG-3** Όρια του Add Funds από το WHMCS (σήμερα 5/100/300).~~ Από τον importer (feat/import-funds-order-grace).
- [x] ~~**WG-4** Ρύθμιση προθεσμίας του πρώτου τιμολογίου (`OrderDaysGrace`: myip 8, nixpal 0· σήμερα 7).~~ Ρύθμιση
      `order_days_grace` (default 7), από τον importer (feat/import-funds-order-grace).
- [ ] **WG-5** Δικαιώματα των ρόλων staff εκτός Full Administrator (runbook ή αντιστοίχιση).
- [ ] **WG-6** Promotions: να μην κλείνουν όσα εκφράζει πια το MyCS (#274).
- [ ] **WG-7** `TermsOfService` → `tos`.
- [ ] **WG-8** Κλειστοί πελάτες (Closed) χωρίς login.
- [ ] **WG-9** Συναίνεση marketing → opt-out του `announcement_news`.
- [ ] **WG-10** `mail_must_verify` off στο cut-over της nixpal (settings-map).

**Π1, πριν τη myip:**
- [ ] **WG-11** Config options των υπηρεσιών (`tblhostingconfigoptions` → `service_configs`) και στο parity.
- [ ] **WG-12** Domains: `recurring_amount` μόνο στις πραγματικές εξαιρέσεις.
- [ ] **WG-13** GA4/Ads `purchase` στην ολοκλήρωση της παραγγελίας.
- [ ] **WG-14** Ημέρες τιμολόγησης ανά κύκλο (= η γραμμή στη Χρέωση).
- [ ] **WG-15** Quantity options με τιμή (CART-9).
- [ ] **WG-16** Ρυθμίσεις module πέρα από το package (= 9 (ζ), και το theme του cPanel).
- [ ] **WG-17** Runbook του myip: παράθυρο αναστολών, auto-match Bank transfer, `InvoicePayTo`, συνημμένο PDF (ρύθμιση).
- [ ] **WG-18** Collections: απόφαση.
- [ ] **WG-19** Ανανέωση πολλών domains μαζί (= Domains).
- [ ] **WG-20** Endpoint αναζήτησης domain για το site.
- [ ] **WG-21** Πρόβα των συνημμένων tickets.
- [ ] **WG-22** DNS ζώνη (ZoneCloud) στο admin· ανάκληση του token του hook στο cut-over.
- [ ] **WG-23** Μεταγραφή ελληνικών προς CNIC/Openprovider (= Domains).

**Π2, μικρά ή απόφαση του υπευθύνου:** WG-24 ως WG-34 (σύνδεση ticket → υπηρεσία, auto-close, rar/7z, γραμμές σε
σβησμένες υπηρεσίες, `termination_date`, `billingcid`, αρχεία/downloads/to-do, `AddFundsRequireOrder`, τιμή ID
protection, custom fields παραγγελίας, live chat/unblockip).

### Cut-over (myip, και ό,τι ισχύει για τη nixpal)
- [ ] **Χάρτης 301 για τα URLs που φέρνουν κίνηση** από Google: `/clients/knowledgebase/...` (~2.500/μήνα): τα 301
      υπάρχουν στο MyCS (`knowledgebase.md`), μένει το rewrite του `/clients/knowledgebase` του myip.gr προς το
      MyCS· `/clients/store/...` (~640/μήνα) στα προϊόντα του MyCS, `/clients/announcements`. Αλλιώς χάνεται η κίνηση.
- [ ] Freeze, import, DNS/rewrites, προώθηση του IPN του PayPal (το endpoint υπάρχει, `routes/legacy.php`), dump του
      WHMCS για αρχείο· οι ρυθμίσεις του myip από το `settings-map.md`· **G8-2** καθάρισμα των `533_`/`c60_`· η
      αρίθμηση συνεχίζει από τον τελευταίο του WHMCS (> 32.476 στις 2026-10-11· ξαναδιαβάζεται τη μέρα).

### Provisioning
- [x] ~~**Η πραγματική IP από τον server**~~ **έγινε (feat/server-ip):** το cPanel λέει την IP κάθε λογαριασμού μαζί με τη
      χρήση του (`services:usage`, κάθε 4 ώρες)· κρατιέται στο `service_usages.server_ip`, φαίνεται στην υπηρεσία του admin
      («IP on the server»), κόκκινη όταν η dedicated IP που έχουμε διαφέρει, και φίλτρο «IP differs from the server».
- [x] **Proxmox (8 VPS)** (`proxmox.md` → Roadmap· μένουν η αντιστοίχιση με τον importer και το create/reinstall,
      που ξανασυζητιούνται):
  - **Έγινε:** inventory μόνο ανάγνωσης (#446), κύκλος ζωής: suspend = stop, unsuspend = start, terminate (#447),
    client area (#502), IP pools (#505), κονσόλα noVNC (#506).
  - **Η κονσόλα σε cPanel (orion):** ανοιχτό ως την ώρα της myip (υπεύθυνος 2026-10-28). Τρεις επιλογές:
    PHP streaming, relay στο cluster ή SPICE (`proxmox.md` → «Η κονσόλα σε cPanel»). Πριν από την παραγωγή χρειάζεται
    ρύθμιση console on/off.
  - **Μετά, με τον importer:** οι υπάρχοντες VPS αντιστοιχίζονται από το `mod_pvewhmcs_vms`, και το pool τους με
    `app:import-whmcs-ip-pools`.
  - **Επόμενο:** create και reinstall χωρίς templates (εγκατάσταση από ISO): ξανασυζητιούνται.
- [x] ~~ngm (1), Hetzner (1): χειροκίνητα προϊόντα~~ **έγινε (feat/offline-modules):** modules «Offline VM» και «Offline Dedicated Server» χωρίς API· τα στοιχεία της μηχανής (VM ID, πάροχος, MAC, IPv6· rack, U, IPMI IP και MAC, serial, θύρα switch/PDU) στην υπηρεσία, κάθε ενέργεια εργασία του staff στα Pending Module Actions. Επόμενο, αν χρειαστεί: η τρέχουσα IP του IPMI από τα DHCP leases του MikroTik με το MAC του.
- [x] ~~TheSSLStore (12): χωρίς module~~ **έγινε (feat/offline-ssl):** module «Offline SSL», ίδιο με τα offline modules.
  Τα στοιχεία της παραγγελίας μένουν στην υπηρεσία, και κάθε ενέργεια γίνεται εργασία του staff, η πληρωμένη ανανέωση
  επίσης. Ο importer βάζει εκεί τα πιστοποιητικά του WHMCS και αποσύρει τα 57 προϊόντα (`importer.md`). Στη λήξη περνούν
  στο Openprovider SSL.
- [x] ~~Οι υπηρεσίες χωρίς module στο WHMCS~~ **έγινε (#628):** ο importer τις βάζει στο «Manual (offline)» (Offline
  Dedicated Server). Ένα suspend για απλήρωτο γίνεται εργασία του staff, και φαίνεται τι θέλει suspend και τι όχι
  (υπεύθυνος, 2026-11-04). Στη myip είναι 17 ζωντανές: dedicated, colocation, managed, backup MX.
- [ ] **Dedicated servers με BMC: ένα extension Redfish** (ιδέα του υπεύθυνου, 2026-11-04· για αργότερα).
  - **Ένα module για όλα:** iDRAC (Dell, 8/9), BMC της Supermicro (X11+), αργότερα iLO (HPE) και XCC (Lenovo). Όλα μιλούν
    το πρότυπο Redfish (DMTF), και οι διαφορές τους είναι ρύθμιση «vendor» του server, όχι ξεχωριστό module.
  - **Με σειρά:**
    1. ανάγνωση: τροφοδοσία, υγεία, θερμοκρασίες, serial/μοντέλο, inventory·
    2. power on/off/reset για το staff, για τον πελάτη αν αποφασιστεί·
    3. suspend: κλείσιμο της θύρας στο switch (Nexus) ή σβήσιμο από το BMC, απόφαση ανά εταιρεία ή προϊόν. Η θύρα
       κρατά τα δεδομένα ασφαλή και ο πελάτης βλέπει γιατί κόπηκε·
    4. console (HTML5 KVM) και reinstall με virtual media.
  - **Ασφάλεια:**
    - BMC μόνο σε management VLAN ή VPN, ποτέ δημόσια·
    - χρήστης BMC με ρόλο Operator, όχι Administrator·
    - credentials κρυπτογραφημένα ανά υπηρεσία·
    - η MyCS ενεργεί μόνο σε μηχάνημα ρητά αντιστοιχισμένο στην υπηρεσία, όπως στο Proxmox.
  - **Μετάβαση:** οι υπηρεσίες του «Manual (offline)» περνούν στο Redfish με αλλαγή του server της υπηρεσίας και τα
    στοιχεία του BMC (η IP και το MAC του IPMI υπάρχουν ήδη στα πεδία του Offline Dedicated). Δεν χρειάζεται νέο import.
- [x] ~~Συμφιλίωση κατάστασης cPanel~~ **έγινε:** «Differs» στο Hosting Accounts (φίλτρο) και στη σελίδα της υπηρεσίας:
      active εδώ/suspended εκεί, suspended εδώ/active εκεί, ended εδώ/υπάρχει ακόμα εκεί (μόνο αφού ο server διαβαστεί
      μετά την αλλαγή της υπηρεσίας).
- [ ] cPanel: root token στους servers του myip πριν τους resellers
      (το create υπάρχει, #114)· αργότερα, αν χρειαστεί, διαχείριση email/domains μέσα από το MyCS.
- [ ] SSL: live δοκιμή με πραγματική παραγγελία (PositiveSSL 6,53 €), με έγκριση του υπευθύνου. Οι 12 του TheSSLStore
      γίνονται νέες παραγγελίες στο Openprovider στη λήξη τους (οι 2 RapidSSL → PositiveSSL).
- [x] ~~SSL vault~~ **έγινε (#437, #438):** κλειδί/CSR από το MyCS, εκδόσεις, εργαλεία ελέγχου και SSL Checker. Μένει
      η εγκατάσταση στο cPanel, αργότερα.
- [x] ~~mailcow: δοκιμή live στο mymail~~ **έγινε (2026-10-28, με άδεια του υπεύθυνου):** server #8, κρυφό προϊόν #41
      «mailcow test (hidden)» (χωρίς restart του SOGo, terminate = delete), υπηρεσία #8 με το `mycs-test.myip.gr`: create
      (domain με όρια, DKIM, domain admin), suspend (admin κλειδωμένος, μαρκαρισμένος), unsuspend, κωδικός, SSO στο
      domain admin, χρήση, upgrade (όρια), terminate (domain και admin διαγράφηκαν): όλα εντάξει.
- [x] ~~mailcow, συνέχεια (υπεύθυνος, 2026-10-28)~~ **έγινε:** **Utilities → Mail Domains** (#492: domains και mailboxes
      κάθε server, χρήση, αντιστοίχιση με υπηρεσία, όπως τα Proxmox Guests, και στήλη «DNS» ✓/!/✗)· στη σελίδα της
      υπηρεσίας, για staff (ενότητα «Mail») και πελάτη, ο έλεγχος DNS (MX, SPF, DKIM, DMARC, autodiscover/autoconfig:
      το DNS που ζητά ο server, το κλειδί DKIM από το mailcow, απέναντι σε ό,τι απαντά το δημόσιο DNS) και τα mailboxes
      με quota και χρήση (feat/mailcow-service-page). Όχι πρόσθετα mailboxes ως config options (μόνο όριο χώρου).
- [x] ~~cPanel: η ίδια εικόνα και resellers~~ **έγινε:** **Utilities → Hosting Accounts** (#495: λογαριασμοί κάθε WHM,
      πακέτο, owner/reseller, δίσκος, inodes, bandwidth, suspend, mail held, αντιστοίχιση, «Services without an account»,
      inodes και «κοντά στο όριο» στη σελίδα της υπηρεσίας)· **δοκιμή reseller στον rigel** (provisioning.md) και «Σύνδεση
      στο WHM» για reseller (ελεγμένη με test· live στην επόμενη δοκιμή).
- [x] ~~cPanel: χρήση πόρων του CloudLinux~~ **έγινε (στάδιο 3):** `cpanel:lve` κάθε ώρα, όλοι οι servers ταυτόχρονα (ένα process ο καθένας), διαβάζει μέσω WHM το
      `ResourceUsage::get_usages` κάθε λογαριασμού (CPU % του ορίου του, RAM, EP, processes, I/O, IOPS και τα όριά τους),
      κρατά τις μετρήσεις 2 μέρες· το μέγιστο 24ώρου στο Hosting Accounts (στήλη, φίλτρο) και στη σελίδα της υπηρεσίας
      (πελάτης και staff, «κοντά στο όριο»). Το όνομα του πακέτου κόβεται στους 24 χαρακτήρες. **Faults και ιστορικό του
      lve-stats** δεν τα δίνει το API (μόνο `lveinfo` ως root): απόφαση υπευθύνου 2026-10-28, αργότερα.
- [ ] ~~cPanel: WordPress (WP Toolkit), ασφάλεια (Imunify360), faults του lve-stats~~ **όχι τώρα** (υπεύθυνος 2026-10-28: «αρκετά
      ολοκληρωμένο, μην το βαρύνουμε»· φαίνονται στο cPanel με το SSO). Το
      backup του λογαριασμού είναι του JetBackup (το backup του cPanel κλειστό στον rigel): αργότερα από το JetBackup.
- [x] ~~Resend welcome email, generator κωδικού, κωδικός που ορίστηκε αλλού~~ **έγινε (feat/resend-welcome, υπεύθυνος
      2026-10-29):** στη σελίδα της υπηρεσίας «Resend welcome email» (ο κωδικός που είναι αποθηκευμένος τώρα, στον κάτοχο,
      3 σε κάθε 60 λεπτά, μόνο το email, audit), «Generate» και «και στείλε το welcome email» στο «Change password on server», «Save a password set
      elsewhere» χωρίς κλήση στον server· «Δημιουργία ισχυρού κωδικού» και για τον πελάτη (`provisioning.md`, «Κωδικός
      υπηρεσίας και welcome email»). cPanel, mailcow, Centova αλλάζουν στον server· Proxmox όχι ακόμα (PR 6 του
      `proxmox.md`).
- [ ] **DirectAdmin: αλλαγή κωδικού στον server** (σημειώσεις στο `provisioning.md`): με `CMD_API_USER_PASSWD` μετά
      από live δοκιμή. Ως τότε «Save a password set elsewhere». (Το ngm την έχει από το extension `Ngm`, 2026-11-04.)
- [ ] **ngm, μετά το πρώτο extension** (`provisioning.md` → «ngm»): live δοκιμή σε ngm-debian με άδεια του υπεύθυνου·
      ό,τι κάνει το WHMCS module και δεν πέρασε ακόμα (καρτέλες του πελάτη, πακέτα σε λίστα, όρια ανά account).
- [ ] Αργότερα ή χειροκίνητα ως τότε: quotes (3 τον χρόνο), downloads (4), unblockip. (Το PaymentsTrackingMatch έχει
      αντίστοιχο, το `PaymentMatcher` του Bank transfer· μένει να ανοίξει στο cut-over του myip, WG-17.)

### Χρέωση και τιμολόγια
- [ ] Promotions: το ποσοστό για N κύκλους είναι ελεγμένο μέσω cron· μένει test ανανέωσης για Price Override και
      δοκιμαστική περίοδο (#274).
- [x] ~~Γλώσσα ανά πελάτη~~ **έγινε (feat/client-language):** του λογαριασμού για τα email και τα PDF (`clients.language`,
      από τον πελάτη στα στοιχεία του ή από το staff), του login για τις σελίδες του (`users.language`, από την επιλογή
      γλώσσας, και στο login)· μόνο επιτρεπτή, αλλιώς του instance. Το import φέρνει και τις δύο (`tblclients.language`,
      `tblusers.language`: myip 37 αγγλικά, nixpal 29 και λίγες άλλες).
- [x] ~~Ενιαίο «Total»~~ **έγινε (fix/invoice-total-wording):** PDF, portal και email δείχνουν το ίδιο: η πίστωση που
      χρησιμοποιήθηκε (όταν υπάρχει) και το σύνολο μετά από αυτήν (`Invoice::statement`)· το «Σύνολο» του portal
      μεταφρασμένο· το αυτόματο πιστωτικό της ακύρωσης στη γλώσσα του πελάτη. Το email έχει και `{{ credit }}`.
- [x] ~~**INV-17**~~ **έγινε (fix/invoice-issued-at):** το «Issued At» αποθηκεύεται, όπως το «Invoice Date» του WHMCS:
      σήμερα ή νωρίτερα, όσο το τιμολόγιο είναι draft ή απλήρωτο (ένα πληρωμένο το μετρούν οι αναφορές στη χρονιά του),
      και η λήξη ποτέ πριν από αυτό· μόνο από τη φόρμα, ποτέ με fill. (INV-8, INV-10 έγιναν· «Group Similar Line Items»
      απορρίφθηκε, `billing-cursor.md`.)
- [x] ~~Πακέτο που γίνεται free ή one-time~~ **έγινε (fix/plan-becomes-free):** κλειδώνει, ή «Never bill it again»
      (`billing-cursor.md` §5). Μένουν οι ημέρες πριν τη λήξη ανά κύκλο.
- [x] ~~Οι 30 ημέρες του «paid not applied»~~ **έγινε:** ρύθμιση `cronjob_paid_not_applied_days` (Settings → Cron, «Paid but
      not applied: protected days», 30, από 1 έως 365).
- [ ] Πληρωμές: fallback του Stripe (#1260 του
      upstream)· SEPA (υπάρχει κώδικας, όχι δοκιμή). (Η ανοχή υπογραφής του Stripe, PAY-14, έγινε: #341.)
- [ ] Τράπεζες και υπόλοιπα (`payments-tracking.md`):
  - **Έγινε:** emails των τραπεζών, widgets «Payments tracking» και «Bank totals» (#450), matching με τιμολόγια (#458,
    #467, #468).
  - **Στο cut-over:** τα στοιχεία του mailbox στο gateway Bank transfer.
- [x] ~~Μικροί κανόνες από τα hooks του myip~~ **έγιναν:** το email στο staff σε υπερπληρωμή υπήρχε ήδη («Payment needs
      attention», `RecordPaymentService::addCredit`)· η αυτόματη ακύρωση απλήρωτων «Add Funds» είναι η ρύθμιση
      `cronjob_add_funds_cancel` (0 = ποτέ, όπως το WHMCS· στο myip 30 στο cut-over). (Το email αναστολής με τον λόγο έγινε.)
- [ ] **Ροή πληρωμής του πελάτη** (`pay-flow-ux.md`, απόφαση 2026-11-03): 7 PRs. Έγιναν: ορολογία (#591), κάρτα κατάστασης
      (#593), πληρωμή ανά πάροχο (#597), κοινή πληρωμή (#599), μπάρα του Mass Pay (PR 5). Μένουν: τρόποι πληρωμής (6a, 6b).
- [ ] **Τι ξεχωρίζει κάθε υπηρεσία** (ιδιοκτήτης 2026-11-03, **μετά** τη ροή πληρωμής, ως δική του σειρά PRs): δέκα ίδια
      «Hosting Plus» δεν ξεχωρίζουν. Δίπλα στο όνομα της υπηρεσίας, στη λίστα υπηρεσιών, στη σελίδα της (τίτλος, breadcrumb)
      και στις γραμμές του τιμολογίου (σελίδα, λίστα, μάλλον και PDF): hosting/cPanel → domain· Centova → username·
      server/VM/cloud → IP και hostname· license → IP ή/και id· τα άλλα modules ό,τι τα ξεχωρίζει. Αφετηρία το
      `Service::mailName` (#592, για τα emails) και το `Services\Index::glance()` της λίστας, σε έναν helper για όλα.
      **PR 1 (feat/service-identifier):** `App\Support\ServiceIdentifier`, από τους κανόνες του `mailName`: license → label
      και IP, αλλιώς το κλειδί masked· αλλιώς domain (το hostname ενός VM/server· χωρίς τελεία, κλειδί masked) και IP·
      χωρίς domain, το username του πίνακα (`*_username`, όχι `pending`) ή το `hostname` της παραγγελίας· αλλιώς το label.
      Ποτέ password ή άλλο property. Το χρησιμοποιούν το `mailName`, η λίστα υπηρεσιών (κάτω από το όνομα· τα licenses
      έχουν ήδη κλειδί και IP), η σελίδα της υπηρεσίας (τίτλος, breadcrumb, `<title>`) και οι γραμμές του τιμολογίου
      (σελίδα, λίστα), όσα μέρη δεν λέει ήδη το κείμενο της γραμμής. Μένει: το PDF του τιμολογίου, η αρχική σελίδα.
- [ ] **Αρχική σελίδα του πελάτη, πιο πυκνή** (ιδιοκτήτης 2026-11-03, μετά τη ροή πληρωμής· πρώτα mockups, όπως εκείνη):
      σήμερα τέσσερα μεγάλα κουτιά υπηρεσιών μόνο με το όνομα, χωρίς domain ή IP/hostname, χωρίς κουμπί SSO όπου το module
      το έχει, χωρίς κάρτες με πλήθος (υπηρεσίες, domains, αιτήματα, λογαριασμοί). Το WHMCS είναι «άσχημο» αλλά έχει όλη την
      πληροφορία compact: πλήθη, λίστα ενεργών με κατάσταση, προϊόν, hostname, SSO και «Προβολή», ληξιπρόθεσμα με «Πληρωμή»,
      αιτήματα. Μαζί με το «Τι ξεχωρίζει κάθε υπηρεσία», που δίνει το αναγνωριστικό των καρτών.
      **Απόφαση (ιδιοκτήτης, 2026-11-03):** η κατεύθυνση A των mockups (compact, όπως το WHMCS) **με όσα έχει σήμερα**:
      πλήθη πάνω (υπηρεσίες, domains, αιτήματα, απλήρωτα με το σύνολο, το καθένα σύνδεσμος)· όλο το «Χρειάζεται την προσοχή
      σας» και το «Όλα εντάξει»· πίνακας ενεργών υπηρεσιών (κατάσταση, προϊόν, αναγνωριστικό, σύνδεση στον πίνακα με τους
      άλλους σε μενού, «Διαχείριση»)· «Επόμενες ανανεώσεις» ως σειρά τριών μηνών που ανοίγουν· απλήρωτα με τι θα γίνει στο
      καθένα (`PaymentStatus`)· αιτήματα· ο χώρος των extensions (`pages.dashboard`, π.χ. Announcements)· αναζήτηση domain
      (η σελίδα `domain-search`). Κάθε ενότητα με το δικαίωμά της, όπως σήμερα. PRs: 1 το αναγνωριστικό (παραπάνω)· 2 πλήθη
      και πίνακας υπηρεσιών· 3 τα κουμπιά σύνδεσης (lazy ή cache, αφού το `getActions` μπορεί να ρωτά τον server· το κλικ στο
      `services.panel-login` με τα guards του)· 4 τιμολόγια, ανανεώσεις, αιτήματα, extensions, αναζήτηση.
      **PR 2 (feat/home-tiles-services):** `App\Support\Portal\Summary`. Τέσσερα πλήθη πάνω από το «Χρειάζεται την προσοχή σας»:
      ενεργές υπηρεσίες (και πόσες σε αναστολή), domains που δεν έληξαν οριστικά (και πόσα λήγουν σε 30 μέρες), ανοιχτά
      αιτήματα (και πόσα περιμένουν απάντηση), απλήρωτα (το σύνολο ανά νόμισμα και πόσα ληξιπρόθεσμα), το καθένα σύνδεσμος
      στη λίστα του και με το δικαίωμά του. Ο πίνακας ενεργών υπηρεσιών (οι ενεργές πρώτα, μετά σε αναστολή, έως 8 και «+N
      ακόμα») με κατάσταση, όνομα, αναγνωριστικό και «Διαχείριση» αντικαθιστά τα μεγάλα κουτιά. Τα υπόλοιπα όπως ήταν.

### Λογαριασμοί και πελάτες
- [ ] GDPR: εξαγωγή και διαγραφή/ανωνυμοποίηση. (Custom fields πελάτη υπάρχουν, custom properties· μένει το import.)
- [x] ~~2FA πελάτη: recovery codes~~ **έγινε (feat/client-2fa-recovery):** 8 κωδικοί μίας χρήσης όταν ανοίγει το 2FA
      (εμφανίζονται μία φορά, φυλάσσονται μόνο ως sha256, κρυπτογραφημένα)· «χρησιμοποιήστε κωδικό ανάκτησης» στη σελίδα
      2FA του login· κλείσιμο του 2FA με κωδικό και κωδικό ανάκτησης· νέοι με τον κωδικό πρόσβασης και έναν κωδικό· τα λάθος μετρούν στο
      ίδιο όριο με τους κωδικούς της εφαρμογής· φεύγουν με το secret (και όταν το κλείνει το staff). Το backup code του
      WHMCS (hash άγνωστου σχήματος) δεν εισάγεται: όσοι έρθουν με 2FA φτιάχνουν τους δικούς τους.
- [x] ~~Valid IPs και comments στη λίστα υπηρεσιών του portal~~ **έγινε (feat/service-list-ips):** κάτω από κάθε
      υπηρεσία η IP της (της άδειας τα Valid IPs, αλλιώς η dedicated IP) και τα πεδία που αναφέρει η άδεια (το `comments`
      της nixpal)· αναζήτηση με όνομα, domain, IP ή αυτά τα πεδία. Ποτέ οι σημειώσεις του staff (`notes`).

### Admin
- [x] ~~Λίστα προϊόντων «σαν WHMCS»~~ **έγινε (feat/product-list-columns):** στήλες Type (το module), Pay Type (από τα
      πλάνα), Stock (∞ χωρίς όριο), Auto Setup, και Shown (Listed/Hidden/Retired). (Duplicate, Pending Orders και
      dashboard υπήρχαν· το Auto Setup ως ρύθμιση του προϊόντος επίσης.)
- [ ] `mycs:login-link`. (Έξοδα: ανήκουν στο Ekdosi.)
- [ ] **Έσοδα για τον προγραμματισμό ταμείου** (`income-planning.md`, σχέδιο εγκρίθηκε 2026-10-28): το widget
      «Έσοδα» του dashboard (#500)· η σελίδα «Έσοδα & πρόβλεψη» (#501)· «Έσοδα ανά πελάτη» (#503)· «Έσοδα ανά προϊόν».
- [ ] **Dashboard του admin: «Needs attention», «Servers», «Recent activity» και το γράφημα** (owner 2026-11-03, από τα
      demos του GaiaCP και του Billanz: μόνο οι ιδέες, όχι κώδικας· το GaiaCP είναι GPL-3.0). **Βήμα 1 έγινε (#621):** το
      «Income · EUR» στο μισό ύψος (805 → 521 px, μετρημένο στο devbox) και μία γραμμή ανά πληρωμή στο «Payments tracking»
      (57 → 33 px: η τράπεζα έγινε στήλη), χωρίς να χαθεί νούμερο. Μένουν οι πίνακες renewals (331 px ο καθένας), τα tiles και
      όσα παρακάτω.
      **Κατεύθυνση B (συμπαγής, στο ύφος του WHMCS, που το staff ξέρει 15 χρόνια), για όλο το dashboard:** μένουν όλα όσα
      έχουμε και μικραίνουν, και τα νέα μπαίνουν δίπλα τους. Το mockup (A κάρτες, B συμπαγές, και ολόκληρο το dashboard σε B)
      είναι σε ιδιωτικό canvas του ιδιοκτήτη. Τα επτά tiles του `Overview` μένουν (πάντα εκεί, όπως στο WHMCS, owner
      2026-10-21), πιο κοντά και με θαμπά τα μηδενικά (**έγινε, βήμα 8**: το sparkline μικρό δίπλα στην τιμή, μια κάρτα χωρίς
      τίποτα στο 70%, οι ετικέτες και οι περιγραφές τους μεταφρασμένες)· οι λωρίδες Registrar credit και Bank totals μένουν ως δύο λεπτές
      γραμμές· το «Income · EUR» σε δύο στήλες χωρίς να χάσει νούμερο (**έγινε, βήμα 9**: η διάταξη του #626, και οι τρεις αριθμοί
      του μήνα σε μία γραμμή με την ετικέτα δίπλα)· το «Payments tracking» (**έγινε, βήμα 9**: η αιτιολογία σε μία γραμμή με
      όλη της στο tooltip, το κίτρινο εξηγείται κάτω από τον τίτλο· search, φίλτρα και σελίδες μένουν, owner 2026-10-28) και οι δύο πίνακες renewals με
      πυκνότερες γραμμές (**έγινε, βήμα 8**, για τα renewals: το διάστημα κάτω από τον τίτλο, π.χ. «Within 30 days», αντί για
      τη γραμμή «Active filters»· οι στήλες μένουν όλες)· το «Staff online» και οι «Active Users» γίνονται **μία κάρτα «Staff and sessions»**, **έγινε (βήμα 7)**:
      δύο μέρη, το καθένα με το δικό του δικαίωμα. Staff: κουκκίδα, όνομα, κατάσταση, «Connected now» ή last seen, **χωρίς
      email και IP** (owner 2026-11-04: μένει το §7β του `docs/livechat.md`, παρότι το mockup τα έδειχνε). Πελάτες: οι
      συνδέσεις της τελευταίας ώρας (έως 20, ο τίτλος μετρά όλες), όνομα με σύνδεσμο, email, πότε, IP που αντιγράφεται με
      κλικ. Μικρή σαν το Servers (owner 2026-11-04): στην αριστερή στήλη κάτω από το Payments tracking, όπου είχε κενό, και
      κάθε μέρος με scroll μετά από 5 γραμμές.
      Αυτά προστίθενται:
      - [x] **Needs attention, έγινε (βήμα 5, #631):** στην κορυφή της δεξιάς στήλης, πάνω από το Servers. Γραμμές, πρώτα
        οι κόκκινες: τιμολόγια με χρέωση προς διευθέτηση (`ChargeAttempt::needsStaff`, φίλτρο «Charge to settle»), ενέργειες
        server που θέλουν staff (`ModuleAction::needsStaff`, φίλτρο «Needs staff»), ληξιπρόθεσμα, tickets που περιμένουν
        (tab «Awaiting us»), έλεγχοι του Automation status που δεν είναι ok, και γκρι τα γεγονότα πυλών από άλλη εταιρεία των
        7 τελευταίων ημερών (φίλτρο «Last 7 days»: κανείς δεν τα σημειώνει ως ελεγμένα). Κάθε γραμμή μόνο για λίστα που ο
        θεατής ανοίγει. Χωρίς τίποτα: «Nothing needs attention» και τι ελέγχθηκε. Τα Hosting Accounts μπήκαν (owner
        2026-11-04): «extra not billed» και «temporary extra to take back», κίτρινες, με σύνδεσμο στο φίλτρο «Check»· το «not
        mapped» μένει στο «Records to check». Το «To handle» **μένει** στο μενού (owner 2026-11-04: μετρητής σε κάθε
        σελίδα). Έμειναν: τα ποσά (πολλά νομίσματα). Αρχική περιγραφή:
        λίστα ενεργειών που δείχνει **μόνο ό,τι θέλει ενέργεια**. Κάθε γραμμή: μία φράση με αριθμό,
        μία γραμμή λεπτομέρειας (πελάτης, ποσό ή ηλικία του παλαιότερου) και σύνδεσμος στη λίστα του με το **ίδιο
        φίλτρο**, ώστε η κάρτα και η λίστα να μη διαφωνούν. Υποψήφιες γραμμές, με αυτή τη σειρά: χρεώσεις που θέλουν
        staff (όπως τις βγάζει το `charges` του MCP), ανοιχτά ή εγκαταλελειμμένα module actions, ξένα γεγονότα
        gateways, ληξιπρόθεσμα προτιμολόγια, tickets που περιμένουν, προβλήματα του HealthChecks. Μόνο ανάγνωση, άρα
        χωρίς adversarial review· κάθε γραμμή θέλει πρώτα ένα φίλτρο στη λίστα της. Το «To handle» του αριστερού μενού
        κάνει ήδη κάτι παρόμοιο (λίστα με badges): μένει ως μετρητής σε κάθε σελίδα, και η κάρτα λέει ποιος, πόσα και πόσο
        παλιό. Ανοιχτό: αν φύγει το «To handle» από το μενού (το μενού μένει στο ίδιο πλάτος, κερδίζει μόνο ύψος), και τι δείχνει η κάρτα όταν δεν υπάρχει
        τίποτα.
      - [x] **Servers, έγινε (βήμα 4):** στην κορυφή της δεξιάς στήλης· ανά ενεργό server τα accounts του (active, suspended,
        pending, ένα count για όλους) πάνω στο «max accounts», με τον πιο γεμάτο πρώτο, πορτοκαλί από 75% και κόκκινο από
        90%· χωρίς όριο μόνο ο αριθμός. Ίδιο δικαίωμα με τη λίστα servers. Αρχική περιγραφή: ανά server το Accounts used/max (`max_accounts`, `Server::accountsCount()`· υπάρχουν ήδη στη
        λίστα servers) ως μπάρα. Uptime **όχι**: δεν έχουμε τέτοια δεδομένα, και το monitoring δεν είναι μέρος του MyCS. Αν
        μπει κάποτε, το κενό διάστημα είναι «χωρίς δεδομένα», όχι κόκκινο.
      - [x] **Recent activity, έγινε (βήμα 3):** οι 8 τελευταίες γραμμές του Activity Log κάτω από το Support, με το ίδιο
        δικαίωμα και τις ίδιες γραμμές (`AuditSummary`), η IP κάτω από staff ή πελάτη, «View all». Αρχική περιγραφή
        (owner 2026-11-03, από το demo του Billanz): κάρτα με τις τελευταίες εγγραφές του `Audit`: ποιος,
        τι, πότε (και η IP στα logins) και «View all» προς τη σελίδα Audits. Ίδια δικαιώματα με τη σελίδα Audits· ό,τι
        δεν δείχνει εκείνη (π.χ. secrets) δεν το δείχνει ούτε η κάρτα.
      - [x] **Το γράφημα Revenue, έγινε (βήμα 6):** οι νέες παραγγελίες μπάρες στον αριστερό άξονα (ακέραιοι), τα δύο ποσά
        γραμμές στον δεξιό, και κάτω από τον τίτλο τα τρία σύνολα της περιόδου. Τα ποσά μόνο στο προεπιλεγμένο νόμισμα
        (`Revenue::payments()`): πριν, ευρώ και δολάρια προστίθονταν σε ένα νούμερο, και το ίδιο έκανε η κάρτα «Revenue»
        του `Overview`, που τώρα παίρνει τις ίδιες πληρωμές, ώστε κάρτα και σύνολο να συμφωνούν. Το ύψος μένει 160 px
        (owner 2026-10-21). Έμεινε: η «Completed orders». Αρχική περιγραφή: έχει ήδη τρεις σειρές (Revenue, Net revenue, New orders), όλες γραμμές. Η αλλαγή: τα New
        orders γίνονται μπάρες (άλλη κλίμακα, αριστερός άξονας) και τα δύο ποσά μένουν γραμμές (δεξιός άξονας), με τα
        τρία σύνολα της περιόδου κάτω από το γράφημα. Η περίοδος είναι η ίδια επιλογή (`DashboardPeriod`) που ήδη οδηγεί το
        `Overview`. Μια τέταρτη σειρά, «Completed orders», μόνο αν τη ζητήσει το staff.
      - [x] **Διάταξη, έγινε (βήμα 2):** δύο ανεξάρτητες στήλες αντί για κοινές σειρές (ιδέα του ιδιοκτήτη 2026-11-03), ώστε
        μια κάρτα να μη μένει με κενό δίπλα σε ψηλότερη (το Income 521 px δίπλα στο Payments 585 px το έδειξε). Αριστερά τα
        λεφτά (Revenue, Income: 774 px), δεξιά η λειτουργία (Support και οι λίστες renewals: 685 px· εκεί μπαίνουν
        και τα Needs attention, Servers, Recent activity όταν γίνουν)· τα tiles και οι λωρίδες από πάνω και το Payments
        tracking, Staff online, Active Users από κάτω μένουν σε όλο το πλάτος (το Payments tracking σε οποιαδήποτε
        στήλη την έκανε διπλάσια από την άλλη, 645 px). Με το Recent activity δεξιά (βήμα 3) το Payments tracking
        γύρισε αριστερά, όπως στο mockup: 1435 px αριστερά, 1170 px δεξιά, και το Servers θα μπει δεξιά. Η διάταξη είναι οι δύο λίστες `Dashboard::LEFT` και
        `RIGHT`· ό,τι widget δεν είναι σε καμία κρατά το πλάτος και τη σειρά του `$sort`. Ένα staff με μία μόνο στήλη δεν
        τη βλέπει μισή. Κάτω από lg μπαίνουν η μία μετά την άλλη (πρώτα τα λεφτά).
      - Το client home είναι ξεχωριστό θέμα και ήδη σε εξέλιξη (PR 1–2 έγιναν: #615, #616· βλ. «PR 2» παραπάνω).
- [ ] **Hosting Accounts: έλεγχος πακέτου και ορίων** (owner 2026-11-03: οι πελάτες ζητούν «λίγο χώρο ακόμα και θα τον
      γυρίσω» και ξεχνιέται· mockup στο ίδιο canvas). Η σελίδα Utilities → Hosting Accounts υπήρχε: κάθε λογαριασμός των
      WHM servers είναι **A service**, **Internal** (με σημείωση) ή **Not mapped** (ενέργειες Service, Internal, Clear· ο
      `AccountSync` αντιστοιχίζει μόνος του όταν μία ζωντανή υπηρεσία έχει αυτό το username), και το «Differs» συγκρίνει μόνο
      την **κατάσταση**.
      - [x] **Έγινε (#622): η στήλη Check και το φίλτρο, μόνο ανάγνωση.** Το sync διαβάζει ανά πακέτο το όριο δίσκου και
        bandwidth (`getpkginfo`: `plan_disk_mb`, `plan_bandwidth_mb`) και ο `AccountCheck` συγκρίνει κάθε αντιστοιχισμένο
        λογαριασμό με το πακέτο + το extra που χρεώνουμε (επιλογή `extra_disk_gb`): **extra not billed** (+N GB), **billed,
        not applied**, **below the package**, **package differs** (π.χ. `MyIP_Starter-20GB` αντί `MyIP_Starter`· το πρόθεμα
        reseller μετράει μόνο του owner), **bandwidth differs**, και «not checked» για πακέτο χωρίς όριο. Διαφορά κάτω από
        100 MB είναι στρογγυλοποίηση. Κρατιέται στη γραμμή του λογαριασμού και ξαναϋπολογίζεται όταν αλλάζει η αντιστοίχιση.
        Στο live (devbox, 2026-11-03, 159 λογαριασμοί): τα όρια διαβάστηκαν για 156· οι 151 έχουν όριο δίσκου ίσο με το
        πακέτο στο MB· 4 έχουν περισσότερο· το WHM στέλνει `BWLIMIT: null` για απεριόριστο στα reseller πακέτα και
        «Failed to load package» για πακέτο που δεν υπάρχει στον server (δεν μετράει ως αποτυχία). Αντιστοιχισμένος ήταν
        μόνο 1 λογαριασμός, άρα τα ευρήματα θα φανούν μετά το import.
      - [x] **Έγινε: προσωρινό extra και έγκριση** (αποφάσεις του υπευθύνου 2026-11-04). Ενέργεια **Temporary extra**: +N GB
        έως ημερομηνία, με σημείωση και ποιος· μέχρι και εκείνη τη μέρα ο έλεγχος το περιμένει («Temporary extra»), μετά ό,τι
        μένει είναι **«Extra to take back»** (γραμμή στο Needs attention), και μόλις ο χώρος επιστραφεί το προσωρινό extra
        ξεχνιέται. Το MyCS **δεν αλλάζει τίποτα στο WHM**: το staff δίνει και παίρνει πίσω τον χώρο με το χέρι. Ενέργεια
        **Approve** για εξαίρεση (π.χ. δωρεάν extra), με υποχρεωτική σημείωση: το εύρημα γίνεται «Approved» όσο μένουν ίδια
        τα πακέτα, η υπηρεσία, ό,τι χρεώνουμε και το όριο που αφορά· αν αλλάξει κάτι, το εύρημα ξαναβγαίνει και η
        έγκριση σβήνει. Ένα δωρεάν extra εγκρίνεται ως **ποσό** (review του #645): μετρά όπως ό,τι χρεώνουμε, άρα ένα
        προσωρινό extra δίπλα του δεν το σβήνει, ό,τι δοθεί παραπάνω φαίνεται ως νέο «extra not billed», και αν αφαιρεθεί
        (έστω μέρος του) η έγκριση σβήνει. Άλλο εύρημα του ίδιου λογαριασμού φαίνεται κανονικά. Αντιστοίχιση σε άλλη υπηρεσία σβήνει και τα δύο.
      - [ ] **Μένουν:** inodes και CloudLinux (CPU, μνήμη, IO): διαβάζουμε το
        όριο του **λογαριασμού**, όχι του πακέτου, άρα σύγκριση με τους άλλους του ίδιου plan [G]. Γνωστό όριο: με login
        reseller που δεν βλέπει τα πακέτα του root, οι λογαριασμοί μένουν ανέλεγκτοι χωρίς αναφορά.
- [ ] Έλεγχος σε πραγματικό browser (`audit-browser.md`, δεν έχει γραφτεί).

### Licensing (`licensing.md`)
- [ ] **Ποιοι χτυπάνε χωρίς να πρέπει** (ο υπεύθυνος, 2026-10-20): οθόνη του staff με άδειες terminated/suspended/
      expired που ακόμα ελέγχουν (στις 90 ημέρες της nixpal: 535 χιλ. «Expired», 44 χιλ. «Suspended»), IPs χωρίς ποτέ
      άδεια, και ενέργειες (block IP, email στον πελάτη). Τα δεδομένα υπάρχουν σε μεγάλο μέρος (`license_check_days`,
      `last_failure`, `zlic_limits`)· λείπουν η άθροιση ανά IP και η οθόνη.

### Domains (`domains.md`, `domains/grepp.md`)
- [ ] **Σελίδα domain συμπαγής** (ιδιοκτήτης 2026-10-23, όπως το mockup της υπηρεσίας): ετικέτες αριστερά, δύο στήλες,
      οι εντολές του registrar σε μία σειρά (όπως τα «Registrar Commands» του WHMCS), «At the registrar» σε μικρότερο
      κουτί. Μαζί:
      - **Αλλαγή registrar από dropdown** (σήμερα δεν γίνεται): σε «κανένας / χειροκίνητα» ή σε άλλη σύνδεση (CNIC →
        Openprovider) για ένα domain που μεταφέρθηκε ή αλλάχτηκε με το χέρι· μόνο staff, με επιβεβαίωση, logged, χωρίς
        κλήση στον registrar (καταγράφει πού είναι, όπως ο Server της υπηρεσίας), μετά sync.
      - **Τιμή ανανέωσης με το χέρι** (σήμερα μόνο «The TLD's renew price», χωρίς πεδίο): ποσό για φίλο/γνωστό, «Keep this
        price» και «Recalculate» πίσω στην τιμή του TLD, όπως η υπηρεσία (εκεί υπάρχουν ήδη: Price, Recalculate Price,
        Keep this price).
- [ ] Μικρά του P1b (#248): πεδίο διεύθυνσης που αδειάζει ίσως μένει παλιό στο Openprovider· η `int` διεύθυνση του .gr
      δεν ενημερώνεται.
- [ ] Ανανέωση από το portal: υπάρχει ως τιμολόγιο (D7a)· ανοιχτό αν θέλουμε και ως `orders`, όπως το WHMCS
      (`domains.md` D7-2). Additional fields ανά TLD: όχι ακόμα.
- [ ] Ο κατάλογος .gr στο MyCS: τιμές και ΦΠΑ πριν πουληθεί από εδώ· ο importer φέρνει το `recurring_amount` των .gr
      δύο χαρακτήρων.
- [ ] Αλλαγή κατόχου .gr: **G8-5c** (από τον πελάτη), **G8-5d** (trade στο Openprovider). Τα G8-5a (#215) και
      **G8-5b** (ροή του staff με τιμολόγιο και δήλωση, `domains/grepp.md`) υπάρχουν.
- [ ] ~~**G8-6** διαγραφή στο Μητρώο~~: όχι (ιδιοκτήτης 2026-10-23: επικίνδυνη, δεν τη χρησιμοποιήσαμε ποτέ· γίνεται στο grweb).
- [ ] Έλεγχος μεταγραφής ελληνικών στοιχείων επαφής προς τους registrars.
- [ ] Τα 8 ερωτήματα του sweep των domains για τον υπεύθυνο (`domains.md`, «Sweep 2026-10-22»).

### Support (`tickets.md`)
- [x] ~~«Last Reply» στη λίστα tickets~~ **έγινε (feat/ticket-last-reply, ο υπεύθυνος 2026-10-23):** πόση ώρα από την
      τελευταία απάντηση («8h 43m», όπως το WHMCS· όχι οι εσωτερικές σημειώσεις), με ταξινόμηση.
- [ ] Το polling email (`FetchTicketMail`) ανοίγει ανά τμήμα μετά από δοκιμή με πραγματικό email (`tickets.md` §6γ).

### Ασφάλεια και ποιότητα
- [ ] phpstan baseline: 339 σφάλματα του upstream (270 εγγραφές)· μειώνεται όπου περνάμε.
- [ ] Το repo γίνεται private (απόφαση 2026-10-22· ακόμα public). Ως τότε: έγγραφα με σήμανση εμπιστευτικού δεν
      μπαίνουν στο repo, μόνο παράφραση.

### myip.gr (μετά τη nixpal)
- [x] ~~Δύο επιλογές του πελάτη για το Ekdosi~~ **έγινε (feat/ekdosi-client-flags):** «Ignore invoicing» και «Immediate invoicing» στην καρτέλα του πελάτη (μόνο staff)· κρατιούνται, τις διαβάζει η γέφυρα όταν έρθει.
- [x] **Ekdosi bridge** (έγινε ως το E5 και η live δοκιμή, 2026-10-27· μένει μόνο το E4, που ανήκει στον importer),
      σε κομμάτια (ο υπεύθυνος, 2026-10-25· `ekdosi-bridge.md`): ρύθμιση κλειστή by default,
      «MyCS Bridge» στο Ekdosi με λιγότερες ρυθμίσεις, ένα «Εισερχόμενα». ΑΦΜ/ΔΟΥ/είδος παραστατικού από τις επαφές,
      γκρινιάρης και ignore invoicing από τον πελάτη, αποδέκτης ανά γραμμή από τη σχετική επαφή υπηρεσίας/domain.
  - [x] **E1 (feat/ekdosi-feed):** `POST /api/ekdosi` (HMAC, ops ping/invoices/invoice/resolve/mark) στο σχήμα του
        plugin, `ekdosi_documents`, `domains.contact_id`, Settings → Ekdosi.
  - [x] **E2 (feat/ekdosi-documents):** τα παραστατικά στο τιμολόγιο (admin και client area), στήλη/φίλτρο στη λίστα,
        προειδοποίηση σε ακύρωση/refund, χωρίς διαγραφή τιμολογίου με παραστατικό.
  - [x] K1 στο Ekdosi (chrismfz/ekdosi#701): «MyCS Bridge».
  - [x] **E3 (#464):** `add_payment` (adversarial, 2 γύροι)· **K2 στο Ekdosi (chrismfz/ekdosi#702):** πληρωμές
        προς τη MyCS, ΜΑΡΚ για split, `mycs:watch-reversals` (ειδοποίηση όταν τιμολόγιο με παραστατικό γίνει
        refunded/cancelled/unpaid στη MyCS· ό,τι κόπηκε μένει, πιστωτικό αν χρειαστεί).
  - [x] **E5 (#478):** «Send to Ekdosi» στο τιμολόγιο και μαζικά, όπως το κουμπί του plugin· feed `requested` (και
        πελατών με ignore, και απλήρωτα, επί πιστώσει). **K3 (chrismfz/ekdosi#703):** το διαβάζει, ποτέ αυτόματη
        έκδοση, και **ξαναδιαβάζει από τη MyCS πριν από κάθε έκδοση** (ό,τι άλλαξε μένει σε αναμονή). Deploy: πρώτα
        το K3, μετά το E5 (το K3 χωρίς E5 δεν ρωτά `requested`· το E5 χωρίς K3 θα είχε κουμπί που δεν κάνει τίποτα).
  - [x] ~~K4 στο Ekdosi: πελάτης στο Ekdosi ανά ιδιώτη της MyCS~~ **δεν γίνεται (υπεύθυνος, 2026-10-27):** η ΑΛΠ μένει
        χειροκίνητη επιλογή. Αν το Ekdosi βρίσκει πελάτη μόνο του, το προσωπικό μπορεί να κόψει κατά λάθος ΑΛΠ σε κάποιον
        που μετά ζητά τιμολόγιο, και τότε ακολουθούν ακύρωση και πιστωτικό· γι' αυτό ούτε η myip το είχε. Το Ekdosi
        ταιριάζει πελάτη μόνο με ΑΦΜ (`WhmcsCustomerMatcher`). Λίγες ΑΛΠ (myip: ~32 τον χρόνο από 736 παραστατικά).
  - [x] **Live δοκιμή άκρη-άκρη** (`ekdosi-bridge.md` → «Live δοκιμή»)· **2026-10-27 (υπεύθυνος + Claude, Chrome):** ΑΛΠ, ΤΠΥ με αντιστοίχιση από ΑΦΜ, «άμεσο», ignore,
        write-back και E2 πέρασαν. Ευρήματα:
        - ~~Ekdosi (K3): ετικέτες «WHMCS» σε ροή της MyCS~~ **έγινε (chrismfz/ekdosi#705)**, μαζί με την ανανέωση της
          σελίδας μετά την καταχώριση πληρωμής. Ο «ΠΕΛΑΤΗΣ ΛΙΑΝΙΚΗΣ» δεν προτείνεται μόνος του για ΑΛΠ: **επίτηδες**
          (βλ. K4).
        - ~~Ekdosi: απλήρωτο έβγαινε εξοφλημένο χωρίς πληρωμή~~ **έγινε (chrismfz/ekdosi#704):** απλήρωτο (και
          Payment Pending/Collections) → «Επί Πιστώσει»· η προεπισκόπηση δείχνει τον τρόπο πληρωμής και ό,τι έχει ήδη
          πληρωθεί (καταχωρείται με το χέρι). Η πληρωμή δηλώνεται με το χέρι και στα δύο (push πληρωμών κλειστό).
        - ~~MyCS: «Τα παραστατικά μου» στο portal (E2b)~~ **έγινε (#482)**.
        - ~~MyCS, σελίδα τιμολογίου~~ **έγινε (fix/invoice-edit-refresh):** οι πίνακες Transactions/Movements δείχνουν
          αμέσως την πληρωμή του Mark Paid (και του credit, του «Close as paid»)· μετά το Save που σβήνει το αίτημα, ξανά
          «Send to Ekdosi»· οι γραμμές που κρατούν τα ποσά τους (πληρωμένο, παραστατικό, πληρωμές) φαίνονται κλειδωμένες
          με τον λόγο (η περιγραφή αλλάζει, όπως δέχεται και το Save).
        - Επί πιστώσει πέρασε (INV-7 → ΤΠΥ6666, ξαναδιάβασμα, πληρωμή με το χέρι και στα δύο).
        - **Split πέρασε (2026-10-28):** INV-8, υπηρεσία με «Σχετική επαφή» τρίτο μόνο με ΑΦΜ (ROTOGRAN) + γραμμή του
          λογαριασμού → δύο προσχέδια, ο τρίτος από την ΑΑΔΕ, 0% σωστά, σύνολο 16,20 € όσο πληρώθηκε. Στην πορεία:
          #485 (επαφή μόνο με ΑΦΜ επιλέγεται), #487 (feed: `taxed` μόνο με ΦΠΑ, `tax_reason`), chrismfz/ekdosi#716
          (split από τη λίστα), #717 (γκρινιάρης χωρίς πελάτη, χώρα στην αυτόματη έκδοση), #718 (τρίτος από την ΑΑΔΕ,
          προειδοποίηση συνόλου).
        - **Reversal πέρασε:** refund του INV-7 στη MyCS → καμπανάκι «Χρειάζεται πιστωτικό (MyCS)» στο Ekdosi, μία φορά.
        - Ανοιχτά στο Ekdosi (δικά του PR): αντιστοίχιση `tax_reason` σε κατηγορία απαλλαγής· συντελεστές εκτός του
          προεπιλεγμένου ανά γραμμή· ενημέρωση του invoicer.myip.gr (παραγωγή, έκδοση 16/10).
  - [x] **E4 στον importer:** οι επαφές και οι αναθέσεις του plugin (`mod_ekdosi_contacts`, `mod_ekdosi_routing`)
        έρχονται ως επαφές του λογαριασμού και `contact_id` υπηρεσιών και domains (`ekdosi-bridge.md` → «Importer»).
        Τα marks δεν εισάγονται. Μένει **έλεγχος στο cut-over**: τιμολόγια με mark που είναι ακόμη ανοιχτά (0 στις
        2026-10-30).
- [x] ~~Ειδοποιήσεις Mattermost~~ **έγινε (#456):** Mattermost ή Slack.
  Κάρτες όπως του WHMCS (2026-10-29): χρώμα, τίτλος με link στη σελίδα του admin, πεδία (πελάτης με link, λήξη,
  σύνολο, τρόπος πληρωμής, τμήμα, προτεραιότητα, κατάσταση). Νέο event `ticket_staff_reply` (απάντηση staff), μόνο
  όπου το ζητά ένας κανόνας.

### Εκκρεμότητες του υπεύθυνου (όχι κώδικας)
- [ ] cdnet.gr: auto renew off στο WHMCS (ανανεώνεται πια από το MyCS).
- [ ] Το .in της nixpal σε grace· 2 domains του myip με `serverHold`. Τα 4 domains της nixpal στο CNIC: η «μεταφορά αντί
      για ανανέωση» υπάρχει (#303, #304)· στο cut-over ανοίγει ο διακόπτης της (κλειστός ως τότε).
- [ ] Πριν το cut-over: `paypal_legacy_receivers` (PayPal της nixpal) και μήνυμα στους 3 πελάτες με συνδρομή (γραμμή 7).
- [x] ~~Σβήσιμο των branches που έχουν γίνει merge~~ **έγινε (2026-10-28):** στο GitHub μένει μόνο το `main`.

## Έγινε (σύνοψη· λεπτομέρειες στα PRs και στα docs)
- **2026-10-20 → 23:**
  - **Tickets (πλήρες port):** Α1 (#239, #240, #242), Α2 εισερχόμενα (#244), Α3 εξερχόμενα (#249), Β φόρμα επικοινωνίας
    (#251), Γ σελίδα επισκέπτη (#252), Δ importer (#256)· εσωτερικές σημειώσεις, έτοιμες απαντήσεις, merge, CC
    (#263, #265–#267, #269)· polish (#260–#262, #273).
  - **Χρέωση:** ομάδες πελατών (#272), promotions όπως του WHMCS (#274), προσφορές (#285), ληξιπρόθεσμα (#286),
    τραπεζική μεταφορά (#287), επιλογές υπηρεσίας / addons (#296), πίστωση στο downgrade (#326).
  - **Reports:** πρόβλεψη εσόδων (#275, #278), έσοδα έτους XLSX/CSV (#280), σύνολα ανά έτος (#281), widget υπολοίπου
    registrars (#283).
  - **Admin και portal:** PDF όπως του WHMCS (#264), μενού/sidebar/dashboard (#276, #277, #279), `/sysadmin` (#295),
    μαζικό email (#294), κλειδί άδειας στα templates (#291), τίτλοι καρτελών πελάτη (#301), «Τα emails μου» (#311).
  - **Domains:** σελίδα μεταφοράς και μαζική μεταφορά (#271), emails ανανέωσης/μεταφοράς (#293), **CNIC** αναγνώσεις,
    διαχείριση, ανανέωση, «μεταφορά αντί για ανανέωση» (#297–#304), sweep χρημάτων και λήξεων (#305–#310, #312),
    domain της φιλοξενίας στο checkout (#313)· χωρίς NS → Default Nameserver 1–5 (#180).
  - **Provisioning:** cPanel resellers, χρήση, εργαλεία πελάτη, domains λογαριασμού (#315, #316, #318, #320, #321)·
    **mailcow** (#324)· **Centova Cast** (#329, #335, #336, #339)· **SSL μέσω Openprovider:** απόφαση (#343),
    παραγγελία και επικύρωση (#345), κατάλογος και τιμές με περιθώριο (#348), επανέκδοση, approver, ανανέωση μόνο
    πληρωμένη, email πελάτη (#350).
  - **Ασφάλεια και λειτουργία:** Automation Status (#258), `/health` (#259), rate limit `zlic.php` (#254), σκλήρυνση
    (#317, #319, #322, #323, #325, #328, #330–#334, #337, #338, #341, #342, #346, #347, #349: headers/CSP, cookies,
    throttles, gateways, IPN), **2FA staff υποχρεωτικό** (#344).
- **Πριν το cut-over της nixpal (ως 2026-10-20):** αρίθμηση σε τρεις τρόπους (#238), χωρίς πιστωτικό για τιμολόγιο που
  δεν εκδόθηκε (#241), σημειώσεις λογαριασμού (#243), πακέτο reseller στο cPanel (#246)· επαφές προς registrars (P1b,
  #247, #253)· glue .gr από τον πελάτη (P5, #250).
- **Καθάρισμα και βάσεις:** χωρίς telemetry/updater/marketplace, CI με Pint και phpstan, deploy στο devbox (Φάση 0)·
  audit sweep του κληρονομημένου κώδικα (Φάση 1a, `audit-sweep.md`).
- **Χρήματα:** ενιαίο σημείο τιμολόγησης σε decimal (Σ7, #107, #109)· αλλαγή πακέτου/κύκλου με αναλογική χρέωση
  (SVC-14, #113, #115)· sweep χρέωσης A–H (#122–#150): ομαδοποίηση, pro rata, merge/split, Mass Pay, EU VAT/VIES και
  reverse charge (#145), αυτόματη χρέωση με retries, Cardlink (#149), λήξη κάρτας (#150).
- **Λογαριασμοί:** staff χωριστά από τους πελάτες, πολλοί users ανά λογαριασμό με δικαιώματα, επαφές (#100–#103,
  #116)· Client Summary (#154).
- **Παραγγελίες:** pending ως την έγκριση του staff (#227), email στο staff (#225), ημερήσια αναφορά αναστολών (#221),
  απευθείας links (#216–#218), ALTCHA (#222).
- **Provisioning και licensing:** Σ1-a/b (#114, #117, #119), servers ανά υπηρεσία (#112)· licensing L1–L3 (#106, #128,
  #131).
- **Domains:** μοντέλο, συγχρονισμός, μεταφορές, ανανεώσεις, grace/redemption (#161–#169, #204)· Openprovider στο `/v1`·
  αναζήτηση/whois/τιμοκατάλογος (#176, #178)· D8 (#178–#180)· **.gr EPP** G0–G7 (#183–#205) και G8-1/3/4/5a (#206–#215).
- **Portal πελάτη:** P1–P4d (#219–#232): επαφές, μητρώο, DNSSEC, μενού, «Χρειάζεται την προσοχή σας», ανανέωση domain
  (D7a), Ctrl+K.
- **Mail και templates:** el/en (#98, #152, #158)· templates του staff δεν γίνονται compile ως Blade.
- **Ποιότητα:** κείμενα el/en με test-φύλακα (#228)· `bin/test` παράλληλα, ~2½ λεπτά (#229).

## Δεν γίνονται (απογραφή: δεν χρησιμοποιούνται)
Late fees, billable items, affiliates (το extension του upstream μένει αχρησιμοποίητο), escalation rules, network
issues, fraud modules, φόρος δεύτερου επιπέδου, premium domains, δωρεάν domain με hosting, «Group Similar Line Items»,
server status, TheSSLStore (SSL μέσω Openprovider). Επίσης: custom fields προϊόντος (Σ1-c), Δ4 `completed`, G2 (ανοιχτά
τιμολόγια όταν αλλάζει το καθεστώς ΦΠΑ), η ένδειξη SSL δίπλα σε κάθε domain του portal (το `ssl-check` του WHMCS:
περιττοί έλεγχοι και φόρτος, ο υπεύθυνος 2026-10-23), οι ανανεώσεις on demand από τον πελάτη (κλειστές στο myip, ποτέ
σε χρήση), τα minor units του Stripe για νομίσματα χωρίς δεκαδικά (μόνο ευρώ, PAY-16).
