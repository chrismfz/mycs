# nixpal licensing: σχεδιασμός (Phase 1)

> Αντικαθιστά το WHMCS Licensing Addon (v3.1, encoded) με δικό μας plugin + module στο Blesta,
> κρατώντας το `zlic.php` contract ώστε κανένας agent/node/controller να μη χρειαστεί update.
> Πηγές: `reference/whmcs/zlic.php` (live αντίγραφο από orion, sha256 `6d6fc2a5…`),
> `chrismfz/zonecloud` (`internal/license/check.go`, `zcloudweb/app/helpers.php`), read-only
> ερωτήματα στη DB της nixpal (2026-10-08), screenshots του admin του WHMCS.

---

## 1. Πώς δουλεύει σήμερα

```
agent / node / controller ──POST license, license_PSK──▶ clients.nixpal.com/zlic.php
        zlic.php ──POST licensekey, domain, ip, dir, check_token──▶ modules/servers/licensing/verify.php
        verify.php ──<status>…</status><md5hash>…</md5hash>…──▶ zlic.php
        zlic.php: έλεγχος md5hash == md5(PSK . check_token), αν Active φτιάχνει localkey ──JSON──▶ client
```

Τι στέλνει πραγματικά το zlic στο verify.php:
- `domain` = **reverse DNS της IP του client** (`gethostbyaddr(REMOTE_ADDR)`), όχι κάτι που δηλώνει ο client.
- `ip` = `REMOTE_ADDR` του client.
- `dir` = `dirname(__FILE__)` του zlic, δηλαδή **πάντα** `/home/clnixpal/public_html`.
  Γι' αυτό 325/326 ενεργές άδειες έχουν αυτό το validdirectory. Το directory binding είναι ουσιαστικά νεκρό.

Ο PSK **δεν** ελέγχεται απευθείας: το verify.php επιστρέφει `md5hash = md5(product_secret . check_token)`
και το zlic το συγκρίνει με `md5(client_PSK . check_token)`. Λάθος PSK → `Invalid` /
`MD5 Checksum Verification Failed`. Άρα ο PSK λειτουργεί ως «ποιο προϊόν είμαι».

### Τι χρησιμοποιούν οι clients από την απάντηση
| Client | Πεδία |
|---|---|
| Go agent/node (`check.go`) | `status`, `nextduedate`, `localkey`. Fallback στο αποθηκευμένο nextduedate αν ο server δεν απαντά ή δεν είναι Active |
| Laravel controller (`helpers.php`) | `status`, `localkey`· από το αποκωδικοποιημένο localkey: `status`, `registeredname`, `nextduedate`, `checkdate` (σελίδα Settings) |
| C++ client (`web-tools.cpp`, παλαιότερος· από αυτόν έγινε port ο Go) | `status == "Active"` και `localkey` (το `message` διαβάζεται αλλά δεν χρησιμοποιείται). Στέλνει **GET** με query string, άρα το GET fallback του zlic πρέπει να μείνει όσο υπάρχουν C++ clients. Αν ο server δεν απαντά → false (χωρίς fallback σε due date). Σε άκυρη άδεια κάνει `sleep(360)` και ξαναδοκιμάζει, εξ ου και ο όγκος στα logs |

Ο έλεγχος του localkey στους clients (Go, Laravel, C++ ίδιος) είναι: `md5(localdata . PSK)` στο τέλος, μετά strrev,
`md5(checkdate . PSK)` στην αρχή, base64 JSON με `checkdate`. **Ο client δεν ελέγχει το ίδιο το JSON
byte-for-byte**, μόνο τα δύο md5 και το `checkdate`. Άρα αρκεί ίδιος αλγόριθμος και ίδια ονόματα πεδίων·
η σειρά των keys στο JSON δεν σπάει κανέναν client. Παρ' όλα αυτά κρατάμε ίδια σειρά για να είναι
τα fixtures συγκρίσιμα.

**Bug στον Go client (zonecloud, όχι εδώ):** το `parseLocalKey` δεν αφαιρεί τα `\n` που βάζει το
`wordwrap` του zlic, οπότε το αποθηκευμένο localkey δεν περνάει ποτέ τον έλεγχο και ο agent/node ρωτάει
τον server σε κάθε check. Επιβεβαιώθηκε τρέχοντας το πραγματικό `parseLocalKey` πάνω σε κλειδί του
`LocalKey` (wrapped → false, χωρίς `\n` → true). Ισχύει ίδιο και με το σημερινό WHMCS. Διόρθωση στο
zonecloud: `strings.ReplaceAll(localkey, "\n", "")` πριν τον έλεγχο. Το κλειδί από τη μεριά μας **δεν**
αλλάζει (C++ και Laravel περιμένουν και αφαιρούν τα `\n`).

