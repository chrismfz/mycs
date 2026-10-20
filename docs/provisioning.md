# Provisioning (Σ1)

Πώς στήνονται και αλλάζουν οι λογαριασμοί στους servers: ποιος server (`ServerSelector`, server groups), πότε
(Auto Setup ανά προϊόν, Σ1-b), τι γίνεται όταν ο server αρνηθεί (εδώ, Σ1-a).

## Αποφάσεις (2026-10-15)
1. **«Με την παραγγελία» και απλήρωτο τιμολόγιο:** ο λογαριασμός στήνεται πριν την πληρωμή, όπως στο WHMCS· αν μείνει
   απλήρωτος, τον αναστέλλει το cron. (Σ1-b· 5 προϊόντα licensing της nixpal.)
2. **«Active μόνο μετά την επιτυχία» μόνο στο create.** Suspend/unsuspend/terminate: το status το ορίζει η χρέωση όπως
   σήμερα· ο server ακολουθεί το status, και ό,τι αποτύχει μπαίνει στα Pending Module Actions.
3. **Retries:** 3 αυτόματα (μετά από 1΄, 10΄, 1 ώρα), μετά στο staff (λίστα, μετρητής στο dashboard, ένα email). Όχι
   ημερήσιο retry από το cron.
4. **Το staff έχει πλήρη ελευθερία:** Accept και Create χωρίς πληρωμή (χειροκίνητο «Create» στην υπηρεσία, Accept
   παραγγελίας στο Σ1-b).

## Pending Module Actions (`module_actions`, Σ1-a)
- Κάθε create/suspend/unsuspend/terminate/upgrade περνά από το `ExtensionHelper::serverAction` και γράφεται: ένα row
  ανά ενέργεια (Module Log). Αποτυχία: το row μένει `open`, οι προσπάθειες μετριούνται στο ίδιο row, με το τελευταίο
  σφάλμα. «No server assigned» (προϊόν χωρίς module) δεν είναι αποτυχία· server που διαγράφηκε είναι.
- Κλείνει: με επιτυχία της ίδιας ενέργειας (`completed`), με επιτυχία της αντίθετης (unsuspend μετά από αποτυχημένο
  suspend και αντίστροφα· terminate κλείνει όλες: `resolved`, «superseded by …»), ή από το staff («Mark resolved»).
- Τα jobs (`App\Jobs\Server\*`, `RetriesModuleAction`): `tries = 4`, backoff 60/600/3600. Μετά την τελευταία:
  `given_up_at`, «needs staff» στη λίστα, ένα system email.
- **Create:** η υπηρεσία μένει `pending` μέχρι να υπάρξει ο λογαριασμός· η περίοδος που πληρώθηκε γράφεται κανονικά
  (`RenewServiceService`), τα χρήματα δεν εξαρτώνται από τον server. Επιτυχία: `active` και welcome mail. Ο
  `CreateJob` είναι unique ανά υπηρεσία και τρέχει μόνο σε `pending` (μια υπηρεσία που ξεκίνησε το staff ή ακυρώθηκε
  στο μεταξύ μένει ως έχει). Χωρίς server: `active` με την πληρωμή, όπως πριν.
- **Ένα create τη φορά ανά υπηρεσία**, όποιος κι αν το ζητά (job, retry, staff): lock `service-create:{id}`, και ο
  έλεγχος «ακόμα pending» ξαναδιαβάζεται μέσα στο lock. Αν η υπηρεσία ακυρώθηκε όσο στηνόταν, ο λογαριασμός φεύγει
  (TerminateJob) και δεν πάει welcome mail.
- Το terminate τρέχει μόνο σε υπηρεσία που έχει τελειώσει (cancelled/terminated): retry ή Retry του staff δεν σβήνει
  λογαριασμό που ξαναζωντάνεψε. «upgrade» = το ChangePackage του WHMCS (ετικέτα «Change Package»)· module χωρίς αυτό
  (DirectAdmin, Plesk, Virtfusion) = τίποτα να γίνει, όχι αποτυχία.
- Admin: Services → Pending Module Actions (top menu: Utilities), Retry (τρέχει το ίδιο job τώρα, με το mail του) και
  Mark resolved· δικαιώματα των υπηρεσιών (`admin.services.viewAny` / `update`).
- Μένει: domains (RegisterDomain/RenewDomain του Openprovider/FORTH) με τα domains· τα «πληρωμένα που δεν εφαρμόστηκαν»
  του BIL-9 στην ίδια λίστα.

