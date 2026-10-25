# Γέφυρα MyCS ↔ Ekdosi

> Απόφαση του υπευθύνου (2026-10-25): η γέφυρα σπάει σε κομμάτια, είναι **ρύθμιση** (κλειστή by default) και έχει
> **λιγότερες ρυθμίσεις** από τη γέφυρα του WHMCS. Στο Ekdosi μπαίνει «MyCS Bridge» δίπλα στο «WHMCS Bridge» στα
> στοιχεία της εταιρείας. Τα «Εισερχόμενα» του Ekdosi μένουν ένα, όποια κι αν είναι η πηγή (απόφαση του Ekdosi).
>
> Τα όρια: η MyCS είναι το control plane και βγάζει **προτιμολόγια**. Το φορολογικό παραστατικό και το myDATA
> ανήκουν στο Ekdosi (`../CLAUDE.md`).

## Γιατί είναι απλούστερη από του WHMCS

Στο WHMCS τα στοιχεία τιμολόγησης ζούσαν σε custom fields (με χάρτη ανά εταιρεία στο Ekdosi) και στους πίνακες του
plugin (`mod_ekdosi_contacts`, `mod_ekdosi_routing`). Στη MyCS είναι δεδομένα:

| Τι | WHMCS | MyCS |
|---|---|---|
| ΑΦΜ, VIES, ΔΟΥ, δραστηριότητα | custom fields 13, 14, 15 (χάρτης στο Ekdosi) | `contacts.tax_id`, `vat_number`, `tax_office`, `activity` |
| Τιμολόγιο ή απόδειξη | custom field `wantsinvoice` (12) | `contacts.document_type` (invoice, receipt, none) |
| Γκρινιάρης (άμεση τιμολόγηση) | custom field 338 | `clients.immediate_invoicing` |
| Χωρίς τιμολόγηση | — | `clients.ignore_invoicing`: δεν μπαίνει ποτέ στο feed |
| Παραστατικά σε τρίτους | `mod_ekdosi_contacts` + `mod_ekdosi_routing` | επαφή του λογαριασμού δεμένη στην υπηρεσία (`services.contact_id`) ή στο domain (`domains.contact_id`) |
| ΦΠΑ στα ποσά | ρύθμιση «WHMCS line amounts include VAT» | κάθε γραμμή δίνει καθαρό, ΦΠΑ και σύνολο |

Το payload έχει **το ίδιο σχήμα** με το `InvoiceFeed` του plugin. Ο ingestor του Ekdosi το παίρνει όπως είναι, και
διαβάζει επιπλέον το μπλοκ `mycs` αντί για τα custom fields.

## Τα κομμάτια

| # | Πού | Τι | Κατάσταση |
|---|---|---|---|
| **E1** | MyCS | Ρύθμιση «Ekdosi» (on/off, διεύθυνση, κοινό secret)· `POST /api/ekdosi` με HMAC και ops `ping`, `invoices`, `invoice`, `resolve`, `mark`· πίνακας `ekdosi_documents` (ΜΑΡΚ, κωδικός, κατάσταση, PDF)· `domains.contact_id` («Σχετική επαφή» στη σελίδα του domain) | **αυτό το PR** |
| E2 | MyCS | Τα παραστατικά στη σελίδα του τιμολογίου (staff) και στο client area («Εκδοθέντα παραστατικά», με το PDF του Ekdosi)· στήλη/φίλτρο «Παραστατικό» στη λίστα τιμολογίων. Αντικαθιστά τα badges και το `issued-for-client` του plugin. Και: η διαγραφή τιμολογίου με παραστατικό απαντά με μήνυμα (σήμερα το FK τη σταματά με raw λάθος)· προειδοποίηση όταν το staff ακυρώνει ή κάνει refund τιμολόγιο με παραστατικό (θέλει πιστωτικό στο Ekdosi) | |
| E3 | MyCS | `add_payment`: το Ekdosi γράφει πληρωμή για τιμολόγιο επί πιστώσει (`RecordPaymentService`, ίδιο `transaction_id` = ίδια πληρωμή, πληρωμένο = τίποτα). Θέλει adversarial review (χρήματα) | |
| E4 | importer | `mod_ekdosi_contacts` → `contacts`· `mod_ekdosi_routing` → `services.contact_id` / `domains.contact_id`· `mod_ekdosi_invoice_marks` → `ekdosi_documents` (βλ. «Importer») | με τον κοινό importer |
| K1 | Ekdosi | `MycsBillingSource` (Phase 1 του `docs/bridges-connectors.md` του Ekdosi)· καρτέλα «MyCS Bridge» με διεύθυνση MyCS + secret· feed στα ίδια «Εισερχόμενα» με σήμα «MyCS» | |
| K2 | Ekdosi | Write-back ΜΑΡΚ/κωδικού/PDF στη MyCS (`op=mark`)· sync πληρωμών· auto-issue για τους γκρινιάρηδες (`mycs.immediate_invoicing`) | |

