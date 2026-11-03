# Runbook cut-over (ROADMAP 10): ό,τι δεν πρέπει να ξεχαστεί

Ξεκίνησε 2026-11-03, από τις πρόβες της nixpal (import 3/11, parity ανά εγγραφή, replay, δοκιμές live στο
mycs.myip.gr). Το πλήρες runbook (πάγωμα, import και parity, DNS/webhooks, χάρτης 301, ενημέρωση πελατών, έλεγχοι πρώτης
μέρας, επιστροφή) χτίζεται εδώ. Ό,τι λείπει ακόμα σημειώνεται ως **ανοιχτό**.

## 1. Το τελικό import έρχεται με όλα (υπεύθυνος, 2026-11-03)
Οι πρόβες τρέχουν **χωρίς** logins, για να μην μπορεί μια scratch βάση να τρέξει κατά λάθος cron ή queue με ζωντανά
credentials. Το import της ημέρας τα φέρνει όλα:

| Τι | Πώς | Τι φέρνει | Πρόβα 3/11 |
|---|---|---|---|
| Logins servers, registrars, gateways, SMTP | `--with-logins` | το token του WHM, τα logins των cnic/openprovider, τα κλειδιά των gateways, το SMTP της εταιρείας | χωρίς (κατά πρόθεση) |
| Το κλειδί του WHMCS | `WHMCS_CC_ENCRYPTION_HASH` στο `.env` (από το `configuration.php` του WHMCS) | το 2FA των logins, τις ρυθμίσεις των gateways όπου διαβάζονται | 2FA: 1 login, πέρασε στις 31/10 |
| Το API του WHMCS | `--whmcs-api-url=https://clients.nixpal.com/` και `WHMCS_API_IDENTIFIER` / `WHMCS_API_SECRET` στο περιβάλλον (ποτέ ως όρισμα) | τις αποθηκευμένες μεθόδους πληρωμής (tokens Stripe και PayPal vault), τους κωδικούς των υπηρεσιών (`DecryptPassword`) | μέθοδοι 66/66· κωδικοί υπηρεσιών **29 από 357**: **ανοιχτό**, να εξηγηθεί πριν το cut-over |
| Τα attachments των tickets | `--ticket-attachments=` (αντίγραφο του φακέλου) | τα αρχεία των tickets | — |

- **Κωδικοί πελατών και admins:** έρχονται αυτούσιοι. Στη nixpal και οι 247 είναι bcrypt (`$2y$`), και το MyCS τους
  δέχεται όπως είναι. Στην πρώτη σύνδεση τους ξαναγράφει με το δικό του cost. Δεν χρειάζεται reset, και η ανακοίνωση το
  λέει σωστά («passwords all move over»).
- **Μετά το import, από το staff:**
  - **Registrar connections:** έρχονται ανενεργές και ενεργοποιούνται.
  - **Gateways:** ενεργοποιούνται.
  - **Stripe:** Save στο gateway, που φτιάχνει το webhook. Η γραμμή «Webhook» πρέπει να γράφει ✓ (#586).
  - **PayPal:** το `webhook_id` του app της εταιρείας.
  - **Cardlink:** τα URLs return/notify στο vPOS της Eurobank.
- **Γίνονται ήδη από τον importer:** οι «Free Account» μπαίνουν σε plan με 0,00, χωρίς λήξη και με κλειδωμένη τιμή
  (#594). Η ρύθμιση `credits_on_downgrade` έρχεται από το WHMCS (#596).

## 2. Τι κλείνει στο WHMCS την ίδια ώρα
Αλλιώς διπλές ανανεώσεις, διπλές καταγραφές πληρωμών ή αντιφατικά suspend.
- **Το cron του WHMCS.**
- **Stripe:** τα endpoints του WHMCS στον ίδιο λογαριασμό Stripe (στο myip: δύο,
  `myip.gr/clients/modules/gateways/callback/stripe.php`), απενεργοποίηση ή διαγραφή.
- **PayPal:** τα webhooks και το IPN του WHMCS (το app του WHMCS). Στο app του MyCS μένει μόνο το δικό του webhook.
  Στις δοκιμές της 2–3/11 έφταναν events με λάθος υπογραφή, κατά πάσα πιθανότητα από δεύτερο app προς το ίδιο URL:
  **ανοιχτό**, έλεγχος στο PayPal Developer.
- **Registrars:** ό,τι ανανεώνει στη θέση του WHMCS (modules, auto-renew), ώστε να ανανεώνει μόνο το MyCS: **ανοιχτό**,
  έλεγχος ανά registrar.
- **Mail:** το piping/POP του WHMCS για τα tickets, ώστε να τα διαβάζει μόνο το MyCS.

## 3. Καθαρισμός όταν τελειώσουν οι δοκιμές
- **Κλειδιά και δικαιώματα:**
  - Το API κλειδί του WHMCS της nixpal πρέπει να αλλάξει: τυπώθηκε σε log session στις 3/11.
  - Αφαιρούνται η IP 84.54.49.31 από το API και ο ρόλος `DecryptPassword`.
- **Αρχεία με μυστικά στον devbox:** `/root/nixpal-api.txt` και `/root/nixpal-cc-hash.txt`.
- **Scratch βάσεις:** `mycs_import_nixpal*` και `whmcs_copy_nixpal*` (ένα import με `--with-logins` κρατά ζωντανά
  credentials), καθώς και τα dumps στο `/root/db`.
- **Δοκιμαστικά του mycs.myip.gr:**
  - τα προϊόντα #45–#47 και οι υπηρεσίες #12–#14,
  - το webhook «MyCS Stripe Webhook»,
  - τα gateways σε live κλειδιά, που κλείνουν.