### Τι επιστρέφει πραγματικά το verify.php (golden fixtures, 2026-10-08)
Καταγράφηκε από τον orion με κλήσεις όπως του zlic, σε δική σας DEVNODE άδεια, σε μία expired,
μία suspended, μία ενεργή Agent από λάθος IP και σε ανύπαρκτο κλειδί. Τα bindings όλων έμειναν ίδια.
Ανωνυμοποιημένα στο `tests/fixtures/zlic/` (εκεί και ο πίνακας με το τι βγάζει το zlic ανά περίπτωση).
- Active / Expired / Suspended: ίδιο σύνολο tags (`status`, `registeredname`, `companyname`, `email`,
  `serviceid`, `productid`, `productname`, `regdate`, `nextduedate`, `billingcycle`, `validdomain`,
  `validip`, `validdirectory`, [`customfields`], `md5hash`). **Χωρίς `description`.** Δεν υπάρχουν
  `configoptions`/`addons`.
- Λάθος IP: μόνο `status=Invalid` + `message=IP Address Invalid`, **χωρίς md5hash**.
- Άγνωστο κλειδί: μόνο `status=Invalid`.
- Τα «License Expired» / «License Suspended» / «Invalid Key - <key>» υπάρχουν μόνο στο log.
- Το `lastaccess` ενημερώνεται και στις αποτυχίες.

### Shadow-run στον orion (2026-10-08): 9/9 ίδιες απαντήσεις
Το νέο endpoint (`lib/nixpal-licensing`, commit `d317952`) στήθηκε ως `clients.nixpal.com/zlic2.php`
δίπλα στο παλιό, με `schema = whmcs`, `read_only = true` και MySQL χρήστη `clnixpal_zlicro` που έχει
**μόνο SELECT** σε 7 πίνακες (επιβεβαιώθηκε ότι η βάση αρνείται UPDATE). Ίδια αιτήματα στα δύο:

| Περίπτωση | Αποτέλεσμα |
|---|---|
| χωρίς παραμέτρους (400) | ίδιο |
| άγνωστο κλειδί | ίδιο |
| ενεργή DEVNODE, POST και GET | ίδιο, και το αποκωδικοποιημένο `localkey` (πεδία και σειρά) |
| λάθος PSK σε ενεργή / σε expired | ίδιο |
| expired, suspended (με `customfields`) | ίδιο |
| ενεργή Agent από άλλη IP | ίδιο (`IP Address Invalid`) |

Τα `md5hash`/`localkey` συγκρίθηκαν μετά από αποκωδικοποίηση (το `check_token` είναι τυχαίο ανά κλήση).
Bindings/status όλων των αδειών αμετάβλητα. Μένει ανοιχτό μόνο το encoding των `customfields` με
ειδικούς χαρακτήρες (δεν υπήρχε τέτοια expired/suspended άδεια για ασφαλή δοκιμή).

Τι έμεινε στον orion: `/home/clnixpal/zlic/` (lib + `config.php` 0600), `public_html/zlic2.php`, ο
χρήστης `clnixpal_zlicro`. Το `zlic.php` δεν άλλαξε (sha256 `6d6fc2a5…`). Αφαίρεση: διαγραφή των δύο
και `uapi --user=clnixpal Mysql delete_user name=clnixpal_zlicro`.

## 2. Δεδομένα σήμερα (nixpal, 2026-10-08)

**Products (`tblproducts`, servertype `licensing`).** Mapping των configoptions, επιβεβαιωμένο με τα screenshots:

| co | Πεδίο | Web Controller (4) | Agent (5) | Node (6) | Controller-Custom (7) | DEVNODE (8) | Firewall (13) |
|---|---|---|---|---|---|---|---|
| 1 | Key Length | 16 | 16 | 16 | 16 | 16 | 16 |
| 2 | Key Prefix | `ZCWeb-` | `ZCAgent-` | `ZCNode-` | `ZCWeb-` | `ZCNode-` | `ZCFirewall-` |
| 3 | Allow Reissue | on | on | on | on | on | on |
| 4 | Allow Domain Conflict | on | on | on | on | on | on |
| 5 | Allow IP Conflict | – | – | – | on | on | – |
| 6 | Allow Directory Conflict | – | on | on | on | on | on |
| 7 | Support/Updates Addon | 0 | 0 | 0 | 0 | 0 | 0 |
| 8 | Secret Key (PSK) | ανά προϊόν | ανά προϊόν | ανά προϊόν | ανά προϊόν | ανά προϊόν | ανά προϊόν |
| 9 | Free Trial | – | – | – | – | – | – |

