# MyCS: Roadmap

> Τι μένει να γίνει και με ποια σειρά. Ενημερώνεται σε κάθε PR: ό,τι κλείνει φεύγει από τα «Ανοιχτά» και μπαίνει μία
> γραμμή στο «Έγινε». Οι λεπτομέρειες κάθε θέματος είναι στο doc του (`domains.md`, `payments.md`, `sweeps/billing.md`
> κ.λπ.)· εδώ μόνο ο χάρτης. Η παλιά μορφή, με τις φάσεις 0–5 και όλο το ιστορικό ως το #232:
> `history/roadmap-2026-10-11-to-20.md`.

## Πού είμαστε (2026-10-20, main στο #232)
- Το MyCS τρέχει στο devbox (`https://mycs.myip.gr`)· **καμία εταιρεία δεν δουλεύει ακόμα σε αυτό**. Pilot είναι η
  nixpal, μετά το myip.gr (`../CLAUDE.md`).
- **Έτοιμα:** η χρέωση και οι ροές χρημάτων, οι λογαριασμοί, οι παραγγελίες, το provisioning, το licensing της nixpal,
  τα domains (Openprovider και Μητρώο .gr με δικό μας EPP), το portal του πελάτη, η μετάφραση el/en, τα παράλληλα tests.
  Σύνοψη στο «Έγινε», παρακάτω.
- **Τι λείπει για να μπει η nixpal:** αρίθμηση τιμολογίων, σημειώσεις λογαριασμού, PayPal vault, importer με parity,
  δοκιμή licensing και cPanel, η λειτουργία της παραγωγής (mail, backups, παρακολούθηση) και το runbook του cut-over.
  Αυτά είναι η σειρά παρακάτω.

## Επόμενα, με σειρά (εγκρίθηκε 2026-10-20)
Η σειρά οδηγεί στο cut-over της nixpal. Ο importer και το runbook περιμένουν τα tickets, που ξαναγράφονται σε άλλη
session για parity με το WHMCS (2026-10-20)· ως τότε, ό,τι δεν εξαρτάται από αυτά.

| # | Τι | Γιατί εδώ |
|---|---|---|
| 1 | **Αρίθμηση:** υπάρχει ήδη ως ρύθμιση ανά εταιρεία (`invoice_number_format` με `{year}-{number}`, `invoice_proforma`, padding). Μένουν τα κενά του Δ2: αριθμός μόνο όταν πληρωθεί (σήμερα και στο draft → unpaid), μετρητές σε `sequences` (μια αποθήκευση ρυθμίσεων δεν τους γυρίζει πίσω), «αριθμός = id» του myip | Contract του `CLAUDE.md`· χρήματα. Ο importer πρέπει να το βρει έτοιμο. |
| 2 | **Σημειώσεις λογαριασμού** (staff μόνο, με συντάκτη και sticky, όπως `tblclients.notes` και `tblnotes`). Υπηρεσίας και domain υπάρχουν· τιμολογίου και tickets δεν χρειάζονται (2026-10-20) | Σχήμα που χρειάζεται ο importer. |
| 3 | **PayPal vault** ως gateway (το package υπάρχει, από το Blesta). Nixpal (read-only, 2026-10-20): `paypal_ppcpv` 56 υπηρεσίες με **15** αποθηκευμένους λογαριασμούς, `paypal` (παλιές συνδρομές, IPN) 79, `stripe` 211 με 52 κάρτες. Έλεγχος αν τα vault tokens του «PayPal Payments» του WHMCS (partner) διαβάζονται από το δικό μας REST app (GET, χωρίς χρέωση)· αλλιώς οι 15 ξανασυνδέουν το PayPal μία φορά | Gateway της nixpal· οι παλιές συνδρομές συνεχίζουν μέσω IPN (H2b). |
| 4 | **Licensing και cPanel σε δοκιμή:** shadow-run του `zlic.php` απέναντι στο WHMCS, rate limit στο `zlic.php`· cPanel/WHM της nixpal από το devbox (create/suspend/terminate, δύο WHM servers) | Contracts και provisioning με πραγματικούς servers πριν τη μεταφορά. |
| 5 | **Λειτουργία της παραγωγής:** πού ζει το instance (`open-questions.md`), SMTP/SPF/DKIM, off-site backups με δοκιμή επαναφοράς, παρακολούθηση και endpoint υγείας, branding PDF της nixpal | Χωρίς αυτά δεν υπάρχει παραγωγή. |
| 6 | **Importer του νέου σχήματος + `mycs:parity`** (μετά τα tickets): λογαριασμοί, επαφές, υπηρεσίες με τον server τους, σημειώσεις, domains (D6, 11 της nixpal), αρίθμηση· συνθετικό σύνολο «σαν nixpal» για τα tests. **Αρίθμηση στο import (#238, tests incomplete στο `InvoiceNumberingAdversarialTest`):** myip με «αριθμός = id»: number = id σε κάθε εισαγόμενο τιμολόγιο· nixpal: ο μετρητής από το `SequentialInvoiceNumberValue` του WHMCS (ο τελευταίος αριθμός, 6818), όχι max(id) + 1· κανένας αριθμός να μη χάνεται σιωπηλά (διπλά `invoicenum`). **Σημειώσεις λογαριασμού:** `tblnotes` → `client_notes` (συντάκτης, sticky), `tblclients.notes` → μία σημείωση ανά λογαριασμό | Πατά στα 1–3 και στα νέα tickets. Re-runnable, με parity (`importer.md`). |
| 7 | **Runbook cut-over με επιστροφή** (πρότυπο: `go-live-runbook.md` του Ekdosi): πάγωμα, import και parity, DNS/webhooks, ενημέρωση πελατών, links των sites, έλεγχοι πρώτης μέρας· 2–3 δοκιμαστικές μεταφορές | Τελευταίο, πάνω σε σταθερό σχήμα και δεδομένα. |

Μικρά που χωράνε ανάμεσα (domains, portal): **P1b** (τα στοιχεία μιας επαφής που άλλαξε ο πελάτης στέλνονται στους
registrars), **P5** (child nameservers/glue από τον πελάτη· για το staff υπάρχει, #196).

## Ανοιχτά, ανά θέμα

### Χρέωση και τιμολόγια
- [ ] Ομάδες πελατών με έκπτωση· έλεγχος ότι οι recurring promotions δουλεύουν από άκρη σε άκρη.
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
- [ ] **WHM reseller accounts** στο cPanel extension (`setupreseller`, ACLs, όρια, suspend/terminate): σήμερα μόνο απλοί
      λογαριασμοί. Μόνο το myip τα έχει (read-only 2026-10-20): **22 ενεργοί** + 1 suspended, 6 πακέτα (Reseller 70: 9,
      Reseller 120: 6, Reseller 30: 3, …), στους Virgo 5, Earth 5, Mars 4, Orion 3, Titan 3, Rigel 2· η nixpal κανέναν.
      Πριν το cut-over του myip.
- [ ] Συμφιλίωση της κατάστασης μετά από job του server (το create υπάρχει, #114)· συγχρονισμός χρήσης cPanel.
- [ ] Modules του myip: Proxmox, Centova, mailcow (το package `mailcow` υπάρχει χωρίς extension).

### Domains (`domains.md`, `domains/grepp.md`)
- [ ] **P1b**, **P5** (παραπάνω)· μαζικές ενέργειες στη λίστα του portal· απόδοση με πολλά domains.
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
- [ ] Rate limit στο `zlic.php`· endpoint υγείας.
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
