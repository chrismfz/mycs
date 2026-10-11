# Openprovider στο Blesta: τι λείπει και τι κάνουμε

Και τα δύο brands χρησιμοποιούν Openprovider (myip 241 ενεργά domains, nixpal 7, 2026-10-10)·
το CNIC είναι σε μετάβαση και δεν μας απασχολεί. Σύγκριση του module του Blesta (v3.0.1,
`blesta/module-openprovider`) με το επίσημο WHMCS module της Openprovider (v5.13.9).

## Απόφαση
Fork του module του Blesta ως `components/modules/myopenprovider` (README εκεί), όχι νέο module
και όχι patch στο `openprovider` (ένα update του Blesta θα το έσβηνε). Το WHMCS module δεν
μεταφέρεται (πολύ μεγαλύτερο, δεμένο με WHMCS, XML API by default). Το mapping του importer
(`migration/importer-mappings/openprovider.php`) δείχνει στο `myopenprovider`.

## Client: δικός μας, όχι το SDK
Η επικοινωνία με το API περνά σταδιακά στο `lib/myopenprovider` (δικός μας client χωρίς εξαρτήσεις,
port από τον `OpenproviderRegistrar` του Ekdosi). Το `openprovider/rest-client-php` (που χρησιμοποιεί το
module του Blesta, v2.0.2-beta, και φέρνει Guzzle/symfony serializer/phpdocumentor από το composer του
Blesta) **δεν** μπαίνει ως εξάρτηση: δύο εκδόσεις των ίδιων πακέτων στο ίδιο process θα συγκρούονταν.
Το χρησιμοποιούμε ως **αναφορά**: παράγεται από το OpenAPI της Openprovider, άρα τα models του
τεκμηριώνουν κάθε πεδίο. Tracking: ελέγχθηκε απέναντι στο **v2.2.3-beta** (2026-08-17)· σε κάθε νέο
release κοιτάμε τι άλλαξε στα endpoints που χρησιμοποιούμε (`src/Domain/Model/*`).

Το παλιό `openprovider/openprovider-domains-blesta` είναι ο πρόγονος του module του Blesta (ίδια δομή,
ούτε αυτό ανανεώνει), «not actively maintained»· δεν έχει κάτι επιπλέον.