Σειρά: E1 → K1 → K2 → E2 → E3. Το E4 μπαίνει στον importer.

## Ρυθμίσεις

**MyCS** (Settings → Ekdosi, μόνο full admins):
- `ekdosi_enabled`: κλειστό. Κλειστό σημαίνει ότι το endpoint απαντά 404 σε όλα.
- `ekdosi_url`: η διεύθυνση του Ekdosi. Τα links PDF κρατιούνται μόνο όταν είναι σε αυτόν τον host, με https.
- `ekdosi_secret`: κοινό και προς τις δύο κατευθύνσεις, ≥ 32 χαρακτήρες (και το endpoint απαντά 422 σε μικρότερο),
  κρυπτογραφημένο.

**Ekdosi («MyCS Bridge»):** διεύθυνση της MyCS, το ίδιο secret, ημερομηνία cut-over (`since`), οι προεπιλεγμένοι τύποι
παραστατικών, ο διακόπτης auto-issue για τους γκρινιάρηδες και ο διακόπτης για την αποστολή πληρωμών. **Φεύγουν**, σε
σχέση με το «WHMCS Bridge»:
- το identifier/secret του API του WHMCS και το ξεχωριστό inbound secret (ένα κοινό secret και προς τις δύο κατευθύνσεις);
- το «ποσά με ΦΠΑ» (κάθε γραμμή δίνει ρητά καθαρό, ΦΠΑ και σύνολο);
- ο χάρτης custom fields;
- ο διακόπτης «Παραστατικά σε τρίτους» (το routing βγαίνει πάντα από τις επαφές);
- η διαδρομή του plugin.

## Το συμβόλαιο (E1)

`POST https://<mycs>/api/ekdosi`, body JSON, `X-Webhook-Signature: sha256=<hex HMAC-SHA256 του raw body με το secret>`
(ίδιο σχήμα με το plugin). Όριο 64 KB ανά body και 120 κλήσεις/λεπτό ανά IP.

| Κωδικός | Πότε |
|---|---|
| 404 `not_found` | η ρύθμιση είναι κλειστή |
| 422 `secret_not_configured` | ανοιχτή, αλλά χωρίς secret |
| 401 `invalid_signature` | η υπογραφή λείπει ή δεν ταιριάζει |
| 400 `empty_body`, `bad_request`, `unknown_op` | λάθος αίτημα |
| 413 `body_too_large` | body πάνω από 64 KB |
| 500 `query_failed` | εσωτερικό λάθος (η λεπτομέρεια μόνο στο log) |

- **`ping`** → `{status: ok, source: mycs, ops: [...]}`. Το Ekdosi ελέγχει έτσι πριν γυρίσει μια εταιρεία στη MyCS.
- **`invoices`** `{status?, since?, offset?, limit?}`:
  - `status` = `paid_unfiled` (default, τα «Εισερχόμενα»): πληρωμένα που δεν έχουν ακόμα παραστατικό από το Ekdosi,
    με `since` την ημερομηνία πληρωμής (χωρίς ημερομηνία πληρωμής μετρά η έκδοση);
  - ή `Paid`, `Unpaid`, `Cancelled`, `Refunded`, `Draft`, `All`, με `since` την ημερομηνία έκδοσης;
  - newest first, `limit` ≤ 200;
  - οι πελάτες με «ignore invoicing» δεν εμφανίζονται ποτέ.
- **`invoice`** `{invoice_id}`: ένα τιμολόγιο, σε οποιαδήποτε κατάσταση και με τη σημαία `ignore_invoicing`· αποφασίζει
  το Ekdosi.
