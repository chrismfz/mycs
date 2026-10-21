# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα «Ανοιχτά» και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του (`domains.md`, `payments.md`, `sweeps/billing.md`
> κ.λπ.)· εδώ μόνο ο χάρτης. Η παλιά μορφή, με τις φάσεις 0–5 και όλο το ιστορικό ως το #232:
> `history/roadmap-2026-10-11-to-20.md`.

## Πού είμαστε (2026-10-20, main στο #253)
- Το MyCS τρέχει στο devbox (`https://mycs.myip.gr`)· **καμία εταιρεία δεν δουλεύει ακόμα σε αυτό**. Pilot είναι η
  nixpal, μετά το myip.gr (`../CLAUDE.md`).
- **Έτοιμα:** η χρέωση και οι ροές χρημάτων, οι λογαριασμοί, οι παραγγελίες, το provisioning, το licensing της nixpal,
  τα domains (Openprovider και Μητρώο .gr με δικό μας EPP), το portal του πελάτη, η μετάφραση el/en, τα παράλληλα tests.
  Σύνοψη στο «Έγινε», παρακάτω.
- **Τι λείπει για να μπει η nixpal:** importer με parity, η λειτουργία της παραγωγής (mail, backups, παρακολούθηση)
  και το runbook του cut-over. Αυτά είναι η σειρά παρακάτω· τα 1–4 έγιναν (2026-10-20).

## Επόμενα, με σειρά (εγκρίθηκε 2026-10-20)
Η σειρά οδηγεί στο cut-over της nixpal. Έλεγχος parity με τον κώδικα (2026-10-20): η χρέωση, οι πιστώσεις, οι
πληρωμές, οι φόροι, οι αυτοματισμοί, το licensing και το cPanel υπάρχουν με tests· λείπουν τα 5–7, και ο importer
έχει κενά (9). Ο importer μπαίνει τελευταίος, όταν ολοκληρωθούν τα υπόλοιπα (ο υπεύθυνος, 2026-10-20). Τα tickets
τελειώνουν σε άλλη session. Μετά: UI/UX polishing, ώστε να μη «ξενίζει» ούτε το staff ούτε τους πελάτες.