## Ανανεώσεις: ποιος ανανεώνει
Ανανεώνει **το Blesta**: το `renewService()` του `myopenprovider` (PR #15) καλεί
`POST /v1beta/domains/{id}/renew` όταν πληρωθεί η ανανέωση. Το autorenew του Openprovider μπαίνει
**off** (στην εγγραφή/μεταφορά, που μοιράζονται το ίδιο payload και έστελναν `default`, και μετά από κάθε
ανανέωση), όπως στο Ekdosi
(«το billing clock είναι δικό μας»). Κανόνες (`RenewalPlan`, unit tested):
- ζωντανό record → renew για τα πληρωμένα έτη· στόχος = τρέχον expiry + έτη (και στο grace period)·
- **έτη = όρος × cycles:** το Blesta δεν κρατά τα cycles στο τιμολόγιο (η γραμμή έχει τιμή × cycles, όχι qty),
  μόνο στις ημερομηνίες που μετακίνησε όταν τιμολόγησε (`createRenewalFromService` και cron:
  `date_last_renewed` = παλιό `date_renews`, `date_renews` + όρος × cycles). Άρα:
  - ένα πληρωμένο τιμολόγιο και κανένα άλλο ανοιχτό: έτη = `date_renews` − `date_last_renewed` όταν είναι
    ακέραια έτη (admin «N cycles», «Renew in Advance» του πελάτη, έτη του Domains plugin· και μετά από αλλαγή
    όρου), αλλιώς ένας όρος (π.χ. το uncancel βάζει «τώρα»)·
  - ένα πληρωμένο και **νεότερο** τιμολόγιο ανανέωσης (ανοιχτό, void ή draft· το void δεν γυρίζει πίσω τις
    ημερομηνίες): οι ημερομηνίες είναι του νεότερου· ένας όρος όταν το διάστημα είναι ένας όρος, αλλιώς
    άγνωστο: άρνηση `cycles_unknown` χωρίς χρέωση. Διέξοδος: «Ανανέωση τώρα» (επιτρέπεται εδώ) ή panel +
    «Σήμανση ως ανανεωμένο»· η εγγραφή γράφεται **για αυτό το τιμολόγιο** (`for`) και μόνο τότε αρκεί
    ένας όρος· παλαιότερη εγγραφή `prepaid` ελέγχεται κανονικά (`prepaid_mismatch`). Παλαιότερο ανοιχτό
    τιμολόγιο δεν επηρεάζει (οι ημερομηνίες είναι του πληρωμένου)·
  - πολλά πληρωμένα (άρνηση εκτός prepaid) ή κανένα: ένας όρος.
  Μη ακέραια έτη (1-10) → άρνηση πριν από οποιαδήποτε κλήση. Χέρι στο `date_renews` από admin μετά την
  τιμολόγηση αλλάζει τα έτη (ό,τι δείχνουν οι ημερομηνίες). Με «process paid service changes» ενεργό οι
  ημερομηνίες γράφονται αφού πληρωθεί το τιμολόγιο· να ελεγχθεί στο staging πριν το ενεργοποιήσουμε·
- `DEL` μέσα στη soft quarantine → restore, μόνο για ανανέωση 1 έτους (το restore φέρνει ένα έτος)·
  στη hard quarantine ή μετά → άρνηση με μήνυμα (κοστίζει επιπλέον, το αποφασίζει άνθρωπος)· ώρα
  Amsterdam· ο κανόνας είναι του WHMCS module της Openprovider (στο Ekdosi το restore είναι μόνο χειροκίνητο)·
- **idempotency:** η εγγραφή μας για την υπηρεσία είναι στο module meta (`myop_renewal_<service id>`) και
  γράφεται **πριν** από κάθε χρέωση ως `pending` με το expiry που πρέπει να φτάσει· γίνεται `done` όταν το
  Openprovider το δείξει. Όσο μια χρέωση είναι pending και δεν φαίνεται (timeout, 5xx, «δεκτό» αλλά όχι
  ακόμα ορατό), οι επαναλήψεις **αρνούνται** για 7 ημέρες αντί να χρεώσουν ξανά. Άρνηση από το Openprovider
  (4xx ή κωδικός σφάλματος) σβήνει το pending (δεν χρεώθηκε τίποτα)·
- **κλειδί περιόδου = τα πληρωμένα τιμολόγια ανανέωσης σε αναμονή** (`service_invoices` type renewal με
  `invoices.date_closed`, ό,τι διαλέγει και το cron `ProcessRenewingServices`)· το `renewService()` δεν παίρνει
  invoice id, και μετά από κάθε επιτυχές run το Blesta σβήνει **ένα** από αυτά χωρίς να λέει ποιο (`GROUP BY`
  στο query του). Ίδιο σύνολο (retry) → καμία δεύτερη χρέωση· νέο τιμολόγιο, ακόμα και με ίδιο renew date →
  κανονική ανανέωση. **Πολλά** πληρωμένα τιμολόγια μαζί → άρνηση `multiple_invoices`: ο admin ανανεώνει τα
  υπόλοιπα με το χέρι («Ανανέωση τώρα» ή panel + «Σήμανση ως ανανεωμένο»)· μία εγγραφή `prepaid` καλύπτει όλα
  όσα περιμένουν. Χωρίς κανένα πληρωμένο τιμολόγιο (κλήση εκτός cron) κλειδί είναι `renews:<renew date>`.
  Κλειδιά διαφορετικού είδους (`renews:` απέναντι σε `inv:`, δηλαδή run χωρίς τιμολόγιο και μετά το cron):
  χρέωση μικρότερη των 30 ημερών που έχει φανεί (pending ή done) μπορεί να είναι ή όχι η ίδια πληρωμή → άρνηση
  `period_ambiguous`, αποφασίζει άνθρωπος («Σήμανση ως ανανεωμένο» ή «Καθαρισμός εγγραφής»)· η άρνηση
  μένει και μετά τις 30 ημέρες (σημάδι `ambiguous` στην εγγραφή), ώστε να μη γίνει χρέωση χωρίς επίβλεψη· ανεπιβεβαίωτη →
  αναμονή· παλαιότερη → προηγούμενη περίοδος. Το γυμνό date του 1.0.0 (άγνωστο είδος): pending = αυτή η
  περίοδος, όπως πριν·
- ακυρωμένο (void) τιμολόγιο δεν μετράει ως πληρωμένο: το `Invoices::setClosed` κρατά `date_closed` μόνο για
  εξοφλημένο active/proforma και το καθαρίζει αλλιώς (ελέγχθηκε στο 6.0.3)·
- pending εγγραφή **άλλης** (προηγούμενης) περιόδου δεν «υιοθετείται»· αν έχει πια φανεί, η τρέχουσα
  πληρωμένη περίοδος ανανεώνεται κανονικά· αν είναι ακόμα ανεπιβεβαίωτη (εντός 7 ημερών), άρνηση·
- επιβεβαίωση από `expiration_date` **ή** `renewal_date` (το Openprovider ορίζει το `renewal_date` ως κύρια
  αναφορά· το `expiration_date` μπορεί να ακολουθεί το registry αργότερα)·
- ένα run ανά υπηρεσία κάθε φορά (`GET_LOCK`)·
- **module meta ανά key:** το `ModuleManager::setMeta()` του Blesta 6.0.3 σβήνει **όλο** το meta του module
  πριν γράψει (ελέγχθηκε στο `app/models/module_manager.php`). Το upstream module το καλούσε για το token
  του κάθε 15 λεπτά, άρα θα έσβηνε τις εγγραφές ανανέωσης. Και το δικό μας και το upstream token γράφονται
  πλέον με upsert σε ένα key (`module_meta` έχει PK `module_id` + `key`, key ≤ 32 χαρακτήρες)·
- δεν υπάρχει πια κανόνας «το expiry είναι ήδη μακριά → skip»: από τη σημερινή ημερομηνία δεν ξεχωρίζει
  ανανέωση αλλού από πρόωρη πληρωμή· τις ανανεώσεις αλλού τις αποτρέπει το autorenew off.

**Cut-over:** τα domains που υπάρχουν σήμερα στο Openprovider έχουν autorenew `default`/`on`. Όταν το
Blesta αναλάβει τις ανανεώσεις, πρέπει να γυρίσουν **off** όλα (αλλιώς διπλή ανανέωση: Openprovider +
Blesta). Θέλει μικρό script (dry-run πρώτα) πάνω στο `Client::setAutorenew`· μπαίνει στο checklist.

## Χειροκίνητη ανανέωση πριν την πληρωμή («prepaid»)
Στο WHMCS για έμπιστους πελάτες / πίστωση / «πρώτα τιμολόγιο» ανανεώναμε χειροκίνητα στον registrar και
βγάζαμε το relid, γιατί το «mark paid» θα ξανάστελνε renew (διπλή χρέωση, domain +1 έτος). Στο Blesta δεν
πειράζεται η σύνδεση υπηρεσίας–τιμολογίου· αντί γι' αυτό, admin tab **«Ανανέωση»** του domain:
- «Ανανέωση τώρα (πριν την πληρωμή)»: renew στο Openprovider με τους ίδιους κανόνες και το ίδιο pending
  (lock, εγγραφή πριν τη χρέωση) και εγγραφή `prepaid` όταν φανεί. Αρνείται αν υπάρχει ήδη `prepaid`, αν
  μια χρέωση είναι ανεπιβεβαίωτη, ή αν περιμένει ήδη **ένα** πληρωμένο τιμολόγιο (το cron το ανανεώνει σε
  λεπτά· με πολλά επιτρέπεται, είναι η διέξοδος του `multiple_invoices`)·
- «Σήμανση ως ανανεωμένο»: χωρίς κλήση API, εγγραφή `prepaid` για ανανέωση από το panel·
- «Καθαρισμός εγγραφής»: σβήνει την εγγραφή (και `done`, π.χ. μετά από `period_ambiguous`)· για `pending` η επιβεβαίωση προειδοποιεί ότι πρέπει
  πρώτα να ελεγχθεί στο panel ότι η χρέωση δεν έγινε (αλλιώς η επόμενη πληρωμή χρεώνει ξανά)·
- η επόμενη πληρωμένη ανανέωση βρίσκει το `prepaid` και το «καταναλώνει» (`done`, χωρίς χρέωση) για όλα τα
  πληρωμένα τιμολόγια εκείνης της στιγμής: για ένα τιμολόγιο πρέπει να έχει ακριβώς τα πληρωμένα έτη, για
  πολλά τουλάχιστον έναν όρο ανά τιμολόγιο που οφείλεται (όσα κάλυψε ήδη χρέωση που υιοθετήθηκε δεν
  οφείλονται ξανά)· αλλιώς άρνηση `prepaid_mismatch`·
  `prepaid` παλαιότερο από 180 ημέρες → άρνηση `prepaid_stale` (δεν καταναλώνεται σιωπηλά τον επόμενο χρόνο).
  Ένα ανεπιβεβαίωτο «Ανανέωση τώρα» κρατά την πληρωμένη ανανέωση για 7 ημέρες· αν φανεί, καταναλώνεται.

Ανανέωση από το panel του registrar χωρίς τη «Σήμανση ως ανανεωμένο» θα έκανε ξανά διπλή ανανέωση.

Ισχύει το ίδιο για το .gr (`grepp`): η λογική (`RenewalPlan`: pending/done/prepaid, κλειδί περιόδου, adopt)
δεν εξαρτάται από τον registrar· όταν γραφτεί το `grepp`, να βγει σε κοινή βιβλιοθήκη (π.χ.
`lib/renewal-guard`) και να τη χρησιμοποιούν και τα δύο modules.

## Το σοβαρό κενό του stock module: καμία ανανέωση (διορθώθηκε στο fork)
Το stock module δεν έχει `renewService()`/`restoreDomain()`· το `Module::renewService()` του Blesta
επιστρέφει `null` (`components/modules/module.php:611` στο Blesta 6.0.3), άρα μια πληρωμένη ανανέωση δεν
στέλνει τίποτα στο Openprovider, και η εγγραφή στέλνει `'autorenew' => 'default'` (upstream
`openprovider.php:732`). Με autorenew off στο account ο πελάτης πληρώνει και το domain λήγει· με on
ανανεώνονται και τα απλήρωτα. Τα docs του Blesta γράφουν «Renew: YES». Στο `myopenprovider` αυτό το
καλύπτει πλέον το `renewService()` (παραπάνω).

## Κενά με σειρά προτεραιότητας
| # | Κενό | Χρήση σε εμάς | Δουλειά |
|---|---|---|---|
| 1 | Renew / restore (redemption) / autorenew off ✅ (`renewService`, `lib/myopenprovider`) | όλα τα domains | έγινε· μένει το script autorenew off για το cut-over |
| 2 | Sync λήξης με το `renewal_date` του OP, έλεγχος ολοκλήρωσης transfer, ανανέωση στην ολοκλήρωση | ~20 transfers/χρόνο | μικρή-μεσαία |
| 3 | EPP ως feature του Blesta ✅ (`features.epp_code` στο `config.json`, πεδίο πακέτου «EPP Code» που γεμίζει το Domains plugin από το TLD): ο πελάτης βλέπει/κάνει reset/λαμβάνει τον auth code μόνο όπου είναι ενεργό· ο admin πάντα (test για το gating). Αποστολή με email (όπως το «Get EPP Code» του WHMCS) με **δικό μας πρότυπο** `myopenprovider_epp_code` στο Settings → Company → Emails, ένα ανά γλώσσα (en_us/el_gr από το `config/myopenprovider.php`, οι υπόλοιπες γλώσσες με το αγγλικό)· το Blesta διαλέγει τη γλώσσα του **πελάτη**· στο HTML το `{epp_code_html}` (το Blesta δεν κάνει autoescape)· ο κωδικός masked στο email log· ένα email ανά υπηρεσία κάθε 5 λεπτά. Το πρότυπο μπαίνει στο install (για την εταιρεία) και στο upgrade σε 1.3.0 (για **κάθε** εταιρεία που έχει το module: το Blesta καλεί το `upgrade()` μία φορά και σημαδεύει όλες τις εγκαταστάσεις)· το uninstall σβήνει τα πρότυπα της εταιρείας και, με την τελευταία εγκατάσταση, το (κοινό) email group. Αποτυχία στη δημιουργία προτύπων γράφεται στο module log χωρίς να σταματά το install. Χωρίς πρότυπο στη γλώσσα του πελάτη το Blesta στέλνει το αγγλικό· ανενεργό ή χωρίς αγγλικό = δεν στέλνεται (ο admin βλέπει γιατί, ο λόγος στο module log). Στη λίστα προτύπων το όνομα εμφανίζεται ως key (`AdminCompanyEmails.templates.myopenprovider_epp_code_name`): το Blesta φορτώνει ονόματα μόνο για core και plugins. **Με το deploy οι πελάτες χάνουν τον κωδικό στην καρτέλα** όπου το TLD δεν έχει «EPP Code». Μένει: `updateEppCode` | συχνό αίτημα πελατών | έγινε (εκτός `updateEppCode`) |
| 4 | Additional fields ανά TLD (σήμερα μόνο .es) και δυναμική λίστα TLDs αντί για hardcoded (λείπει π.χ. .com.gr) | .eu 23, IDN 135 | μεσαία |
| 5 | Glue/child NS, DNS zones/records, DNSSEC, ID protection, premium, υπόλοιπο reseller | ελάχιστη έως μηδενική (DNS management 8) | προαιρετικά |

Μικρότερα: ~~το `logRequest` κρύβει μόνο το `password`~~ διορθώθηκε: κρύβει σε κάθε βάθος `password`,
`auth_code`, `internal_auth_code` και `token`, σε requests **και** responses (το retrieve του domain στην
καρτέλα «Settings» επέστρεφε τον auth code, το login το token)· το sandbox URL (`http://api.sandbox.openprovider.nl:8480`) είναι μάλλον
παρωχημένο (δεν ελέγχθηκε live).

Υπάρχει ήδη: register/transfer, nameservers, επαφές με επαναχρησιμοποίηση handle, εισαγωγή τιμών
TLD, tab «Settings» με τον auth code και reset, lock/unlock.

## Σύγκριση με τα stock registrar modules του Blesta 6.0.3 (2026-10-10)
Read-only ανάλυση των namesilo, internetbs, connectreseller, realtime_register, opensrs, nominet
(+ enom, logicboxes, namecheap, ovh_domains, generic_domains, stock openprovider) και του Domains/Order plugin.
Reference για τη λογική του API: το WHMCS module της ίδιας της Openprovider (`openprovider/Openprovider-WHMCS-domains`)
και το σχήμα του `rest-client-php`. Τα Domains/Order plugins καλούν από το module μόνο: `getExpirationDate`,
`getRegistrationDate`, `get/setDomainNameservers`, `checkAvailability`, `checkTransferAvailability`,
`bulkCheckAvailability`, `isValidTerm`, `getTlds`, `getFilteredTldPricing`, `supportsFeature` και τα add/edit fields·
τα υπόλοιπα (register/transfer/lock/contacts/EPP) δεν σπάνε τίποτα σιωπηλά αν λείπουν.

Ένα PR ανά θέμα, με αυτή τη σειρά:
| # | Θέμα | Γιατί |
|---|---|---|
| H1 | `getExpirationDate`/`getRegistrationDate`: `false` όταν λείπει η ημερομηνία (όχι 1970), `creation_date` αντί για `order_date`, Amsterdam → UTC | το Domains plugin θέτει `date_renews` από την ημερομηνία· 1970 = άμεσο τιμολόγιο ανανέωσης |
| H2 | transfer: οι nameservers του πακέτου **δεν** αντικαθιστούν αυτούς του domain ✅ — μόνο NS που δόθηκαν για το transfer, αλλιώς οι τρέχοντες NS του domain από το DNS (με glue IPs για NS μέσα στο domain), αλλιώς άρνηση. Το `import_nameservers_from_registry` του API δεν υπάρχει στο rest-client v2.0.2-beta του Blesta (θα πεταγόταν) | σήμερα το transfer στέλνει τους NS του πακέτου |
| H3 | admin «Add Service»: επιλογή Register/Transfer (μοτίβο namesilo) ✅ — «Εγγραφή» προεπιλεγμένη και χωρίς JS· το EPP κρύβεται στην εγγραφή και αγνοείται στον server· NS όχι προσυμπληρωμένοι (κενά = του πακέτου στην εγγραφή, τρέχοντες στη μεταφορά)· ο EPP κωδικός αποθηκεύεται encrypted στα πεδία της υπηρεσίας (ήταν καθαρός). TLD-specific πεδία μόνο στη φόρμα πελάτη (το admin δεν ξέρει το TLD πριν γραφτεί το domain) | το core admin form δεν στέλνει ποτέ `transfer` |
| H4 | ποια ημερομηνία συγχρονίζεται (expiration vs renewal) και η ρύθμιση «days before expiration» ✅ (απόφαση και μετρήσεις παρακάτω) | το sync του Domains plugin ξαναγράφει το `date_renews` |
| M1 | cron για εκκρεμή transfers (μοτίβο nominet) ✅ — `check_pending_transfers` κάθε 6 ώρες (ανά εταιρεία, χωρίς διπλά runs): ACT → `transfer_pending=0` + resync ημερομηνιών στο Domains plugin· FAI → `failed` + log· >14 ημέρες → log. Η υπηρεσία μένει όπως την ενεργοποίησε το Blesta | ενεργοποίηση της υπηρεσίας όταν ολοκληρωθεί |
| M2 | πραγματικό `checkTransferAvailability` και έλεγχος πριν το create/transfer ✅ — μεταφέρεται μόνο domain καταχωρημένο (`active`) που δεν είναι ήδη στον λογαριασμό μας· σφάλμα API = όχι· πριν από κάθε εγγραφή/μεταφορά ο αντίστοιχος έλεγχος, πριν φτιαχτούν επαφές στο Openprovider | σήμερα σφάλμα API = «transfer δυνατό» |
| M3 | `getFilteredTldPricing` με τα φίλτρα του· bug `transfer_price` υπό έλεγχο `renew_price` ✅ — επίσης όλες οι σελίδες του `searchExtensionRequest` (500 ανά σελίδα· πριν ερχόταν μόνο η πρώτη, μικρή, default σελίδα) και TLD χωρίς τιμή εγγραφής/μεταφοράς/ανανέωσης παραλείπεται (στο TLD sync θα γινόταν 0 = δωρεάν) με καταγραφή | |
| M4 | δυναμική λίστα TLDs ✅ — τα ενεργά TLDs του Openprovider με και τις τρεις τιμές (ίδια cached λίστα με τις τιμές) + η στατική λίστα· μόνο η στατική αν δεν διαβάζεται το API (χωρίς σφάλμα στη σελίδα). Να ελεγχθεί στο staging ποιες τιμές `status` επιστρέφει το Openprovider για τα TLDs | λείπει π.χ. `.com.gr` |
| M5 | admin ενέργειες: «Sync ημερομηνιών τώρα», κατάσταση transfer ✅ — στην καρτέλα «Ανανέωση»: «Συγχρονισμός ημερομηνιών από το Openprovider τώρα» (δείχνει τη λήξη, resync στο επόμενο τρέξιμο του Domains plugin)· ένδειξη εκκρεμούς/αποτυχημένης μεταφοράς και «Έλεγχος μεταφοράς τώρα» (ο ίδιος έλεγχος με το cron του M1) | |
| M6 | `updateEppCode`, lock/unlock/`getDomainIsLocked`, `getDomainInfo`, get/set contacts ως μέθοδοι ✅ — `getDomainInfo` χωρίς auth code· `updateEppCode` κενό = νέος κωδικός από το Openprovider· `get/setDomainContacts` σε μορφή Blesta (ένα contact χωρίς `type` πάει σε όλους τους ρόλους, handles με ίδια στοιχεία επαναχρησιμοποιούνται) | Blesta API, εργαλεία |
| L1 | features `id_protection` (whois privacy) και `dns_management` — L1a ✅ `id_protection`: η επιλογή του Domains plugin στέλνει `is_private_whois_enabled` στην εγγραφή/μεταφορά (μόνο όταν επιλέχθηκε) και το αλλάζει στο `editService`· L1b ✅ `dns_management`: tab «DNS» (admin πάντα, client μόνο με την επιλογή DNS Management) πάνω στις DNS zones του Openprovider — λίστα, δημιουργία zone, προσθήκη/αφαίρεση εγγραφών (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, έλεγχος στο `DnsRecord`)· το SOA και τα NS του apex μένουν στο Openprovider. Η zone χρησιμεύει μόνο όταν το domain δείχνει στους nameservers του Openprovider | ✅ |
| L2 | glue / child nameservers ✅ — `setNameserverIps` (API του module· κανένα plugin του Blesta 6.0.3 δεν το καλεί) και tab «Hosts nameserver» για admin και client: λίστα, προσθήκη/αλλαγή (IPv4 και/ή IPv6) και διαγραφή hosts στο `/v1beta/dns/nameservers`. Μόνο hosts κάτω από το ίδιο το domain του service (`GlueHost`)· όχι private/loopback/link-local IPs. Το Openprovider αρνείται διαγραφή host που χρησιμοποιείται ακόμα | ✅ |
| L3 | `getAdminEditFields` ✅ — στο admin «Edit Service» το πεδίο domain για διόρθωση του ονόματος της υπηρεσίας (π.χ. μετά το import από WHMCS). Με «Use module» γίνεται δεκτό μόνο αν το domain υπάρχει (ζωντανό) στον λογαριασμό Openprovider· χωρίς αυτό αλλάζει μόνο στο Blesta. Καμία ενέργεια στο registry. NS, contacts κτλ. μένουν στα tabs | ✅ |
| L4 | `getDomainNameServers` χωρίς `gethostbyname` σε κάθε φόρτωση ✅ — οι IPs βγαίνουν από τα `ip`/`ip6` (glue) της εγγραφής του Openprovider, χωρίς `gethostbyname` σε κάθε φόρτωση· η live εγγραφή προτιμάται (`opRecord`) | ✅ |
| L5 | `bulkCheckAvailability` (ένα `checkDomainRequest` για πολλά) ✅ — ένα `checkDomainRequest` ανά 15 ονόματα (αναζήτηση πολλών TLDs στη φόρμα παραγγελίας του Order plugin) αντί για μία κλήση ανά domain· αντιστοίχιση με το όνομα της απάντησης· αποτυχημένο αίτημα ή όνομα χωρίς απάντηση = μη διαθέσιμο | ✅ |
| L6 | `isValidTerm` ανά TLD ✅ — από τα `min_period`/`max_period` και το `transfer_available` του Openprovider (ίδια cached λίστα με τις τιμές). Το Order plugin το ελέγχει στο checkout. Μεταφορά: 1 έτος (έως το ελάχιστο του TLD, π.χ. 2 για .gr) και μόνο όπου επιτρέπεται. Άγνωστο TLD ή API κάτω = default του Blesta (≤10). **Να επιβεβαιωθεί** με τα πραγματικά δεδομένα του λογαριασμού μετά το deploy (π.χ. .gr) | ✅ |
| L7 | `editService`/`cancelService` ✅ — `editService`: id protection (L1a) και διόρθωση domain (L3). `cancelService`: autorenew off μόνο αν δεν είναι ήδη off (live εγγραφή)· domain που ο λογαριασμός δεν έχει πια (αποτυχημένο transfer, διαγραμμένο) δεν μπλοκάρει την ακύρωση (πριν: σφάλμα και ξανά-προσπάθεια από το cron κάθε μέρα)· εκκρεμές transfer → `transfer_pending=canceled` ώστε να σταματήσει το polling (και το cron αγνοεί canceled services)· σφάλμα API → μπλοκάρει, με οδηγία για «Use module» | ✅ |

### H4: ποια ημερομηνία λήξης βλέπει το Blesta (απόφαση 2026-10-10)
- Το Domains plugin συγχρονίζει (cron `domain_synchronization`) την ημερομηνία λήξης από το `getExpirationDate()` και
  **ξαναγράφει** `services.date_renews = λήξη − domains_renewal_days_before_expiration` όταν η λήξη ≥ `date_renews`
  (`plugins/domains/domains_plugin.php:1583-1609`)· το ίδιο κάνει και στην ενεργοποίηση της υπηρεσίας (`updateRenewalDate`).
- **Απόφαση:** το `getExpirationDate()` επιστρέφει το `expiration_date` (η πραγματική λήξη, αυτή που βλέπει ο πελάτης).
  Το WHMCS module της Openprovider δείχνει αντί γι' αυτό το `renewal_date` (`DomainSyncController.php:64`)· εμείς
  καλύπτουμε τη διαφορά με τη ρύθμιση του Domains plugin.
- **Μέτρηση** `expiration_date − renewal_date` στο Openprovider, ένα domain ανά TLD από τα ενεργά (nixpal + myip,
  24 TLDs, 2026-10-10): **.es 6 ημέρες**, .at/.fr/.nl 2, .be/.de/.eu 1, όλα τα άλλα (.com, .net, .org, .co.uk, .ch,
  .it, .io, .ai, …) 0.
- **Κανόνας:** `domains_renewal_days_before_expiration` ≥ μέγιστη διαφορά + `inv_days_before_renewal` + χρόνος
  πληρωμής. Σήμερα 30 και 5 (staging): το τιμολόγιο ανανέωσης βγαίνει ~35 ημέρες πριν τη λήξη, δηλαδή ~29 ημέρες πριν
  το `renewal_date` του .es. Να μη μειωθεί κάτω από ~15. Νέο TLD με μεγαλύτερη διαφορά → ξαναμέτρηση.
- **Σχέση με τις ανανεώσεις μας:** στην τιμολόγηση το Blesta μετακινεί το `date_renews` μετά τη λήξη, οπότε το sync
  δεν το αγγίζει μέχρι την ανανέωση και τα έτη (`date_renews − date_last_renewed`) μένουν όπως τα τιμολόγησε. Domain
  που ανανεώθηκε εκτός Blesta: το sync μεταφέρει το `date_renews` μπροστά χωρίς τιμολόγιο (σωστό: δεν χρωστιέται
  ανανέωση).

### Review όλης της σειράς H1–L7 (2026-10-10)

Δύο ανεξάρτητα reviews (ασφάλεια/άκρες, ορθότητα/συμβάσεις Blesta). Διορθώθηκαν σε ξεχωριστό PR πάνω στο L7:
- Οι ημερομηνίες (`getExpirationDate`/`getRegistrationDate`, «Sync τώρα», resync μετά το transfer) και το `setDomainNameservers` διαβάζουν τη **live** εγγραφή (`liveRecord`), όχι μια παλιά FAI/DEL που μπορεί να εμφανίζεται πρώτη μετά από αποτυχημένο transfer.
- Id protection: μετράει η τιμή της επιλογής (`"0"` = off), και τίποτα δεν αλλάζει όταν το edit δεν φέρνει `configoptions`.
- Διόρθωση domain (L3): απορρίπτεται όνομα που έχει ήδη άλλη (μη ακυρωμένη) υπηρεσία του module. `setNameserverIps`: μόνο hosts κάτω από domain που διαχειρίζεται κάποια υπηρεσία του module.
- DNS: όχι NS στο apex (δεν θα μπορούσε να αφαιρεθεί από το tab).
- Admin «Transfer» χωρίς EPP → σφάλμα, όχι σιωπηλή εγγραφή.
- Cron transfers: το timeout σημειώνεται μία φορά (`transfer_pending=timeout`)· σφάλμα του «Check transfer» εμφανίζεται ως σφάλμα. Ακύρωση με REQ δεν αγγίζει το autorenew.
- TLD λίστα: μία φορά ανά request (και η αποτυχία), σελιδοποίηση με το `total`, index για το `isValidTerm`, στο log μόνο σύνοψη. Το pricing βγάζει μόνο terms που επιτρέπει το `isValidTerm` για εγγραφή ή μεταφορά.
- IDN στο `bulkCheckAvailability` (punycode)· η ημερομηνία στο μήνυμα «Sync τώρα» σε ώρα Amsterdam· company restore σε `finally`· docblocks.

Δεύτερο review (μετά τις διορθώσεις) — διορθώθηκαν επίσης:
- Μόνο DEL/FAI εγγραφές = το domain δεν είναι στον λογαριασμό (`not_exist`, καμία ημερομηνία ή αλλαγή από νεκρή εγγραφή).
- Transfer states: `timeout` φαίνεται στην καρτέλα «Ανανέωση» με «Check transfer»· REQ μετά από `failed`/`timeout` → `1` με νέο παράθυρο 14 ημερών· ACT ολοκληρώνει από κάθε κατάσταση· η καρτέλα δείχνει την κατάσταση μετά τον έλεγχο.
- Pricing: τα επιτρεπτά terms υπολογίζονται μία φορά ανά TLD και το `getRow()` μία φορά ανά request (πριν: χιλιάδες queries στο TLD sync).
- Id protection στην εγγραφή/μεταφορά με την τιμή (`"0"` = off)· η επιλογή διαβάζεται από το `option_value` (επιβεβαιώθηκε στο `Services::getOptions` του staging).
- Το «Transfer» ξαναστέλνεται (hidden) όταν η φόρμα ξαναεμφανίζεται, ώστε δεύτερο submit χωρίς EPP να μη γίνει εγγραφή.
- `otherServiceWithDomain` διαβάζει το status από τις γραμμές του `searchServiceFields` (υπάρχει στο 6.0.3, επιβεβαιώθηκε).

Δεν άλλαξαν (εξήγηση): το `addTaskRun` του Blesta 6.0.3 δέχεται `$company_id` (το review το αμφισβήτησε, επιβεβαιώθηκε στο staging)· η μίξη των δύο API clients μένει ως έχει (κόστος μίας επιπλέον αναζήτησης σε edit)· το renewal state κρατιέται ανά service id και μετά από διόρθωση ονόματος.

### .gr: όχι μέσω Openprovider

Τα .gr θα τα χειρίζεται δικό μας module **grepp** (είμαστε επίσημος καταχωρητής στο μητρώο, FORTH), όχι το myopenprovider. Στο Domains plugin το .gr ανατίθεται στο grepp. Ό,τι γράφει το myopenprovider για .gr (περίοδοι, τιμή μεταφοράς, `isValidTerm`) δεν θα χρησιμοποιείται. Για το grepp: η μεταφορά .gr είναι δωρεάν και χωρίς ανανέωση, οπότε οι ημερομηνίες πρέπει να έρχονται από το μητρώο μετά τη μεταφορά, όπως κάνει εδώ το resync.

## Ανοιχτά
- Με δύο ανοιχτά τιμολόγια ανανέωσης, τα έτη του **πρώτου** δεν είναι γνωστά (οι ημερομηνίες δείχνουν μόνο
  το τελευταίο)· όσο υπάρχει άλλο ανοιχτό (active/proforma) τιμολόγιο ανανέωσης, το πληρωμένο ανανεώνεται για
  **έναν όρο**, ή άρνηση `cycles_unknown` όταν το άλλο είναι πολλών περιόδων. Μένει μία σιωπηλή περίπτωση:
  πρώτο πολλών cycles, δεύτερο ενός όρου, πληρωμή μόνο του πρώτου → ανανεώνεται ένας όρος (λιγότερα, ποτέ
  περισσότερα). Σπάνιο· αν χρειαστεί, ανάγνωση των ημερομηνιών από την περιγραφή της γραμμής.
- Μια χρέωση «δεκτή» που δεν φαίνεται ποτέ σε `expiration_date`/`renewal_date` θεωρείται μη χρεωμένη μετά
  από 7 ημέρες (registry που αργεί πολύ θα έδινε δεύτερη χρέωση)· το μήνυμα `unconfirmed` ζητά έλεγχο στο panel.
- Πριν το πρώτο deploy: `SELECT `key`, value FROM module_meta WHERE `key` LIKE 'myop_renewal_%'` (δεν
  αναμένονται εγγραφές· το 1.0.0 δεν έτρεξε πουθενά).
- `language/el_gr/myopenprovider.php` έχει μόνο ό,τι προσθέσαμε εμείς· τα υπόλοιπα πέφτουν στα en_us ανά
  key (minphp `Language::loadLang` φορτώνει και τη default γλώσσα, `getText` κάνει fallback· ελέγχθηκε στο
  Blesta 6.0.3). Η ελληνική μετάφραση του stock module στον server είναι της Blesta (άλλη άδεια).
- Εξάρτηση: το REST client (`openprovider/rest-client-php`, symfony/serializer, phpdocumentor) έρχεται από
  το root composer του Blesta, επειδή το Blesta απαιτεί `blesta/openprovider ~3.0.1`. Αν ένα update του
  Blesta το αλλάξει ή το αφαιρέσει, το fork σπάει: έλεγχος μετά από κάθε upgrade του Blesta, και αν
  χρειαστεί, δικό μας `vendor/` στο module (το `Loader::load(__DIR__/vendor/autoload.php)` το περιμένει ήδη).
- Το `getRows()` του fork κρατά μόνο τα δικά του rows (το upstream μάζευε και του stock module, ίδιο marker
  `openprovider_module`).
- Η προεπιλογή autorenew του reseller account στο Openprovider (αφορά τα domains μέχρι το cut-over script).
- Το bearer token του Openprovider αποθηκεύεται στο module meta χωρίς κρυπτογράφηση (όπως και στο stock
  module)· αν το `ModuleManager` κρυπτογραφεί meta με `encrypted`, να γίνει (ανεπιβεβαίωτο).
- Αν το Openprovider επιστρέφει `auth_code` στο listDomains (στα logs κρύβεται πλέον έτσι κι αλλιώς).
- Αν χρησιμοποιούμε glue records.

## Για αργότερα: TLD pricing sync με περιθώριο (όπως το «TLD Import & Pricing Sync» του WHMCS)
Στόχος: για κάθε TLD, κόστος από το Openprovider δίπλα στην τιμή πώλησης (register / renew /
transfer, και redemption), περιθώριο σε % και σε ποσό, μαζική εφαρμογή markup (ποσοστό ή σταθερό,
στρογγυλοποίηση π.χ. σε .50/.99) και επιλογή ποια TLDs συγχρονίζονται.

Τι υπάρχει ήδη: το Domains plugin του Blesta κάνει import TLDs/τιμών από registrar module που
υλοποιεί `getTldPricing` (το module το υλοποιεί, με cache), με markup κατά το import και cron sync.
Να επιβεβαιωθεί στο staging τι ακριβώς δείχνει (αν βλέπει κανείς κόστος και περιθώριο ανά TLD).

Τι μάλλον θα χρειαστεί:
- Σελίδα «κόστος vs τιμή» με στήλη margin % ανά TLD/όρο και φίλτρα (π.χ. αρνητικό ή χαμηλό
  περιθώριο), ως tab του module ή μικρό plugin (π.χ. στο `myip_tools`). Το κόστος έρχεται από το
  `getTldPricing` του `myopenprovider`, η τιμή από τα πακέτα του Domains plugin.
- Redemption/restore fee στο pricing (το WHMCS το συγχρονίζει προαιρετικά· το module του Blesta όχι).
- Κανόνες markup: ποσοστό ή σταθερό, ελάχιστο ποσό κέρδους, στρογγυλοποίηση, εξαιρέσεις ανά TLD,
  και ειδοποίηση όταν αλλάζει κόστος στο Openprovider (σε EUR· το κόστος του account είναι EUR).
- Ιστορικό αλλαγών τιμών (ποιος/πότε, παλιά/νέα τιμή) για να μην αλλάζουν τιμές «σιωπηλά».