- **`resolve`** `{invoice_id}`: μόνο οι αποδέκτες των γραμμών, όπως το `op=resolve` του plugin.
- **`mark`** `{invoice_id, mark, fingerprint?, code?|invcode?, state?, pdf_url?}`: το write-back.
  - Ένα **νέο** παραστατικό γίνεται δεκτό μόνο για το τιμολόγιο όπως το διάβασε το Ekdosi. Το K1 στέλνει πίσω το
    `mycs.fingerprint` που διάβασε (λογαριασμός, νόμισμα, γραμμές με είδος και σύνολο, ενεργά notes· όχι η πληρωμή), και αν το τιμολόγιο άλλαξε απαντά 409
    `invoice_changed`. Χωρίς fingerprint, πιάνονται μόνο το merge και το split.
  - Με 409 `invoice_changed` το Ekdosi έχει ήδη εκδώσει για κάτι που δεν υπάρχει πια: το ακυρώνει και ξαναδιαβάζει.
    Οι γραμμές που μετακινήθηκαν δεν έχουν παραστατικό, οπότε ξαναεμφανίζονται κανονικά.
  - Η ενημέρωση ενός ΜΑΡΚ που υπάρχει ήδη (π.χ. ακύρωση) γίνεται πάντα δεκτή.
  - Το write-back κλειδώνει το τιμολόγιο όπως οι πληρωμές, το merge και το split: ποτέ μαζί με ένα από αυτά.
  - Ο ίδιος ΜΑΡΚ ξανά ενημερώνει (κωδικό, PDF, `cancelled`)· ό,τι δεν στέλνεται μένει ως ήταν.
  - Ένα τιμολόγιο μπορεί να έχει πολλά παραστατικά (split σε αποδέκτες).
  - ΜΑΡΚ που υπάρχει ήδη σε άλλο τιμολόγιο → 409 `mark_on_another_invoice`· τιμολόγιο που δεν υπάρχει → 404.
  - Τιμολόγιο με **ενεργό** παραστατικό βγαίνει από το `paid_unfiled`. Αν το μόνο του παραστατικό ακυρωθεί στην ΑΑΔΕ
    (π.χ. για επανέκδοση σε σωστό ΑΦΜ), ξαναμπαίνει.
  - Το `cancelled` είναι τελικό: ένα καθυστερημένο ή επαναλαμβανόμενο `active` δεν το γυρίζει πίσω (το body δεν έχει
    timestamp, όπως και του plugin).
  - Ο ΜΑΡΚ είναι αριθμός (string ή int): τα μηδενικά μπροστά φεύγουν (`0400…` = `400…`), το `0` δεν γίνεται δεκτό.
  - Το link PDF ξαναχτίζεται από τα μέρη του: μόνο https, μόνο ο host και το path του `ekdosi_url`, χωρίς userinfo, `\`
    ή κενά (όπου PHP και browser διαβάζουν άλλον host), χωρίς τμήματα `.`/`..` (και ως `%2e`), με το port του
    `ekdosi_url`.
- Ένα τιμολόγιο με ενεργό παραστατικό **δεν γίνεται merge, split ή μεταφορά σε άλλον λογαριασμό**, ούτε draft
  (`InvoiceLineMove::refusal`, `MoveToClient`), και **οι γραμμές, ο πελάτης, το νόμισμα και τα credit/debit notes του
  δεν αλλάζουν**: ούτε από το staff ή το API (`InvoiceItemObserver`, `StaffLineEdit`, `InvoiceObserver`,
  `AdjustmentNoteObserver`), ούτε από την εφαρμογή. Το sync τιμών (`ChangeOpenLine`) το αφήνει με γραμμή στο log, το fee
  grace/redemption δεν προστίθεται (ειδοποίηση στο staff), και η αυτόματη ακύρωση στο τέλος μιας υπηρεσίας
  (`CancelOpenInvoices`) το αφήνει στο staff. Αλλιώς οι γραμμές του θα ξαναέμπαιναν στα «Εισερχόμενα» (δεύτερο παραστατικό για την
  ίδια πώληση), ή ένα παραστατικό στο ΑΦΜ ενός πελάτη θα πήγαινε σε άλλον. Ακυρώνεται πρώτα στο Ekdosi.

**Payload τιμολογίου:** τα κλειδιά του `InvoiceFeed`:
- τιμολόγιο: `invoiceid`, `userid`, `date`, `datepaid`, `subtotal`, `tax`, `total`, `credit`, `status`, `paymentmethod`,
  `currencycode`;
- στοιχεία πελάτη: από την κύρια επαφή;
- `customfields`: πάντα `[]`;
- `items.item[]` με `type`, `relid`, `amount` (καθαρό), `whmcs_product_id`/`whmcs_group_id` (προϊόν/κατηγορία);
- `transactions`.

Μαζί με αυτά, ό,τι το WHMCS δεν έδινε:
- `taxtype` = `Exclusive` πάντα.
- Ανά γραμμή: `quantity`, `unit_price`, `tax_rate`, `tax_amount`, `total`, `period_start/end`.
- `third_party` πάντα, στο σχήμα του `resolve`. Κάθε γραμμή έχει την επαφή της υπηρεσίας ή του domain της (ενός
  upgrade, της υπηρεσίας του) όταν αυτή **δεν** είναι η κύρια επαφή του λογαριασμού. Επαφή άλλου λογαριασμού
  (υπόλοιπο μετά από μεταφορά) δεν μετρά. Το `is_receipt` βγαίνει από το `document_type` της επαφής.
- `mycs`: `source`, `ignore_invoicing`, `immediate_invoicing`, `adjustments` (credit/debit notes, που το WHMCS δεν
  έχει), `recipient` (η κύρια επαφή με ΑΦΜ, ΔΟΥ, δραστηριότητα και `document_type`), `documents` (ό,τι έχει ήδη
  γράψει πίσω το Ekdosi).

Για το K1 (από τα reviews του #459):
- Το `total` μετρά και τα credit/debit notes, ενώ `subtotal` και `tax` όχι. Με notes, `subtotal + tax − credit ≠ total`
  κατά `mycs.adjustments`. Το K1 το διαβάζει· αλλιώς ο έλεγχος συνέπειας του Ekdosi κρατά το τιμολόγιο (hold), που είναι
  το ασφαλές.
- `paymentmethod` = το gateway της τελευταίας πληρωμής, με τα ονόματα της MyCS (`banktransfer`, `stripe`, `paypal`,
  `cardlink` …), όχι του WHMCS. Ο χάρτης πληρωμών του Ekdosi ξαναγίνεται στο cut-over. Τιμολόγιο πληρωμένο μόνο από
  πίστωση: κενό.
- Τα στοιχεία του αποδέκτη είναι τα **τωρινά** της επαφής (όπως στο plugin), όχι του snapshot της έκδοσης.
- Η σελιδοποίηση είναι offset, όπως στο plugin: ό,τι πληρώνεται ή τιμολογείται όσο το Ekdosi διαβάζει σελίδες
  μετακινεί τις επόμενες· όσα δεν φανούν, έρχονται στο επόμενο poll.
- Τα τιμολόγια που έρχονται από τον importer έχουν σήμερα γραμμές domain/addon/upgrade χωρίς `reference_type`, άρα
  `type ''`. Το E4 πρέπει να τις δένει, για να έχει το Ekdosi routing και κατηγορία εσόδων.

Τύποι γραμμής (όπως στο WHMCS, για το routing και την κατηγορία εσόδων του Ekdosi):

| Γραμμή | `type` |
|---|---|
| υπηρεσία | `Hosting` |
| upgrade | `Upgrade` |
| domain | `DomainRegister`, `DomainTransfer` ή `Domain` |
| αλλαγή κατόχου | `DomainOwnerChange` |
| add funds | `AddFunds` |
| ελεύθερη γραμμή | `''` |

## Η «Σχετική επαφή» της υπηρεσίας και του domain

- Ήταν μόνο πληροφορία (`accounts.md`, 3b). Τώρα είναι ο **αποδέκτης του παραστατικού** για τις γραμμές της
  υπηρεσίας ή του domain. Το προτιμολόγιο μένει του λογαριασμού, όπως στο WHMCS· το split το κάνει το Ekdosi
  («Διαχωρισμός σε προσχέδια»), με τον αποδέκτη κάθε γραμμής έτοιμο.
- **Domain:** νέα στήλη `domains.contact_id`, **όχι** ένας από τους ρόλους του registrar. Αν άλλαζε ο ρόλος billing,
  η αλλαγή θα έφευγε στο μητρώο (`PushContactJob`). Το staff την ορίζει στη σελίδα του domain (Contacts → «Σχετική
  επαφή», με το `admin.domains.update`), μόνο από τις επαφές του ίδιου λογαριασμού, όχι αρχειοθετημένες. Σε μεταφορά του domain σε άλλο
  λογαριασμό σβήνει, όπως της υπηρεσίας. Ο πελάτης δεν την αλλάζει ακόμα από το portal· όταν χρειαστεί, όπως της
  υπηρεσίας.

- **Ποια γραμμή πού:** υπηρεσία και upgrade της → η επαφή της υπηρεσίας· domain και αλλαγή κατόχου του → η επαφή του
  domain. Το `relid` στο `third_party.lines` είναι το id της υπηρεσίας ή του domain, όπως στο routing του plugin.
- **Αρχειοθετημένη επαφή** δεν είναι αποδέκτης: τιμολογείται ο λογαριασμός. Το staff που αρχειοθετεί επαφή σε χρήση
  αλλάζει πρώτα τη σχετική επαφή όπου θέλει άλλον αποδέκτη.

## Cut-over

- **Μία πηγή ανά εταιρεία:** όσο το WHMCS τροφοδοτεί το Ekdosi (και στο shadow run), η ρύθμιση της MyCS μένει κλειστή.
  Αλλιώς το ίδιο τιμολόγιο μπαίνει δύο φορές στα «Εισερχόμενα».
- Τα IDs των τιμολογίων του WHMCS κρατιούνται, άρα το `whmcs_invoice_id` του Ekdosi δείχνει στο ίδιο τιμολόγιο και
  μετά.
- Την ημέρα του cut-over:
  1. ο importer φέρνει τα `mod_ekdosi_invoice_marks` (E4), ώστε τα τιμολογημένα να μη ξαναμπούν στο feed;
  2. ανοίγει η ρύθμιση της MyCS;
  3. στο Ekdosi η εταιρεία γυρίζει από «WHMCS Bridge» σε «MyCS Bridge», με το ίδιο `since`.

## Live δοκιμή (πριν το cut-over)

Ο υπεύθυνος (2026-10-25): δοκιμές με πραγματικά τιμολόγια στο `ekdosi.myip.gr` (πάροχος με DEV στοιχεία), με Chrome,
όταν κλείσουν τα E1 και K1. Τι ελέγχεται:
- [ ] **Σύνδεση:** Settings → Ekdosi στη MyCS και «MyCS Bridge» στο Ekdosi με το ίδιο secret· «Test connection» πράσινο.
- [ ] **Fetch:** ένα πληρωμένο τιμολόγιο της MyCS εμφανίζεται στα «Εισερχόμενα» με σήμα MyCS, σωστό πελάτη (ΑΦΜ),
      ΔΟΥ, δραστηριότητα, καθαρό/ΦΠΑ/σύνολο.
- [ ] **ΤΠΥ/ΑΛΠ:** πελάτης με ΑΦΜ και «τιμολόγιο» → ΤΠΥ· χωρίς ΑΦΜ ή «απόδειξη» → ΑΛΠ.
- [ ] **Γκρινιάρης:** με το checkbox, το τιμολόγιο εκδίδεται μόνο του (auto-issue) και ο ΜΑΡΚ επιστρέφει στη MyCS.
- [ ] **Ignore invoicing:** τιμολόγιο τέτοιου πελάτη δεν φτάνει ποτέ στα «Εισερχόμενα».
- [ ] **Τρίτοι:** υπηρεσία/domain με «Σχετική επαφή» άλλη από τον λογαριασμό → η γραμμή κρατιέται για split.
- [ ] **Αρχειοθέτηση / έκδοση:** δεν ξαναενοχλούν στο επόμενο fetch.
- [ ] **ΦΠΑ:** 24% σωστά· γραμμή 0% κρατιέται για άνθρωπο· τιμολόγιο με credit note κρατιέται με σημείωση.
- [ ] **Επί πιστώσει:** πληρωμή στη MyCS κλείνει την οφειλή στο Ekdosi (sync).
- [ ] **Write-back:** στη MyCS το τιμολόγιο δείχνει τον ΜΑΡΚ (E2) και δεν γίνεται πια merge/split/αλλαγή γραμμών.

## Importer (E4, με τον κοινό importer)

- `mod_ekdosi_contacts` → κανονικές `contacts` του λογαριασμού:
  - `company_name`, `gr_vatno` → `tax_id`, `vies_vatno` → `vat_number`, `tax_office`, `description` → `activity`,
    διεύθυνση, email, τηλέφωνο;
  - κρατιέται χάρτης παλιό id → νέο.
- `mod_ekdosi_routing` → η επαφή δένεται στην υπηρεσία (`service_type` hosting → `services.contact_id`) ή στο domain
  (domain → `domains.contact_id`). Αναθέσεις σε υπηρεσίες που έληξαν περνούν κι αυτές (9 σήμερα).
- `is_receipt`: στο WHMCS είναι ανά ανάθεση, εδώ το `document_type` είναι ανά επαφή.
  - Επαφή με όλες τις αναθέσεις receipt → `receipt`· αλλιώς `invoice`.
  - Αν μια επαφή έχει και τα δύο, ο importer το αναφέρει (parity) και δεν διαλέγει σιωπηλά.
- `mod_ekdosi_invoice_marks` → `ekdosi_documents` (ΜΑΡΚ, κωδικός, κατάσταση, PDF).
- `mod_ekdosi_bridge_log`: δεν περνά (ιστορικό). `mod_timologia*`: αρχείο.