Οι τιμές των PSK δεν γράφονται εδώ· μεταφέρονται από τη DB στο config του package κατά το import.

**Κλειδιά.** Και τα 1339 είναι `<prefix><16 lowercase hex>` (ZCAgent 721, ZCNode 377, ZCWeb 241).
Κανένα ορφανό (όλα έχουν service).

**`mod_licensing`** (1339): Active 326 · Expired 1003 · Suspended 9 · Reissued 1. Το status ακολουθεί
το service (Active→Active, Suspended→Suspended, Terminated/Cancelled→Expired). 763 έχουν πολλαπλά
domains (comma-separated), 8 πολλαπλές IPs, max reissues 11.

**Addon settings:** `maxreissues=10`, `logprune=90` (ημέρες), `mod_licensingbans` κενός.

**Log:** τελευταίες 7 ημέρες: License Expired 40 944 · License Suspended 6 659 · IP Address Invalid 551.
Οι επιτυχίες δεν γράφονται. Ο όγκος είναι σχεδόν όλος νεκροί agents που συνεχίζουν να ρωτάνε.

## 3. Σχεδιασμός στο Blesta

### 3.1 Module `components/modules/nixpal_licensing`
Ένα module ανά προϊόν άδειας, ρυθμίσεις **ανά package** (τα configoptions του WHMCS χωρίς τα
domain/dir conflict, βλ. §3.3): `key_length`, `key_prefix`, `allow_reissue`, `allow_ip_conflict`,
`secret_key` (PSK), `free_trial`.

- `addService`: κλειδί = `key_prefix` + `bin2hex(random_bytes(key_length/2))` (ίδια μορφή με σήμερα),
  κενά bindings, status Active. Το κλειδί αποθηκεύεται και ως service field (`license_key`) ώστε να
  φαίνεται και να ψάχνεται από τα native views του Blesta.
- `suspendService` / `unsuspendService` / `cancelService` → Suspended / Active / Expired.
- `renewService` δεν χρειάζεται τίποτα: το `nextduedate` διαβάζεται live από το service.
- **Client tab «Άδεια»**: key, δεμένη IP, τελευταίο rDNS / τελευταία πρόσβαση (πληροφοριακά), μήνυμα «θα ανιχνευθεί στο
  επόμενο check» όταν η IP είναι κενή, κουμπί **Reissue** (αν το επιτρέπει το package και reissues < maxreissues).
  Reissue = status `Reissued` + καθαρισμός bindings + reissues++· το κλειδί μένει ίδιο και στο
  επόμενο check γίνεται auto-bind και επιστρέφει σε Active (όπως στο WHMCS· να επιβεβαιωθεί με fixture).
- **Admin tab**: όλα τα παραπάνω + χειροκίνητη επεξεργασία bindings, αλλαγή status, reset reissues,
  τα τελευταία log entries αυτής της άδειας.

### 3.2 Plugin `plugins/nixpal_licensing`
- Πίνακες: `nixpal_licenses` (ίδια πεδία με `mod_licensing` + `whmcs_id` για το import),
  `nixpal_license_log`, `nixpal_license_bans`.
- **Κονσόλα admin** (αντίστοιχη του Licensing Manager): Home (σύνολα ανά status/προϊόν),
  Search/Browse (key, domain, IP, client, status), Access Logs (φίλτρα domain/IP/dir/message,
  σελιδοποίηση), Ban Control, Settings (`maxreissues`, `logprune`).
- Cron task: prune του log μετά από `logprune` ημέρες.
- **Public controller `zlic`**: ίδιο input/output με το σημερινό `zlic.php`, αλλά in-process
  (χωρίς curl προς verify.php):
  1. 400 + `Missing license or license_PSK` αν λείπουν.
  2. lookup κλειδιού → αν δεν υπάρχει: `Invalid` / `Invalid Key`.
  3. PSK ≠ `secret_key` του package → `Invalid` / `MD5 Checksum Verification Failed`.
  4. status Suspended / Expired → αντίστοιχα messages.
  5. **μόνο IP binding** (βλ. §3.4): auto-bind όταν είναι κενό, σεβασμός του `allow_ip_conflict`.
     Λάθος IP → `{"status":"Invalid","message":"IP Address Invalid"}`.
  6. Active → πεδία όπως του verify.php + `localkey` (ίδιος αλγόριθμος) + `remotecheck: true`.
  7. log μόνο αποτυχιών (όπως σήμερα) + `lastaccess`.