## Auto Setup ανά προϊόν (`products.autosetup`, Σ1-b1)
| | WHMCS | πότε στήνεται | πληρωμένη, πριν στηθεί |
|---|---|---|---|
| `order` | `order` | με την παραγγελία (listener `SetUpOnOrder` του `Service\Ordered`, create μετά το commit· χωρίς module: active αμέσως) | — |
| `payment` (default) | `payment` | με την πρώτη πληρωμή (CreateJob· χωρίς module: active) | pending όσο τρέχει το create |
| `accept` | `on` | με το Accept της παραγγελίας (Σ1-b2) ή το Create/status του staff | **pending**, με module ή χωρίς |
| `never` | `''` | μόνο από το staff (Accept, Create, status) | **pending** |

**Ημερομηνίες όταν ξεκινά** (`Service::datesOnStart`, από το `ServiceObserver` σε κάθε pending → active που δεν
έφερε δική του ημερομηνία: create, Accept, status από το staff):
- **Πληρωμένη και περίμενε** (server που απέτυχε, dedicated που στήνεται με το χέρι 10 μέρες): η περίοδος που πληρώθηκε
  μετράει από σήμερα· το `expires_at` μετατοπίζεται κατά τις μέρες αναμονής, όπως η χάρη του staff (οι γραμμές του
  τιμολογίου μένουν). Π.χ. πληρωμή 1/1, active 11/1 → λήξη 11/2, επόμενο τιμολόγιο 11/2–11/3.
- **Απλήρωτη** (με την παραγγελία, ή create/Accept του staff πριν την πληρωμή): `expires_at` = μέρα της παραγγελίας
  (το next due = regdate του WHMCS). Το απλήρωτο πρώτο τιμολόγιο την αναστέλλει (Suspend Days) και την τερματίζει όπως
  κάθε ληξιπρόθεσμη· πληρωμένο, η πρώτη περίοδος είναι από την παραγγελία. Όχι πριν λήξει το τιμολόγιο: αν η
  προθεσμία του είναι μεταγενέστερη, μπαίνει «suspend hold» ως εκείνη τη μέρα (το ίδιο πεδίο της φόρμας). Upgrade δεν
  γίνεται όσο η γραμμή της παραγγελίας είναι απλήρωτη (θα χανόταν το setup fee).
- Δωρεάν πρώτη περίοδος (κουπόνι 100%): η αναμονή μετράει από την παραγγελία.
- **Ημερομηνία του staff** στην ίδια αποθήκευση (π.χ. 1η του μήνα, όπως η qualityweb στο myip) κρατιέται· το
  «Expires at» αλλάζει ελεύθερα και η επόμενη ανανέωση τιμολογείται από εκεί.
- Μόνο στην **πρώτη** ενεργοποίηση (`services.activated_at`): ένα pending → active αργότερα (status που διόρθωσε το
  staff) δεν μετακινεί τίποτα. Η αρχή ενός κουπονιού «για N κύκλους» μετακινείται μαζί με τις περιόδους.
- One-time και free plans: χωρίς ημερομηνίες, όπως πριν. Ένα one-time plan με «με την παραγγελία» στήνεται με την
  πληρωμή: τίποτα δεν θα κυνηγούσε το απλήρωτο τιμολόγιό του (δεν αναστέλλεται, και η ακύρωση απλήρωτης παραγγελίας
  παίρνει μόνο pending).

## Status παραγγελίας και κουμπιά (`orders.status`, Σ1-b2, Δ4)
- `pending` στη δημιουργία (default της στήλης). Ακολουθεί τις υπηρεσίες της (`Order::refreshStatus`, από το
  `ServiceObserver`: αλλαγή status ή `order_id`, δημιουργία ήδη ξεκινημένης, διαγραφή): `active` όταν καμία δεν μένει
  `pending`, `cancelled` όταν όλες τελείωσαν (π.χ. απλήρωτη, από το cron). Το status του staff δεν αναιρείται.
- `orders.invoice_id`: το δικό της τιμολόγιο (το `tblorders.invoiceid` του WHMCS)· το γράφει το `PlaceOrderService`, το
  backfill (το πρώτο τιμολόγιο των υπηρεσιών της) και ο importer. Χωρίς foreign key: ο importer φέρνει τις παραγγελίες
  πριν από τα τιμολόγια.
