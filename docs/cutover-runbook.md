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
| Το API του WHMCS | `--whmcs-api-url=https://clients.nixpal.com/` και `WHMCS_API_IDENTIFIER` / `WHMCS_API_SECRET` στο περιβάλλον (ποτέ ως όρισμα) | τις αποθηκευμένες μεθόδους πληρωμής (tokens Stripe και PayPal vault), τους κωδικούς των υπηρεσιών (`DecryptPassword`) | μέθοδοι 66/66· κωδικοί υπηρεσιών: ήρθαν όλοι όσοι υπάρχουν (29: τα 11 cPanel, 17 άδειες, 1 άλλη)· οι άλλες 328 άδειες έχουν κενό κωδικό στο WHMCS (κρυπτογραφημένο, άρα το πεδίο δεν είναι άδειο). Η γραμμή του importer τους μετρά πια ως «empty in WHMCS» (#600). Μένουν χωρίς κωδικό, οπότε σε κάθε επανάληψη του import ξαναρωτιούνται: «0 of 328 imported, 328 empty» δεν είναι regression |
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
- **Υπενθυμίσεις της ημέρας:** ως σταλμένες μετρούν όσες έστειλε το WHMCS ως το τελευταίο **ολοκληρωμένο** ημερήσιο
  cron του (`lastDailyCronEndTime`). Ένα import πριν από τις 09:00 της ημέρας αφήνει τις υπενθυμίσεις εκείνης της ημέρας
  στο cron του MyCS, που τις στέλνει μία φορά. Μέχρι τώρα μετρούσαν ως σταλμένες και δεν τις έστελνε κανείς. Το replay
  της 3/11 το έδειξε: 4 «Second Invoice Overdue Notice» της nixpal. Η γραμμή του importer γράφει ως ποια μέρα μέτρησε
  («up to its daily run of …»).
  - **Προϋπόθεση:** το cron του WHMCS κλείνει **πριν** από το τελικό dump, ή τουλάχιστον πριν από την ημερήσια ώρα του
    (09:00). Αλλιώς ένα dump των 02:21 και ένα WHMCS που τρέχει στις 09:00 στέλνουν τις υπενθυμίσεις της ημέρας δύο
    φορές.
  - **Dump την ώρα του cron:** το ημερήσιο cron της nixpal κρατά περίπου 35 s. Αν στο dump η ημερομηνία του
    `lastDailyCronInvocationTime` είναι μεταγενέστερη από του `lastDailyCronEndTime`, το cron είχε ξεκινήσει και δεν είχε
    τελειώσει. Τότε το dump ξαναπαίρνεται, αλλιώς οι υπενθυμίσεις εκείνου του πρωινού φεύγουν ξανά.

## 2. Τι κλείνει στο WHMCS την ίδια ώρα
Αλλιώς διπλές ανανεώσεις, διπλές καταγραφές πληρωμών ή αντιφατικά suspend.
- **Το cron του WHMCS.**
- **Stripe:** τα endpoints του WHMCS στον ίδιο λογαριασμό Stripe (στο myip: δύο,
  `myip.gr/clients/modules/gateways/callback/stripe.php`), απενεργοποίηση ή διαγραφή.
- **PayPal:** τα webhooks και το IPN του WHMCS (το app του WHMCS). Στο app του MyCS μένει μόνο το δικό του webhook
  (ένα webhook ανά app, επιβεβαίωσε ο υπεύθυνος στις 3/11). Τα 400 της 2–3/11 **δεν** ήταν δεύτερο app: στο access
  log του nginx είναι όλα retries (`PayPal/AUHR`) δύο events της πρώτης πληρωμής (2/11 14:36:05 και 14:37:09,
  `PayPal/AUHD`), που απέτυχαν στον έλεγχο υπογραφής. Οι επόμενες πρώτες αποστολές πέρασαν όλες. Αιτία: ο έλεγχος
  έστελνε στο PayPal το event ξαναγραμμένο από `json_decode`/encode και όχι αυτούσιο (ένα `{}` γινόταν `[]`). Από το
  #601 το στέλνει όπως ήρθε. **Μετά το deploy:** η επόμενη πληρωμή PayPal με redirect να μη δώσει κανένα 400
  `PayPal/AUHD` στο access log. Τα retries των δύο παλιών (PayPal τα ξαναστέλνει ως 3 μέρες) μπορεί να περάσουν τώρα,
  και αγνοούνται (`CHECKOUT.ORDER.*`).
- **Registrars:** hard cut (υπεύθυνος, 2026-11-03). Την ημέρα που μπαίνει live το MyCS κλείνουν τα crons του WHMCS,
  οπότε ανανεώνει μόνο το MyCS.
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
