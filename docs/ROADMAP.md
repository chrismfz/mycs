# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα «Ανοιχτά» και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του (`domains.md`, `payments.md`, `sweeps/billing.md`
> κ.λπ.)· εδώ μόνο ο χάρτης. Ιστορικό: `history/roadmap-2026-10-11-to-20.md` (ως το #232).
>
> **Καθάρισμα 2026-10-23 (main στο #350):** κάθε ανοιχτό σημείο ελέγχθηκε απέναντι στον κώδικα και στα PRs· ό,τι είχε γίνει
> πέρασε στο «Έγινε». Μαζί, τι χρησιμοποιούν πραγματικά staff και πελάτες στο WHMCS του myip (access log 1–22/10,
> `whmcs-usage.md`).

## Πού είμαστε (2026-10-23, main στο #350)
- Το MyCS τρέχει στο devbox (`https://mycs.myip.gr`)· **καμία εταιρεία δεν δουλεύει ακόμα σε αυτό**. Pilot είναι η
  nixpal, μετά το myip.gr (`../CLAUDE.md`).
- **Έτοιμα:** η χρέωση και οι ροές χρημάτων (ομάδες πελατών, promotions, προσφορές, τραπεζική μεταφορά), οι λογαριασμοί,
  οι παραγγελίες, τα **tickets** (πλήρες port), το provisioning (cPanel με resellers και χρήση, mailcow, Centova Cast,
  **SSL μέσω Openprovider**, licensing της nixpal), τα domains (Openprovider, CNIC, Μητρώο .gr με δικό μας EPP), τα
  βασικά reports, το portal, el/en, σκλήρυνση ασφαλείας (2FA staff υποχρεωτικό). Σύνοψη στο «Έγινε».
- **Τι λείπει για να μπει η nixpal:** ο importer με parity (9), η παραγωγή στον orion (8) και το runbook (10).

## Επόμενα, με σειρά (εγκρίθηκε 2026-10-20, ενημέρωση 2026-10-23)
Η σειρά οδηγεί στο cut-over της nixpal. Ο importer (9) μπαίνει στο τελικό στάδιο, για να ετοιμάζει και τα του myip
(cPanel resellers, mailcow, Centova, Proxmox)· η παραγωγή στον orion (8) μετά τις δοκιμές και τα audits· οι συνδρομές
PayPal (7) την ημέρα του migration (ο υπεύθυνος, 2026-10-22). Μετά: UI/UX polishing.

| # | Τι | Κατάσταση |
|---|---|---|
| 1–6δ | Αρίθμηση, σημειώσεις, PayPal vault, licensing/cPanel, ομάδες/promotions, reports, προσφορές, τραπεζική μεταφορά, emails domains, μαζικό email | **Έγιναν** (βλ. «Έγινε»). Από τα reports μένει μόνο σελίδα «Reports» με κατηγορίες όπως του WHMCS (Daily Performance, New Customers, Aging, Income by Product, Top 10), όταν χρειαστεί. |
| 7 | **Παλιές συνδρομές PayPal: την ημέρα του migration.** 5 υπηρεσίες, 3 πελάτες, €9/μήνα. Μήνυμα στους 3 να αποθηκεύσουν το PayPal στο portal (vault), ακύρωση της παλιάς συνδρομής από το staff· δίχτυ το H2b· στον importer `paypal` → νέο gateway PayPal με το `subscriptionid` | Στο cut-over. Χωρίς νέο κώδικα. |
| 8 | **Παραγωγή:** στον orion ως `mycs.nixpal.com`· SMTP ίδιο με το WHMCS, JetBackup στον rosso και δοκιμή επαναφοράς. Έγιναν: `/health` (#259), Automation Status (#258), στοιχεία εταιρείας/logo (#264), `deploy/private-files.sh` (#322), headers ασφαλείας (#342), 2FA staff (#344) | Μένουν deploy, SMTP, backups. |
| 9 | **Importer του νέου σχήματος + `mycs:parity`.** Εισάγονται ήδη: **tickets** (#256), κατάλογος, licensing, admins. **Μένουν:** πελάτες, επαφές, υπηρεσίες με server, τιμολόγια (αρίθμηση: myip number = id· nixpal μετρητής 6818· tests incomplete στο `InvoiceNumberingAdversarialTest`), **domains (D6: 11 της nixpal, 1.383 του myip· δεν έχει γραφτεί)**, πιστώσεις με ιστορικό (`tblcredit`), αποθηκευμένες μέθοδοι (`tblpaymethods` → `billing_agreements`), suspend until (14), config options, addons (`tblhostingaddons`), custom fields (`IP Address` → `dedicated_ip`, και `tblhosting.dedicatedip`· τα `comments` των αδειών έρχονται ήδη ως properties με το licensing import· `tblhosting.notes` → `notes`, μόνο για το staff), VAT (`tbltax`), ομάδες, γλώσσα πελάτη, `tblconfiguration`, σημειώσεις (`tblnotes`, `tblclients.notes`). **Και ό,τι ζητούν τα modules:** cPanel `reseller`/`cpanel_reseller` και όρια· mailcow (MyMail configoption 1–16, username/κωδικός, `mailcow_locked_by_us` για τις suspended)· Centova (όνομα = `tblhosting.username`, έλεγχος των 3 που διαφέρουν στο `listaccounts`, `centova_port`/`servertype`) | Τελικό στάδιο. Re-runnable, με parity (`importer.md`). |
| 10 | **Runbook cut-over με επιστροφή** (πρότυπο: `go-live-runbook.md` του Ekdosi): πάγωμα, import και parity, DNS/webhooks, **χάρτης 301 (βλ. «Cut-over»)**, ενημέρωση πελατών, έλεγχοι πρώτης μέρας· 2–3 δοκιμαστικές μεταφορές | Τελευταίο. |

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
- [ ] Whois από το admin: υπάρχει ανά domain (κουμπί «WHOIS» στο domain, ανοίγει το `/whois`)· μένει, αν χρειαστεί, φόρμα
      στο μενού Domains του admin (80 χρήσεις/μήνα στο WHMCS).

### Cut-over (myip, και ό,τι ισχύει για τη nixpal)
- [ ] **Χάρτης 301 για τα URLs που φέρνουν κίνηση** από Google: `/clients/knowledgebase/...` (~2.500/μήνα): τα 301
      υπάρχουν στο MyCS (`knowledgebase.md`), μένει το rewrite του `/clients/knowledgebase` του myip.gr προς το
      MyCS· `/clients/store/...` (~640/μήνα) στα προϊόντα του MyCS, `/clients/announcements`. Αλλιώς χάνεται η κίνηση.
- [ ] Freeze, import, DNS/rewrites, προώθηση του IPN του PayPal (το endpoint υπάρχει, `routes/legacy.php`), dump του
      WHMCS για αρχείο· οι ρυθμίσεις του myip από το `settings-map.md`· **G8-2** καθάρισμα των `533_`/`c60_`· η
      αρίθμηση συνεχίζει από τον τελευταίο του WHMCS (> 32.476 στις 2026-10-11· ξαναδιαβάζεται τη μέρα).

### Provisioning
- [ ] **Η πραγματική IP από τον server** (ο υπεύθυνος, 2026-10-23): ξεχωριστό πεδίο με την IP που αναφέρει το cPanel για
      τον λογαριασμό (στον συγχρονισμό), δίπλα στη `dedicated_ip`, και ένδειξη στο staff όταν δεν συμφωνούν.
- [ ] **Proxmox (8 VPS): απόφαση υπευθύνου**, extension ή χειροκίνητο προϊόν (χωρίς start/stop και console για τον
      πελάτη). Δικό μας module, με το pvewhmcs ως αναφορά (GPL: όχι κώδικας, `provisioning.md`).
- [ ] ngm (1), Hetzner (1): χειροκίνητα προϊόντα (χωρίς κώδικα).
- [ ] cPanel: root token στους servers του myip πριν τους resellers· συμφιλίωση κατάστασης μετά από job του server
      (το create υπάρχει, #114)· αργότερα, αν χρειαστεί, διαχείριση email/domains μέσα από το MyCS.
- [ ] SSL: live δοκιμή με πραγματική παραγγελία (PositiveSSL 6,53 €), με έγκριση του υπευθύνου. Οι 12 του TheSSLStore
      γίνονται νέες παραγγελίες στο Openprovider στη λήξη τους (οι 2 RapidSSL → PositiveSSL).
- [ ] mailcow: δοκιμή live στο mymail (με άδεια και το API key).
- [ ] Αργότερα ή χειροκίνητα ως τότε: quotes (3 τον χρόνο), downloads (4), unblockip, PaymentsTrackingMatch.

### Χρέωση και τιμολόγια
- [ ] Promotions: το ποσοστό για N κύκλους είναι ελεγμένο μέσω cron· μένει test ανανέωσης για Price Override και
      δοκιμαστική περίοδο (#274).
- [ ] Γλώσσα ανά πελάτη: υπάρχει το `NotificationHelper::localeFor`· μένει στήλη στον πελάτη και το import
      (`tblclients.language`· nixpal: 6 πελάτες).
- [ ] Το «Total» του PDF είναι μετά την πίστωση, του portal και του email το πλήρες· ενιαία διατύπωση (και το «Total»
      του portal είναι αμετάφραστο, `invoices/show.blade.php`). Το αυτόματο πιστωτικό της ακύρωσης γράφεται στα αγγλικά.
- [x] ~~**INV-17**~~ **έγινε (fix/invoice-issued-at):** το «Issued At» αποθηκεύεται, όπως το «Invoice Date» του WHMCS:
      σήμερα ή νωρίτερα, όσο το τιμολόγιο είναι draft ή απλήρωτο (ένα πληρωμένο το μετρούν οι αναφορές στη χρονιά του),
      και η λήξη ποτέ πριν από αυτό· μόνο από τη φόρμα, ποτέ με fill. (INV-8, INV-10 έγιναν· «Group Similar Line Items»
      απορρίφθηκε, `billing-cursor.md`.)
- [ ] Πακέτο που γίνεται free ή one-time (`billing-cursor.md` §5)· ημέρες πριν τη λήξη ανά κύκλο.
- [ ] Ανανεώσεις υπηρεσιών on demand από τον πελάτη (ρύθμιση του WHMCS, `settings-map.md`).
- [ ] Οι 30 ημέρες του «paid not applied» (`RenewalBilling::PAID_NOT_APPLIED_DAYS`) γίνονται ρύθμιση.
- [ ] Πληρωμές: μοναδικό κλειδί στο `transaction_id`· minor units στο Stripe (PAY-16)· fallback του Stripe (#1260 του
      upstream)· SEPA (υπάρχει κώδικας, όχι δοκιμή). (Η ανοχή υπογραφής του Stripe, PAY-14, έγινε: #341.)
- [ ] Tests incomplete: `BillingCursorMoneyTest` γραμμές 173 και 224 (μάλλον διορθώθηκαν: έλεγχος και αφαίρεση),
      285 «τιμολόγιο που δημιουργείται πληρωμένο ανανεώνει» (ανοιχτό).
- [ ] Τράπεζες και υπόλοιπα («πολύ αργότερα»): υπόλοιπα **PayPal/Stripe** στο dashboard (των registrars υπάρχει), port
      του addon των emails της τράπεζας (PaymentsTrackingMatch), «Payments tracking» με δικό του ρόλο, σύνολα τραπεζών
      μόνο για `*`, μέθοδος «τραπεζική κατάθεση».
- [ ] Μικροί κανόνες από τα hooks του myip: email στο staff σε υπερπληρωμή· αυτόματη ακύρωση απλήρωτων «Add Funds»
      μετά από 30 μέρες. (Το email αναστολής με τον λόγο έγινε.)

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
- [ ] Λίστα προϊόντων «σαν WHMCS»: στήλες Type/Pay Type/Stock/Auto Setup (Duplicate, Pending Orders και dashboard
      υπάρχουν).
- [ ] `mycs:login-link`. (Έξοδα: ανήκουν στο Ekdosi.)
- [ ] Έλεγχος σε πραγματικό browser (`audit-browser.md`, δεν έχει γραφτεί).

### Licensing (`licensing.md`)
- [ ] **Ποιοι χτυπάνε χωρίς να πρέπει** (ο υπεύθυνος, 2026-10-20): οθόνη του staff με άδειες terminated/suspended/
      expired που ακόμα ελέγχουν (στις 90 ημέρες της nixpal: 535 χιλ. «Expired», 44 χιλ. «Suspended»), IPs χωρίς ποτέ
      άδεια, και ενέργειες (block IP, email στον πελάτη). Τα δεδομένα υπάρχουν σε μεγάλο μέρος (`license_check_days`,
      `last_failure`, `zlic_limits`)· λείπουν η άθροιση ανά IP και η οθόνη.

### Domains (`domains.md`, `domains/grepp.md`)
- [ ] Μικρά του P1b (#248): πεδίο διεύθυνσης που αδειάζει ίσως μένει παλιό στο Openprovider· η `int` διεύθυνση του .gr
      δεν ενημερώνεται.
- [ ] Ανανέωση από το portal: υπάρχει ως τιμολόγιο (D7a)· ανοιχτό αν θέλουμε και ως `orders`, όπως το WHMCS
      (`domains.md` D7-2). Additional fields ανά TLD: όχι ακόμα.
- [ ] Ο κατάλογος .gr στο MyCS: τιμές και ΦΠΑ πριν πουληθεί από εδώ· ο importer φέρνει το `recurring_amount` των .gr
      δύο χαρακτήρων.
- [ ] Αλλαγή κατόχου .gr: **G8-5b** (ροή του staff με χρέωση, υπογεγραμμένη δήλωση), **G8-5c** (από τον πελάτη),
      **G8-5d** (trade στο Openprovider). Το G8-5a (#215) υπάρχει.
- [ ] **G8-6** διαγραφή στο Μητρώο, με προειδοποίηση, πληκτρολόγηση του ονόματος και δικό της δικαίωμα (backlog).
- [ ] Έλεγχος μεταγραφής ελληνικών στοιχείων επαφής προς τους registrars.
- [ ] Τα 8 ερωτήματα του sweep των domains για τον υπεύθυνο (`domains.md`, «Sweep 2026-10-22»).

### Support (`tickets.md`)
- [ ] Το polling email (`FetchTicketMail`) ανοίγει ανά τμήμα μετά από δοκιμή με πραγματικό email (`tickets.md` §6γ).

### Ασφάλεια και ποιότητα
- [ ] phpstan baseline: 339 σφάλματα του upstream (270 εγγραφές)· μειώνεται όπου περνάμε.
- [ ] Το repo γίνεται private (απόφαση 2026-10-22· ακόμα public). Ως τότε: έγγραφα με σήμανση εμπιστευτικού δεν
      μπαίνουν στο repo, μόνο παράφραση.

### myip.gr (μετά τη nixpal)
- [ ] **Ekdosi bridge** και custom fields (ΑΦΜ, ΔΟΥ, «Θα ήθελα τιμολόγιο», «Γκρινιάρης»): **τελευταίο** (ο υπεύθυνος,
      2026-10-21)· απλούστερο από το addon + API του WHMCS. Φορολογικά παραστατικά στο portal από το Ekdosi·
      «Εισερχόμενα» χωρίς διπλά. (Στο WHMCS το staff ανοίγει τα «marks» του bridge ~20 φορές τη μέρα.)
- [ ] Ειδοποιήσεις Mattermost· εύκολη επεξεργασία των κανόνων από το admin.

### Εκκρεμότητες του υπεύθυνου (όχι κώδικας)
- [ ] cdnet.gr: auto renew off στο WHMCS (ανανεώνεται πια από το MyCS).
- [ ] Το .in της nixpal σε grace· 2 domains του myip με `serverHold`· ο διακόπτης «μεταφορά αντί για ανανέωση» του CNIC
      κλειστός ως ότου τον ανοίξεις· η nixpal θέλει δική της σύνδεση CNIC ή μεταφορά των 4 domains της πριν το cut-over.
- [ ] Το hook `invoicepaid.php` στο rigel (ξεχασμένο debug που γράφει το επώνυμο του πελάτη σε αρχείο): να σβηστεί μαζί
      με το αρχείο.
- [ ] Σβήσιμο των branches που έχουν γίνει merge (~63, από αυτά 15 `adv/*`).

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
περιττοί έλεγχοι και φόρτος, ο υπεύθυνος 2026-10-23).