- **Importer:** το status έρχεται από το `tblorders.status` (Active/Pending/Cancelled/Fraud), όχι από τις υπηρεσίες (στο
  import δεν τρέχουν observers).
- **Accept Order:** μόνο σε `pending` με κάτι ζωντανό. `active`, `accepted_at/by`· **κάθε** `pending` υπηρεσία
  στήνεται, πληρωμένη ή όχι (απόφαση 2, 2026-10-15· όπως το «Run Module Create» του WHMCS, και οι «never»). «Run module
  create»: με server → CreateJob (active όταν υπάρξει ο λογαριασμός)· χωρίς, ή χωρίς create (στήθηκε με το χέρι) →
  active τώρα. «Send welcome email» ανεξάρτητο. Οι ημερομηνίες ακολουθούν το `datesOnStart`. Το cron δεν ακυρώνει ως
  «απλήρωτη παραγγελία» υπηρεσία παραγγελίας που έγινε accept (το create της μπορεί να αποτυγχάνει ακόμα).
- **Cancel Order:** οι υπηρεσίες γίνονται `cancelled` (stock πίσω, pending upgrades αποσύρονται), οι γραμμές τους στα
  ανοιχτά τιμολόγια ακυρώνονται (`CancelOpenInvoices`, το credit επιστρέφει). Οι λογαριασμοί μένουν, εκτός αν το staff
  τσεκάρει «Terminate accounts». Τα πληρωμένα τιμολόγια μένουν.
- **Cancel & Refund:** όπως το Cancel, και επιστρέφουν οι πληρωμές **του τιμολογίου της παραγγελίας** μόνο (όπως το
  WHMCS· ανανεώσεις και upgrades που πληρώθηκαν αργότερα παραδόθηκαν: επιστροφή από το τιμολόγιό τους), πληρωμένου ή
  πληρωμένου εν μέρει, **πριν** ακυρωθούν τα ανοιχτά. Gateway / με το χέρι / σε credit· ό,τι πληρώθηκε με credit γυρνά
  σε credit. Θέλει και το `admin.invoice_transactions.update`.
  - Ό,τι επιστράφηκε ήδη (από εδώ, ή από το dashboard του gateway με webhook) ή πάρθηκε με chargeback δεν επιστρέφεται
    ξανά (`refundable_amount`).
  - Gateway που δεν κάνει refund (ή αποτυχία): ειδοποίηση στο staff ανά πληρωμή, οι υπόλοιπες συνεχίζουν.
  - Chargeback σε περίοδο που παραδόθηκε: το τιμολόγιο ξαναχρωστιέται και μένει ανοιχτό για το staff (να το εισπράξει ή
    να το ακυρώσει με το χέρι).
  - Τιμολόγιο παραγγελίας που χρεώνει και κάτι άλλο: ειδοποίηση στο staff.
- **Set as Fraud:** όπως το Cancel, status `fraud` (η εκτίμηση απάτης δεν υπάρχει, Δ4). **Set back to Pending:** μόνο
  το status, και όχι σε παραγγελία χωρίς τίποτα ζωντανό.
- Κλειδώματα: υπηρεσίες, μετά η παραγγελία (Accept, Cancel), όπως μια πληρωμή ή ένα create που τελειώνει.
- Pending Orders: tab της λίστας (`?tab=pending`), στο top menu (Orders, active μόνο σε αυτό το tab), badge και μετρητής
  στο dashboard.

## Μυστικά (sweep §6, PR ασφαλείας)
- **Κανένα μυστικό σε property.** Ο κωδικός ενός λογαριασμού πάει στο `services.password` (encrypted, `$hidden`, εκτός
  audit) και τα modules τον διαβάζουν ως `password` (`getServiceProperties`). Το DirectAdmin έγραφε το
  `directadmin_password` καθαρό· το migration `2026_11_06_000000` τα μεταφέρει και σβήνει την τιμή από τα audits.
  Κωδικοί λογαριασμών με CSPRNG (`Str::password`).
- **Logs:** `App\Support\Redact`: κλειδιά που ονομάζουν μυστικό (pass, secret, token, key, authorization, cookie,
  session) σε κάθε βάθος και στο query του URL, τα cPanel `cpsess…`, και κάθε αποθηκευμένο μυστικό (τα encrypted
  settings) όπου κι αν εμφανιστεί. Το debug log των requests (`RequestListener`) περνά από εκεί. Το email log κρατά το
  mail χωρίς τα μυστικά των δεδομένων του (`service_password`, ένα password που έδωσε το module)· ο πελάτης τα παίρνει.
