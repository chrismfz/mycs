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
- Admin: Services → Pending Module Actions (top menu: Utilities), Retry (τρέχει το ίδιο job τώρα, με το mail του) και
  Mark resolved· δικαιώματα των υπηρεσιών (`admin.services.viewAny` / `update`).
- Μένει: domains (RegisterDomain/RenewDomain του Openprovider/FORTH) με τα domains· τα «πληρωμένα που δεν εφαρμόστηκαν»
  του BIL-9 στην ίδια λίστα.