- Υλοποιημένο στο `lib/nixpal-licensing` (`Zlic`, `LocalKey`), με tests απέναντι στα fixtures και στον
  αυτούσιο κώδικα του zlic.php. Σειρά ελέγχων όπως στο WHMCS: κλειδί → Suspended/Expired → IP → PSK.
  Σκόπιμες διαφορές: (α) λάθος PSK δεν κάνει ποτέ bind (στο WHMCS το verify.php δεν βλέπει τον PSK
  και θα έδενε), και γράφεται στο log ως `MD5 Checksum Verification Failed`· (β) reverse DNS μόνο
  όταν γίνεται bind ή γράφεται log, όχι σε κάθε επιτυχημένο check.
- Η καθαρή λογική (localkey encode/decode, έλεγχος bindings, παραγωγή κλειδιού) σε κλάσεις χωρίς
  εξάρτηση από Blesta, με unit tests απέναντι στα `tests/fixtures/zlic/`.
- **Χωρίς rewrite:** το `zlic.php` μένει αρχείο στο docroot (`lib/nixpal-licensing/public/zlic.php`)
  και διαβάζει απευθείας τη DB μέσω repository (`whmcs` σήμερα, `blesta` με το plugin). Το
  `.htaccess` του Blesta στέλνει στο `index.php` μόνο ό,τι δεν είναι υπαρκτό αρχείο, οπότε στο
  cut-over το ίδιο αρχείο σερβίρεται ως έχει. Το plugin μόνο διαχειρίζεται τα δεδομένα.
- **Shadow-run:** το ίδιο αρχείο ως `zlic2.php` με `schema = whmcs` και `read_only = true`, δίπλα
  στο παλιό, πριν υπάρξει καν το Blesta.
- Προαιρετικά: cache/rate-limit για Expired/Suspended (≈ 47k αιτήματα/εβδομάδα που δεν αλλάζουν ποτέ απάντηση).

### 3.3 Απόφαση: binding μόνο σε IP (2026-10-08)
Το domain είναι το rDNS της IP του client και το directory είναι πάντα το path του zlic, άρα δεν
προσθέτουν έλεγχο. Ο τύπος της άδειας προκύπτει από το prefix του κλειδιού και τον PSK.
Άρα: **κλειδί + PSK (προϊόν) + IP**.
- Το rDNS και η τελευταία IP καταγράφονται (log, `lastaccess`, κονσόλα) μόνο για ενημέρωση.
- Στην απάντηση μένουν τα `validdomain`/`validdirectory` (για το ίδιο σχήμα JSON), αλλά δεν ελέγχονται.
- Από τις ρυθμίσεις του package φεύγουν τα `allow_domain_conflict` / `allow_dir_conflict`.
  Μένουν τα `allow_ip_conflict`, `allow_reissue`, `key_prefix`, `key_length`, `secret_key`, `free_trial`.
- Το import κρατάει τα υπάρχοντα `validip` αυτούσια· τα `validdomain`/`validdirectory` μεταφέρονται ως πληροφορία.

### 3.3.1 Κύκλος ζωής υπηρεσίας στο Blesta (review 2026-10-08)
Το Blesta δημιουργεί την υπηρεσία με την παραγγελία (`pending`, ή `in_review` όσο το order δεν έχει γίνει
accept) και καλεί το `addService()` του module **και τότε και στην ενεργοποίηση**. Η δεύτερη κλήση παίρνει
μόνο τα vars της αλλαγής, όχι τα πεδία της υπηρεσίας (`app/models/services.php`, Blesta 6.0).
- Το κλειδί δημιουργείται με την παραγγελία (το βλέπει ο πελάτης), αλλά **εγγραφή στο `nixpal_licenses`
  (αυτό που διαβάζει το zlic) γίνεται μόνο όταν η υπηρεσία είναι active ή suspended**. Μια απλήρωτη
  παραγγελία δεν απαντά Active. Ίδιο με το WHMCS, που φτιάχνει το `mod_licensing` στο module create.
- Στην ενεργοποίηση το plugin (`Services.editBefore`) δίνει στο `addService()` το κλειδί που έχει ήδη η
  υπηρεσία, αλλιώς θα έβγαινε νέο. Η εγγραφή γίνεται στο `Services.editAfter`.
- Το zlic απαντά με βάση και το status της υπηρεσίας: canceled → Expired, suspended/pending → Suspended.
- Λογική στο `lib/.../ServiceLifecycle.php`· test όλης της σειράς με τις πραγματικές κλάσεις plugin/module
  σε `tests/Blesta/`.

### 3.4 Import / migration (`migration/import_licensing.php`)