- **Ουρά:** `Mail` και `SystemMail` είναι `ShouldBeEncrypted` (και στα `failed_jobs`)· ο listener του email log τα
  αποκρυπτογραφεί όπως ο worker.
- **Φόρμες:** server, gateway, extension δεν στέλνουν ποτέ στη σελίδα ένα αποθηκευμένο μυστικό· κενό = κρατιέται (όπως
  οι Ρυθμίσεις και τα product settings). Το «Test connection» χρησιμοποιεί το αποθηκευμένο.
- **Νέα διεύθυνση = ξανά τα μυστικά:** αν αλλάξει host/IP/port/URL ενός server, gateway ή extension, τα αποθηκευμένα
  μυστικά δεν στέλνονται (ούτε στο «Test connection») μέχρι να γραφτούν ξανά: ένα root key δεν φεύγει ποτέ προς
  διεύθυνση που έγραψε κάποιος που δεν μπορούσε να το διαβάσει. Μετά την αποθήκευση, ό,τι γράφτηκε φεύγει από τη σελίδα.
- Μυστικά του πυρήνα στις Ρυθμίσεις (captcha secret, OAuth client secrets) και το Discord webhook: `encrypted`.
- Το email log κρατά ένα δεύτερο render του mail με τα μυστικά ήδη αντικατεστημένα (όχι αναζήτηση μετά: η Markdown και
  το escaping αλλάζουν πώς γράφεται ένας κωδικός). `Redact` ξαναδιαβάζει τα αποθηκευμένα μυστικά κάθε λεπτό (workers),
  και δεν ξαναχτίζει το URL.
