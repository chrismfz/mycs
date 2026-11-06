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
- **Μετά το import, από το staff:**
  - **Registrar connections:** έρχονται ανενεργές και ενεργοποιούνται.
  - **Gateways (απόφαση ιδιοκτήτη 2026-11-06):** **μετά** την αλλαγή του host (τα webhooks παίρνουν το `APP_URL` της
    στιγμής), `php artisan app:enable-imported-gateways`. Η εντολή ανοίγει όποιο ήρθε ολόκληρο και απαντά:
    - **Stripe:** φτιάχνεται το webhook με τα κλειδιά του (έτσι ελέγχεται και ότι απαντούν· ένα restricted key θέλει το Webhook Endpoints). Η γραμμή «Webhook» πρέπει να γράφει ✓ (#586).
    - **PayPal:** το client id/secret παίρνει token, και φτιάχνεται (ή βρίσκεται) το webhook του MyCS στο app, με όλα τα
      events· το id του γράφεται στο `webhook_id`. Το webhook του WHMCS δεν το αγγίζει: σβήνεται με το χέρι.
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
- **Upgrades του WHMCS που δεν πληρώθηκαν (ADV-A1-1, απόφαση ιδιοκτήτη 2026-11-07): τακτοποιούνται ή ακυρώνονται στο
  WHMCS πριν από το τελικό dump.** Εδώ έρχονται ως απλή χρέωση της υπηρεσίας: η πληρωμή τους **δεν** κάνει upgrade, και
  το τιμολόγιό τους έρχεται με `no_auto_charge` (η MyCS δεν το χρεώνει μόνη της). Έλεγχος 2026-11-06: κανένα σε καμία
  εταιρεία.
  1. Ακριβώς πριν από το dump: `SELECT u.id, u.relid, u.type, ii.invoiceid FROM tblupgrades u JOIN tblinvoiceitems ii
     ON ii.type = 'Upgrade' AND ii.relid = u.id JOIN tblinvoices i ON i.id = ii.invoiceid WHERE u.status = 'Pending'
     AND u.paid <> 'Y' AND u.type IN ('package', 'configoptions') AND i.status NOT IN ('Paid', 'Cancelled', 'Refunded')`
     = καμία γραμμή. Όσα βγουν: να πληρωθούν (και να εφαρμοστούν) ή να ακυρωθούν στο WHMCS, με το τιμολόγιό τους.
  2. Μετά το import: `grep 'unpaid WHMCS upgrade' <log του import>` **πρέπει να μη βρει τίποτα**. Αν βρει (ονομάζει
     τιμολόγιο, υπηρεσία και upgrade): τακτοποιούνται στο WHMCS και το import ξανατρέχει. Το `no_auto_charge` μπαίνει
     σε όλο το τιμολόγιο, όχι μόνο στη γραμμή του upgrade: δεν είναι λύση για να μείνει έτσι (R1b-4).
- **«Payment Pending» (ADV-A1-3):** έρχονται `unpaid` με πληρωμή `processing` του υπολοίπου (transaction id
  `whmcs-pending-<τιμολόγιο>`, με την ημέρα του τιμολογίου): ούτε χρέωση της αποθηκευμένης μεθόδου ούτε υπενθυμίσεις.
  Ενός γονικού Mass Pay, τα ανοιχτά παιδιά του κρατούνται για όσο η χρέωσή του, και η πίστωση που πήρε τα πληρώνει.
  Ο importer τα ονομάζει (`grep 'Payment Pending' <log>`). Όταν περάσει η χρέωση (το webhook της θα είναι «ξένο»), το staff καταγράφει την πληρωμή με αυτό το
  transaction id, **με το ίδιο gateway ή χωρίς gateway**· αν απέτυχε, σβήνει την πληρωμή `processing`. Όσες μείνουν
  7 μέρες μετά το import τις θυμίζει το cron («needs staff attention»).
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
     δεν έχει τιμολογηθεί ακόμα. Αν έχει ήδη ανοιχτό τιμολόγιο ανανέωσης στα 31,00 (το cut-over πέφτει μέσα στις 10
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
