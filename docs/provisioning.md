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
- **Job που χάθηκε μετά το commit (predeploy S4, 2026-11-02, μετά τα reviews του #582):** τα server jobs που
  αποφασίζει ένα transaction (create, suspend του cron, unsuspend, upgrade) περνούν από `AfterCommit::job`. Στο
  database queue (αυτό τρέχουμε) γράφονται **μέσα στο transaction**: commit μαζί του ή rollback μαζί του, ποτέ ανάμεσα.
  Γι' αυτό ο `CreateJob` δεν είναι πια unique στο queue· δύο create δεν βλάπτουν (lock `service-create:{id}` και
  «ακόμα pending» μέσα σε αυτό). Σε άλλο queue (redis) μπαίνουν μετά το commit· ένα push που αποτυγχάνει γράφει ανοιχτή
  γραμμή «Not queued: …» στα Pending Module Actions (Retry, mail στο staff), το τιμολόγιο κρατά το `apply_pending_since`,
  και το replay της πληρωμής ξαναστέλνει **μόνο ό,τι γράφτηκε έτσι** (`ModuleAction::askAgainNotQueued`). Ποτέ create
  από μαντεψιά πάνω στην κατάσταση της υπηρεσίας: ένα create που σκοτώθηκε στη μέση δεν αφήνει γραμμή και μπορεί να
  έφτιαξε τον λογαριασμό (διπλό VM). Το `app:owed-creates` (κάθε 15') μόνο **ειδοποιεί** το staff για πληρωμένη
  υπηρεσία που μένει `pending` χωρίς κανένα create καταγεγραμμένο (περίοδος applied εδώ 15' έως 7 μέρες πριν, τιμολόγιο
  που κράτησε τα χρήματά του· όχι imports, όχι χειροκίνητες χωρίς πληρωμή), μία φορά την εβδομάδα ανά υπηρεσία.
- **Λόγος αναστολής στον WHM (2026-11-03, δοκιμή live):** το suspend στέλνει στον WHM τον λόγο της υπηρεσίας
  (`suspend_reason`: «Overdue on Payment» από το cron, ή ό,τι έγραψε το staff). Χωρίς αυτόν ο WHM έγραφε «Unknown». Το
  WHMCS στέλνει επίσης τον δικό του.
- **Mail αλλαγής υπηρεσίας (`service_upgraded`):** όταν εφαρμόζεται ένα upgrade, downgrade ή αλλαγή επιλογών, ο πελάτης
  μαθαίνει από τι σε τι άλλαξε και με ποια τιμή ανανεώνεται, μετά το commit. Το WHMCS στέλνει αντίστοιχη επιβεβαίωση.
  Ο πελάτης μπορεί να το κλείσει από τις προτιμήσεις του (`product`).
- **Ένα create τη φορά ανά υπηρεσία**, όποιος κι αν το ζητά (job, retry, staff): lock `service-create:{id}`, και ο
  έλεγχος «ακόμα pending» ξαναδιαβάζεται μέσα στο lock. Αν η υπηρεσία ακυρώθηκε όσο στηνόταν, ο λογαριασμός φεύγει
  (TerminateJob) και δεν πάει welcome mail.
- Το terminate τρέχει μόνο σε υπηρεσία που έχει τελειώσει (cancelled/terminated): retry ή Retry του staff δεν σβήνει
  λογαριασμό που ξαναζωντάνεψε. «upgrade» = το ChangePackage του WHMCS (ετικέτα «Change Package»)· module χωρίς αυτό
  (DirectAdmin, Plesk, Virtfusion) = τίποτα να γίνει, όχι αποτυχία.
- **Delete υπηρεσίας με ανοιχτή εργασία (sweep3 L-DATA-INTEGRITY-2, PROVISIONING-5, F-PROVISIONING-OPS-2, απόφαση του
  owner 2026-11-08, χωρίς migration):** το `module_actions.service_id` είναι ON DELETE CASCADE, άρα ένα delete θα έσβηνε
  μαζί και τον τερματισμό που δεν έγινε. Όσο η υπηρεσία έχει ανοιχτό terminate (αποτυχία ή βήμα του staff) ή εργασία
  «by hand», το `DeleteService` αρνείται («close the task first»). Αν το ίδιο το «Also trigger deletion of server»
  αφήνει εργασία (OfflineVm/Dedicated, Proxmox «Terminate: stop», πρόσθετο), η υπηρεσία δεν σβήνεται: μένει
  `terminated` με την εργασία ανοιχτή, και σβήνεται αφού την κλείσει το staff. Μια ανοιχτή αποτυχία άλλης ενέργειας
  (π.χ. create) δεν κρατά το delete.
- Admin: Services → Pending Module Actions (top menu: Utilities), Retry (τρέχει το ίδιο job τώρα, με το mail του) και
  Mark resolved· δικαιώματα των υπηρεσιών (`admin.services.viewAny` / `update`).
- Μένει: domains (RegisterDomain/RenewDomain του Openprovider/FORTH) με τα domains· τα «πληρωμένα που δεν εφαρμόστηκαν»
  του BIL-9 στην ίδια λίστα.

## Λογαριασμοί που περισσεύουν: ο κανόνας για κάθε module (απόφαση του owner 2026-11-08)
- **Μετά από κάθε ανάγνωση των λογαριασμών ενός server** (cpanel:sync, proxmox:sync, mailcow:sync και το «Read now» των
  σελίδων τους), ένα **«check this»** στα Pending Module Actions για κάθε λογαριασμό (guest, mail domain):
  - που υπάρχει ακόμα στον server ενώ η υπηρεσία του έχει τελειώσει στο MyCS (terminated ή cancelled): `stray_ended`
    («Check: ended in MyCS»), με το `service_id` της·
  - που τρέχει (όχι suspended/stopped) χωρίς υπηρεσία, χωρίς «internal», χωρίς να είναι template ή λογαριασμός πελάτη
    ενός reseller μας (αυτόν τον καλύπτει ο λογαριασμός του reseller): `stray_unmapped` («Check: not mapped»).
- **Ένα ανοιχτό ανά server και λογαριασμό**, όχι ένα ανά ανάγνωση: `subject` = `«server id»:«remote id»` (username,
  VMID, domain· αν δεν χωρά στους 191 χαρακτήρες, με το sha1 του). Ανοίγει με `given_up_at`, χωρίς Retry (ούτε από το
  MCP) και χωρίς mail ανά task. Κλείνει **μόνο του** όταν μια επόμενη ανάγνωση δεν το βρίσκει πια (ο λογαριασμός
  έφυγε, έγινε mapped, σημειώθηκε internal, η υπηρεσία ξαναέγινε active): `resolved`, «cleared: a later read no longer
  finds it». Αν από «ended» γίνει «not mapped» (σβήστηκε η υπηρεσία), κλείνει το ένα και ανοίγει το άλλο.
- Το staff το κλείνει με «Checked»: τότε **δεν ξανανοίγει** όσο ο λογαριασμός μένει όπως είναι· αν αλλάξει (φύγει, γίνει
  mapped) και ξαναγυρίσει, ανοίγει νέο.
- **Οι υπάρχοντες κανόνες μένουν:** διαφορά που την εξηγεί ανοιχτή ενέργεια του MyCS (suspend, unsuspend ή terminate
  της υπηρεσίας, αποτυχημένη ή «by hand») δεν είναι task, όπως στο `CpanelAccount::differs`· ούτε υπηρεσία που άλλαξε
  αφού ξεκίνησε η ανάγνωση (το κρίνει η επόμενη· ένα ανοιχτό μένει όπως είναι). **Μόνο tasks, καμία αυτόματη ενέργεια
  στον server:** τίποτα δεν τερματίζεται ή αναστέλλεται από εδώ (ο reference λογαριασμός cPanel, υπηρεσία #4 στον
  orion, δεν τερματίζεται ποτέ).
- **Πώς το υλοποιεί ένα module:** η sync του υλοποιεί το `App\Contracts\ReadsAccounts` (`accounts(Server)`: οι
  λογαριασμοί που άφησε η τελευταία ανάγνωση, χωρίς τους gone, ως `App\Services\Servers\ServerAccount`: remote id,
  label, running, mapped service id, internal) και, μέσα στο lock της ανάγνωσης, καλεί
  `StrayAccounts::afterRead($server, new self, $readAt)` με την ώρα που **ξεκίνησε** η ανάγνωση. Τα tasks τα φτιάχνει
  μόνο η κοινή `App\Services\Servers\StrayAccounts`. Σήμερα: cPanel (`Cpanel\AccountSync`), Proxmox
  (`Proxmox\GuestSync`), Mailcow (`Mailcow\DomainSync`). **Centova, DirectAdmin, NGM, Redfish** (και όποιο module
  αποκτήσει ανάγνωση λογαριασμών) απλώς υλοποιούν το ίδιο interface· τίποτα άλλο.
- **Domains:** η προειδοποίηση της registrar sync, ό,τι διάβασε και δεν εφάρμοσε (`DomainSyncService::plan` «_warning»:
  λήξη που θα πήγαινε πίσω, handle επαφής που διαφέρει), γίνεται το ίδιο είδος task για το domain: `domain_check`
  («Check: domain sync»), ένα ανοιχτό ανά domain με το τελευταίο κείμενο, που κλείνει με sync χωρίς προειδοποίηση. Μόνο
  στη sync που γράφει (όχι στο dry run, όχι μετά από write του staff), κάτω από το lock του registrar· όχι όσο είναι
  ανοιχτή ανανέωση, εγγραφή, μεταφορά ή αλλαγή επαφής του (εκείνη η γραμμή είναι ήδη του staff· ένα ανοιχτό
  `domain_check` τότε κλείνει με «cleared: an open task for the domain … explains it»). Το `sync_error` του domain μένει ως είχε.
- **Διαγραφή υπηρεσίας:** ένα ανοιχτό `stray_ended` την κρατά, όπως ο ανοιχτός τερματισμός και το «by hand»
  (`ModuleAction::openTaskKeeps`): με το cascade θα έφευγε μαζί της, και ένας λογαριασμός suspended ή VM σταματημένη
  δεν θα άνοιγε ξανά task ως «not mapped» (REV-STRAY-1). Το staff το κλείνει («Checked») και μετά τη διαγράφει.
- **Rollback report:** οι έλεγχοι (`ModuleAction::CHECKS`) δεν μπαίνουν στο «Server and module actions»· δεν έγινε
  τίποτα σε server για να περάσει στο WHMCS (REV-STRAY-2).
- **Οι έλεγχοι δεν είναι αποτυχίες** (απόφαση του owner 2026-11-08, μετά το review· `ModuleAction::scopeFailures`):
  τα `ModuleAction::CHECKS` δεν κάνουν WARN το Health `module_actions` (μετριούνται στο detail και στο `data`:
  `accounts_to_check`, `domains_to_check`), δεν κοκκινίζουν μέρα στο Cron calendar, δεν κάνουν «failed» μέρα στο
  CronSummary (άρα στο «failures only» δεν φεύγει mail γι' αυτά) και δεν μπαίνουν στο «Module actions left open» ούτε στο
  φίλτρο «Needs staff». Φαίνονται χωριστά: στο ημερήσιο summary μια γραμμή «N accounts to check» (και «N domains to
  check»), με τα ανοιχτά τη στιγμή που φεύγει· στο «Needs attention» του dashboard μια γκρι γραμμή, όχι αποτυχία, που
  ανοίγει τη λίστα με το φίλτρο «To check». Κανένα mail ανά task. Ο μετρητής «Pending Module Actions» του dashboard
  μετρά, όπως πριν, όλα τα ανοιχτά της λίστας.
- **Ανοιχτά για τον owner** (review 2026-11-08, NOTE-1, χωρίς αλλαγή κώδικα):
  - στο πρώτο deploy τα unmapped του WHMCS θα ανοίξουν πολλά checks μαζί (όχι αποτυχίες, βλ. πάνω)·
  - υπηρεσία που τερματίστηκε ενώ το TerminateJob περιμένει στην ουρά (χωρίς ModuleAction ακόμα) παίρνει `stray_ended`
    αν τύχει ανάγνωση εκείνη τη στιγμή· κλείνει στην επόμενη (το ίδιο κενό έχει το `CpanelAccount::differs`)·
  - tasks server που απενεργοποιήθηκε ή σβήστηκε δεν κλείνουν μόνα τους (δεν γίνεται ανάγνωση): τα κλείνει το staff.

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
  - **Γραμμή της παραγγελίας σε άλλο τιμολόγιο** (split από το #780· sweep 8 S8-LATEST-FIXES-2, REV-793-2/-3/-4,
    ιδιοκτήτης 2026-11-11): ένας κανόνας για το πού ζουν οι γραμμές. Αν γραμμή της **πρώτης χρέωσης** της παραγγελίας
    (η παλαιότερη γραμμή κάθε υπηρεσίας ή domain της: αυτή που έγραψε η παραγγελία) ζει σε άλλο τιμολόγιο που δεν είναι
    cancelled ούτε refunded, το «Cancel & Refund» **αρνείται ολόκληρο**, πριν ακυρωθεί ή επιστραφεί οτιδήποτε, και λέει
    ποιο τιμολόγιο. Πιάνει split, split ενός split και κομμάτι που έγινε merge αλλού (split και merge μετακινούν τις
    ίδιες τις γραμμές). Οι ανανεώσεις των ίδιων υπηρεσιών είναι νεότερες γραμμές και δεν το εμποδίζουν· κομμάτι που
    ακυρώθηκε ή επιστράφηκε ολόκληρο επίσης όχι. Το staff επιστρέφει κάθε τιμολόγιο από τη σελίδα του και ακυρώνει με
    «Cancel Order». Δεν υπάρχει επιστροφή σε πολλά τιμολόγια (`CancelOrder::movedParts`· το λέει και το παράθυρο
    επιβεβαίωσης). Παραγγελία της οποίας το τιμολόγιο δεν υπάρχει πια (εισαγόμενη, το έσβησε το WHMCS) δεν αρνείται
    ποτέ για αυτό (REV-793-R2-1). Γνωστό και αποδεκτό: εισαγόμενη παραγγελία που το τιμολόγιό της υπάρχει αλλά η γραμμή
    του δεν δείχνει την υπηρεσία (χωρίς relid) μπορεί να αρνηθεί χωρίς λόγο· η άρνηση απλώς στέλνει το staff να το κάνει
    με το χέρι.
  - **Domain της παραγγελίας που έχει ήδη καταχωρηθεί** (ούτε pending ούτε τελικό· sweep 8 S8-DOMAINS-1, ιδιοκτήτης
    2026-11-11): η επιστροφή γίνεται, αλλά **δεν αναιρεί την καταχώρηση**. Ο registrar δεν καλείται και η κατάσταση
    του domain δεν αλλάζει. Το παράθυρο επιβεβαίωσης προειδοποιεί. **Μόνο αν γύρισαν πράγματι χρήματα** του τιμολογίου της, με αυτό το κλικ ή νωρίτερα (REV-793-R2-4· REV-793-6: όχι
    σε απλήρωτη παραγγελία, άρνηση ή αποτυχία του gateway) το staff παίρνει ειδοποίηση «Left for staff» και εργασία
    «Check: registered domain refunded» στα Pending Module Actions (`ModuleAction::DOMAIN_REFUNDED`, «check this»: δεν
    είναι αποτυχία, κλείνει μόνο με «Checked», **μία ανοιχτή ανά domain**, REV-793-7), που λέει ποιο domain να κρατήσει
    (και να χρεώσει) ή να σβήσει στον registrar με το χέρι.
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
  Το create του DirectAdmin κρατά username (`directadmin_pending_username`) και κωδικό **πριν** τη ζήτηση, όπως το
  cPanel: ένα retry μετά από χαμένη απάντηση ζητά τον ίδιο λογαριασμό και τον υιοθετεί αν υπάρχει με το ίδιο domain
  (sweep2 F-PROVISIONING-OPS-3). Μόνο μια ρητή άρνηση στην πρώτη προσπάθεια τα σβήνει.
  **cPanel, του ποιου είναι ο λογαριασμός** (sweep 7, S7-PROVISIONING-1 και review του #777): πριν από suspend, unsuspend,
  terminate, «Login to cPanel», «Login to Webmail», το WHM ενός reseller και αλλαγή κωδικού ρωτά το WHM (`accountsummary`)
  και αρνείται αν ο λογαριασμός με αυτό το username έχει άλλο κύριο domain από της υπηρεσίας (username που έμεινε από το
  WHMCS ή ξαναδόθηκε σε άλλον). Το ίδιο domain γραμμένο αλλιώς είναι το ίδιο (`CPanel::normalDomain`: πεζά, χωρίς τελεία
  στο τέλος, χωρίς `www.`, IDN σε punycode). Υπηρεσία χωρίς κανένα domain δεν ελέγχεται (τίποτα να συγκριθεί)· μένει ο
  έλεγχος «άλλη ζωντανή υπηρεσία με τον ίδιο λογαριασμό», που πια ισχύει και στο unsuspend. Η άρνηση μένει στο staff όπως
  κάθε αποτυχία module. Ποιες υπηρεσίες θα αρνηθεί: `php artisan cpanel:sync --domains` (μόνο ids)· το staff διορθώνει το
  domain ή το username της υπηρεσίας. Δεν ελέγχονται (ακόμα): νέο mailbox, usage, λίστα domains.
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
Τι γράφει το welcome email ανά module (διευθύνσεις panel, nameservers, SSH) και ποιο module δίνει τι: `templates.md`.
Ένα νέο module που έχει δική του διεύθυνση για τον πελάτη δίνει `panel_url` από το `welcomeData` του (cPanel, ngm).
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
| ngm | ναι (`POST /accounts/{user}/password`: panel και SSH/SFTP) | ναι | ναι |
| Offline VM / Dedicated (Hetzner) | όχι (χωρίς API) | ναι | ναι, αν έχει username/κωδικό |
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
- **ngm** (έγινε, 2026-11-04): το extension `Ngm` αλλάζει τον κωδικό του account (panel και SSH/SFTP), ποτέ των
  mailboxes ή των FTP accounts. Η πολιτική κωδικών του ngm (`security.password_policy`) απαντά 400 με τον λόγο, που τον
  βλέπει ο πελάτης (`ServerRefused`).

## ngm (2026-11-04)

Το δικό μας panel (ngm.myip.gr, ngm2, ngm-debian). Το extension `Ngm` (`extensions/Servers/Ngm`) κάνει ό,τι και το
WHMCS module μας (`modules/servers/ngm` στο rigel), πάνω στο ίδιο `/api/v1` (`docs/api-whmcs.md` του ngm):

- **Server:** `host` (https και port 9601, εκτός αν δοθούν: `https://host:port`, `http://` για lab) και `token`
  (encrypted). Το «Test connection» είναι `GET /ping`.
- **Προϊόν:** `package` (όνομα πακέτου στο ngm, κενό = κανένα) και `php` (της κύριας σελίδας, κενό = του host).
- **Create:** `POST /accounts` με username, κωδικό, πακέτο, domain (κύρια σελίδα) και php. Το username είναι της
  υπηρεσίας (καθαρισμένο όπως στο WHMCS: `^[a-z][a-z0-9_]{0,31}$`), αλλιώς από το domain, με ψηφίο όταν είναι πιασμένο
  στο ngm ή σε άλλη υπηρεσία. Το όνομα και ο κωδικός φυλάγονται πριν από την κλήση. Ένα 409 μετά από χαμένη απάντηση
  είναι δικό μας account μόνο αν ισχύουν όλα:
  - το είχαμε ζητήσει·
  - δεν το έχει άλλη υπηρεσία·
  - το ngm το έχει με αυτό ακριβώς το όνομα (ο έλεγχος «own retry» του WHMCS)·
  - το `created_at` του δεν είναι πριν από το αίτημά μας (με 5 λεπτά ανοχή για τα ρολόγια). Έτσι δεν υιοθετείται ποτέ
    account που έγινε με το χέρι στο μεσοδιάστημα (review του #630).

  Τότε υιοθετείται, με αναφορά για το staff, γιατί το ngm απαντά 409 και σε account που έμεινε μισό. Σε κάθε άλλο 409 το
  όνομα εγκαταλείπεται για το επόμενο ελεύθερο: τα ονόματα βάσεων του ngm μπορεί να συγκρούονται ενώ το όνομα του account
  είναι ελεύθερο. Υπηρεσία suspended πριν από το create παίρνει `enabled: false`. Μια pending παίρνει `true`, γιατί στη
  MyCS το create γίνεται πάντα πριν η υπηρεσία γίνει active.
- **Suspend** (με το `suspend_reason`), **unsuspend**, **change package**, **password**, **SSO** (`POST …/sso`),
  **usage** (`GET /usage`, ένα αίτημα ανά server). Πρώτα ελέγχεται ότι το account υπάρχει με αυτό το όνομα και ότι δεν
  το έχει άλλη ζωντανή υπηρεσία. Σε create με χαμένη απάντηση (όνομα ζητήθηκε, κανένα δεν κρατήθηκε) κάθε ενέργεια
  αποτυγχάνει ονομάζοντας το account, για να το ελέγξει το staff στο ngm.
- **SSO:** ο πελάτης παίρνει το εισιτήριο του πελάτη, και ένα suspended account δεν ανοίγει. Το «Log in to panel» του
  staff στέλνει `{"admin": true}` (όπως το AdminSingleSignOn του WHMCS): impersonation με banner και audit στο ngm, και
  ανοίγει και suspended account. Το panel είναι στο ίδιο host:port με το API (`https://host:9601/ui/`).
- **Terminate:** `DELETE /accounts/{user}`. Το ngm κρατά τα backups και το username μένει στην υπηρεσία. Αν το account
  λείπει ήδη, η ενέργεια θεωρείται έγινε μόνο για account που έφτιαξε η MyCS. Για ένα εισαγόμενο είναι εργασία του staff.
- **Αποτυχίες:** μένουν ανοιχτές στα Pending Module Actions με τον λόγο του ngm. Τα 400/409/422 είναι `ServerRefused`
  (ο λόγος του ngm φαίνεται στον πελάτη). Τα 401/403 (token, scopes, `api.account_writes`), 5xx και timeouts είναι
  μόνο για το staff, χωρίς ποτέ το token. Τα `warning`/`warnings` του ngm (π.χ. η κύρια σελίδα δεν στήθηκε) πάνε στο
  `report()`, και η ενέργεια μετρά ως έγινε.
- **Δεν πέρασαν ακόμα** (τα έχει το WHMCS module, ή η νεότερη έκδοσή του στο `chrismfz/ngm`): οι καρτέλες του
  πελάτη (domains, mailboxes, βάσεις), η λίστα πακέτων από το `GET /packages` στη
  ρύθμιση του προϊόντος, τα όρια ανά account (`/limits`), η μεταφορά του token από το WHMCS (`accesshash`).

## Μηχανήματα με BMC: Redfish (υπεύθυνος, 2026-11-11)

Πρώτο βήμα του «extension Redfish» του ROADMAP: **μόνο ανάγνωση**, για να δοκιμαστεί σε ένα Supermicro.

- **Ένα μηχάνημα = ένας server** (Servers → Create server, module «Redfish»). Δεν υπάρχει ξεχωριστό «inventory».
  - **Δοσμένο σε πελάτη:** η υπηρεσία που έχει αυτόν τον server (`services.server_id`), όπως σε κάθε module.
  - **Ελεύθερο:** «Max Accounts» 1 και καμία υπηρεσία πάνω του. Μέσα σε server group, μια παραγγελία παίρνει το πρώτο
    ελεύθερο (`ServerSelector`)· όταν τελειώσουν, το create αποτυγχάνει και το βλέπει το staff.
  - **Δικό μας:** «Max Accounts» **0**: δεν δίνεται ποτέ σε παραγγελία. Το 0 ισχύει πια για κάθε server (πριν η φόρμα
    δεχόταν από 1· κενό = χωρίς όριο, όπως πριν).
    Προσοχή: ο importer γράφει το «maxaccounts» του WHMCS σε κάθε εκτέλεση, και το 0 του WHMCS σημαίνει «χωρίς
    όριο»: ένα 0 που έβαλε το staff σε **εισαγμένο** server χάνεται στην επόμενη εκτέλεση. Οι servers Redfish δεν
    εισάγονται, άρα δεν τους αφορά.
- **Ρυθμίσεις του server:** διεύθυνση του BMC (`https://…`, στο management δίκτυο), IP και «Self-signed» όπως στα
  panels (`PanelConnection`: μόνο https, self-signed μόνο προς γνωστή IP, ποτέ redirect), username, κωδικός
  (κρυπτογραφημένος), «Location» (ελεύθερο κείμενο).
- **Λογαριασμός στο BMC: μόνο ανάγνωση.** Το MyCS κάνει μόνο GET· δεν χρειάζεται και δεν πρέπει να έχει Operator ή
  Administrator όσο δεν υπάρχουν ενέργειες. Το BMC δεν βγαίνει ποτέ δημόσια.
- **Τι διαβάζει** (`packages/redfish`, `RedfishClient::machine()`, 3–5 αιτήματα): κατασκευαστή, μοντέλο, serial, SKU,
  asset tag, hostname, BIOS, επεξεργαστές, μνήμη, **power state**, **health** (το χειρότερο από system και chassis: εκεί
  φαίνονται ανεμιστήρες και τροφοδοτικά) και **watt**: την τωρινή κατανάλωση (`Chassis/…/Power` → το πρώτο
  `PowerControl`, που είναι το σύνολο του μηχανήματος· αλλιώς `EnvironmentMetrics`) και, όπου τα δίνει το BMC, ελάχιστο/μέσο/μέγιστο του τελευταίου
  διαστήματός του.
- **«Test Connection» και «Hardware»** (σελίδα του server): ζωντανή ανάγνωση της σύνοψης, μόνο όταν το ζητήσει το
  staff. Το «Hardware» έχει link προς τη σελίδα του μηχανήματος στο Utilities.
- **Utilities → Machines** (υπεύθυνος, 2026-11-11): μία γραμμή ανά μηχάνημα και σελίδα για το καθένα.
  - `redfish:sync` **κάθε ώρα** (και «Read now» / «Read all now») διαβάζει κάθε ενεργό server Redfish **με τα
    εξαρτήματά του**: ανεμιστήρες και θερμοκρασίες (`Chassis/…/Thermal`), τροφοδοτικά, επεξεργαστές, DIMM, δίσκους
    (όσους βλέπει το BMC μέσω controller· δίσκοι σε onboard SATA με software RAID συχνά δεν φαίνονται). Δεκάδες
    αιτήματα ανά μηχάνημα, με όριο χρόνου (60″ στο cron, 20″ στο «Read now»)· αν δεν προλάβει, η σελίδα το λέει.
  - Κρατιέται **μόνο η τελευταία ανάγνωση** (`redfish_machines`, μία γραμμή ανά server): όχι ιστορικό, όχι kWh. Η
    λίστα και η σελίδα **δεν καλούν ποτέ BMC**· το ίδιο θα ισχύει αν αργότερα δείξουμε κάτι στον πελάτη.
  - Λίστα: ποιος το έχει (υπηρεσία πελάτη, «Free», «Our own»), power, health, watt, θερμοκρασία εισόδου, «Look at»,
    πότε διαβάστηκε. Φίλτρο «Needs a look».
  - **«Look at»:** εξάρτημα με health όχι OK, δίσκος που προβλέπει βλάβη ή με κάτω από 10% ζωή, θερμοκρασία στο όριο
    προειδοποίησής της. «Needs a look» είναι επίσης: health όχι OK, ανάγνωση που απέτυχε, ή παλιότερη από 3 ώρες.
  - **Μόνο ένδειξη** (απόφαση 2026-11-11, «για αρχή»): ούτε εργασία ούτε mail. Τα alerts είναι του monitoring.
  - BMC που δεν απαντά: η γραμμή κρατά την προηγούμενη ανάγνωση, με το γιατί και το πότε απέτυχε.
  - Τιμές-γέμισμα του firmware («To be filled by O.E.M.», «Default string», «N/A») δεν δείχνονται. Το μοντέλο του
    επεξεργαστή είναι του ίδιου του CPU, όχι η «οικογένεια» της σύνοψης.
  - Δικαιώματα: των servers (`admin.servers.viewAny` για να δει, `admin.servers.update` για «Read now»).
- **Ενέργειες υπηρεσίας** (create, suspend, unsuspend, terminate, αλλαγή πακέτου): εργασίες του staff στα Pending Module
  Actions, όπως στο Offline Dedicated (`OfflineMachineServer`). Το module δεν αγγίζει το μηχάνημα.
- **Στην υπηρεσία** μένουν δύο πεδία, «Operating system» και «Notes», με τα ίδια κλειδιά του Offline Dedicated: μια
  υπηρεσία που αλλάζει server από «Manual (offline)» σε Redfish κρατά ό,τι είχε γράψει το staff (και τα υπόλοιπα
  `machine_*` μένουν στη βάση, απλώς δεν φαίνονται).
- **Χρόνος:** κάθε ανάγνωση περιμένει ως 8 δευτερόλεπτα· BMC που δεν απαντά δίνει μήνυμα στο staff, όχι timeout της
  σελίδας. Ένα redirect του BMC δεν ακολουθείται· ζητείται μία φορά το ίδιο path με ή χωρίς το τελικό «/».
- **Ό,τι λέει ένα BMC είναι ξένο κείμενο:** κόβεται στους 200 χαρακτήρες, χωρίς control characters, και δείχνεται πάντα
  escaped. Ένα link του BMC ακολουθείται μόνο ως path κάτω από `/redfish/v1/` του ίδιου BMC.
- **Δεν έγιναν** (επόμενα βήματα του ROADMAP, το καθένα με απόφαση): event log του BMC, κάρτες δικτύου, κατάσταση RAID
  (volumes), εργασία ή mail όταν κάτι δεν είναι OK, κατανάλωση σε βάθος χρόνου, power on/off, suspend από θύρα ή BMC, console, ο,τιδήποτε προς τον πελάτη.
- **Δοκιμή σε πραγματικό BMC:** η σύνοψη διαβάστηκε από ένα Supermicro (2026-11-11, από τον υπεύθυνο)· τα εξαρτήματα
  όχι ακόμα, και iDRAC καθόλου. Τα tests απαντούν με ψεύτικο BMC (`Http::preventStrayRequests`). Τα
  παλιά firmware (Supermicro X10, iDRAC 7/8) έχουν ελλιπές Redfish: το «Test Connection» λέει τι λείπει.

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
  **Τι δείχνει και τι στέλνει (2026-11-11, έλεγχος απέναντι στο module του WHMCS):**

  | Πεδίο | Πού το κρατάμε | Πελάτης | Welcome email |
  |---|---|---|---|
  | Username | `services.username`, `centova_username` | ναι | `service.username` |
  | Κωδικός διαχείρισης | `services.password` (κρυπτογραφημένος· στο Centova είναι hash) | αλλαγή μόνο | `service_password` |
  | Κωδικός πηγής (encoder) | **πουθενά**: διαβάζεται από το Centova (`server.getaccount`) όταν δείχνεται | ναι, όποιος διαχειρίζεται την υπηρεσία | `centova_source_password` |
  | Stream host:port | host του server, `centova_port` | ναι | `centova_host`, `centova_port` |
  | Τύπος server, AutoDJ | `centova_servertype`, `centova_sourcetype` | ναι | όχι |
  | Όρια (ακροατές, bitrate, χώρος, μεταφορά) | του template στο Centova | χώρος και μεταφορά μέσω χρήσης | όχι |

  Ο κωδικός πηγής δεν αποθηκεύεται (απόφαση ιδιοκτήτη): ο πελάτης τον αλλάζει στο panel και ένα αντίγραφο εδώ θα έμενε
  λάθος χωρίς να φαίνεται. Η σελίδα της υπηρεσίας και το welcome email κάνουν ένα `getaccount` (timeout 8 δευτ.)· με την
  ίδια απάντηση διορθώνονται port, τύπος και AutoDJ που κρατά η υπηρεσία (κενά ή λάθος από το import). Αν το Centova δεν
  απαντά, ή αν άλλη ζωντανή υπηρεσία εδώ έχει το ίδιο username, δείχνεται μόνο ό,τι κρατά η υπηρεσία, χωρίς κωδικό πηγής.
  Γνωστό και αποδεκτό (code review του #786): και το κουμπί «Πίνακας Centova Cast» περνά από το ίδιο `getaccount`, άρα
  με το Centova κάτω περιμένει ως το timeout πριν ανοίξει τη σελίδα σύνδεσης (που είναι επίσης κάτω).
  Ελέγχθηκε στον stream (Centova 3.3.2, μόνο ανάγνωση): το `getaccount` δίνει `sourcepassword`, `servertype`, `apptypes`
  (το AutoDJ), `port` και τα όρια. **Δεν κάναμε** (υπάρχουν στο panel του Centova, 9 σταθμοί): κατάσταση server/AutoDJ
  στη σελίδα, ακροατές/bitrate, κουμπιά Start/Stop/Restart για το staff. Το staff βλέπει τα properties της υπηρεσίας· τον
  κωδικό πηγής τον βλέπει στο Centova.
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