- Test: κάθε πεδίο `password` στο `getConfig()` server/gateway είναι `encrypted`.
- Ο κωδικός στη σελίδα υπηρεσίας του πελάτη (sweep §6 #7, έγινε): δεν είναι στο HTML· το «Show» τον ζητά από τον
  server (`revealPassword`, μόνο όποιος διαχειρίζεται την υπηρεσία, 10/λεπτό, εκτός snapshot του Livewire). Παλιά
  email logs με κωδικούς σβήνονται στις 90 μέρες.

## Provisioning fixes (sweep §2, §4, §7)
- **cPanel «Change Package» = WHM `changepackage` (`user`, `pkg`).** Το `modifyacct` με `plan` δεν άλλαζε ποτέ πακέτο:
  το WHM το αγνοεί (μόνο ένα «Unrecognized modifyacct parameter» στο log του) και απαντά `result: 1`, «Account
  Modified». Επαληθεύτηκε σε WHM 138 (virgo, λογαριασμός του ιδιοκτήτη, 2026-10-15):
  - `modifyacct plan=…` → `result 1`, κανένα warning, το πακέτο ίδιο.
  - `changepackage` → `result 1`, «OK», και στο `output.raw` τι άλλαξε· το πακέτο εφαρμόστηκε.
  - λάθη → `result 0` και `reason` («API failure: (XID …) Specified package does not exist.», «You do not have a user
    named “…”.»): exception, Pending Module Actions με αυτό το μήνυμα.
  - **Ένα πακέτο χωρίς shell κόβει το shell** του λογαριασμού (και τα cron του). Πριν το cut-over: έλεγχος των
    πακέτων του myip (`HASSHELL`) απέναντι στους λογαριασμούς που έχουν shell.
- **Change package μόνο όταν αλλάζει** (όπως το WHMCS): πρώτα `accountsummary`· ίδιο πακέτο = τίποτα (ένα upgrade
  μόνο επιλογών δεν ξαναεφαρμόζει πακέτο, όρια ή shell που το staff άλλαξε στο WHM). Πακέτο: το config option
  `package` πρώτα, όπως στο create.
- **cPanel create idempotent:** το username γράφεται πριν το `createacct` (`cpanel_pending_username`)· retry: αν ο WHM
  έχει ήδη λογαριασμό με αυτό το όνομα **και το domain της παραγγελίας** (`accountsummary`), ολοκληρώνεται χωρίς
  δεύτερο· με άλλο domain είναι ξένος λογαριασμός: δεν υιοθετείται, το όνομα πετιέται. Άρνηση του `createacct`
  (`result 0`) πετά επίσης το όνομα (το επόμενο retry παίρνει νέο)· χαμένη απάντηση το κρατά. Το terminate ενός τέτοιου
  λογαριασμού (create με χαμένη απάντηση, μετά ακύρωση) τον βρίσκει με τον ίδιο έλεγχο. Username: πεζά, γράμμα πρώτα, 8.
- `UpgradeJob` όχι σε υπηρεσία που τελείωσε. Upgrade μόνο σε προϊόν του ίδιου module με τον server όπου είναι ο
  λογαριασμός (λίστα πελάτη και φόρμα «Upgrades» του προϊόντος).
- **Επιλογή server:** ο server του προϊόντος μόνο αν είναι enabled και έχει χώρο· αλλιώς το create αποτυγχάνει
  (`NoServerWithRoom`, Pending Module Actions), δεν γεμίζει σιωπηλά. Group: servers ενός module. Δεν σβήνεται group
  ή server που τον χρησιμοποιούν προϊόντα.

## Custom fields και modules που χρειαζόμαστε (απόφαση 2026-10-15)
Τα product custom fields του WHMCS είναι σχεδόν όλα δεδομένα modules (`tblcustomfields`, μόνο αθροίσματα):
- **nixpal:** `comments` (5 προϊόντα licensing, 14/336 με τιμή) = «Custom Fields Reported» του licensing: property της
  υπηρεσίας, που το `zlic.php` επιστρέφει κατά το `custom_fields` του προϊόντος (υπάρχει ήδη, parity test). `IP Address`
  (προϊόν `agent`, 0 υπηρεσίες): δεν μεταφέρεται, το licensing δένει την IP μόνο του.
- **myip:** Centova Cast (9 υπηρεσίες: port, username, sourcepassword, …), Hetzner Robot, Hetzner Cloud, pvewhmcs
  (Proxmox), λίγα χειροκίνητα.

**Απόφαση: όχι γενικό σύστημα custom fields (το Σ1-c όπως είχε σχεδιαστεί) τώρα.** Ό,τι ζητείται στην παραγγελία το
δίνει το module (`getCheckoutConfig`)· ό,τι είναι δεδομένο του module γίνεται property της υπηρεσίας (κωδικοί όχι:
`services.password`, κανόνας του PR ασφαλείας). Ο importer μεταφέρει τις τιμές που δεν είναι κενές ως properties.

Modules για το myip:
- **Hetzner Robot, Hetzner Cloud: όχι.**
- **Centova Cast:** δεν υπάρχει στο MyCS· γράφεται από την αρχή. Το Blesta έχει δικό του (vendor: μόνο ιδέες, ποιες
  κλήσεις και πεδία, όχι κώδικας). Οι 9 υπηρεσίες πριν το cut-over του myip.
- **Proxmox:** το myip τρέχει το Proxmox VE for WHMCS της The-Network-Crew (pvewhmcs· η nixpal/myip του έστειλε το
  PR #178, clusters). Άδεια **GPL-3.0** (επιβεβαιώθηκε 2026-10-15): όχι αυτούσιος κώδικας σε αυτό το repo (MIT).
  **Δικό μας module** πάνω στο API του Proxmox, με το pvewhmcs ως αναφορά συμπεριφοράς (ποιες κλήσεις, τι πεδία, πώς
  βρίσκει το node σε cluster). Ιδέες και από το Blesta, όχι κώδικας.

## Δοκιμή στον orion (2026-10-20, ο υπεύθυνος έδωσε την άδεια)
Από το devbox, μέσα από την εφαρμογή (`ExtensionHelper`, module actions), με τον reseller της nixpal και το προϊόν
«Wordpress V16», στο `mycs-test-1.dummy-domain.tld`:
- **create:** το WHM απέρριψε το πακέτο `V16` («not a valid package name»): ο reseller το έχει ως `<reseller>_V16`. Το
  WHMCS βάζει μόνο του το πρόθεμα του reseller· το extension πλέον το ίδιο (#246: όπως το δίνει το `listpkgs`, με το
  πρόθεμα όταν λείπει). Με το πλήρες όνομα ο λογαριασμός έγινε, με ιδιοκτήτη τον reseller.
- **suspend / unsuspend / terminate:** εντάξει, επαληθευμένα με `accountsummary`· στο τέλος ο λογαριασμός δεν υπάρχει.
- Η υπηρεσία δοκιμής (#3) μένει terminated στο devbox.
