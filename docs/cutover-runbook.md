# Runbook cut-over (ROADMAP 10): ό,τι δεν πρέπει να ξεχαστεί

Ξεκίνησε 2026-11-03, από τις πρόβες της nixpal (import 3/11, parity ανά εγγραφή, replay, δοκιμές live στο
mycs.myip.gr). Το πλήρες runbook (πάγωμα, import και parity, DNS/webhooks, χάρτης 301, ενημέρωση πελατών, έλεγχοι πρώτης
μέρας, επιστροφή στο §3) χτίζεται εδώ. Ό,τι λείπει ακόμα σημειώνεται ως **ανοιχτό**.

## 1. Το τελικό import έρχεται με όλα (υπεύθυνος, 2026-11-03)
Οι πρόβες τρέχουν **χωρίς** logins, για να μην μπορεί μια scratch βάση να τρέξει κατά λάθος cron ή queue με ζωντανά
credentials. Το import της ημέρας τα φέρνει όλα:

| Τι | Πώς | Τι φέρνει | Πρόβα 3/11 |
|---|---|---|---|
| Logins servers, registrars, gateways, SMTP | `--with-logins` | το token του WHM, τα logins των cnic/openprovider, τα κλειδιά των gateways, το SMTP της εταιρείας | χωρίς (κατά πρόθεση) |
| Το κλειδί του WHMCS | `WHMCS_CC_ENCRYPTION_HASH` στο `.env` (από το `configuration.php` του WHMCS) | το 2FA των logins, τις ρυθμίσεις των gateways όπου διαβάζονται | 2FA: 1 login, πέρασε στις 31/10 |
| Το API του WHMCS | `--whmcs-api-url=https://clients.nixpal.com/` και `WHMCS_API_IDENTIFIER` / `WHMCS_API_SECRET` στο περιβάλλον (ποτέ ως όρισμα) | τις αποθηκευμένες μεθόδους πληρωμής (tokens Stripe και PayPal vault), τους κωδικούς των υπηρεσιών (`DecryptPassword`) | μέθοδοι 66/66· κωδικοί υπηρεσιών: ήρθαν όλοι όσοι υπάρχουν (29: τα 11 cPanel, 17 άδειες, 1 άλλη)· οι άλλες 328 άδειες έχουν κενό κωδικό στο WHMCS (κρυπτογραφημένο, άρα το πεδίο δεν είναι άδειο). Η γραμμή του importer τους μετρά πια ως «empty in WHMCS» (#600). Μένουν χωρίς κωδικό, οπότε σε κάθε επανάληψη του import ξαναρωτιούνται: «0 of 328 imported, 328 empty» δεν είναι regression |
| Τα attachments των tickets | `--ticket-attachments=` μία φορά ανά φάκελο (αντίγραφα): ο φάκελος του Storage του WHMCS και ό,τι χρησιμοποιούσε πριν. myip: `/home/myipgr/whmcs` (μόνο τα αρχεία `NNNNNN_*` της ρίζας) **και** `/home/myipgr/whmcs/attachments`· nixpal: `/home/clnixpal/files/attachments`. Η γραμμή «WHMCS keeps ticket files in …» του import λέει τον τωρινό | τα αρχεία των tickets | myip 5/11 (WG-21): 4.110 ήρθαν, 44 απορρίφθηκαν από τους κανόνες, 4 λείπουν |

- **Κωδικοί πελατών και admins:** έρχονται αυτούσιοι. Στη nixpal και οι 247 είναι bcrypt (`$2y$`), και το MyCS τους
  δέχεται όπως είναι. Στην πρώτη σύνδεση τους ξαναγράφει με το δικό του cost. Δεν χρειάζεται reset, και η ανακοίνωση το
  λέει σωστά («passwords all move over»).
- **Το cron κατά το import (sweep F-CUTOVER-DAY-1/2):** όσο τρέχει το import (και μετά από ένα που απέτυχε) το cron δεν
  κάνει τίποτα (`whmcs_import_running_at`, το σβήνει το τέλος του import). Μετά το import το hourly catch-up δεν ξεκινά
  run πάνω σε βάση χωρίς δικό της run: το πρώτο είναι στην ώρα του (`cronjob_time`), άρα τα βήματα «πριν από το πρώτο
  cron» έχουν ως τότε. Το timezone, το URL, το όνομα, οι γλώσσες και το νόμισμα του `mycs:install` κρατιούνται πάνω από
  το reset της βάσης.
- **Το import δεν ξανατρέχει μετά το go-live (sweep 11, S11-IMPORT-2· review του #811):**
  - **Από τη στιγμή που το site ανοίγει στους πελάτες το import δεν ξανατρέχει, με ή χωρίς flag.** Ο φρουρός του κώδικα
    προστατεύει μόνο **από το πρώτο daily cron και μετά**: τότε το `app:import-from-whmcs` με το ίδιο
    `--confirm-database` αρνείται («This instance has run its daily cron since its last import …») και δεν αδειάζει
    τίποτα. Στις ώρες ανάμεσα στο άνοιγμα και στο πρώτο daily cron (ως 24) δεν υπάρχει φρουρός: ισχύει ο κανόνας αυτός
    (REV-811-1).
  - **Το τελικό import σε instance που έτρεξε ήδη cron με δοκιμαστικά δεδομένα** (δοκιμές live πριν από το cut-over): το
    import αρνείται και το λέει· το `--replace-live-data` προστίθεται **για αυτή τη μία εκτέλεση**. Το `--dry-run` πριν
    από το dump το προειδοποιεί. Instance χωρίς κανέναν πελάτη (εγκατεστημένο μέρες πριν, με τον scheduler ανοιχτό) δεν
    το χρειάζεται (REV-811-2). Κατά τα άλλα το flag είναι για πρόβες και αντίγραφα που έτρεξαν cron.
  - **Το site δεν ανοίγει όσο το ημερήσιο mail λέει ότι ένα import δεν τελείωσε** («a WHMCS import … has not ended»): το
    import ξανατρέχει πρώτα, ως το τέλος. Αν το site άνοιξε πάνω σε import που δεν τελείωσε, δεν ξανατρέχει· ρωτάμε
    (REV-811-3: ο φρουρός είναι κλειστός όσο υπάρχει ο δείκτης `whmcs_import_running_at`).
  - Όσα οι σημειώσεις του import λένε «run the import again before go-live, or after it …» γίνονται μετά το go-live με
    το χέρι: κωδικός υπηρεσίας στη σελίδα της, logins στους Servers / Domains → Registrars / Gateways, ανακοινώσεις στα
    Announcements.
- **Μετά το import, από το staff:**
  - **Registrar connections:** έρχονται ανενεργές και ενεργοποιούνται. **Πριν ανοίξει καθεμία (sweep 10, S10-DOMAINS-2,
    γνωστό):** οι πληρωμένες ανανεώσεις domain του WHMCS που έμειναν «προς ανανέωση» είναι στην έξοδο του import, μία
    γραμμή η καθεμία («Domain #…: invoice #… paid its renewal to …, WHMCS's expiry is …: left to be renewed»· στην πρόβα 6
    στη μία εταιρεία, 0 στην άλλη). Κάθε μία συγκρίνεται με τον registrar με το χέρι: αν ο registrar υπολείπεται της
    περιόδου μόνο μέρος έτους, το πρώτο cron με ανοιχτή σύνδεση θα αγοράσει **ολόκληρο έτος**, όπως θα έκανε το WHMCS. Τι
    γίνεται με καθεμία (το έτος αγοράζεται, ή τακτοποιείται πρώτα στο WHMCS και ξανά dump) το αποφασίζει ο υπεύθυνος πριν
    ανοίξει η σύνδεση.
  - **Domains που θα γίνουν Deleted από τις ημερομηνίες του WHMCS (sweep 10, S10-DOMAINS-1, γνωστό):** ο importer τα
    ονομάζει («… live domain(s) whose WHMCS expiry is past their grace and redemption (#…): the date step marks them
    Deleted …»). Το `domains:sync --scheduled` τρέχει κάθε 10 λεπτά και τα κάνει Deleted **πριν** διαβαστεί οποιοσδήποτε
    registrar· ένα Deleted δεν συγχρονίζεται ξανά (δεν τιμολογείται, δεν ειδοποιείται, δεν κρατά το όνομά του).
    1. **Πριν από το dump:** η λίστα από το import της πρόβας· κάθε ένα ελέγχεται στον registrar του. Αν ζει, διορθώνεται η
       λήξη του στο WHMCS (Expiry Date)· αν όχι, μένει (ή γίνεται Cancelled στο WHMCS).
    2. **Αμέσως μετά το τελικό import, πριν τρέξει ο scheduler:** η ίδια γραμμή της εξόδου πρέπει να έχει μόνο όσα
       αφέθηκαν επίτηδες. Αν έμεινε κάποιο που ζει: το admin δεν έχει ενέργεια που να αλλάζει τη λήξη ενός domain, άρα ή
       διορθώνεται στο WHMCS και ξανά dump και import, ή όποιος τρέχει το cut-over γράφει τη σωστή `domains.expires_at` στη
       βάση πριν ξεκινήσει ο scheduler (και το σημειώνει στο domain).
    Γνωστά, ήδη στο main (REV-806-3, REV-806-4): μια ανάγνωση που **απέτυχε** (λάθος credentials) γράφεται ως «Last synced»,
    άρα το «Never» δεν σημαίνει «δεν διαβάστηκε ποτέ με επιτυχία» και το αντίστροφο· και το cron ακυρώνει την ανοιχτή
    ανανέωση ενός domain που πέρασε τα στάδιά του (`lapsedForGood`), ακόμη κι αν ο registrar του δεν διαβάστηκε ποτέ.
  - **Υπηρεσίες ακόμη «Pending» στο WHMCS (sweep 10, S10-CRON-BILLING-1, γνωστό):** στο dump,
    `SELECT id, userid, regdate, nextduedate FROM tblhosting WHERE domainstatus = 'Pending'` (τις απλήρωτες παραγγελίες
    πάνω από 7 μέρες τις αρνείται ήδη το import)· ο importer τις μετρά («N service(s) still «Pending» in WHMCS (#…)»).
    Έρχονται με due date τη μέρα της παραγγελίας: όταν μια τέτοια πληρωθεί και ξεκινήσει μετά το cut-over, το staff ελέγχει
    το Next Due Date της (αν είναι στο παρελθόν, μπαίνει ένας κύκλος από τη μέρα που ξεκίνησε), αλλιώς αναστέλλεται το
    ίδιο βράδυ.
  - **Gateways (απόφαση ιδιοκτήτη 2026-11-06):** **μετά** την αλλαγή του host (τα webhooks παίρνουν το `APP_URL` της
    στιγμής), `php artisan app:enable-imported-gateways`. Η εντολή ανοίγει όποιο ήρθε ολόκληρο και απαντά:
    - **Stripe:** φτιάχνεται το webhook με τα κλειδιά του (έτσι ελέγχεται και ότι απαντούν· ένα restricted key θέλει το Webhook Endpoints). Η γραμμή «Webhook» πρέπει να γράφει ✓ (#586).
    - **PayPal:** το client id/secret παίρνει token, και φτιάχνεται (ή βρίσκεται) το webhook του MyCS στο app, με όλα τα
      events· το id του γράφεται στο `webhook_id`. Το webhook του WHMCS δεν το αγγίζει: σβήνεται με το χέρι.
    - **PayPal, συμφωνία συναλλαγών** (`payments-tracking.md`, «Συμφωνία gateway»· μόνο διαβάζει):
      1. **Μέρες πριν:** στο REST app του MyCS (developer.paypal.com → Apps & Credentials → το app → Features) τικ στο
         **«Transaction Search»**. Το PayPal το δίνει στα νέα tokens με καθυστέρηση ωρών, γι' αυτό όχι την τελευταία στιγμή.
      2. **Στο cut-over:** στο gateway PayPal (Setup → Payment Gateways), «Reconcile from» = η ώρα του cut-over
         (`YYYY-MM-DD HH:MM`). Κενό σημαίνει ότι δεν συγκρίνεται τίποτα. Ό,τι έγινε πριν (το WHMCS) δεν κρίνεται.
      3. **Πρώτη εκτέλεση, το επόμενο πρωί** (06:10, ή με το χέρι `php artisan gateways:reconcile`): να γράψει
         «PayPal: N new difference(s) …» και όχι «could not be read». 403 / `NOT_AUTHORIZED` = λείπει το βήμα 1 (ή δεν
         έχει περάσει ακόμη στο token). Μετά, **Billing → PayPal Transactions**: οι πληρωμές PayPal της πρώτης μέρας
         να φαίνονται «Matches». Αν σχεδόν όλες βγουν «Not recorded here» μαζί με «Not at the gateway», τα ids του
         Transaction Search δεν είναι αυτά που γράφουμε: δεν έχει ελεγχθεί σε ζωντανό λογαριασμό, να αναφερθεί αμέσως.
      4. **Οι συνδρομές παλιού τύπου** (5 υπηρεσίες 3 πελατών): μια χρέωσή τους μετά το cut-over φαίνεται εδώ ως «Not
         recorded here» με το id της συνδρομής. Το staff την καταγράφει (ή την επιστρέφει) και ακυρώνει τη συνδρομή στο
         PayPal, όπως ήδη προβλέπεται μετά την πρώτη χρέωση από το vault.
      - **Στην πρόβα** (προαιρετικά): «Reconcile from» έναν μήνα πίσω και `php artisan gateways:reconcile --days=30`, για
        να φανεί αν τα ids των εισαγόμενων πληρωμών ταιριάζουν με του PayPal. Είναι κλήση (μόνο GET) στο ζωντανό PayPal:
        με το OK του ιδιοκτήτη.
    - **Bank transfer:** όταν ήρθαν οι λογαριασμοί.
    - Ό,τι άλλο μένει κλειστό, με μήνυμα. **Cardlink:** τα κλειδιά και τα URLs return/notify στο vPOS της Eurobank, με το χέρι.
    Test keys του Stripe και sandbox του PayPal μένουν κλειστά (μόνο με `--allow-test`, σε δοκιμαστικό site: αλλιώς μια
    κάρτα δοκιμής θα πλήρωνε πραγματικό τιμολόγιο). Σταματά χωρίς να αλλάξει τίποτα αν η διεύθυνση δεν είναι https.
    Δεν ανοίγει δεύτερο Stripe/PayPal δίπλα σε ένα ενεργό, ούτε ξανά ένα που άνοιξε η ίδια και έκλεισε μετά το staff.
    Exit ≠ 0 όταν ένα gateway ήρθε ολόκληρο αλλά δεν άνοιξε.
    Ποτέ σε βάση πρόβας: ο scheduler της θα χρέωνε πραγματικά.
  - **Proxmox (myip):**
    - ο server του cluster: το import τον φτιάχνει από την αρχή, γιατί αδειάζει τη βάση. Ό,τι είχε στηθεί πριν (π.χ. ο
      «vega-proxmox» του mycs.myip.gr) δεν περνά. Συμπληρώνονται: το API token, τα IP/self-signed των κόμβων, το pool
      `mycs` και η ρύθμιση Terminate·
    - οι VPS του WHMCS αντιστοιχίζονται με το χέρι από τα Proxmox Guests (`proxmox.md` → Α3), και μετά το «Pool command».
    Μέχρι τότε η MyCS δεν αγγίζει κανένα VM.
  - **cPanel, domain υπηρεσίας ≠ κύριο domain του λογαριασμού στο WHM (sweep 7, S7-PROVISIONING-1):** μετά το import και
    πριν ανοίξει το cron, `php artisan cpanel:sync --domains` (read-only). Ανά server λέει τα ids των υπηρεσιών που το
    module θα αρνηθεί να κάνει suspend, unsuspend, terminate ή login (ο λογαριασμός με το username τους έχει άλλο κύριο
    domain). Για καθεμία: διορθώνεται το domain της υπηρεσίας (αν άλλαξε στο WHM) ή το username της (αν είναι άλλου), και
    ξανά ώσπου να πει «every service's domain is its account's». Αλλιώς κάθε τέτοια αναστολή γίνεται εργασία του staff.
  - **Υπηρεσίες που τρέχουν χωρίς να χρεώνονται (`service_unbilled`, 2026-11-10):** μετά το import (και σε κάθε πρόβα),
    `php artisan mycs:money-check --rule=service_unbilled` (μόνο διαβάζει· με `--rule` δεν στέλνει email). Τυπώνει τα
    ευρήματα και, ανά είδος, πόσα και ποια ids: `service_unbilled no_due_date: N (services …)`, `period_not_paid`,
    `past_due`, `addon_of_ended` (τι σημαίνει το καθένα και τι κάνει το staff: `money-trail.md` §5). Τα νούμερα μπαίνουν
    στην αναφορά της πρόβας· ό,τι βγει διορθώνεται στο WHMCS πριν από το dump, ή το αναλαμβάνει το staff την πρώτη μέρα.
  - **Παλιά απλήρωτα τιμολόγια ανανέωσης με πίστωση του πελάτη (sweep 11, S11-MONEY-LEDGER-1): μετά το import και ΠΡΙΝ
    ανοίξει ο scheduler.** Το βήμα της πίστωσης του πρώτου cron πληρώνει κάθε απλήρωτο τιμολόγιο πελάτη που έχει πίστωση
    από το WHMCS, όσο παλιό κι αν είναι (το WHMCS χρησιμοποιεί την πίστωση μόνο όταν εκδίδει τιμολόγιο)· ένα ξεχασμένο
    τιμολόγιο ανανέωσης ζωντανής υπηρεσίας θα πληρωνόταν έτσι και θα **αγόραζε μία περίοδο ακόμη** που κανείς δεν
    ζήτησε. Δεν είναι «της πρώτης μέρας»: γίνεται πριν από το πρώτο cron.
    1. `php artisan mycs:money-check --rule=service_unbilled` και από την έξοδο τα `period_not_paid`.
    2. Για κάθε ένα που είναι **παλιό απλήρωτο τιμολόγιο** (όχι της τρέχουσας περιόδου) και ο πελάτης του έχει πίστωση
       (φαίνεται στη σελίδα του πελάτη): **ακύρωση** του τιμολογίου αν δεν θα εισπραχθεί, ή **τακτοποίηση** (πληρωμή με το
       χέρι και έλεγχος της λήξης της υπηρεσίας) αν οφείλεται πράγματι.
    3. Ξανά η ίδια εντολή: δεν πρέπει να μένει κανένα τέτοιο. Μετά ανοίγει ο scheduler.
    Έκθεση στα αντίγραφα της πρόβας (2026-11-12): 1 τιμολόγιο στη μία εταιρεία· 4 στην άλλη, από τα οποία τα 3
    πληρώνονται ολόκληρα από την πίστωση.
  - **Offline VM (myip: Hetzner Cloud) και «Manual (offline)» (προϊόντα χωρίς module):** καμία ρύθμιση. Κάθε ενέργεια
    γίνεται εργασία στα Pending Module Actions. Στη λίστα του import, όποια υπηρεσία δεν είναι μηχάνημα (SSL, license)
    μεταφέρεται όπου ταιριάζει.
  - **ngm (myip):** το API token του server (admin, scopes `read` και `accounts`, στο ngm `/ui/tokens`, καλύτερα με
    `AllowedCIDRs` την IP της MyCS). Στο ngm: `api.account_writes` ανοιχτό, και `api.allow_sso` για το «Log in to the
    panel». Μετά «Test connection».
- **Collections του myip (WG-18, απόφαση ιδιοκτήτη 2026-11-05): ακυρώνονται στο WHMCS πριν από το τελικό dump.** Τα
  γράφει το `AfterModuleTerminate` του `auto-cancellation.php` όταν τερματίζεται υπηρεσία. Ο importer τα κάνει `unpaid`,
  οπότε θα έπαιρναν υπενθυμίσεις και αυτόματη χρέωση. Έλεγχος 2026-11-05: 15 τιμολόγια, 813,99, 7 πελάτες (2015–2026),
  **κανένα** με πληρωμή ή πίστωση, άρα η ακύρωση δεν αφήνει υπόλοιπο. Η nixpal δεν έχει κανένα.
  1. Αφού κλείσει το cron του WHMCS (§2) **και** σταματήσουν οι τερματισμοί με το χέρι στο admin: το hook τρέχει σε
     κάθε terminate, όχι μόνο του cron. Billing → Invoices, φίλτρο Status = Collections, επιλογή όλων, «Mark
     Cancelled». Δεν στέλνει mail και δεν τρέχει κανένα ενεργό hook (έλεγχος του review, 2026-11-05).
  2. Έλεγχος, ξανά **ακριβώς πριν από το dump**: `SELECT COUNT(*) FROM tblinvoices WHERE status = 'Collections'` = 0.
  3. Μετά το import: `grep 'in Collections' <log του import>` δεν πρέπει να βρει τίποτα (η γραμμή βγαίνει νωρίς και
     χάνεται στο scroll). Αν βρει, όσα τιμολόγια λέει έμειναν ως απλήρωτα και ακυρώνονται στο MyCS πριν τρέξει το πρώτο
     cron του.
- **Υπηρεσίες που ο importer ονομάζει στο «Line periods» (απόφαση ιδιοκτήτη 2026-11-11, `importer.md` → «Περίοδος στην
  τελευταία πληρωμένη γραμμή και στην ανοιχτή ανανέωση»): το staff τις κοιτάζει μία-μία.** Ο importer γράφει την περίοδο
  στην τελευταία πληρωμένη γραμμή και στην ανοιχτή ανανέωση κάθε ζωντανής υπηρεσίας μόνο όταν οι ημερομηνίες του WHMCS
  απέχουν ακριβώς έναν κύκλο. Όπου όχι, αφήνει τις γραμμές όπως ήταν και ονομάζει την υπηρεσία με τον λόγο.
  0. **Για όσο μια τέτοια υπηρεσία μένει «χωρίς περίοδο» (2026-11-12, REV-805-1):** αν η πληρωμή του εισαγόμενου
     τιμολογίου της σβηστεί ή χαθεί (chargeback), **μη γυρίσεις τη λήξη πίσω**: μια γραμμή χωρίς περίοδο δεν δίνεται ξανά
     όταν πληρωθεί. Είσπραξέ το, ή ακύρωσέ το και χρέωσε ξανά.
  1. Μετά το import: `grep -A8 'got no period' <log του import>`: μία γραμμή ανά λόγο, με πλήθος και ids.
     - **«not one cycle … before the due date»**, **«no due date»**: βήμα 2.
     - **«upgraded or downgraded in WHMCS inside the last paid period»**: σωστό όπως είναι· οι αχρησιμοποίητες μέρες
       της μετρούν στην τωρινή τιμή.
     - **«an invoice of 0.00, or one with no money on it»**: υπηρεσία με promotion 100% ή τιμολόγιο που έκλεισε paid χωρίς
       πληρωμή· σωστό όπως είναι.
     - **«more than one unpaid line»**: δύο απλήρωτες ανανεώσεις. Καλύτερα να τακτοποιηθούν στο WHMCS πριν από το dump
       (μία πληρώνεται ή ακυρώνεται)· αλλιώς όποια πληρωθεί εδώ παίρνει τον πρώτο απλήρωτο μήνα.
     - **«no paid line»**: βήμα 4 αν είναι πρώτη παραγγελία· αλλιώς υπηρεσία που φτιάχτηκε χωρίς τιμολόγιο.
     - **«a line on another customer's invoice»**: η υπηρεσία μεταφέρθηκε σε άλλον πελάτη στο WHMCS και η ανοιχτή της
       ανανέωση (ή άλλη απλήρωτη γραμμή της) έμεινε σε τιμολόγιο του πρώτου· η ανοιχτή ανανέωση ήρθε χωρίς περίοδο
       (sweep 9, review του #804). Η πληρωμένη γραμμή της δεν αλλάζει.
       - **Το καλύτερο, πριν από το dump:** στο WHMCS ακυρώνεται το τιμολόγιο που έμεινε στον πρώτο πελάτη και το WHMCS
         χρεώνει τον πελάτη της υπηρεσίας· το import ξανατρέχει.
       - **Αν μείνει ως έχει, μετά το import:** το τιμολόγιο που έμεινε στον πρώτο πελάτη **σβήνεται** («Delete») ή
         ακυρώνεται με **«bill them all again»**. **Ποτέ «Cancel» «as usual» / «give them all up»:** σε υπηρεσία χωρίς
         καμία γραμμή με περίοδο εγκαταλείπει την επόμενη περίοδο του πραγματικού πελάτη, που δεν χρεώνεται ποτέ
         (REV-804-1). Όσο μένει ανοιχτό, ο πρώτος πελάτης μπορεί να το πληρώσει (ή να χρεωθεί η κάρτα του) και να
         ανανεώσει την υπηρεσία του άλλου (REV-804-4).
       - **Active υπηρεσία:** το cron χρεώνει τον πελάτη της από τη λήξη της· το staff ελέγχει το επόμενο τιμολόγιό της
         (σωστός πελάτης, σωστή περίοδος, μία φορά).
       - **Suspended υπηρεσία:** το cron **δεν** τη χρεώνει και τίποτα δεν ειδοποιεί (REV-804-3)· το staff εκδίδει με το
         χέρι το τιμολόγιο του πελάτη της για την περίοδο της αναστολής («Invoice selected items»), αλλιώς τερματίζεται
         στις Termination Days χωρίς να της ζητηθεί ποτέ τίποτα.
     Μέτρηση του κανόνα στα αντίγραφα της 2026-11-11 (πριν γραφτεί ο κώδικας· η πρόβα θα το επιβεβαιώσει), χωρίς τις
     υπηρεσίες με upgrade ή δύο απλήρωτες, που δεν μετρήθηκαν: nixpal καμία, myip 24 (22 με
     ημερομηνίες που δεν απέχουν έναν κύκλο, 2 χωρίς πληρωμένη γραμμή).
  2. Για καθεμία, στο WHMCS: είναι σωστό το `nextduedate`; Αν μετακινήθηκε με το χέρι κατά λάθος, διορθώνεται εκεί και το
     import ξανατρέχει. Αν είναι σωστό (πολυετής πληρωμή, δώρο ημερών, χρόνια σε αναστολή), μένει: η υπηρεσία δουλεύει
     με τους παλιούς κανόνες (η επόμενη πληρωμή της παίρνει την πρώτη ακάλυπτη περίοδο).
  3. **Πλήρες refund ή «Cancel» του τελευταίου πληρωμένου τιμολογίου του WHMCS σε ονομασμένη υπηρεσία: το staff κοιτάζει
     την υπηρεσία αμέσως μετά.** Δύο περιπτώσεις:
     - η υπηρεσία **δεν έχει καμία γραμμή με περίοδο** (η πληρωμένη δεν ταίριαξε και δεν έχει ανοιχτή ανανέωση): εγκαταλείπεται η
       **επόμενη** περίοδος (sweep 8)· το `service_unbilled` το λέει μόλις περάσει η λήξη. Αν η υπηρεσία συνεχίζει, η
       ανανέωση εκδίδεται με το χέρι («Invoice selected items»).
     - η υπηρεσία **έχει ανοιχτή ανανέωση με περίοδο** (η πληρωμένη γραμμή της δεν ταίριαξε): δεν εγκαταλείπεται τίποτα
       και **τίποτα δεν ειδοποιεί** (το `service_unbilled` σιωπά)· η υπηρεσία τρέχει ως τη λήξη της με τα χρήματα
       γυρισμένα. Αν δεν έπρεπε, το staff γυρίζει τη λήξη πίσω ή την τερματίζει με το χέρι.
  4. «no paid line» σε **Active** υπηρεσία προϊόντος pro rata με απλήρωτη πρώτη παραγγελία: η πληρωμή θα δώσει ολόκληρο
     κύκλο από την ημέρα της παραγγελίας· μετά την πληρωμή το staff βάζει τη λήξη στη μέρα του προϊόντος (S8-IMPORT-1).
  5. Γνωστό, χωρίς ενέργεια (ιδιοκτήτης 2026-11-11): υπηρεσίες πελατών με έκπτωση ομάδας ή promotion, ώσπου να ανανεωθούν
     μία φορά στο MyCS: η πίστωση αχρησιμοποίητων ημερών μετρά τη γραμμή πριν από την έκπτωση (6,67 αντί 6,00), και μια
     αλλαγή πλάνου με απλήρωτη ανοιχτή ανανέωση του WHMCS τη βγάζει 8,00 αντί 9,00 (`importer.md`)· το staff κοιτάζει το
     ποσό πριν επιβεβαιώσει.
  6. Ό,τι άλλο βρεθεί στα εισαγόμενα δεδομένα δεν γίνεται λογική στον importer: μπαίνει εδώ ως «κοιτάξτε αυτές τις Ν με
     το χέρι» (ιδιοκτήτης 2026-11-11).
- **Upgrades του WHMCS που δεν πληρώθηκαν (ADV-A1-1, απόφαση ιδιοκτήτη 2026-11-07): τακτοποιούνται ή ακυρώνονται στο
  WHMCS πριν από το τελικό dump.** Εδώ έρχονται ως απλή χρέωση της υπηρεσίας: η πληρωμή τους **δεν** κάνει upgrade, και
  το τιμολόγιό τους έρχεται με `no_auto_charge` (η MyCS δεν το χρεώνει μόνη της). Έλεγχος 2026-11-06: κανένα σε καμία
  εταιρεία.
  1. Ακριβώς πριν από το dump: `SELECT u.id, u.relid, u.type, ii.invoiceid FROM tblupgrades u JOIN tblinvoiceitems ii
     ON ii.type = 'Upgrade' AND ii.relid = u.id JOIN tblinvoices i ON i.id = ii.invoiceid WHERE u.status = 'Pending'
     AND u.paid <> 'Y' AND u.type IN ('package', 'configoptions') AND i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded')`
     = καμία γραμμή. Είναι τα τιμολόγια που ο importer κάνει `unpaid` (`invoiceStatus`): Unpaid, Payment Pending, Collections
     (και ό,τι άλλο status)· όχι Draft, Paid, Cancelled, Refunded. Όσα βγουν: να πληρωθούν (και να εφαρμοστούν) ή να ακυρωθούν στο WHMCS, με το τιμολόγιό τους.
  2. Μετά το import: `grep 'unpaid WHMCS upgrade' <log του import>` **πρέπει να μη βρει τίποτα**. Αν βρει (ονομάζει
     τιμολόγιο, υπηρεσία και upgrade): τακτοποιούνται στο WHMCS και το import ξανατρέχει. Το `no_auto_charge` μπαίνει
     σε όλο το τιμολόγιο, όχι μόνο στη γραμμή του upgrade: δεν είναι λύση για να μείνει έτσι (R1b-4).
- **«Payment Pending» (ADV-A1-3):** έρχονται `unpaid` με πληρωμή `processing` του υπολοίπου (transaction id
  `whmcs-pending-<τιμολόγιο>`, με την ημέρα του τιμολογίου): ούτε χρέωση της αποθηκευμένης μεθόδου ούτε υπενθυμίσεις.
  Ο importer τα ονομάζει (`grep 'Payment Pending' <log>`). Όταν περάσει η χρέωση (το webhook της θα είναι «ξένο»), το staff καταγράφει την πληρωμή με αυτό το
  transaction id, **με το ίδιο gateway ή χωρίς gateway**· αν απέτυχε, σβήνει την πληρωμή `processing`. Όσες μείνουν
  7 μέρες μετά το import τις θυμίζει το cron («needs staff attention», μία φορά την εβδομάδα· αν το mail δεν μπορεί να μπει
  στην ουρά, ξανά την επόμενη μέρα· μια αποτυχία SMTP αργότερα, στον worker, δεν το ξαναστέλνει πριν περάσει η εβδομάδα).
- **Mass Pay σε «Payment Pending» (απόφαση ιδιοκτήτη, review 2 του #697): κανένα πριν από το τελικό dump.** Ο importer
  **αρνείται να τρέξει** αν βρει τιμολόγιο σε Payment Pending με γραμμή «Invoice» (γονικό Mass Pay) (το ονομάζει με τα παιδιά του), και με `--dry-run`.
  Έλεγχος 2026-11-06: κανένα σε καμία εταιρεία (ούτε κανένα Payment Pending).
  1. Ακριβώς πριν από το dump: `SELECT p.id FROM tblinvoices p WHERE p.status = 'Payment Pending' AND EXISTS (SELECT 1
     FROM tblinvoiceitems ii WHERE ii.invoiceid = p.id AND ii.type = 'Invoice')` = καμία γραμμή (ο ίδιος κανόνας με του
     importer, `pendingMassPay`: και ένα που πήρε μέρος των χρημάτων, έχει παιδί άλλου πελάτη ή άλλη γραμμή). Όσα βγουν: περιμένουμε να περάσει (ή να αποτύχει) η χρέωση στο WHMCS
     και παίρνουμε ξανά dump.
  2. Αν το import αρνηθεί («WHMCS Mass Pay invoice(s) in «Payment Pending»»): το ίδιο, και ξανά import. Τίποτα δεν έχει
     αδειάσει.
- **Απλήρωτο Mass Pay με μερική πληρωμή ή γραμμή άλλου είδους (sweep 6 S6-IMPORT-2, απόφαση ιδιοκτήτη 2026-11-08):
  τακτοποιείται στο WHMCS πριν από το τελικό dump.** Ένα απλήρωτο (Unpaid, Collections) Mass Pay που πήρε μέρος των χρημάτων,
  ή έχει και γραμμή που δεν είναι «Invoice» (π.χ. late fee), παιδί άλλου πελάτη ή παιδί που είναι το ίδιο Mass Pay, δεν είναι
  γονικό Mass Pay για τον importer: θα ερχόταν ως απλό απλήρωτο τιμολόγιο ενώ και τα παιδιά του μένουν απλήρωτα, και ο πελάτης
  θα χρεωνόταν δύο φορές. Ο importer **αρνείται να τρέξει** (και με `--dry-run`, πριν αδειάσει οτιδήποτε) και το ονομάζει με
  τα παιδιά του (`unsettledMassPay`). Έλεγχος 2026-11-10 στα αντίγραφα: κανένα σε καμία εταιρεία.
  1. Ακριβώς πριν από το dump: `php artisan app:import-from-whmcs … --dry-run` πάνω στο αντίγραφο (ο κανόνας είναι του
     importer: απλήρωτο τιμολόγιο με γραμμή «Invoice» που δεν μετρά ως γονικό). Για μια πρώτη ματιά στο WHMCS: `SELECT p.id
     FROM tblinvoices p WHERE p.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded', 'Payment Pending') AND EXISTS (SELECT 1
     FROM tblinvoiceitems ii WHERE ii.invoiceid = p.id AND ii.type = 'Invoice') AND (EXISTS (SELECT 1 FROM tblaccounts a WHERE
     a.invoiceid = p.id AND a.amountin > 0) OR EXISTS (SELECT 1 FROM tblinvoiceitems o WHERE o.invoiceid = p.id AND o.type <>
     'Invoice'))`. Όσα βγουν, στο WHMCS: η πληρωμή μεταφέρεται στα παιδιά (Transactions: αλλαγή του Invoice ID της πληρωμής
     στο παιδί, ή «Apply Credit» στα παιδιά αν έγινε πίστωση) και το Mass Pay τιμολόγιο ακυρώνεται («Mark Cancelled»)· μια
     ξένη γραμμή (late fee) σβήνεται ή μπαίνει σε δικό της τιμολόγιο. Μετά ξανά dump.
  2. Αν το import αρνηθεί («WHMCS unpaid Mass Pay invoice(s) with a part payment or a line of another kind»): το ίδιο, και
     ξανά import. Τίποτα δεν έχει αδειάσει.
- **Παλιές απλήρωτες παραγγελίες (sweep 4 C-FIRST-RUN-1, απόφαση ιδιοκτήτη 2026-11-08): ακυρώνονται στο WHMCS πριν από
  το τελικό dump.** Μετά το cut-over η πίστωση και η αυτόματη χρέωση πληρώνουν τις παραγγελίες όπως πάντα, οπότε το πρώτο
  cron θα πλήρωνε με την πίστωση του πελάτη μια παραγγελία που εγκατέλειψε μήνες πριν: η υπηρεσία θα στηνόταν και το domain
  θα αγοραζόταν στον registrar. Ο importer **αρνείται να τρέξει** (και με `--dry-run`, πριν αδειάσει οτιδήποτε) όσο υπάρχει
  απλήρωτο τιμολόγιο (Unpaid ή Collections· όχι Payment Pending, που έρχεται με πληρωμή σε εξέλιξη) με ημερομηνία πάνω από
  7 μέρες πριν που παραγγέλνει κάτι (Pending υπηρεσία, Pending addon, καταχώριση ή μεταφορά domain που είναι ακόμη
  `Pending`), και τα ονομάζει (`abandonedOrders`). Ένα domain που το WHMCS έχει ήδη Active (καταχωρίστηκε πριν πληρωθεί) ή
  έστειλε (Pending Registration/Transfer) **δεν** σταματά το import: η πληρωμή του δεν στήνει τίποτα, είναι χρέος του πελάτη
  και **δεν ακυρώνεται** (sweep 5 G-MONEY-SERVICE-3).
  1. Ακριβώς πριν από το dump: `SELECT DISTINCT i.id FROM tblinvoices i JOIN tblinvoiceitems ii ON ii.invoiceid = i.id
     LEFT JOIN tblhosting h ON ii.type = 'Hosting' AND h.id = ii.relid LEFT JOIN tblhostingaddons a ON ii.type = 'Addon'
     AND a.id = ii.relid LEFT JOIN tbldomains d ON ii.type IN ('DomainRegister', 'DomainTransfer') AND d.id = ii.relid
     WHERE i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded', 'Payment Pending') AND i.date < CURDATE() - INTERVAL
     7 DAY AND (h.domainstatus = 'Pending' OR a.status = 'Pending' OR d.status = 'Pending')` =
     καμία γραμμή. Όσα βγουν: ακυρώνονται στο WHMCS (Orders → «Cancel Order», ή «Mark Cancelled» στο τιμολόγιο), και ξανά dump.
  2. Αν το import αρνηθεί («WHMCS unpaid order invoice(s) older than 7 days»): το ίδιο, και ξανά import. Τίποτα δεν έχει
     αδειάσει.
- **Απλήρωτα Add Funds με έκπτωση (S6-MONEY-LEDGER-1, review του #766): ακυρώνονται στο WHMCS πριν από το τελικό dump.**
  Στο MyCS ένα τιμολόγιο αγοράς πίστωσης δεν παίρνει έκπτωση (ούτε αρνητική γραμμή ούτε πιστωτικό σημείωμα): η πίστωση
  δίνεται μόνο για χρήματα που ήρθαν. Αν ερχόταν ένα τέτοιο από το WHMCS και ο πελάτης πλήρωνε το μειωμένο ποσό, η πληρωμή
  θα γραφόταν, το τιμολόγιο θα έμενε απλήρωτο με 0,00 για πληρωμή, πίστωση δεν θα δινόταν και το staff θα έπρεπε να κάνει
  refund στο gateway. Ο importer **αρνείται να τρέξει** (και με `--dry-run`, πριν αδειάσει οτιδήποτε) όσο υπάρχει μη
  εξοφλημένο τιμολόγιο (ό,τι δεν είναι Paid, Cancelled ή Refunded) με γραμμή `AddFunds` και γραμμή με αρνητικό ποσό, και
  τα ονομάζει (`discountedAddFunds`). Στα αντίγραφα της 2026-11-06 (nixpal) και 2026-11-08 (myip) δεν υπάρχει κανένα.
  1. Ακριβώς πριν από το dump: `SELECT DISTINCT i.id FROM tblinvoices i JOIN tblinvoiceitems a ON a.invoiceid = i.id AND
     a.type = 'AddFunds' JOIN tblinvoiceitems d ON d.invoiceid = i.id AND d.amount < 0 WHERE i.status NOT IN ('Paid',
     'Cancelled', 'Refunded')` = καμία γραμμή. Όσα βγουν: «Mark Cancelled» στο WHMCS και νέο Add Funds με το σωστό ποσό
     (ή ο πελάτης το ξαναφτιάχνει), και ξανά dump.
  2. Αν το import αρνηθεί («WHMCS unpaid Add Funds invoice(s) with a negative line»): το ίδιο, και ξανά import. Τίποτα δεν
     έχει αδειάσει.
- **Ανανεώσεις που ακυρώθηκαν ή σβήστηκαν στο WHMCS ενώ το `nextinvoicedate` προχώρησε (απόφαση ιδιοκτήτη 2026-11-08, sweep 5· στη θέση
  του κανόνα του sweep 4 C-FIRST-RUN-2· addons και σβησμένα τιμολόγια: sweep 6 S6-IMPORT-3/4): διορθώνονται στο WHMCS πριν από το τελικό dump.** Το WHMCS δεν ξαναχρεώνει μια τέτοια
  περίοδο· εδώ θα χρεωνόταν ξανά από το `nextduedate`. Ο importer **αρνείται να τρέξει** (και με `--dry-run`, πριν αδειάσει
  οτιδήποτε) όσο μια ζωντανή υπηρεσία ή ένα ζωντανό addon (Active, Suspended) έχει `nextinvoicedate > nextduedate` και τα μόνα τιμολόγια ανανέωσής
  του για το `nextduedate` είναι Cancelled ή σβησμένα, και τα ονομάζει (`cancelledRenewals`: υπηρεσίες ως `#id`, addons ως
  `addon #id`). Ένα σβησμένο τιμολόγιο αφήνει πίσω τις γραμμές του (`tblinvoiceitems` χωρίς γραμμή στο `tblinvoices`)· γραμμή
  ανανέωσης της περιόδου είναι όποια έχει **δική της** `duedate` ίσο με το `nextduedate` (το τιμολόγιό της μπορεί να λήγει άλλη
  μέρα, μαζί με άλλα είδη: review του #764)· αν έστω μία τέτοια γραμμή είναι σε ζωντανό τιμολόγιο, τίποτα δεν σταματά. Μια υπηρεσία με αίτημα ακύρωσης μένει έξω: εδώ δεν ξαναχρεώνεται και τελειώνει στην
  ημερομηνία της, όπως ζητήθηκε· το ίδιο ένα addon υπηρεσίας με αίτημα ακύρωσης, και ένα addon υπηρεσίας που έρχεται τελειωμένη
  (Terminated, Cancelled, Fraud), και τα addons χωρίς επόμενη περίοδο (One Time, Free, Free Account).
  1. Ακριβώς πριν από το dump, υπηρεσίες: `SELECT s.id FROM tblhosting s JOIN tblinvoiceitems ii ON ii.type = 'Hosting' AND
     ii.relid = s.id LEFT JOIN tblinvoices i ON i.id = ii.invoiceid WHERE s.domainstatus IN ('Active', 'Suspended') AND NOT
     EXISTS (SELECT 1 FROM tblcancelrequests c WHERE c.relid = s.id) AND s.nextduedate > '0000-00-00' AND s.nextinvoicedate >
     s.nextduedate AND ii.duedate = s.nextduedate GROUP BY s.id HAVING SUM(CASE WHEN COALESCE(i.status,
     'Cancelled') = 'Cancelled' THEN 0 ELSE 1 END) = 0` = καμία γραμμή. Addons: `SELECT s.id FROM tblhostingaddons s JOIN
     tblhosting h ON h.id = s.hostingid JOIN tblinvoiceitems ii ON ii.type = 'Addon' AND ii.relid = s.id LEFT JOIN tblinvoices i
     ON i.id = ii.invoiceid WHERE s.status IN ('Active', 'Suspended') AND h.domainstatus NOT IN ('Terminated', 'Cancelled',
     'Fraud') AND s.billingcycle NOT IN ('Free Account', 'One Time', 'Free') AND NOT EXISTS (SELECT 1 FROM tblcancelrequests c WHERE c.relid = s.hostingid) AND s.nextduedate > '0000-00-00'
     AND s.nextinvoicedate > s.nextduedate AND ii.duedate = s.nextduedate GROUP BY s.id HAVING SUM(CASE
     WHEN COALESCE(i.status, 'Cancelled') = 'Cancelled' THEN 0 ELSE 1 END) = 0` = καμία γραμμή. Έλεγχος 2026-11-10 στα
     αντίγραφα: καμία σε καμία εταιρεία. Για όσα βγουν, στην υπηρεσία ή στο addon στο WHMCS: είτε η περίοδος χρεώνεται ξανά
     (`Next Invoice Date` = `Next Due Date`), είτε χαρίζεται (`Next Due Date` = `Next Invoice Date`). Τα addons μιας υπηρεσίας
     μένουν στην ημέρα χρέωσής της (ο έλεγχος `addonsApart`). Μετά ξανά dump.
  2. Αν το import αρνηθεί («WHMCS live service(s) or addon(s) whose renewal invoice was cancelled or deleted and next invoice date moved past it»):
     το ίδιο, και ξανά import. Τίποτα δεν έχει αδειάσει.
- **Ξεχασμένα απλήρωτα τιμολόγια ανανέωσης (sweep 7 S7-IMPORT-1, απόφαση ιδιοκτήτη 2026-11-10): ακυρώνονται ή
  τακτοποιούνται στο WHMCS πριν από το τελικό dump.** Ένα τιμολόγιο που έρχεται απλήρωτο (Unpaid, Collections, Payment Pending) με γραμμή ανανέωσης περιόδου που η
  ζωντανή υπηρεσία (ή το ζωντανό addon) έχει ήδη περάσει (η `duedate` της γραμμής είναι πριν από το `nextduedate`: το staff
  μετακίνησε την ημερομηνία με το χέρι, ή πληρώθηκε ένα διπλό) αγνοείται από το WHMCS. Εδώ θα μετρούσε ως «η ανανέωση έχει
  ήδη τιμολογηθεί»: η επόμενη περίοδος δεν θα τιμολογούνταν και η υπηρεσία θα έμπαινε σε αναστολή. Ο importer **αρνείται να
  τρέξει** (και με `--dry-run`, πριν αδειάσει οτιδήποτε) και τα ονομάζει (`staleRenewals`: τιμολόγιο και `service #id` ή
  `addon #id`). Μια ληξιπρόθεσμη ανανέωση της **τρέχουσας** περιόδου (γραμμή με `duedate` = `nextduedate`) δεν πιάνεται: είναι
  χρέος και έρχεται όπως είναι.
  1. Ακριβώς πριν από το dump, υπηρεσίες: `SELECT DISTINCT i.id, s.id FROM tblhosting s JOIN tblinvoiceitems ii ON ii.type =
     'Hosting' AND ii.relid = s.id JOIN tblinvoices i ON i.id = ii.invoiceid WHERE s.domainstatus IN ('Active', 'Suspended')
     AND s.nextduedate > '0000-00-00' AND ii.duedate > '0000-00-00' AND ii.duedate < s.nextduedate AND (ii.duedate >
     s.regdate OR i.date < CURDATE() - INTERVAL 7 DAY) AND i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded')` =
     καμία γραμμή (μια πρώτη παραγγελία, γραμμή με `duedate` ως και το `regdate`, μετρά μόνο όταν το τιμολόγιό της είναι
     πάνω από 7 μέρες παλιό). Addons: `SELECT DISTINCT i.id, s.id FROM
     tblhostingaddons s JOIN tblhosting h ON h.id = s.hostingid JOIN tblinvoiceitems ii ON ii.type = 'Addon' AND ii.relid =
     s.id JOIN tblinvoices i ON i.id = ii.invoiceid WHERE s.status IN ('Active', 'Suspended') AND h.domainstatus NOT IN
     ('Terminated', 'Cancelled', 'Fraud') AND s.billingcycle NOT IN ('Free Account', 'One Time', 'Free') AND s.nextduedate >
     '0000-00-00' AND ii.duedate > '0000-00-00' AND ii.duedate < s.nextduedate AND (ii.duedate > s.regdate OR i.date <
     CURDATE() - INTERVAL 7 DAY) AND i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded')` = καμία γραμμή. Έλεγχος 2026-11-10 στα αντίγραφα: καμία σε καμία εταιρεία.
     Όσα βγουν, στο WHMCS: «Mark Cancelled» αν δεν οφείλεται πια, ή πληρωμή/τακτοποίηση αν οφείλεται (τότε το `Next Due
     Date` της υπηρεσίας να είναι το σωστό). Μετά ξανά dump.
  2. Αν το import αρνηθεί («WHMCS unpaid invoice(s) with a renewal line of a period its live service or addon is already
     past (or a first order still unpaid after 7 days)»): το ίδιο, και ξανά import. Τίποτα δεν έχει αδειάσει.
- **Παλιές πληρωμένες παραγγελίες domain που έμειναν «Pending» (sweep 7 S7-IMPORT-2, απόφαση ιδιοκτήτη 2026-11-10):
  κλείνουν στο WHMCS πριν από το τελικό dump.** Ένα domain σκέτο «Pending» με πληρωμένη παραγγελία στέλνεται στον registrar
  από το πρώτο cron μετά το cut-over (όπως είναι γραμμένο στο `importer.md`). Αν η πληρωμή είναι πάνω από **30 μέρες** πριν,
  συνήθως η καταχώριση απέτυχε και τακτοποιήθηκε εκτός WHMCS: ο importer **αρνείται να τρέξει** (και με `--dry-run`) και
  ονομάζει τα domains (`stalePaidDomainOrders`). Νεότερες στέλνονται όπως πριν.
  1. Ακριβώς πριν από το dump: `SELECT DISTINCT d.id FROM tbldomains d JOIN tblinvoiceitems ii ON ii.type IN
     ('DomainRegister', 'DomainTransfer') AND ii.relid = d.id JOIN tblinvoices i ON i.id = ii.invoiceid WHERE d.status =
     'Pending' AND i.status = 'Paid' AND (CASE WHEN i.datepaid > '0000-00-00' THEN i.datepaid ELSE i.date END) < CURDATE() -
     INTERVAL 30 DAY` = καμία γραμμή (ο importer αφήνει έξω όσα έχουν λήξη στο μέλλον και έρχονται active). Έλεγχος
     2026-11-10 στα αντίγραφα: καμία σε καμία εταιρεία (κανένα domain «Pending»). Όσα βγουν, στο domain στο WHMCS: status
     **Cancelled** αν δεν το θέλει πια ο πελάτης (και refund ή πίστωση αν οφείλεται), ή **Active** με τη σωστή λήξη αν
     καταχωρίστηκε στο μεταξύ. Μετά ξανά dump.
  2. Αν το import αρνηθεί («WHMCS domain order(s) paid more than 30 days ago and still «Pending»»): το ίδιο, και ξανά
     import. Τίποτα δεν έχει αδειάσει.
- **Διπλό απλήρωτο τιμολόγιο ανανέωσης domain (sweep 10, S10-IMPORT-1, απόφαση ιδιοκτήτη 2026-11-12): ακυρώνεται στο
  WHMCS πριν από το dump, ή εδώ μετά το import.** Απλήρωτη γραμμή `Domain` για περίοδο που έχει ήδη πληρωθεί σε άλλο
  τιμολόγιο του ίδιου domain: πληρωμένη εδώ (και από την αυτόματη πίστωση στο πρώτο cron) δεν αγοράζει τίποτα, ο πελάτης
  πληρώνει την ίδια χρονιά δύο φορές. Ο importer **δεν σταματά**· τις ονομάζει στο τέλος των γραμμών («unpaid domain
  renewal(s) of a period a paid renewal of the same domain already covers: domain #… invoice #… line #…»).
  1. Πριν από το dump: το import της πρόβας πάνω στο αντίγραφο τις ονομάζει (το `--dry-run` όχι: δεν διαβάζει γραμμές)·
     για κάθε τιμολόγιο που ονομάζει, «Mark Cancelled» στο WHMCS.
  2. Αν μείνει κάποιο στο τελικό import: ακύρωση του τιμολογίου στο MyCS **πριν ανοίξει το cron** (το βήμα της πίστωσης
     θα το πλήρωνε).
- **Ποσότητα > 1 (sweep IMPORT-1, `sweeps/2026-11-07.md`): καμία πριν από το τελικό dump, αλλιώς έλεγχος.** Ο importer
  γράφει τιμή = `amount` (addon: `recurring`) και ποσότητα = `qty`, και η ανανέωση χρεώνει το γινόμενο. Αν στο WHMCS το
  `amount` είναι ήδη το σύνολο, κάθε ανανέωση χρεώνει `qty` φορές. Έλεγχος 2026-11-06: καμία σε καμία εταιρεία.
  Ακριβώς πριν από το dump: `SELECT COUNT(*) FROM tblhosting WHERE qty > 1 AND domainstatus IN ('Active','Suspended')` και
  `SELECT COUNT(*) FROM tblhostingaddons WHERE qty > 1 AND status IN ('Active','Suspended')` = 0. Αν βγει κάποιο, συγκρίνουμε
  ένα με την τελευταία γραμμή ανανέωσής του (`tblinvoiceitems.amount`) πριν από το import.
- **Addons με άλλη λήξη από τη γονική (απόφαση ιδιοκτήτη 2026-11-07): με το χέρι, πριν από το τελικό dump.** Ένα addon με
  άλλο `nextduedate` από τη γονική του **σταματά το import** (2026-11-08: εδώ ένα addon έχει πάντα τη λήξη της
  γονικής): το import τα ονομάζει και δεν γράφει τίποτα. Αποφασίστηκε να παίρνει τη λήξη της γονικής, με το χέρι στο WHMCS
  (`nextduedate` του addon = της γονικής), χωρίς συμψηφισμό για τις μέρες που χάνονται ή χαρίζονται. **Από 2026-11-10 (sweep 7
  S7-LATEST-FIXES-1, απόφαση ιδιοκτήτη) ο έλεγχος είναι η ίδια η ημερομηνία**, όχι η ίδια μέρα του μήνα: με ίδιο κύκλο, η
  ίδια μέρα σε άλλον μήνα δεν είναι η λήξη της γονικής. Δύο σχήματα έρχονται όπως είναι, το ζευγάρι ακέραιες περιόδους μακριά (η 29η–31η
  κομμένη σε μικρό μήνα, `WhmcsAddonImport::periodsBehind`) επειδή το ένα από τα δύο δεν έχει πληρώσει ακόμη την περίοδό του:
  το addon **πίσω** από τη γονική με απλήρωτη τη δική του ανανέωση (γραμμή `Addon` με `duedate` = το `nextduedate` του addon σε
  απλήρωτο τιμολόγιο), και το αντίστροφο, το addon **μπροστά** από τη γονική που έχει απλήρωτη τη δική της ανανέωση (γραμμή
  `Hosting` με `duedate` = το `nextduedate` της γονικής· review του #775, REV-775-4). **Το δεύτερο δεν ευθυγραμμίζεται με το
  χέρι:** δεν υπάρχει αλλαγή στο WHMCS που να αφήνει τα χρήματα όπως είναι (το addon πίσω = ξαναχρεώνεται μήνας που πλήρωσε·
  η γονική μπροστά = χαρίζεται ο απλήρωτος μήνας της και το τιμολόγιό του σταματά το import ως ξεχασμένο). Στο MyCS το addon
  ανανεώνεται μόνο του στην ώρα του, η γονική δεν τιμολογείται ξανά όσο είναι ανοιχτή η ανανέωσή της, και μόλις αυτή πληρωθεί
  λήγουν μαζί· ως τότε η αλλαγή κύκλου της γονικής αρνείται (`addons.md`). **Έλεγχος 2026-11-10: το addon #170 του myip
  (μηνιαίο) είναι αυτό το σχήμα, έναν μήνα μπροστά από τη γονική του με απλήρωτη την ανανέωσή της: έρχεται όπως είναι,
  καμία ενέργεια.** Nixpal: κανένα addon.
  **Ένα addon με άλλο κύκλο από τη γονική του σταματά κι αυτό το import** (απόφαση ιδιοκτήτη 2026-11-09: ένα addon έχει
  πάντα και τον κύκλο της γονικής, ώστε να ανανεώνεται κάθε φορά στο τιμολόγιό της· πριν δεχόμασταν ένα μηνιαίο addon σε
  ετήσια υπηρεσία στη μέρα της). Με το χέρι στο WHMCS: `Billing Cycle` του addon = της γονικής, `Recurring Amount` η τιμή του
  για αυτόν τον κύκλο, `Next Due Date` = της γονικής· ό,τι έχει ήδη πληρωθεί πέρα από αυτό ή λείπει ως τότε το τακτοποιεί
  το staff με τον πελάτη (πίστωση ή χρέωση με το χέρι). Έλεγχος πριν από το dump: `SELECT a.id, a.billingcycle,
  h.billingcycle FROM tblhostingaddons a JOIN tblhosting h ON h.id = a.hostingid WHERE a.status IN
  ('Active','Suspended','Pending') AND h.domainstatus NOT IN ('Terminated','Cancelled','Fraud') AND a.billingcycle NOT IN
  ('Free Account','One Time','Free') AND a.billingcycle <> h.billingcycle` = κανένα (myip 2026-11-09: 15 ζωντανά addons, όλα
  στον κύκλο της γονικής· nixpal: κανένα addon). Μετά το import, το προϊόν-addon χρειάζεται τιμή για κάθε κύκλο στον οποίο
  μπορεί να αλλάξει η γονική: αλλιώς η αλλαγή κύκλου της γονικής αρνείται και ονομάζει το addon (`addons.md`). Δεν ελέγχεται addon γονικής που έληξε (μπαίνει λήξαν μαζί της)·
  ένα επαναλαμβανόμενο addon γονικής χωρίς `nextduedate` σταματά το import (δώστε της ημερομηνία ή τερματίστε το addon). Έλεγχος
  2026-11-06: 3 στο myip (#160, #169, #170· το #160 είναι Suspended ενώ η γονική του είναι Active: να δει κάποιος γιατί), κανένα
  στη nixpal. Ακριβώς πριν από το dump: `SELECT a.id FROM tblhostingaddons a JOIN tblhosting h ON h.id = a.hostingid WHERE
  a.status IN ('Active','Suspended','Pending') AND h.domainstatus IN ('Active','Suspended','Pending') AND a.billingcycle NOT IN
  ('Free Account','One Time','Free') AND a.nextduedate <> h.nextduedate` δίνει τις υποψήφιες· από αυτές μένει ως έχει
  μόνο το ζευγάρι που απέχει ακέραιες περιόδους επειδή αυτό που είναι πίσω (addon ή γονική) έχει απλήρωτη την ανανέωσή του. Το
  dry run του import δίνει την τελική λίστα.
- **Παλιά ανοιχτά τιμολόγια (sweep 2, F-RENEWAL-CYCLE-2):** ένα ανοιχτό τιμολόγιο του WHMCS με γραμμή της υπηρεσίας χωρίς
  περίοδο μετράει στο MyCS ως ανανέωση ήδη τιμολογημένη, και οι επόμενες δεν βγαίνουν ώσπου να πληρωθεί ή να ακυρωθεί. Πριν από
  το dump: τα ανοιχτά τιμολόγια που δεν θα πληρωθούν πια (παλιά, ξεχασμένα) ακυρώνονται στο WHMCS. Όσα είναι περιόδου που η
  υπηρεσία έχει ήδη περάσει τα αρνείται πια το ίδιο το import (S7-IMPORT-1, παραπάνω)· ένα απλήρωτο της τρέχουσας περιόδου
  που δεν θα πληρωθεί μένει στην κρίση του staff.
- **Γίνονται ήδη από τον importer:** οι «Free Account» μπαίνουν σε plan με 0,00, χωρίς λήξη και με κλειδωμένη τιμή
  (#594). Η ρύθμιση `credits_on_downgrade` έρχεται από το WHMCS (#596).
- **Ρυθμίσεις του myip που το import δεν μπορεί να φέρει (WG-17, απόφαση ιδιοκτήτη 2026-11-05):**
  1. **Παράθυρο αναστολών (και τερματισμών):** στο WHMCS γινόταν με το crontab (όχι Σάββατο, Κυριακή, Δευτέρα πριν τις
     10:00), όχι με ρύθμιση. Εδώ: Settings → Cron, «Suspend and terminate on» (`cronjob_dunning_days`) = Δευτέρα έως
     Παρασκευή και «… from» (`cronjob_dunning_from`) = 10:00. Είναι
     λίγο αυστηρότερο (μία ώρα για όλες τις μέρες: και Τρίτη–Παρασκευή από τις 10:00)· ό,τι πέφτει εκτός περιμένει την
     επόμενη επιτρεπτή ώρα, τίποτα δεν χάνεται.
  2. **Αυτόματη αντιστοίχιση εμβασμάτων:** το gateway «Bank transfer» έρχεται απενεργοποιημένο (`importPaymentWays`), με
     τους λογαριασμούς από το template «Invoice Created»: να ελεγχθούν, ή να μπει το κείμενο του gateway του WHMCS όπως
     είναι στο admin του (Setup → Payments → Bank Transfer), πριν ενεργοποιηθεί. Χωρίς το IMAP των
     ειδοποιήσεων της τράπεζας. Στις ρυθμίσεις του, όλα αυτά (αλλιώς δεν ταιριάζει τίποτα, χωρίς προειδοποίηση: review
     του #664):
     - «Read the bank alert emails» ανοιχτό·
     - IMAP host, port, encryption, username, password, «Folders»·
     - «Our mail server (authserv-id)» και, αν οι ειδοποιήσεις φτάνουν με προώθηση (sieve redirect), «Our ARC sealing
       domain»: μόνο ειδοποιήσεις που περνούν τον έλεγχο γνησιότητας μετρούν·
     - «Our accounts»: οι λογαριασμοί της εταιρείας (ένα πίστωμα σε άλλον δεν είναι ποτέ δικό μας)·
     - προαιρετικά «Credits to ignore» (π.χ. εκκαθαρίσεις του acquirer)·
     - τελευταίο το «Match credits to invoices automatically» (`PaymentMatcher`).
     Ταιριάζει μόνο ό,τι διαβάζεται από τη στιγμή που ανοίγει, μέσα στις «Match credits of the last (days)» (7)· τα παλιά
     εμβάσματα του WHMCS όχι, και δεν χρειάζεται.
  - Γίνονται από το import: το «Pay To Text» του WHMCS → «Bill To Text» (`bill_to_text`). Το PDF του τιμολογίου το
    επισυνάπτει πάντα το MyCS (το myip το είχε κλειστό στο WHMCS: οι πελάτες του θα το παίρνουν, απόφαση ιδιοκτήτη).
- **Όρια των cPanel resellers (WG-16, απόφαση ιδιοκτήτη 2026-11-05: τα ορίζουν τα προϊόντα του MyCS):** ο importer φέρνει
  στα προϊόντα reseller τα όρια του WHMCS (π.χ. Reseller 70: 70.000 MB χώρου, 4.000.000 MB bandwidth), αλλά δεν τα
  στέλνει στους υπάρχοντες resellers. Στο WHM τα περισσότερα μπήκαν με το χέρι και διαφέρουν λίγο (π.χ. 71.680 MB = 70
  GB, 4.194.304 MB = 4 TB). Μετά το import:
  **Μόνο μετά το τελευταίο import:** ένα re-run ξαναφέρνει τις τιμές του WHMCS και αναιρεί το βήμα 1 (ή διορθώνονται
  πρώτα στο WHMCS, πριν από το τελικό import).
  1. Οι τιμές των προϊόντων reseller στο MyCS διορθώνονται σε ό,τι θέλουμε να ισχύει (π.χ. 71.680/4.194.304), **και το
     overselling**: 5 resellers (σε mars, earth, titan, virgo) το έχουν ανοιχτό στο WHM με το χέρι, για χώρο ή bandwidth,
     ενώ τα προϊόντα το έχουν κλειστό· το βήμα 2 το κλείνει (μετά δεν φτιάχνουν λογαριασμούς πέρα από το όριο).
  2. Σε κάθε υπηρεσία reseller, «Change package» στο ίδιο πακέτο (ή «Retry» της ενέργειας), ώστε το WHM να πάρει τα όρια
     του προϊόντος. **Πρώτα σε έναν**, και έλεγχος στο WHM (Reseller Center → Edit Privileges/Resources) ότι κράτησε ό,τι
     δεν του στάλθηκε (π.χ. το όριο λογαριασμών): η τεκμηρίωση του WHM δεν λέει τι γίνεται με όσα λείπουν από το
     `setresellerlimits` (review του #663).
  3. **Χωρίς κανένα όριο στο WHM** (έλεγχος 2026-11-05, ονόματα στον υπεύθυνο, όχι εδώ: το repo είναι δημόσιο): 4
     resellers, 2 στον earth και 2 στον orion. Ο ένας είναι σε Reseller 30 (παίρνει τα όρια του προϊόντος με το βήμα 2)·
     ο ένας είναι της nixpal. Οι άλλοι δύο είναι resellers στο WHM σε προϊόν **που δεν είναι** reseller (Business20,
     Semi-Dedicated Hosting): ένα «Change package» θα προσπαθούσε να τους κάνει απλούς λογαριασμούς (και θα αρνιόταν αν
     έχουν λογαριασμούς πελατών). **Μένουν όπως είναι** (ιδιοκτήτης 2026-11-06: ειδικές περιπτώσεις): στο βήμα 2 δεν
     γίνεται «Change package» σε αυτούς τους δύο.
- **Extra Addon domain του myip (WG-15, απόφαση ιδιοκτήτη 2026-11-05):** το μόνο quantity option που πήρε ποτέ υπηρεσία
  έρχεται κρυφό (το MyCS δεν χρεώνει ποσότητα ακόμα, CART-9)· η μία υπηρεσία που το έχει (MyMail 50, ×2, ο αριθμός της
  στον υπεύθυνο: το repo είναι δημόσιο) κρατά την τιμή του WHMCS. Μετά το import, με το χέρι:
  1. Νέο προϊόν addon «Extra Addon domain»: 5,00 τον μήνα, 60,00 τον χρόνο, 120,00 τη διετία, **με ΦΠΑ** (όπως το
     MyMail 50), δεμένο στα MyMail 25/50/100/200/1000 (το WHMCS το δένει και στο κρυφό MyMail 25).
  2. Στην υπηρεσία: «Add addon» με ποσότητα 2, **«Χωρίς τιμολόγιο»** (ποτέ το pro-rata «invoice now»: η τρέχουσα περίοδος
     είναι πληρωμένη στα 31,00, που έχουν ήδη μέσα τα 10,00), με λήξη την **επόμενη λήξη της** όταν η ανανέωσή της
     δεν έχει τιμολογηθεί ακόμα. Ο κύκλος του addon είναι της υπηρεσίας (2026-11-09: δεν διαλέγεται), γι' αυτό το προϊόν
     έχει τιμή για κάθε κύκλο των MyMail. Αν έχει ήδη ανοιχτό τιμολόγιο ανανέωσης στα 31,00 (το cut-over πέφτει μέσα στις 10
     ημέρες πριν τη λήξη της), λήξη του addon η **μεθεπόμενη**, ώστε τα 10,00 να μη χρεωθούν δύο φορές εκείνον τον μήνα
     (review του #662).
  3. Τιμή της υπηρεσίας: 21,00 (η τιμή του MyMail 50· τα 10,00 είναι πια του addon). Μια ανοιχτή ανανέωση δεν αλλάζει με
     αυτό: μένει στα 31,00, που καλύπτουν και το addon εκείνον τον μήνα (βήμα 2).
  4. Το κρυφό option «Extra Addon domain» βγαίνει από τα MyMail (ή σβήνεται).
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

## 1β. Χάρτης 301 (οι διευθύνσεις του WHMCS που φέρνουν κίνηση)
Το MyCS απαντά ήδη (`LegacyWhmcsController`, 301) σε κάθε διεύθυνση του WHMCS, με `/clients` μπροστά (myip) και χωρίς
(nixpal): store και προϊόντα (και τα παλιά slugs, `whmcs_product_slugs` από το `tblproducts_slugs`), `cart.php` (pid, gid,
domain register/transfer), `clientarea.php` (ανά action, με τα ids του WHMCS που κρατιούνται), `viewinvoice.php`,
`viewticket.php` (με τον αριθμό), `submitticket.php`, login/register/pwreset, ανακοινώσεις, `index.php?rp=…`, το
knowledgebase (δικός του controller). Ό,τι άλλο του WHMCS πάει στην αρχική, ποτέ σε 404. Μόνο σε δικές μας διευθύνσεις.
- **Host του MyCS για το myip:** `clients.myip.gr`, **δικό του, ανεξάρτητο account** (ιδιοκτήτης 2026-11-06, για
  ασφάλεια). Σήμερα κάνει redirect στο `/clients/` του WHMCS (`importer.md`): αυτό καταργείται και δείχνει στο MyCS. Οι
  διευθύνσεις χωρίς `/clients` εκεί (`/store/…`, `cart.php`) τις απαντά το MyCS κατευθείαν.
- **myip.gr** (το site Astro, `chrismfz/myip`, στον rigel με cPanel/LiteSpeed: τα redirects του είναι `.htaccess` που
  βγάζει το `deploy.sh`): ένα **γενικό redirect** του `/clients/` στο `clients.myip.gr`, **εκτός από** το admin και το API
  του WHMCS, που μένουν όπως είναι για ώρα ανάγκης (ιδιοκτήτης 2026-11-06), με όσα χρειάζονται για να φορτώσουν:
  ```apache
  RewriteEngine On
  # Callbacks των gateways (POST): ένα 301 τα κάνει GET. 307: ίδια μέθοδος και σώμα, στο MyCS.
  RewriteRule ^clients/modules/gateways/callback/(.*)$ https://clients.myip.gr/clients/modules/gateways/callback/$1 [R=307,L]
  # Το WHMCS μένει για το staff: admin, API (includes/api.php) και τα αρχεία που φορτώνουν
  # (η λίστα να ελεγχθεί πριν: ανοίγουμε το admin με τους κανόνες και κοιτάμε στο access log για 301 σε αρχεία του).
  RewriteRule ^clients/(sysadmin|includes|assets|modules|vendor|resources)/ - [L]
  # Όλα τα άλλα του πελάτη: στο MyCS, με το query τους (LegacyWhmcsController).
  RewriteRule ^clients/?(.*)$ https://clients.myip.gr/clients/$1 [R=301,L]
  ```
  - Το IPN του PayPal (μόνο οι παλιές συνδρομές, ROADMAP 7) φτάνει έτσι κατευθείαν στο MyCS (δέχεται και το
    `/clients/…/paypal.php`, `routes/legacy.php`). Αν φανεί ότι το PayPal δεν ακολουθεί το 307, proxy (`[P]`) όπου το
    LiteSpeed το επιτρέπει, με «Trusted Proxies» στο MyCS = μόνο η IP του rigel (ποτέ `*`). Τα άλλα callbacks εκεί
    (Stripe `stripe.php`, Eurobank, ppcpv) παίρνουν 405 από το MyCS: αναμενόμενο, αφού κλείνουν στο §2.
  - Το admin του WHMCS μένει στο `myip.gr/clients/sysadmin/` με κλειστό cron (§2, §3): μόνο για ανάγνωση και για ώρα
    ανάγκης. Το API του ίδιο· τα credentials του που δεν χρειάζονται (ekdosi, invoicer) ανακαλούνται (`importer.md`).
  - Έλεγχος μετά: ένα άρθρο του knowledgebase, ένα `/clients/store/cpanel-hosting/business10`, ένα
    `cart.php?a=add&pid=` από τον Google Search Console, όλα σε 301 και όχι 404· και το `/clients/sysadmin/` ανοίγει
    ακόμα. Στο site: `PUBLIC_DOMAIN_ADD_URL` (WG-20) και τα `/clients/store/…` του `links.ts` κατευθείαν στο
    `clients.myip.gr` με το επόμενο deploy.
- **nixpal:** το `clients.nixpal.com` είναι το ίδιο το MyCS, άρα οι διευθύνσεις φτάνουν κατευθείαν. Στον orion (cPanel,
  Apache) το `.htaccess` του Laravel στέλνει στο `index.php` ό,τι `.php` δεν υπάρχει ως αρχείο: να ελεγχθεί ένα
  `cart.php?a=add&pid=` μετά το deploy.

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
- **Live αναζήτηση domain του site (myip, WG-20):** το `/api/domain-check.php` του site φορτώνει το WHMCS. Στο
  `_config.php` του rigel (`public_html/api/`, όχι στο repo), στο κλειδί `domain`:
  `'backend' => 'mycs', 'mycs_url' => 'https://clients.myip.gr', 'mycs_key' => '<ίδιο με το domain_check_site_key>'`.
  Πριν: «Site key» στο MyCS (Settings → Domains → Site search API, 32+ χαρακτήρες). Έλεγχος:
  `curl -s 'https://myip.gr/api/domain-check.php?list=tlds' | jq '.tlds | length'` > 0 και ένα `?domain=` ελεύθερο
  (`true`). Επιστροφή: `'backend' => 'whmcs'`. Τα links του καλαθιού του site (`DOMAINS_CART_URL`, `/clients/store/…`
  στο `links.ts`) αλλάζουν με τον χάρτη 301. Το «Στο καλάθι» της αναζήτησης: στο `.env` του site
  `PUBLIC_DOMAIN_ADD_URL=https://clients.myip.gr/domain-search?domain={domain}` και deploy (η αναζήτηση του MyCS με το
  «Προσθήκη»· όχι κατευθείαν στο καλάθι: ένα δημόσιο GET που ελέγχει διαθεσιμότητα θα ήταν whois χωρίς κλειδί, review
  του #667). Πριν: σύγκριση μερικών τιμών του `?list=tlds` με του WHMCS (ΦΠΑ). Η cache του site είναι ξεχωριστή ανά
  backend, οπότε ο έλεγχος βλέπει αμέσως το MyCS.
- **Token του ZoneCloud controller (myip, WG-22):** το hook `_searchDomainZcloud.php` του WHMCS το έχει σε απλό κείμενο·
  ανακαλείται στον controller και βγαίνει νέο, αν το χρειάζεται κάτι άλλο.

## 3. Επιστροφή στο WHMCS (πρόταση, 2026-11-03· η προθεσμία αποφασίστηκε· η αναφορά αλλαγών: `mycs:rollback-report`, 2026-11-06)
Μόνο για κάτι που δεν διορθώνεται μέσα σε λίγες ώρες: το MyCS δεν καταγράφει πληρωμές, χρεώνει λάθος ποσά ή λάθος
πελάτες, ή το cron του δεν ολοκληρώνεται. Για οτιδήποτε άλλο διορθώνουμε προς τα εμπρός (fix forward).

**Πριν το cut-over:**
- Το WHMCS **δεν σβήνεται**. Μένει όπως ήταν τη στιγμή του τελικού dump, με κλειστό cron και πρόσβαση μόνο για το staff
  (`old-clients`, ROADMAP 8). Κρατάμε και το ίδιο το dump.
- **Προθεσμία απόφασης: 48 ώρες** από το πρώτο ημερήσιο cron του MyCS (υπεύθυνος, 2026-11-03). Μετά από αυτήν, μόνο fix
  forward, γιατί όσο περνά ο καιρός τόσο μεγαλώνει ό,τι πρέπει να ξαναπεράσει στο WHMCS.
- Τις πρώτες μέρες ισχύει το δίχτυ του ROADMAP 9β(4):
  - suspensions και terminations κλειστά,
  - καθημερινή σύνοψη στο staff για ό,τι τιμολόγησε και χρέωσε το cron: **Automation Status → «Το cron ανά μέρα»**
    (ημερολόγιο του μήνα, ό,τι έκανε κάθε μέρα, και για τις επόμενες 14 τι θα αναστείλει και θα τερματίσει αν δεν
    πληρωθεί τίποτα), και η κάρτα «Cron» στο dashboard (χθες, τώρα, αύριο, μεθαύριο). Με το δίχτυ ανοιχτό, η λίστα
    «Περιμένουν αναστολή» δείχνει ό,τι κρατά: να διαβαστεί πριν ξαναγίνει on το «Enable Suspension» (Settings → Cron), γιατί στο πρώτο cron μετά αναστέλλονται όλες μαζί.
    Και το ίδιο με email: **Settings → Automation → Daily Summary Email** (off από προεπιλογή). «Every day» ή «Only when
    something failed» (μέρα χωρίς cron, κάρτα που δεν χρεώθηκε, ενέργεια module ανοιχτή), παραλήπτες από το staff που
    βλέπει Cron stats (κανείς: το System Email Address), ώρα αποστολής (μετά το Time of Day) και «Working days only»
    (η Δευτέρα καλύπτει το Σαββατοκύριακο). Κάθε email: οι μέρες από το προηγούμενο (έως 7), και τι αναστέλλεται ή
    τερματίζεται τώρα, αύριο και μεθαύριο, με τις υπηρεσίες· με «Working days only» της Παρασκευής φτάνει ως τη Δευτέρα,
    γιατί το email της Δευτέρας φεύγει μετά τις αναστολές της (ADV-A3-5). Για τις πρώτες μέρες: «Every day». Δοκιμή χωρίς να
    περιμένεις: `php artisan app:cron-summary --force`.

  Έτσι μια επιστροφή έχει ελάχιστα να ξαναπεράσει στο WHMCS.
- **Η αναφορά των αλλαγών:** `php artisan mycs:rollback-report --since="<ώρα που άνοιξε το MyCS>" [--csv=/root/rollback]`
  (read-only). **Η ώρα του cut-over σημειώνεται τη στιγμή που ανοίγει το MyCS**: είναι το μόνο που χρειάζεται. Δείχνει ανά
  μέρος ό,τι έκανε το MyCS από τότε και τι γίνεται με το καθένα στο WHMCS (ο πίνακας παρακάτω), με πρώτα τα χρήματα:
  πληρωμές, refunds, τιμολόγια που έβγαλε, γραμμές που πρόσθεσε σε τιμολόγια του WHMCS και αλλαγές τους, πιστώσεις, νέοι
  πελάτες και logins, αλλαγές πελατών και επαφών, παραγγελίες, νέες και αλλαγμένες υπηρεσίες, ενέργειες στους servers,
  νέα και αλλαγμένα domains, ό,τι έγινε στους registrars, tickets και απαντήσεις, κάρτες που αποθηκεύτηκαν, και ποιοι
  πελάτες πήραν mail (για το βήμα 7). Στηρίζεται σε δύο πράγματα: ο importer κρατά τα ids του WHMCS και δεν γράφει audits,
  ενώ το web, το cron και τα jobs γράφουν. Άρα ό,τι δημιουργήθηκε ή άλλαξε μετά το `--since` είναι του MyCS. Το `--csv`
  γράφει ένα αρχείο ανά μέρος (στοιχεία πελατών: σβήνεται μετά). Με cut-over Σαββατοκύριακο, χωρίς crons στο WHMCS και με
  το δίχτυ ανοιχτό, η αναφορά έχει κυρίως ό,τι έκαναν οι ίδιοι οι πελάτες: πληρωμές, παραγγελίες, tickets, στοιχεία.
  «Του MyCS» είναι μια γραμμή με audit δημιουργίας μετά το `--since`· χωρίς audit, μόνο με `created_at` μετά, και ποτέ
  μια που της άλλαξε η ημερομηνία εδώ (όχι μόνο το `created_at`: γραμμές του WHMCS χωρίς ημερομηνία, το merge που τη
  γυρίζει πίσω, οι διαγραφές, πληρωμή με την ημέρα που ήρθαν τα χρήματα, τιμολόγιο του WHMCS με σημερινή ημερομηνία·
  adversarial #690 και δεύτερος γύρος). **Οι πληρωμές περνούν χωρίς τον αυτοματισμό του WHMCS** (Auto Renew on Payment,
  auto-setup, auto-unsuspend κλειστά όσο περνούν, ή AddTransaction): αλλιώς ένα domain που ανανέωσε το MyCS ανανεώνεται
  ξανά (η στήλη «done here» δείχνει πού). Επίσης: αιτήματα ακύρωσης, νέες επαφές, αλλαγές logins (και ποιων άλλαξε ο
  κωδικός), άδειες (reissue), tickets του WHMCS που άλλαξαν, mail σε καραντίνα. Δεν φαίνεται ό,τι γράφεται με
  query χωρίς το model (μεταδεδομένα του ίδιου του MyCS: handles, «Do not auto-charge», ειδοποιήσεις hold)· η μεταφορά
  domain σε άλλο λογαριασμό γράφει δικό της audit, και κάθε μεταφορά αφήνει σημείωση στους δύο πελάτες (Client notes).

**Τι ξαναπερνά στο WHMCS** (με το API του, χειροκίνητα ή με script, πάντα πριν ανοίξει ξανά το cron του):
- Πληρωμές και refunds που πήρε το MyCS → `AddInvoicePayment` / `AddTransaction` στο τιμολόγιο του WHMCS. Αν το
  τιμολόγιο το έφτιαξε το MyCS, το φτιάχνουμε πρώτα στο WHMCS.
- Τιμολόγια ανανέωσης που έβγαλε το MyCS → τα ίδια στο WHMCS. Αλλιώς το WHMCS βγάζει δικά του για τις ίδιες περιόδους
  και ο πελάτης βλέπει δύο.
- Λήξεις υπηρεσιών και domains που προχώρησαν (`nextduedate`, `UpdateClientProduct`, `UpdateClientDomain`). Αυτό
  ισχύει κυρίως για τα domains που ανανέωσε το MyCS στον registrar: αλλιώς το WHMCS τα ανανεώνει δεύτερη φορά.
- Αλλαγές σε servers (create, suspend, unsuspend, terminate): η κατάσταση στο WHMCS να ταιριάζει με τον server.
- Νέοι πελάτες, παραγγελίες, πιστώσεις, tickets και απαντήσεις.
- Νέες αποθηκευμένες κάρτες και αλλαγμένοι κωδικοί: δεν μεταφέρονται. Ο πελάτης ξαναβάζει την κάρτα του, ή κάνει
  reset τον κωδικό του.

**Τι γυρίζει πίσω** (η αντίστροφη σειρά του §2):
1. Σελίδα συντήρησης στο MyCS, και τα crons και οι ουρές του σταματούν.
   Ένα `deploy/update.sh` μέσα σε αυτό το παράθυρο (π.χ. διόρθωση στο `mycs:rollback-report`) αφήνει το site όπως το
   βρήκε: κάτω, και χωρίς να ξεκινήσει workers που δεν έτρεχαν πριν (sweep 6, S6-OPS-2). Το λέει στις τελευταίες γραμμές του.
   Εξαίρεση: μετά από deploy που **απέτυχε**, το επόμενο που πετυχαίνει ανεβάζει το site. Μέσα σε αυτό το παράθυρο σβήσε
   πρώτα το `storage/framework/deploy-left-down` (ή μην κάνεις deploy πάνω σε αποτυχημένο). Σημάδι παλιότερο από 24 ώρες
   (ή με ώρα στο μέλλον) αγνοείται και σβήνεται μόνο του (sweep 7, S7-OPS-2)· αν δεν σβήνεται (αρχείο άλλου χρήστη), το
   deploy το λέει και συνεχίζει.
2. Η αναφορά των αλλαγών περνά στο WHMCS, όπως παραπάνω.
3. Webhooks:
   - Stripe: ενεργοποιούνται ξανά τα endpoints του WHMCS και απενεργοποιείται του MyCS.
   - PayPal: το webhook του app του WHMCS.
   - Cardlink: τα URLs του vPOS ξανά προς το WHMCS.
4. DNS και χάρτης 301 αντίστροφα: το `clients.nixpal.com` γυρίζει στο WHMCS.
5. Το piping/POP των tickets γυρίζει στο WHMCS.
6. **Τελευταίο:** το cron του WHMCS (και των registrars).
7. Ενημερώνονται οι πελάτες που πλήρωσαν, πήραν mail ή άλλαξαν κάτι στο MyCS στο μεταξύ.

**Πρόβα:** η επιστροφή δοκιμάζεται σε scratch, ποτέ στο live. Τα βήματα:
- dump του WHMCS και import στο MyCS,
- μερικές πληρωμές και ανανεώσεις στο scratch MyCS,
- η αναφορά των αλλαγών περνά σε ένα αντίγραφο του WHMCS,
- `mycs:parity` ανάμεσα στο αντίγραφο και στο scratch MyCS.

## 4. Καθαρισμός όταν τελειώσουν οι δοκιμές
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