| # | Τι | Γιατί εδώ |
|---|---|---|
| 1 | ~~**Αρίθμηση**~~ **έγινε (#238, #241):** τρεις τρόποι ως ρύθμιση (μετρητής, proforma με αριθμό στην πληρωμή και έτος πληρωμής, «αριθμός = id» του myip)· ένα τιμολόγιο που δεν εκδόθηκε ποτέ ακυρώνεται χωρίς πιστωτικό. Η nixpal μένει proforma (2026-10-20). Για το import: στο 6 | — |
| 2 | ~~**Σημειώσεις λογαριασμού**~~ **έγινε (#243):** `client_notes` (staff μόνο, συντάκτης, sticky banner)· υπηρεσίας και domain υπήρχαν· τιμολογίου και tickets δεν χρειάζονται | — |
| 3 | ~~**PayPal vault**~~ **υπάρχει (έλεγχος 2026-10-20):** το gateway PayPal κάνει Vault v3 (αποθήκευση από τον πελάτη, χρέωση με `vault_id`, idempotent, PENDING ως processing), η αυτόματη χρέωση (`AutoCharge`) το καλύπτει, και ο έλεγχος ιδιοκτησίας (PAY-8) υπάρχει. Τα 15 vault tokens της nixpal διαβάζονται με τα REST credentials του `paypal_ppcpv` (13/15, 2026-10-08, `history/blesta-modules/importer.md`). **Μένουν:** ο importer των `tblpaymethods` (στο 6)· στο cut-over τα **ίδια** credentials στο gateway PayPal, και οι παλιές συνδρομές PayPal στο vault (Π7.9, δίχτυ IPN στο H2b) | — |
| 4 | ~~**Licensing και cPanel σε δοκιμή**~~ **έγινε:** το `zlic.php` δουλεύει live από το MyCS με client χωρίς αλλαγή (ο υπεύθυνος, 2026-10-20· shadow-run δεν χρειάζεται)· rate limit (#254)· cPanel στον orion: create/suspend/unsuspend/terminate με reseller της nixpal, και το πρόθεμα του πακέτου (#246) | — |
| 5 | ~~**Ομάδες πελατών με έκπτωση**~~ **έγινε (#272):** η έκπτωση μέσα στην τιμή, μετά το κουπόνι, ποτέ σε domains· κάθε υπηρεσία κρατά το ποσοστό της και ακολουθεί την ομάδα, εκτός από «Κλείδωμα τιμής» (`decisions.md`). Μαζί τα **promotions όπως του WHMCS** (#274): Price Override, νέοι/υπάρχοντες πελάτες, μία φορά ανά πελάτη, «απαιτεί προϊόν», κύκλοι χρέωσης | — |
| 6 | **Reports και έσοδα** (σταδιακά, «τα βασικά»): ~~πρόβλεψη εσόδων και widget Billing/Income Forecast~~ (#275, #278)· Έσοδα έτους για τον λογιστή με XLSX/CSV (#280)· σύνολα ανά έτος στο Summary του πελάτη (#281). Μετά, όταν χρειαστεί: σελίδα «Reports» με κατηγορίες όπως του WHMCS, Daily Performance, Annual Income, New Customers, Aging Invoices, Income by Product, Top 10 Clients | Σε εξέλιξη. |
| 6α | ~~**Προσφορές**~~ **έγινε (#285):** προσφορές από το admin με link (`/offer/<slug>`) που βάζει τα προϊόντα στο καλάθι και εφαρμόζει το κουπόνι· idempotent, ένα ανά παραγγελία όπου πρέπει | — |
| 6β | ~~**Τραπεζική μεταφορά**~~ **έγινε (#287):** offline gateway με τους λογαριασμούς στο παράθυρο πληρωμής, στο PDF και στο email του απλήρωτου τιμολογίου, με τον αριθμό στην αιτιολογία· νομίσματα, όχι σε τιμολόγια που χρεώνονται αυτόματα | — |
| 6γ | **Emails των domains** (έλεγχος emails 2026-10-21, ο υπεύθυνος): ο πελάτης παίρνει επιβεβαίωση ανανέωσης (WHMCS: ~550 τον χρόνο στο myip)· μεταφορά που πέτυχε ή απέτυχε, και γιατί, σε πελάτη και staff· ανανέωση που απέτυχε στο staff (σήμερα μόνο στα Pending Module Actions) | Σε εξέλιξη. |
| 6δ | **Μαζικό email / ανακοινώσεις με φίλτρα** (feat/bulk-mail) (WHMCS: ~520+ τον χρόνο, χορηγίες, αλλαγές δικτύου, μεταφορές server, προϊόντα που καταργούνται): από το admin, σε ενεργούς πελάτες, σε πελάτες ενεργών υπηρεσιών, ανά προϊόν, server ή TLD, με template· καλύπτει και την «αποστολή template από το staff» | Μετά το 6γ. |
| 7 | **Παλιές συνδρομές PayPal** (nixpal: 5 συνδρομές, 77 υπηρεσίες, ~21% των πληρωμών): σήμερα κάθε IPN επαληθεύεται, γράφεται και πάει στο staff (H2b), που την περνά με το χέρι· σχέδιο μετάβασης στο vault (ειδοποίηση των πελατών, νέα έγκριση) ή καταγραφή της πληρωμής από το IPN | Αλλιώς ένα πέμπτο των πληρωμών με το χέρι. |
| 8 | **Λειτουργία της παραγωγής:** στον orion ως `mycs.nixpal.com` όταν είμαστε σίγουροι (2026-10-20, `open-questions.md`): SMTP ίδιο με το WHMCS, backups με JetBackup στον rosso και δοκιμή επαναφοράς· ~~παρακολούθηση και endpoint υγείας~~ έγινε (#258 Automation Status, #259 `/health`)· logo και στοιχεία της εταιρείας στα Settings (το PDF τα παίρνει από εκεί, #264) | Χωρίς αυτά δεν υπάρχει παραγωγή. |
| 9 | **Importer του νέου σχήματος + `mycs:parity`** (στο τέλος, όταν ολοκληρωθεί το parity· ο υπεύθυνος, 2026-10-20): λογαριασμοί, επαφές, υπηρεσίες με τον server τους, σημειώσεις, domains (D6, 11 της nixpal), αρίθμηση· **και ό,τι βρήκε ο έλεγχος της 2026-10-20 ότι λείπει:** το «suspend until» (14 υπηρεσίες), οι επιλογές config options κάθε υπηρεσίας (`tblhostingconfigoptions`), ο server κάθε υπηρεσίας, οι κανόνες VAT (`tbltax`, 27), το ιστορικό πιστώσεων (`tblcredit`, όχι μόνο το υπόλοιπο), οι επαφές (`tblcontacts`), η γλώσσα κάθε πελάτη, οι ομάδες (`tblclientgroups`), οι ρυθμίσεις αυτοματισμών (`tblconfiguration`), το `subscriptionid` (7), οι κωδικοί υπηρεσιών· συνθετικό σύνολο «σαν nixpal» για τα tests. **Αποθηκευμένες μέθοδοι:** `tblpaymethods` → `billing_agreements` (nixpal: 15 PayPal vault `{vaultId}`, 52 κάρτες Stripe `{customer, method}`, χωρίς τις soft-deleted), και η προεπιλογή ανά πελάτη. **Αρίθμηση στο import (#238, tests incomplete στο `InvoiceNumberingAdversarialTest`):** myip με «αριθμός = id»: number = id σε κάθε εισαγόμενο τιμολόγιο· nixpal: ο μετρητής από το `SequentialInvoiceNumberValue` του WHMCS (ο τελευταίος αριθμός, 6818), όχι max(id) + 1· κανένας αριθμός να μη χάνεται σιωπηλά (διπλά `invoicenum`). **Σημειώσεις λογαριασμού:** `tblnotes` → `client_notes` (συντάκτης, sticky), `tblclients.notes` → μία σημείωση ανά λογαριασμό | Πατά στα 1–3 και στα νέα tickets. Re-runnable, με parity (`importer.md`). |
| 10 | **Runbook cut-over με επιστροφή** (πρότυπο: `go-live-runbook.md` του Ekdosi): πάγωμα, import και parity, DNS/webhooks, ενημέρωση πελατών, links των sites, έλεγχοι πρώτης μέρας· 2–3 δοκιμαστικές μεταφορές | Τελευταίο, πάνω σε σταθερό σχήμα και δεδομένα. |

Μικρά που χωράνε ανάμεσα (domains, portal): ~~P1b~~, ~~P5~~ έγιναν (#247, #253, #250).

## Ανοιχτά, ανά θέμα

### Έλεγχος parity 2026-10-21 (nixpal και myip, μόνο αναγνώσεις στο WHMCS)
**nixpal** (τα modules και τα gateways της υπάρχουν όλα):
- [ ] **4 domains στο CNIC** (3 ενεργά, 1 σε grace): μαζί με τη ροή του CNIC παρακάτω, ή μεταφορά με το χέρι πριν το cut-over.
- [x] **Το κλειδί της άδειας στο email καλωσορίσματος** (feat/template-license): τα templates διαβάζουν μόνο `product`, `plan`, `client` της
      υπηρεσίας· να επιτραπεί `{{ service.license.license_key }}`. Το κείμενο ανά προϊόν υπάρχει (`email_template` →
      `{{ product_info }}`): τα «ZoneCloud» templates της nixpal μεταφέρονται εκεί.
- [ ] Το custom field «comments» (16 υπηρεσίες) και «IP Address» (1): στην εισαγωγή, στο label ή στις σημειώσεις.
- [ ] Αργότερα: valid IPs και comments στη λίστα υπηρεσιών του portal· ρύθμιση για Google Analytics.

**CNIC** (myip: **196 ενεργά, 132 λήγουν σε 6 μήνες**· nixpal: 4). Ο υπεύθυνος (2026-10-21): ένα απλό CNIC module
(nameservers, ημερομηνίες) και η ροή του hook του WHMCS: στην ανανέωση unlock, EPP code και μεταφορά στο Openprovider
με τους ίδιους nameservers (χωρίς τα σφάλματα σειράς του παλιού hook), και λίστα για το staff όσων μένουν χειροκίνητα.
Αργότερα, με την ησυχία μας. **Αναθεώρηση (ο υπεύθυνος, 2026-10-21):** domains λήγουν έως το 2034 (2027: 97, 2028: 16, 2029+:
12), άρα κανονικός registrar, σε βήματα (`domains.md` «CentralNic Reseller»): (1) αναγνώσεις (feat/cnic-registrar), (2) διαχείριση
(nameservers, lock, EPP, επαφές), (3) ανανέωση, (4) μεταφορά στο Openprovider στην ανανέωση.
**Δοκιμή live (2026-10-21, μόνο αναγνώσεις):**
- Και τα 202 domains του λογαριασμού διαβάστηκαν. Από τα 198 κοινά με το WHMCS του myip, 171 έχουν ίδια λήξη και 27 μία μέρα νωρίτερα: τα 24 .eu, γιατί το CNIC τα σβήνει την προηγούμενη μέρα (`RENEWAL DATE`), και 3 λόγω στρογγυλοποίησης στο WHMCS.
- **Ο λογαριασμός είναι κοινός** (ο υπεύθυνος, 2026-10-21): 198 domains του myip και 4 της nixpal. Ο importer κάθε instance παίρνει τα domains από τη βάση του **δικού του WHMCS**, όχι από τους registrars (CNIC, Openprovider, grepp). Τα συνδέει με τη σύνδεση του registrar τους και δεν εισάγει ποτέ «ό,τι υπάρχει στον λογαριασμό».
- Η nixpal θα χρειαστεί τη δική της σύνδεση CNIC στο δικό της MyCS, ή μεταφορά των 4 domains της πριν το cut-over.
- Για τον υπεύθυνο: ένα .in της nixpal είναι σε grace, και 2 domains του myip έχουν `serverHold` από το μητρώο.

**myip:**
- [ ] cPanel: λογαριασμοί reseller (22), αλλαγή κωδικού (staff και πελάτης, 39 τον χρόνο), αργότερα συγχρονισμός χρήσης.
- [ ] mailcow (10 υπηρεσίες): server extension πάνω στο `packages/mailcow`.
- [ ] Proxmox (8 VPS): extension ή χειροκίνητο προϊόν (χωρίς start/stop και console για τον πελάτη): **απόφαση υπευθύνου**.
- [ ] Product addons (Dedicated IP ×9, System Maintenance, 5 με δικό τους όνομα): ~~επιλογές της υπηρεσίας~~ έγινε (#296· extra χώρος από το cPanel module, IPs από το staff)· **μένει** η αντιστοίχιση στον importer (περιγράφεται στο `importer.md`).
- [ ] Μικροί κανόνες από τα hooks: email στο staff σε υπερπληρωμή· email σε κάθε suspend με τον λόγο· αυτόματη ακύρωση
      απλήρωτων «Add Funds» μετά από 30 μέρες· έλεγχος ότι το
      Openprovider κρατά τους nameservers του πελάτη μετά από transfer· έλεγχος μεταγραφής ελληνικών στοιχείων επαφής.
- [ ] Αργότερα ή χειροκίνητα ως τότε: TheSSLStore (12), Centova (9, τα πεδία του στις σημειώσεις), ngm (1), Hetzner (1),
      quotes (3 τον χρόνο), downloads (4), unblockip,
      PaymentsTrackingMatch (αντιστοίχιση των emails της τράπεζας).
- [ ] **Ekdosi bridge** και custom fields (ΑΦΜ, ΔΟΥ, «Θα ήθελα τιμολόγιο», «Γκρινιάρης»): **τελευταίο** (ο υπεύθυνος,
      2026-10-21)· ένας πιο απλός και αποδοτικός τρόπος από το addon + API του WHMCS.
- **Emails (έλεγχος 2026-10-21, `tblemails` 12 μηνών ~14.500, templates, hooks, mailboxes):** στον πελάτη το MyCS
  καλύπτει τιμολόγια, υπενθυμίσεις και καθυστερήσεις, κάρτες, πληρωμές, tickets, παραγγελίες, welcome, αναστολές,
  ακυρώσεις, υπενθυμίσεις λήξης, κατοχύρωση και μεταφορά domain· **λείπουν** η επιβεβαίωση ανανέωσης domain, η
  αποτυχία μεταφοράς (6γ) και το μαζικό email (6δ). Στο staff: παραγγελία, ακύρωση, αποτυχίες modules, chargebacks και
  το ημερήσιο digest αναστολών υπάρχουν· **λείπει** email για ανανέωση ή μεταφορά domain που απέτυχε (6γ). Δεν
  μεταφέρονται: τα «επιτυχής setup/ανανέωση» προς το staff (θόρυβος), το ημερήσιο email για .eu (το καλύπτει το widget·
  ήταν για το παλιό restore fee των 50 €), τα templates των προϊόντων που δεν πουλάμε πια (SSL Store, SiteLock, Weebly,
  NordVPN, Marketgoo κ.ά.), quotes, affiliates, DNS Manager. Ίσως αργότερα: αίτημα αξιολόγησης ticket, email καλωσορίσματος
  στην εγγραφή. **Για τον υπεύθυνο:** το hook `invoicepaid.php` στο rigel είναι ξεχασμένο debug που γράφει το επώνυμο
  του πελάτη σε αρχείο σε κάθε πληρωμή· να σβηστεί μαζί με το αρχείο.
- Διορθώσεις της απογραφής: τα config options του myip χρησιμοποιούνται ελάχιστα (~10 υπηρεσίες, όχι 282)· αρκετά
  addons του WHMCS είναι ενεργά χωρίς αρχεία (gr, gr_management, dnschecker κ.ά.): δεν κάνουν τίποτα.

### Χρέωση και τιμολόγια
- [ ] Έλεγχος ότι οι recurring promotions δουλεύουν από άκρη σε άκρη (ανανέωση με κουπόνι, χωρίς ειδικό test σήμερα).
- [ ] Γλώσσα ανά πελάτη (στήλη, import από `tblclients.language`, templates ανά γλώσσα): σήμερα τα PDF και τα emails
      βγαίνουν στη γλώσσα της εταιρείας (#264). Η nixpal: 6 πελάτες σε άλλη γλώσσα από αγγλικά ή την προεπιλογή.
- [ ] Το «Total» του PDF είναι μετά την πίστωση (όπως το WHMCS), του portal και του email το πλήρες (#264)· ενιαία
      διατύπωση. Η περιγραφή του αυτόματου πιστωτικού της ακύρωσης είναι αποθηκευμένη στα αγγλικά.
- [x] Η παραγωγή έχει ήδη `APP_TIMEZONE=Europe/Athens` (έλεγχος 2026-10-21): τα όρια μέρας και μήνα είναι σωστά.
- [x] Ακυρωμένο τιμολόγιο **δεν** ακυρώνει την υπηρεσία (ο υπεύθυνος, 2026-10-20: «όταν κάνουμε λάθη βοηθάει»).
      Έλεγχος: στη nixpal κανένα τέτοιο hook· στο myip το `CancelProductOnCancelInvoice.php` είναι ολόκληρο σε σχόλιο.
- [ ] Τιμολόγιο: Paid/Balance στο PDF (INV-8), INV-10, INV-17 (`sweeps/billing.md`)· «Group Similar Line Items».
- [ ] Ένα πακέτο που γίνεται free ή one-time (`billing-cursor.md`, §5)· ημέρες πριν τη λήξη ανά κύκλο.
- [ ] Ανανεώσεις υπηρεσιών on demand από τον πελάτη (ρύθμιση του WHMCS, `settings-map.md`).
- [ ] Οι 30 ημέρες του «paid not applied» (`RenewalBilling::PAID_NOT_APPLIED_DAYS`) γίνονται ρύθμιση.
- [ ] Πληρωμές: μοναδικό κλειδί στο `transaction_id`· ανοχή χρόνου στην υπογραφή του Stripe (PAY-14)· helper για
      minor units στο Stripe (PAY-16)· fallback του Stripe (#1260 του upstream)· SEPA του Stripe (υπάρχει κώδικας, όχι
      δοκιμή).
- [ ] Tests σημειωμένα incomplete: `BillingCursorMoneyTest` γραμμές 173 και 224 (μάλλον διορθώθηκαν με #74/#113·
      έλεγχος και αφαίρεση του marker), 285 «τιμολόγιο που δημιουργείται πληρωμένο ανανεώνει» (ανοιχτό).
- [ ] Τράπεζες και υπόλοιπα (2026-10-17, «πολύ αργότερα»): υπόλοιπα PayPal/Stripe στο dashboard, port του addon των
      emails της τράπεζας, «Payments tracking» με δικό του ρόλο, σύνολα και υπόλοιπα τραπεζών μόνο για `*`, μέθοδος
      πληρωμής «τραπεζική κατάθεση».

### Λογαριασμοί και πελάτες
- [ ] «Μεταφορά υπηρεσίας σε άλλο πελάτη»· custom fields πελάτη (οι επαφές υπάρχουν, #103)· GDPR (εξαγωγή, διαγραφή).
- [ ] 2FA πελάτη: recovery codes (του staff υπάρχει, #100).

### Admin
- [ ] Φόρμα υπηρεσίας και λίστα προϊόντων «σαν WHMCS» (στήλες Type/Pay Type/Stock/Auto Setup, Duplicate)· Dashboard
      και Pending Orders, ό,τι λείπει (`admin-ux.md`).
- [ ] Reports· εξαγωγή εσόδων/εξόδων· `mycs:login-link`.
- [ ] Έλεγχος σε πραγματικό browser (`audit-browser.md`, δεν έχει γραφτεί).

### Provisioning
- [ ] **cPanel «σαν WHMCS/Blesta»** (ο υπεύθυνος, 2026-10-20: σήμερα το extension είναι πολύ «dummy»· ιδέες από το Blesta
      και το cPanel/WHM API): αντιστοίχιση πακέτων WHM ↔ προϊόντων MyCS από λίστα, χρήση (quota, bandwidth) πίσω στο
      MyCS, addon/parked/sub domains, email accounts (λίστα, δημιουργία), reseller accounts (παρακάτω). Δοκιμή στον
      orion 2026-10-20 (reseller της nixpal): create/suspend/unsuspend/terminate δουλεύουν (`provisioning.md`).
- [ ] **WHM reseller accounts** στο cPanel extension (`setupreseller`, ACLs, όρια, suspend/terminate): σήμερα μόνο απλοί
      λογαριασμοί. Μόνο το myip τα έχει (read-only 2026-10-20): **22 ενεργοί** + 1 suspended, 6 πακέτα (Reseller 70: 9,
      Reseller 120: 6, Reseller 30: 3, …), στους Virgo 5, Earth 5, Mars 4, Orion 3, Titan 3, Rigel 2· η nixpal κανέναν.
      Πριν το cut-over του myip.
- [ ] Συμφιλίωση της κατάστασης μετά από job του server (το create υπάρχει, #114)· συγχρονισμός χρήσης cPanel.
- [ ] Modules του myip: Proxmox, Centova, mailcow (το package `mailcow` υπάρχει χωρίς extension).

### Licensing (`licensing.md`)
- [ ] **Ποιοι χτυπάνε χωρίς να πρέπει** (ο υπεύθυνος, 2026-10-20): οθόνη του staff με
      - άδειες terminated/suspended/expired που ακόμα ελέγχουν (πόσοι έλεγχοι, από πού, πότε τελευταία)· στις 90 ημέρες
        της nixpal στο WHMCS ήταν 535 χιλ. «Expired» και 44 χιλ. «Suspended»·
      - IPs που χτυπάνε χωρίς να είχαν ποτέ άδεια (άγνωστα κλειδιά, IP mismatch)·
      - ενέργειες: block IP (στο edge/angie ή στο zlic.php), ειδοποίηση του πελάτη ότι έχει ανοιχτά binaries χωρίς άδεια
        (template email). Τα δεδομένα υπάρχουν ήδη σε μεγάλο μέρος (`license_check_days`, `last_failure`, access log,
        `zlic_limits` του #254)· λείπουν η άθροιση ανά IP χωρίς άδεια και η οθόνη.

### Domains (`domains.md`, `domains/grepp.md`)
- [ ] Domain που παραγγέλνεται μαζί με VPS ή hosting: να πέφτει στους προεπιλεγμένους nameservers όταν ο server του
      δεν έχει δικούς του (το WHMCS του myip σταματά με «No nameservers are defined…», 2026-10-20, Pegasus).
- [ ] Μαζικές ενέργειες στη λίστα του portal· απόδοση με πολλά domains.
- [ ] Μικρά του P1b (#248): πεδίο διεύθυνσης που αδειάζει ίσως μένει παλιό στο Openprovider· η `int` διεύθυνση του .gr
      δεν ενημερώνεται.
- [ ] Απόφαση: ανανέωση από το portal ως `invoices` ή ως `orders` (`domains.md`, D7-2 «Ανοιχτό»)· ανανέωση στην
      έκδοση ανά πελάτη· additional fields ανά TLD.
- [ ] Ο κατάλογος .gr στο MyCS: τιμές και ΦΠΑ πριν πουληθεί από εδώ· ο importer φέρνει το `recurring_amount` των .gr
      δύο χαρακτήρων (τα τιμολογεί μόνο το staff).
- [ ] Αλλαγή κατόχου .gr: **G8-5b** (ροή του staff με χρέωση, υπογεγραμμένη δήλωση, επιβεβαιώσεις), **G8-5c** (από
      τον πελάτη), **G8-5d** (trade στο Openprovider). Το G8-5a (#215) υπάρχει.
- [ ] **G8-6** διαγραφή στο Μητρώο, με ρητή προειδοποίηση, πληκτρολόγηση του ονόματος και δικό της δικαίωμα (backlog).
- [ ] **G8-2** καθάρισμα των `533_`/`c60_` στο cut-over του myip.
- [ ] Driver CNIC (αν χρειαστεί).

### Support (`tickets.md`)
- [ ] Port των tickets (σχέδιο και μοντέλο ασφάλειας στο `tickets.md`, #224 και #234, **αποφάσεις owner 2026-10-20**):
      PRs Α1 (τμήματα ως πίνακας, 5 καταστάσεις, tickets επισκέπτη, αριθμός με μάσκα), Α2 (εισερχόμενα από το Ekdosi,
      σκληρυμένα, καραντίνα), Α3 (εξερχόμενα με τυχαίο Message-ID, ειδοποιήσεις staff), Β (φόρμα επικοινωνίας με
      ALTCHA), Γ (σελίδα επισκέπτη με υπογεγραμμένο link), Δ (νέος importer tickets: επισκέπτες ως επισκέπτες, `tid`,
      σημειώσεις, συνημμένα). Το FetchEmails μένει κλειστό ώσπου να μπουν τα Α: είναι ευάλωτο. Knowledgebase όχι
      (είναι στα sites των εταιρειών).

### Ασφάλεια και ποιότητα
- [x] Endpoint υγείας (#259) και Automation Status (#258).
- [ ] phpstan baseline: 339 σφάλματα του upstream (σε 270 εγγραφές)· μειώνεται όπου περνάμε.
- [ ] Το repo γίνεται private «όταν ηρεμήσουμε» (ο υπεύθυνος). Ως τότε: έγγραφα με σήμανση εμπιστευτικού δεν
      μπαίνουν στο repo, μόνο παράφραση.

### myip.gr (μετά τη nixpal)
- [ ] Bridge προς Ekdosi (`history/blesta-modules/HANDOFF.md` §2.2)· φορολογικά παραστατικά στο portal από το Ekdosi·
      «Εισερχόμενα» στο Ekdosi χωρίς διπλά.
- [ ] Οι κανόνες των hooks του myip· ειδοποιήσεις Mattermost· εύκολη επεξεργασία από το admin.
- [ ] Πλήρης importer: tickets με συνημμένα, addons, custom fields, αρίθμηση = id και συνέχεια από τον τελευταίο του
      WHMCS (> 32.476 στις 2026-10-11· ξαναδιαβάζεται τη μέρα του cut-over), τα 1.383 domains.
- [ ] Freeze, import, DNS/rewrites, forwarder IPN για το PayPal, dump του WHMCS για αρχείο· οι ρυθμίσεις του myip από
      το `settings-map.md` περνούν πριν το cut-over.

### Εκκρεμότητες του υπεύθυνου (όχι κώδικας)
- [ ] cdnet.gr: auto renew off στο WHMCS (ανανεώνεται πια από το MyCS· δοκιμή DNSSEC 2026-10-18).
- [ ] Σβήσιμο των branches που έχουν γίνει merge (`adv/*`, `fix/upgrade-test-date`, `spike/domains`).

## Έγινε (σύνοψη· λεπτομέρειες στα PRs και στα docs)
- **Πριν το cut-over της nixpal (2026-10-20):** αρίθμηση σε τρεις τρόπους (#238), χωρίς πιστωτικό για τιμολόγιο που δεν
  εκδόθηκε (#241), σημειώσεις λογαριασμού (#243), πακέτο reseller στο cPanel (#246), rate limit στο `zlic.php` (#254)·
  επαφές: όσο ένα domain τις χρησιμοποιεί, το όνομα μένει και η διεύθυνση, το τηλέφωνο και το email πάνε στους
  registrars (P1b, #247, #253)· glue .gr από τον πελάτη (P5, #250).
- **Καθάρισμα και βάσεις:** χωρίς telemetry/updater/marketplace, CI με Pint και phpstan, εγκατάσταση και deploy στο
  devbox (Φάση 0)· audit sweep του κληρονομημένου κώδικα και σταθεροποίηση (Φάση 1a, `audit-sweep.md`).
- **Χρήματα:** ενιαίο σημείο τιμολόγησης σε decimal (Σ7, #107, #109)· αλλαγή πακέτου/κύκλου με αναλογική χρέωση
  (SVC-14, #113, #115)· sweep χρέωσης A–H (#122–#150): ακύρωση = η περίοδος δεν χρεώνεται, ομαδοποίηση, pro rata,
  merge/split, Mass Pay, EU VAT/VIES και reverse charge (#145), αυτόματη χρέωση με retries, Cardlink (#149),
  ειδοποίηση λήξης κάρτας (#150).
- **Λογαριασμοί:** staff χωριστά από τους πελάτες, πολλοί users ανά λογαριασμό με δικαιώματα, επαφές, «Σχετική επαφή»
  υπηρεσίας (#100–#103, #116)· Client Summary (#154).
- **Παραγγελίες:** σημειώσεις παραγγελίας· κάθε παραγγελία pending ως την έγκριση του staff, όπως το WHMCS (#227)·
  email στο staff για νέα παραγγελία (#225)· ημερήσια αναφορά αναστολών/τερματισμών (#221)· απευθείας links και μενού
  επισκέπτη στο κατάστημα (#216–#218)· ALTCHA captcha (#222).
- **Provisioning και licensing:** Σ1-a/b (#114, #117, #119), servers ανά υπηρεσία (#112)· licensing L1–L3 (#106,
  #128, #131). Τα custom fields προϊόντος (Σ1-c) δεν γίνονται (`provisioning.md`, 2026-10-15).
- **Domains:** μοντέλο, συγχρονισμός, μεταφορές, ανανεώσεις, grace/redemption (#161–#169, #204)· Openprovider στο
  `/v1`· αναζήτηση/whois/τιμοκατάλογος με JSON API (#176, #178)· D8 (#178–#180)· **.gr EPP (grepp)** G0–G7 (#183–#205)
  και G8-1/3/4/5a (#206–#215): υπόλοιπο λογαριασμού στο dashboard, επαφές, DNSSEC, glue για το staff (#196), IDNA
  (#199), αλλαγή κατόχου από το staff.
- **Portal πελάτη (D7-2, #176):** P1 επαφές (#219), P2 μητρώο (#220), P3 DNSSEC (#223), P4a μενού και διαδρομή
  (#226), P4b «Χρειάζεται την προσοχή σας», λίστες, auto renew στη γραμμή (#230), P4d «Επόμενες ανανεώσεις» (#231) και
  αναζήτηση Ctrl+K (#232).
- **Admin UX:** #153, #154, #212, #213.
- **Mail και templates:** templates el/en (#98), διορθώσεις (#152, #158)· templates του staff δεν γίνονται compile ως
  Blade (`history/audit-security.md`).
- **Ποιότητα:** όλα τα κείμενα σε el και en με test-φύλακα (#228)· `bin/test`, παράλληλα tests τοπικά και στο CI,
  ~2½ λεπτά αντί για ~45 (#229).

## Δεν γίνονται (απογραφή: δεν χρησιμοποιούνται)
Late fees, billable items, affiliates, escalation rules, network issues, fraud modules, φόρος δεύτερου επιπέδου,
premium domains, δωρεάν domain με hosting. Επίσης: custom fields προϊόντος (Σ1-c, `provisioning.md`), Δ4 `completed`,
G2 (ανοιχτά τιμολόγια όταν αλλάζει το καθεστώς ΦΠΑ).
