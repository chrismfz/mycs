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

## Κωδικός υπηρεσίας και welcome email (υπεύθυνος, 2026-10-29)
Από τις συνήθειες του WHMCS. Στη σελίδα της υπηρεσίας του staff, στο «More»:
- **«Resend welcome email»** (το «Resend Welcome Email» του WHMCS, `App\Services\Service\WelcomeMail`): το ίδιο mail με
  την ενεργοποίηση (`new_server_created`, `serverCreatedNotification`), με τον κωδικό που είναι αποθηκευμένος **τώρα**.
  - Πάει μόνο στον κάτοχο του λογαριασμού, χωρίς CC/BCC (private template). Το email log το κρατά χωρίς τον κωδικό,
    όπως το πρώτο.
  - Με επιβεβαίωση που λέει σε ποια διεύθυνση πάει· ο κωδικός δεν φαίνεται πουθενά σε αυτή τη ροή.
  - Το πολύ 3 σε οποιαδήποτε 60 λεπτά ανά υπηρεσία (μαζί με το «και στείλε το welcome email» παρακάτω): κυλιόμενο
    παράθυρο από τα audits `welcome_resent`, με lock ώστε δύο ταυτόχρονα κλικ να μετρηθούν το ένα μετά το άλλο.
  - Μόνο το email, όπως στο WHMCS: χωρίς νέα ειδοποίηση στην εφαρμογή ή push «η υπηρεσία είναι ενεργή».
  - Το «Send» είναι απενεργό όταν δεν μπορεί να σταλεί, και το παράθυρο λέει γιατί.
  - Στο audit της υπηρεσίας ως `welcome_resent`: ποιο staff, σε ποια διεύθυνση, ποιο email log.
  - Μόνο σε **active** υπηρεσία: το mail λέει «η υπηρεσία είναι ενεργή», και μια suspended δεν δέχεται σύνδεση.
  - Δεν στέλνεται όταν η υπηρεσία έχει username αλλά όχι κωδικό (π.χ. υπηρεσία του WHMCS πριν το βήμα αποκρυπτογράφησης
    των κωδικών, `importer.md`), ούτε όταν δεν έχει ούτε στοιχεία σύνδεσης ούτε άδεια. Κωδικός χωρίς username (ο root
    ενός VPS, προϊόν χωρίς module) στέλνεται: το προεπιλεγμένο template τον δείχνει πια και χωρίς username (το migration
    `2027_01_23_000000` αλλάζει μόνο ένα template που δεν το έχει πειράξει το staff). Μια άδεια nixpal χωρίς login
    στέλνεται (το κλειδί της από το `{{ service.license.license_key }}` του προϊόντος).
  - Ούτε όταν το template είναι κλειστό, το mail απενεργοποιημένο ή ο πελάτης το έχει κλείσει: το staff βλέπει τον λόγο.
- **«Change password on server»** (υπήρχε): τώρα με **«Generate»** δίπλα στο πεδίο (`App\Support\ServicePassword`: 16
  χαρακτήρες από CSPRNG, κεφαλαία, πεζά, αριθμοί και λίγα σύμβολα `-_.!@` που δέχονται όλα τα modules, χωρίς 0/O, 1/l/I),
  που φαίνεται με το μάτι για να το αντιγράψει το staff. Και checkbox «και στείλε το welcome email με τον νέο κωδικό».
  - Το mail φεύγει μόνο αν ο νέος κωδικός **αποθηκεύτηκε** στην υπηρεσία. Αν ο server τον πήρε αλλά η αποθήκευση απέτυχε
    (το cPanel το αναφέρει), το staff ειδοποιείται να τον αποθηκεύσει με το παρακάτω και δεν στέλνεται τίποτα.
  - Audit: `password_changed_on_server` (ποιο staff). Η αλλαγή της στήλης έχει και το δικό της audit, χωρίς την τιμή.
- **«Save a password set elsewhere»**: για κωδικό που άλλαξε ο πελάτης στον server, ή εμείς αλλού (WHM, panel του
  Centova/mailcow). Τον αποθηκεύει **μόνο** στην υπηρεσία, χωρίς καμία κλήση στον server, ώστε το επόμενο welcome email
  να στείλει αυτόν που δουλεύει. Ίδιο checkbox για το welcome email. Audit: `password_stored`. Χωρίς τον κανόνα των 10
  χαρακτήρων: είναι ό,τι ισχύει ήδη στον server.
