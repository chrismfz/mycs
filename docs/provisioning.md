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
- `pending` στη δημιουργία (default της στήλης· το `PlaceOrderService` δεν αλλάζει). Ακολουθεί τις υπηρεσίες της
  (`Order::refreshStatus`, από το `ServiceObserver`): `active` όταν καμία δεν μένει `pending`, `cancelled` όταν όλες
  τελείωσαν (π.χ. απλήρωτη, από το cron). Το status που έβαλε το staff δεν αναιρείται.
- **Accept Order:** `active`, `accepted_at/by`· κάθε `pending` υπηρεσία στήνεται, πληρωμένη ή όχι (απόφαση 4).
  «Run module create»: με server → CreateJob (active όταν υπάρξει ο λογαριασμός)· χωρίς, ή χωρίς create (στήθηκε με το
  χέρι) → active τώρα. «Send welcome email». Οι ημερομηνίες ακολουθούν το `datesOnStart`.
- **Cancel Order:** οι υπηρεσίες γίνονται `cancelled` (stock πίσω, pending upgrades αποσύρονται), οι γραμμές τους στα
  ανοιχτά τιμολόγια ακυρώνονται (`CancelOpenInvoices`, το credit επιστρέφει). Οι λογαριασμοί που στήθηκαν μένουν, εκτός
  αν το staff τσεκάρει «Terminate accounts». Τα πληρωμένα τιμολόγια μένουν.
- **Cancel & Refund:** όπως το Cancel, και κάθε πληρωμή τιμολογίου που χρεώνει **μόνο** αυτή την παραγγελία επιστρέφει
  (gateway / με το χέρι / σε credit· ό,τι πληρώθηκε με credit γυρνά σε credit). Τιμολόγιο που χρεώνει και κάτι άλλο:
  ειδοποίηση στο staff. Θέλει και το `admin.invoice_transactions.update`.
- **Set as Fraud:** όπως το Cancel, status `fraud` (η εκτίμηση απάτης δεν υπάρχει, Δ4). **Set back to Pending:** μόνο
  το status.
- Pending Orders: tab της λίστας (`?activeTab=pending`), στο top menu (Orders), badge και μετρητής στο dashboard.