Ο importer του Blesta φέρνει πελάτες, προϊόντα, υπηρεσίες, invoices και tickets, αλλά **όχι** τις άδειες:
ο πίνακας `mod_licensing` ανήκει στο Licensing Addon του WHMCS, που ο importer δεν γνωρίζει. Άρα
χρειάζεται δικό μας βήμα μετά τον importer:

1. **Packages.** Για κάθε προϊόν licensing (4, 5, 6, 7, 8, 13): module → `nixpal_licensing`, και package
   meta από τα configoptions: `key_prefix` (co2), `key_length` (co1), `secret_key` (co8),
   `allow_reissue` (co3), `allow_ip_conflict` (co5), `free_trial` (co9).
2. **Αντιστοίχιση υπηρεσιών WHMCS → Blesta:** ο importer κρατάει το id του WHMCS στο
   `services.id_value` (επιβεβαιωμένο από τον κώδικά του, `docs/importer.md`), άρα
   `mod_licensing.serviceid = services.id_value`. Τα packages μπορούν να μπουν κατευθείαν στο
   `nixpal_licensing` με δικό μας mapping file `licensing.php` στον importer, αντί για το βήμα 1.
3. **Άδειες.** Για κάθε γραμμή `mod_licensing`: service field `license_key` και γραμμή στο
   `nixpal_licenses` με **το ίδιο κλειδί αυτούσιο**, bindings, reissues, status, lastaccess,
   `custom_fields` και `whmcs_id`. Δεν παράγεται κανένα νέο κλειδί: το prefix απλώς ταξιδεύει μέσα
   στο κλειδί, και το `key_prefix` του package χρειάζεται μόνο για τις νέες άδειες.
4. **Log (προαιρετικό).** Μόνο οι τελευταίες 90 ημέρες του `mod_licensinglog` (~680k γραμμές
   σήμερα), αν τις θέλουμε για ιστορικό.
5. **Έλεγχος.** Ίδιος αριθμός ανά status. Μετά shadow-run: κάθε ενεργό κλειδί απαντά ίδια στο
   παλιό και στο νέο endpoint (το ίδιο script σύγκρισης με το shadow-run του WHMCS).

Το script γράφεται αφού δούμε στο staging τι ακριβώς κάνει ο importer με τις υπηρεσίες licensing
(σε ποιο module τις βάζει, αν κρατάει ids και service fields).

### 3.5 Κατάσταση υλοποίησης (2026-10-08)

| Κομμάτι | Πού | Κατάσταση |
|---|---|---|
| Πυρήνας (`LocalKey`, `Zlic`, repositories WHMCS/Blesta) | `lib/nixpal-licensing` | ✅ 54 tests, shadow-run 9/9 στον orion |
| Plugin: πίνακες, κονσόλα, logs, ρυθμίσεις, cron, event `Services.add` | `plugins/nixpal_licensing` | γραμμένο, **μη δοκιμασμένο σε Blesta** |
| `zlic.php` στο docroot του Blesta | `plugins/nixpal_licensing/zlic.php` | ✅ end-to-end tests σε ψεύτικο docroot |
| Module: package options, κλειδί, suspend/cancel, tabs πελάτη/admin, Reissue | `components/modules/nixpal_licensing` | γραμμένο, **μη δοκιμασμένο σε Blesta** |
| Import αδειών | `migration/import_licensing.php` | σχέδιο (§3.4), μετά το staging |

Το Blesta καλεί το `addService()` **πριν** δημιουργηθεί η υπηρεσία, άρα χωρίς id. Το module επιστρέφει
το κλειδί ως service field `license_key`, και η γραμμή στο `nixpal_licenses` δημιουργείται μόλις υπάρξει
id: event `Services.add` του plugin, και lazily από το module (suspend/cancel/tabs).

## 4. Ανοιχτά

1. Reissue: επιβεβαίωση με fixture ότι μετά το Reissue το πρώτο check κάνει auto-bind και επιστρέφει
   σε Active (δεν δοκιμάστηκε για να μη δεθεί άδεια πελάτη στην IP του orion· θέλει δική μας test άδεια).
2. Πότε σταματάει το GET fallback: μόνο όταν δεν υπάρχουν πια C++ clients στο πεδίο (φαίνεται από τα
   User-Agents/GET στο access log του νέου endpoint).
3. Μελλοντικά (zonecloud `docs/roadmap-licensing.md`, Phase A): signed token. Το plugin είναι το
   σωστό σημείο να προστεθεί ως επιπλέον πεδίο, χωρίς να σπάσει τους παλιούς clients.