- Το πεδίο «Password» της φόρμας κάνει το ίδιο (αποθήκευση μόνο εδώ) και το λέει πια στο hint του.
- Όλα θέλουν `update` στις υπηρεσίες (`admin.services.update`), όπως η σελίδα.
- **Ο πελάτης:** στη φόρμα «Αλλαγή κωδικού» της υπηρεσίας του (cPanel, mailcow, Centova), κουμπί «Δημιουργία ισχυρού
  κωδικού»: συμπληρώνει και τα δύο πεδία και τον δείχνει για αντιγραφή, ως την αλλαγή.

**Ανά module:**

| Module | Αλλαγή στον server | Αποθήκευση μόνο εδώ | Resend |
|---|---|---|---|
| cPanel | ναι (`passwd`, και MySQL αν ζητηθεί) | ναι | ναι |
| mailcow | ναι (κωδικός του domain admin) | ναι | ναι |
| CentovaCast | ναι (`adminpassword`) | ναι | ναι |
| Proxmox | **όχι** (παρακάτω) | ναι | ναι, με τον αποθηκευμένο κωδικό |
| Nixpal Licensing | δεν έχει κωδικό | — | ναι (το κλειδί) |
| Offline VM / Dedicated (ngm, Hetzner) | όχι (χωρίς API) | ναι | ναι, αν έχει username/κωδικό |
| DirectAdmin | όχι ακόμα (παρακάτω) | ναι | ναι |

- **Proxmox, γιατί όχι:** δύο δρόμοι, κανένας έτοιμος.
  - Ο QEMU guest agent (`agent/set-user-password`): θέλει τον agent σε κάθε guest (οι 8 VPS στήθηκαν από ISO, άγνωστο
    αν τον έχουν), δικαίωμα που ο ρόλος `MyCSLifecycle` δεν έχει (`VM.GuestAgent.Unrestricted` στο PVE 9, `VM.Monitor`
    στο 8), και τον σωστό χρήστη ανά λειτουργικό (root, Administrator).
  - Το cloud-init (`cipassword`): μόνο σε VM από cloud-init template, και εφαρμόζεται στο επόμενο boot. Ανήκει στο PR 6
    του `proxmox.md` (reinstall και reset κωδικού).
  - Μισή λύση (π.χ. «άλλαξε μόνο αν τρέχει ο agent») δεν χτίστηκε. Ως τότε: ο κωδικός αλλάζει μέσα στο VM (κονσόλα) και
    αποθηκεύεται με το «Save a password set elsewhere».
- **DirectAdmin, αργότερα** (3 υπηρεσίες στο WHMCS· το extension του upstream κάνει create/suspend/terminate): το
  `changePassword` θα είναι το `CMD_API_USER_PASSWD` (POST `username`, `passwd`, `passwd2`, ως admin ή reseller), με
  τον ίδιο τρόπο με το cPanel: πρώτα ο server, μετά `services.password`, `ServerRefused` στην άρνηση. Με αυτό ανάβει
  μόνο του και το κουμπί, ο generator και το checkbox. Πριν από αυτό: live δοκιμή σε DirectAdmin με άδεια του
  υπεύθυνου, και έλεγχος ότι οι κανόνες κωδικού του (`DIFFICULT_PASSWORD_ENFORCEMENT`) δέχονται τα σύμβολα του generator.
- **ngm, αργότερα** (1 υπηρεσία, Offline VM): αν ο πάροχος δώσει API για reset του root, γίνεται module με
  `changePassword`· αλλιώς μένει «Save a password set elsewhere» μετά την αλλαγή στο panel του ngm.

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
- **Centova Cast:** έγινε (feat/centova, `extensions/Servers/CentovaCast`): δικό μας, πάνω στο XML API του Centova, με
  το ανοιχτό module του Centova για το WHMCS ως αναφορά κλήσεων (όχι κώδικας). Οι 9 υπηρεσίες με τον importer.
  **Δοκιμή live στο stream (2026-10-22, με άδεια του υπεύθυνου):** server #4, κρυφό προϊόν #39 «Centova test (hidden)»
  (template `icecast100`, terminate = delete), υπηρεσία #5 του πελάτη #2. Create (θύρα, τύπος IceCast), εύρεση στο
  `listaccounts` με το email του πελάτη, σύνδεση στο panel, κωδικός, suspend (disabled, μαρκαρισμένο) και unsuspend
  (enabled, χωρίς μαρκάρισμα), χρήση, terminate (διαγράφηκε· το Centova ξανά με 9 accounts): όλα εντάξει. Η δοκιμή βρήκε
  τρία πράγματα του Centova 3.3.2, διορθωμένα και με tests: ελληνικά σε HTML entities (`&Iota;…`) μέσα στο XML
  (#335), το `server.enabled` με κάθε τιμή σε `<field>` (#335), και debug banner πριν από το XML ενός σφάλματος (#336).
  **Δεύτερη δοκιμή live (2026-10-28, υπεύθυνος):** ShoutCast v1 (template `shoutcast_1_100`, κρυφό προϊόν #42) και
  ShoutCast v2 (`shoutcast_2_100`, #43): create (θύρες 9217/9248, ο τύπος από το template), suspend (disabled),
  unsuspend, terminate (διαγράφηκαν· ο stream ξανά με τους 9 σταθμούς: 4 IceCast, 4 ShoutCast v2, 1 ShoutCast v1). Ο
  τύπος ενός νέου σταθμού είναι του template του προϊόντος του (`icecast100`, `icecast200`, `shoutcast_1_100`,
  `shoutcast_2_100`)· suspend/terminate δεν εξαρτώνται από τον τύπο.
  **Στον stream:** το Centova δεν μπορούσε να φτιάξει κανένα account (`Field 'statuscache' doesn't have a default value`)
  επειδή η MariaDB ήταν σε strict mode· ο υπεύθυνος αφαίρεσε το `STRICT_TRANS_TABLES` (`sql_mode` στο `my.cnf`).
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

## Δοκιμή reseller στον rigel (2026-10-28, υπεύθυνος: root token για δοκιμές)
Από το devbox, μέσα από την εφαρμογή, κρυφό προϊόν #44 «cPanel reseller test (hidden)» (`Business10`, ACL `reseller70`,
2.000 MB / 20.000 MB / 3 λογαριασμοί), υπηρεσία #11 στο `mycs-rtest.myip.gr`:
- **create:** ο λογαριασμός έγινε reseller που ανήκει στον εαυτό του, με τα ACL του `reseller70` και τα όρια του
  προϊόντος (`resellerstats`: 2000/20000, χωρίς overselling· `acctcounts`: όριο 3).
- Ένας λογαριασμός κάτω από αυτόν (`createacct` του root με owner τον reseller, σαν να τον έφτιαξε ο ίδιος).
- **suspend / unsuspend:** περνούν και στον λογαριασμό του (`suspendreseller`). **Χρήση:** 1 από 3 λογαριασμούς, με τα
  όρια του προϊόντος. **SSO** στο cPanel και **κωδικός:** εντάξει.
- **terminate:** χωρίς επιβεβαίωση αρνείται («owns 1 other account»)· με το username πληκτρολογημένο, `terminatereseller`:
  και οι δύο διαγράφηκαν· ο rigel ξανά με 144 λογαριασμούς και τους δύο resellers του.
- Βρέθηκε: ο reseller δεν είχε «Σύνδεση στο WHM» (το WHMCS την έχει): προστέθηκε (feat/cpanel-reseller-whm-login),
  ελεγμένη με test (`whostmgrd`)· live στην επόμενη δοκιμή reseller.

## Δοκιμή live 2026-10-22 (ο υπεύθυνος έδωσε την άδεια)
Υπηρεσία δοκιμής **#4** («Wordpress V16», πελάτης #2 «MyCS cPanel test», `mycs-test-2.dummy-domain.tld`) στον orion, με τον
reseller της nixpal. **Μένει ως reference** (ο υπεύθυνος): λογαριασμός με test mailbox, addon, alias και subdomain.
- **create:** εντάξει, μέσα από `ExtensionHelper::createServer`.
- **χρήση:** `listaccts`/`showbw` με το login του reseller δίνουν 0 MB / 16 GB, bandwidth χωρίς όριο.
- **domains:** `get_domain_info` με το login του reseller επιστρέφει όλα τα domains των λογαριασμών του (42), με `user` και
  `user_owner`. Τα addon, alias και subdomain της δοκιμής διαβάστηκαν με τον σωστό τύπο, μαζί με το «κρυφό» subdomain του addon.
- **πελάτης (Chrome, «Login as client»):**
  - οι συντομεύσεις ανοίγουν τη σωστή σελίδα του cPanel: Email Accounts, Domains (`Domains_domains`), Manage My Databases
    (`Database_MySQL`);
  - το Webmail ανοίγει στο :2096.
  - Τα ονόματα των σελίδων είναι τα `implements` του `/usr/local/cpanel/base/frontend/jupiter/dynamicui.conf`.
- **email και κωδικός:**
  - το `add_pop` έφτιαξε mailbox (quota 1 GB), που φαίνεται στο cPanel;
  - το `passwd` (και με `db_pass_update`) άλλαξε τον κωδικό, και ο αποθηκευμένος ταιριάζει;
  - ένας αδύναμος κωδικός απορρίπτεται από το WHM με τον λόγο του (strength 65).
- **staff:** «Usage», «Hosted domains», «Change password on server», και το global search από ένα alias βρίσκει την υπηρεσία
  με Client / Account / Server.
- **Σημειώσεις:**
  - μια σελίδα του admin τερματίζει το «Login as client» (AUTH-19): για δοκιμή χρειάζονται χωριστά tabs/κύκλοι;
  - ο κανόνας μας για τον κωδικό (10+ χαρακτήρες, κεφαλαία, πεζά, αριθμοί) δεν φτάνει πάντα το 65 του WHM: τότε ο πελάτης
    βλέπει την άρνηση του WHM με τον λόγο της.

## Επιλογές αντί για τα addons του WHMCS (2026-10-21)

Τα product addons του WHMCS γίνονται **επιλογές της ίδιας της υπηρεσίας** (configurable options). Ανανεώνονται μαζί της, με τον ίδιο κύκλο και την ίδια λήξη. Ο πελάτης τις προσθέτει, τις αλλάζει ή τις αφαιρεί με την αναβάθμιση, με αναλογική χρέωση. Στο myip ήταν 15 ενεργά addons (2026-10-21): 11 Dedicated IP, 3 extra χώρος, 1 System Maintenance. Στη nixpal κανένα.
- **Extra χώρος σε βήματα των 10GB** (απόφαση υπευθύνου):
  - Επιλογή `extra_disk_gb`, τύπου select και upgradable, με τιμές «None» (env `0`), «+10GB» (`10`), «+20GB» (`20`)… Κάθε τιμή έχει τη δική της τιμή χρέωσης.
  - Το cPanel module βάζει quota = quota του πακέτου + extra (WHM `getpkginfo` → `editquota`, σε MB), στο create και σε κάθε αναβάθμιση, και το ξαναβάζει μετά το `changepackage`. Με «None» επιστρέφει στο quota του πακέτου.
  - Το extra που έβαλε κρατιέται στην υπηρεσία (`cpanel_extra_disk_gb`). Μια αλλαγή προϊόντος που αφαιρεί την επιλογή επαναφέρει το quota του πακέτου. Μια υπηρεσία που δεν είχε ποτέ extra δεν αγγίζεται, άρα ένα quota που έβαλε το staff στον WHM μένει όπως είναι. Πακέτο χωρίς όριο: τίποτα.
  - Η τιμή διαβάζεται ως ακέραιος αριθμός GB («2.5» → 2), ποτέ μεγαλύτερος από όσο γράφει.
  - Αν το quota αποτύχει μετά τη δημιουργία του λογαριασμού (ή μετά την υιοθεσία ενός λογαριασμού που φτιάχτηκε ενώ χάθηκε η απάντηση), δεν ξαναγίνεται create. Ανοίγει «Change Package» για το staff, με Retry διαθέσιμο αμέσως και email· το Retry ξαναβάζει το quota.
- **Dedicated IP, extra IPv4 σε VPS, dedicated ή colocation: πάντα από το staff** (απόφαση υπευθύνου):
  - Επιλογή με «Done by staff» (`config_options.staff_task`). Για dedicated IP που μπορεί να προστεθεί αργότερα: select «No» / «Yes», upgradable· env π.χ. `dedicated_ip_option`, όχι `dedicated_ip`, γιατί αυτό είναι το πεδίο της IP της υπηρεσίας στα properties των modules. Τα checkboxes δεν αναβαθμίζονται.
  - Τιμή «χωρίς»: env ή όνομα `0`, κενό, `no`, `none`, `off`, `false`, «Όχι», «Χωρίς», «Κανένα», «Καμία».
  - Ανοίγει **Staff Task** στις Pending Module Actions, με την υπηρεσία και το domain, και φεύγει email στο staff:
    - «Give»: όταν η υπηρεσία ξεκινά ή επανέρχεται από τερματισμό ή ακύρωση με την επιλογή, ή όταν μια αναβάθμιση την προσθέτει·
    - «Change: … → …»: όταν αλλάζει η τιμή της, π.χ. 2 IPs → 1·
    - «Take back»: όταν μια αναβάθμιση την αφαιρεί (και όταν αλλάζει σε προϊόν χωρίς αυτήν), ή όταν η υπηρεσία λήγει ή τερματίζεται.
  - Η ίδια εργασία δεν ανοίγει δεύτερη φορά όσο είναι ανοιχτή.
  - Το module δεν αγγίζει IPs, και τα Staff Tasks δεν έχουν Retry. Το staff κάνει τη δουλειά και πατά «Done».
- **System Maintenance:** ξεχωριστό προϊόν, χωρίς module.
