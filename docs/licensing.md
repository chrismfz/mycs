# nixpal licensing στο MyCS

Ο πυρήνας είναι το `packages/nixpal-licensing` (`Zlic`, `LocalKey`, με tests απέναντι στον αυθεντικό κώδικα του
zlic.php και shadow-run 9/9 στον orion). Ο σχεδιασμός και τα δεδομένα της nixpal: `docs/history/blesta-modules/licensing.md`.
Εδώ μόνο ό,τι ισχύει στο MyCS.

## Contract (CLAUDE.md)
`clients.nixpal.com/zlic.php`: ίδιο URL, ίδιο input (`license`, `license_PSK`, POST πρώτα, GET για τους C++ clients),
ίδιο JSON, `md5hash`/`localkey` byte-for-byte. Route `/zlic.php` (`routes/zlic.php`, χωρίς session/cookie/CSRF) και
`public/zlic.php` που απλώς φορτώνει το `index.php`, ώστε να δουλεύει και με web server που εκτελεί τα `*.php` απευθείας.
Το `LicensingParityTest` τρέχει τις ίδιες άδειες στους πίνακες του WHMCS και του MyCS και συγκρίνει κάθε απάντηση.

**Rate limit (#254, `ZlicController`, `ZlicLimiter`):** ανά «καλούντα» (μια IPv4, ή ένα /64 της IPv6), την ώρα:
60 αναζητήσεις (άγνωστο κλειδί, παράμετρος που λείπει) και 60 λάθος απαντήσεις μιας γνωστής άδειας (άλλη IP, λάθος PSK)·
η δεύτερη ανά άδεια, ώστε μια άδεια που αποτυγχάνει να μην κόβει τις άλλες της ίδιας IP. Ένα ζευγάρι καλούντα και
κλειδιού που πήρε υπογεγραμμένη απάντηση (Active, Suspended, Expired) δεν κόβεται ποτέ για 30 ημέρες: ούτε ένας γείτονας
που ψάχνει κλειδιά το κλειδώνει, ούτε ο κάτοχος μιας suspended άδειας τη γυρίζει σε «Remote Check Failed». Κάθε έλεγχος
μετριέται πριν απαντηθεί (ατομικά, σε δικό του πίνακα `zlic_limits`· το increment του `file` cache χάνει hits) και
επιστρέφεται όταν δεν ήταν αυτό που μετρά ο μετρητής. Πάνω από όριο: «Remote Check Failed» (ό,τι έλεγε το zlic.php όταν
δεν μπορούσε να ελέγξει· οι clients μένουν στο local key τους), χωρίς ερώτημα στις άδειες· μια άδεια που κόβεται
γράφεται μία φορά τη μέρα ως αποτυχία «Rate limited». Το reissue μηδενίζει τους μετρητές της. Αν οι μετρητές δεν
δουλεύουν, ο έλεγχος απαντιέται κανονικά. Τα όρια με βάση τις 90 ημέρες της nixpal στο WHMCS (2026-10-20): το πολύ 11
λάθος απαντήσεις την ώρα από μία IP, 3 άγνωστα κλειδιά συνολικά, έως 4 άδειες ανά IP, 21 έλεγχοι το λεπτό. Ένας νέος
client (χωρίς υπογεγραμμένη απάντηση ακόμα) σε IP όπου κάποιος ψάχνει κλειδιά περιμένει έως την ώρα. Μαζικό flood:
στο edge (angie), όχι εδώ. Οι μετρητές καθαρίζουν στο cron (`zlic_limits_pruned`).

## Πού ζουν
| | MyCS | WHMCS |
|---|---|---|
| Άδεια | `licenses` (μία ανά υπηρεσία) | `mod_licensing` |
| Access log | `license_access_logs`, 90 ημέρες (cron `license_logs_pruned`) | `mod_licensinglog`, `logprune` 90 |
| Ρυθμίσεις προϊόντος | server extension **nixpal Licensing**: Key Prefix, Key Length, Secret Key (PSK, κρυπτογραφημένο), Allow Reissue, Allow IP Conflict, Custom Fields Reported | configoptions 2, 1, 8, 3, 5· custom fields του προϊόντος |
| Όριο reissue | `Licenses::MAX_REISSUES` = 10 | `maxreissues` 10 |

Binding μόνο σε IP (απόφαση 2026-10-08): domain (rDNS) και directory κρατιούνται ως πληροφορία. Domain/Directory
Conflict, Free Trial και Support/Updates Addon δεν μεταφέρονται (σε όλα τα προϊόντα: on/on/off/None, άρα χωρίς επίδραση).

## Κύκλος ζωής
Create → κλειδί `prefix + hex` (ίδια μορφή με σήμερα), Active· δεύτερο create κρατά το κλειδί. Το κλειδί γράφεται
και ως **domain της υπηρεσίας**, όπως στο WHMCS (1.340/1.341 της nixpal): φαίνεται στις λίστες, στον πελάτη και στην
αναζήτηση· ένα domain που έχει ήδη η υπηρεσία κρατιέται. Suspend → Suspended,
unsuspend → Active (ένα Reissued μένει Reissued), terminate → Expired. Το Expired είναι τελικό, με μία εξαίρεση (sweep 10,
S10-PROVISIONING-1): το **«Create»** του staff σε υπηρεσία που ξανάγινε Active μετά το τέλος της κάνει την άδεια πάλι
Active, με το ίδιο κλειδί και τα ίδια bindings· σε υπηρεσία που δεν τρέχει μένει Expired. Άρα: αφού φέρεις πίσω μια
υπηρεσία licensing που είχε λήξει, πάτα «Create» (όχι «Unsuspend»)· αν την έβαλες Pending, το πρώτο «Create» την ξεκινά
και χρειάζεται **δεύτερο** για την άδεια. Το «Unsuspend» δεν την επαναφέρει (review του #808, REV-808-1): δεν ξεχωρίζει
το staff από το unsuspend μιας πληρωμής, και μια άδεια που το staff έκανε Expired με το χέρι (ή ήρθε Expired από το
WHMCS) δεν πρέπει να ζωντανέψει με μια καθυστερημένη πληρωμή. Reissue (πελάτης: Active, το προϊόν το επιτρέπει,
κάτω από το όριο· staff: πάντα): καθαρίζει τα bindings, status Reissued, +1, log «License Reissued»· το επόμενο check
δένει την άδεια στη νέα IP.

## Πέρα από το WHMCS: σημεία ζωής
Το WHMCS κρατά μόνο `lastaccess`, που αλλάζει και στις αποτυχίες. Εδώ, ανά άδεια: `last_ok_at` (τελευταία απάντηση
Active), `last_seen_ip`, `last_seen_client` (μέθοδος + User-Agent: πόσοι GET/C++ clients ζουν ακόμα), `last_failure_at`
και `last_failure`· και `license_check_days` (ok / failed ανά ημέρα). Οι οθόνες έρχονται στο L2.

## Τηλεμετρία: `/licensing/v1/check` (2026-11-15)
Ο έλεγχος των νέων releases του zonecloud. Ο controller (zcloudweb) μένει στο zlic.php προς το παρόν (απόφαση
ιδιοκτήτη 2026-11-15).
- **Η απάντηση είναι του zlic.php, byte-for-byte.** Ίδιος κώδικας (`ZlicController::answer`), ίδια όρια, ίδιο JSON
  (`md5hash`, `localkey`). Ένας client που γυρίζει στο zlic.php όταν αυτό δεν απαντά διαβάζει το ίδιο και από τα δύο.
  Απαντά και σε deploy και δεν «καθαρίζει» το input, όπως το zlic.php. Μόνο POST, JSON ή form, με `license` και
  `license_PSK` όπως στο zlic.php. Είναι έξω από το `api/*` για να μην πάρει τα CORS headers του Laravel, που το
  zlic.php δεν έχει (review 2-4 του #863).
- **`telemetry`, προαιρετικό:** `product` (agent/node/controller), `version`, `build_time`, `commit`, `sha256` (του
  binary), `install_id` (UUID που φτιάχνεται μία φορά στην εγκατάσταση), `client_time` (unix ή ημερομηνία), `os`,
  `arch`, `licensed` (bool), `failures` (int), `token` (`ok`, `bad` ή `none`: ο έλεγχος του token από τον client). Κάθε πεδίο ελέγχεται μόνο του: ό,τι λείπει ή είναι λάθος μένει έξω,
  **ποτέ δεν απορρίπτεται το request**. Ένα `build_time` στο μέλλον μένει έξω.
- **Πού γράφεται:** `license_installs`, μία γραμμή ανά άδεια, IP, product και `install_id`, **μετά** την απάντηση.
  Κρατά την τελευταία έκδοση και την προηγούμενη, πόσα checks έγιναν, πότε φάνηκε πρώτη και τελευταία φορά, και το
  `downgraded_at` όταν ένα build παλιότερο από αυτό που έτρεχε πριν ξαναφανεί. Οι ημερομηνίες κρατιούνται στη ζώνη
  ώρας της εφαρμογής, όπως όλες.
- **Μόνο από εκεί που τρέχει η άδεια.** Το PSK είναι του προϊόντος και βρίσκεται σε κάθε client, άρα δεν αποδεικνύει
  τίποτα. Γράφεται μετά από «Active», που δίνεται μόνο σε δεμένη ή καταχωρημένη IP. Μετά από «Suspended» ή «Expired»,
  που υπογράφονται για κάθε IP, γράφεται μόνο όταν η IP είναι μία από τις δεμένες της άδειας ή της λίστας της bulk.
  Τίποτα για «Invalid», για ένα άγνωστο κλειδί, ή για απάντηση από όριο ή σφάλμα («Remote Check Failed»).
- Ένα κλειδί που μπήκε σε bulk γράφεται στην bulk· η σελίδα της δικής του υπηρεσίας δείχνει «None reported».
- Το πολύ 1.000 εγκαταστάσεις ανά άδεια **των τελευταίων 30 ημερών**. Οι παλιότερες περιμένουν το retention και δεν
  μπλοκάρουν τις νέες. Ένα σφάλμα εδώ δεν αλλάζει ποτέ την απάντηση. Retention: 400 μέρες από το τελευταίο check
  (Data Retention).
- **Deploy:** πρώτα το migration (`license_installs`). Χωρίς αυτό η κονσόλα Licenses βγάζει 500. Το endpoint όχι:
  πιάνει τα δικά του σφάλματα.
- **Staff:**
  - Κονσόλα Licenses: στήλες «Version» (της πιο πρόσφατης εγκατάστασης), «Installs» (των τελευταίων 7 ημερών) και
    «API» (2026-11-15). Η «API» δείχνει από ποιο endpoint ήρθε το τελευταίο check, πετυχημένο ή όχι: `v0` για το zlic.php,
    `v1` για το `/licensing/v1/check`. Το κρατά το `licenses.last_endpoint`. Οι στήλες Client και Product κόβονται,
    και το πλήρες όνομα φαίνεται στο hover.
  - Tab **Signals**, με ό,τι φάνηκε τις τελευταίες 7 μέρες:
    - downgrade σε παλιότερο build·
    - ρολόι πάνω από μία μέρα μακριά από το δικό μας (ένα ρολόι γυρισμένο πίσω κρατά ένα localkey έγκυρο)·
    - μια άδεια που δεν είναι bulk και τρέχει σε πάνω από μία εγκατάσταση. Μετά από reissue σε νέο server
      φαίνονται δύο για λίγες μέρες· το staff κρίνει.
  - Σελίδα υπηρεσίας: «Installs», οι 10 πιο πρόσφατες σε μία γραμμή η καθεμία.

## Signed token (2026-11-15, λειτουργία σκιάς)
Το localkey υπογράφεται με το PSK, που υπάρχει σε κάθε client, άρα πλαστογραφείται. Το token υπογράφεται με ed25519:
το private key υπάρχει **μόνο** στον MyCS και οι clients κρατούν το public. Ούτε ένας patched client ούτε ένας ψεύτικος
license server μπορεί να φτιάξει token.
- **Πότε:** μόνο στο `/licensing/v1/check`, μόνο μαζί με «Active», και μόνο όταν έχει οριστεί κλειδί. Μπαίνει ως νέο
  πεδίο `token`. Όλα τα άλλα πεδία της απάντησης μένουν του zlic.php, μαζί με τα `md5hash` και `localkey`. Το zlic.php
  δεν δίνει ποτέ token. Χωρίς κλειδί, ή με χαλασμένο κλειδί, η απάντηση πάει χωρίς token, όπως πριν.
- **Μορφή:** `<kid>.<payload>.<signature>` σε base64url. Η υπογραφή καλύπτει ακριβώς τα bytes `<kid>.<payload>`, οπότε
  κανείς client δεν χρειάζεται να ξαναφτιάξει JSON. Το payload περιέχει `v` (1), `kid`, `key`, `ip` (του caller),
  `install` (το `install_id` της τηλεμετρίας ή ''), `status`, `due` (το due date της υπηρεσίας ή null), `iat`, `exp`. Ο κώδικας είναι στο
  `packages/nixpal-licensing/src/Token.php`. Ένα σταθερό δείγμα για τα tests των clients, με δοκιμαστικό κλειδί, είναι
  στο `packages/nixpal-licensing/tests/fixtures/token/v1.json`. Το κλειδί του δεν γίνεται δεκτό ως πραγματικό.
- **Διάρκεια:** 30 μέρες από το check, **όποιο κι αν είναι το due date** (απόφαση ιδιοκτήτη 2026-11-15, επιλογή Α).
  Αποφασίζει μόνο το status: μια υπηρεσία που έχει ξεπεράσει το due date αλλά είναι ακόμα Active (όχι ακόμα σε αναστολή)
  παίρνει token, και μια υπηρεσία σε αναστολή δεν παίρνει κανένα. Το `due` μπαίνει στο token μόνο για πληροφορία. Ο
  client παίρνει καινούργιο σε κάθε check (κάθε 12 ώρες), οπότε καμία άδεια δεν φτάνει το όριο όσο βλέπει τον server. Τα
  30 μέρες είναι πόσο μπορεί να δουλέψει ένας client χωρίς να μας βρει, **όταν** το token αρχίσει να αποφασίζει. Η
  απόρριψη ήταν το «ως το due date»: θα έδινε σε μια υπηρεσία που έχει ξεπεράσει το due date, αλλά είναι ακόμα Active,
  token που έχει λήξει ήδη από τη στιγμή που εκδίδεται.
- **Κλειδί:** `php artisan mycs:license-token-key` φτιάχνει ένα ζεύγος. Το secret πάει στο `.env`
  (`MYCS_LICENSE_TOKEN_KEY`, `MYCS_LICENSE_TOKEN_KID`), ποτέ στο repo, στη βάση ή σε log. Το public key πάει στους
  clients μαζί με το kid. Με `--show` εμφανίζεται το public key του κλειδιού που έχει ήδη οριστεί. Ένα κλειδί ανά
  εταιρεία (instance). Για rotation: νέο kid, και οι clients κρατούν και το παλιό και το νέο public key για όσο χρειαστεί.
- **Τι ελέγχει ένας client** (το `Token::verify` κάνει τα τρία πρώτα, τα υπόλοιπα είναι του client):
  1. τρία μέρη χωρισμένα με `.`, και το `kid` είναι ένα από τα public keys που κρατά·
  2. η υπογραφή ed25519 επαληθεύεται πάνω στα bytes `<kid>.<payload>`, όπως ακριβώς ήρθαν·
  3. στο payload: `v` = 1, το `kid` ίδιο με του πρώτου μέρους, και `exp` ≥ τώρα·
  4. το `key` είναι το δικό του κλειδί, το `install` είναι το δικό του `install_id` (αυτό που στέλνει στην τηλεμετρία),
     και το `status` είναι `Active`. Χωρίς το `install`, μία δεμένη εγκατάσταση θα μπορούσε να ανανεώνει token κάθε
     12 ώρες και να το μοιράζει σε αντίγραφα μέσω ενός ψεύτικου server (review του #864)·
  5. το `iat` δεν είναι στο μέλλον (με λίγα λεπτά ανοχή για το ρολόι) και `exp − iat` ≤ 30 μέρες·
  6. το `ip` είναι η IP από την οποία τον είδε ο server. Πίσω από NAT ή proxy ο client δεν την ξέρει, οπότε **δεν**
     τη συγκρίνει. Την κρατά μόνο για πληροφορία ή για log·
  7. η πιο πρόσφατη απάντηση του server υπερισχύει: αν ήρθε Suspended ή Expired, ένα token που ισχύει ακόμα **δεν**
     το παρακάμπτει.
- **Σκιά:** ο client ελέγχει το token που πήρε και στο επόμενο check το αναφέρει στην τηλεμετρία
  (`token`: `ok`, `bad` ή `none`). Το `token_check` στο `license_installs` το κρατά. Ένα `bad` εμφανίζεται στα Signals
  και στη σελίδα της υπηρεσίας. **Το token δεν αποφασίζει τίποτα** μέχρι για εβδομάδες να μην εμφανιστεί κανένα `bad`
  (zonecloud `docs/roadmap-licensing.md`, Phase A).

## Επόμενα
- **L2 (έγινε):**
  - Πελάτης, σελίδα υπηρεσίας: «License» με κλειδί (αντιγραφή), Valid IPs, status, τα πεδία που αναφέρει (Custom Fields
    Reported, π.χ. `comments`), **Reissue** (όποιος διαχειρίζεται την υπηρεσία, αν το προϊόν το επιτρέπει, κάτω από το
    όριο). Registration Date σε κάθε υπηρεσία. Το domain (= κλειδί) δεν δείχνεται δεύτερη φορά.
    Από 2026-10-28 (#493) και ό,τι βλέπει το staff για το πού είναι δεμένη: Valid domains (το reverse DNS της IP· όχι όταν είναι η ίδια η IP, χωρίς reverse DNS),
    «Last good check» (`last_ok_at`, ήταν «Last check») και «Last seen from» (`last_seen_ip`, μόνο από καλό check· όχι όσο είναι Reissued, π.χ. μετά από μεταφορά σε άλλον πελάτη). Όχι
    ο client του check (`last_seen_client`, του staff), ούτε το directory όταν είναι το docroot του zlic
    (`ZlicController::DIRECTORY`, σε όλες σχεδόν: δεν λέει τίποτα στον πελάτη). Στη λίστα υπηρεσιών το κλειδί κάθε
    άδειας, με αντιγραφή, δίπλα στις IPs.
  - Staff, Edit Service: ενότητα «License» (κλειδί, status, reissues / όριο, IP/domain/directory, τελευταίο καλό check,
    από πού και με τι client, τελευταία αποτυχία, checks 30 ημερών)· **Reissue license** (όποιο κι αν είναι το όριο, όπως
    το WHMCS) και **Edit license** (status, bindings, με audit)· το access log της άδειας.
  - Κονσόλα «Licenses» (Services, και top menu): όλες, **Silent** (ενεργές χωρίς καλό check 7 μέρες), **Expired but
    calling** (ληγμένες/ανεσταλμένες που ρώτησαν τις 7 τελευταίες μέρες), **Went quiet after expiry** (2026-11-14:
    έτρεχαν μετά το τέλος τους, η τελευταία τους απάντηση ήταν «Expired»/«Suspended», και σώπασαν πάνω από 7 μέρες,
    ως 90 πίσω: ή τις ξήλωσαν ή κάτι τις κρατά ζωντανές χωρίς να ρωτούν· το staff κοιτάζει. Όχι όσες είχαν καλό check
    μετά από εκείνη την απάντηση, ούτε όσες μπήκαν σε bulk. Διαβάζει το status της γραμμής: μια άδεια που απαντά Expired
    επειδή έληξε η υπηρεσία, ενώ η γραμμή μένει Active, φαίνεται στο Silent. Οι άδειες από το WHMCS δεν έχουν
    `last_failure`: όσες σώπασαν πριν το cut-over δεν φαίνονται εδώ)· η γραμμή ανοίγει την υπηρεσία. Δικαιώματα
    των υπηρεσιών.
- **L3 (έγινε, μόνο το εργαλείο· όχι import στο live πριν το cut-over: απόφαση 2026-10-15):**
  Τα **licensing products** με τις ρυθμίσεις τους (όχι τα licenses) είναι κατάλογος και έρχονται με το
  `app:import-whmcs-catalog` (απόφαση ιδιοκτήτη 2026-10-16).
  - `App\Services\Licensing\WhmcsLicensingImport`, μέσα στο `app:import-from-whmcs` (μετά τις υπηρεσίες) και μόνο του:
    `php artisan app:import-whmcs-licensing <whmcs_db> [user] [host] [port] [--log-days=90]` (μόνο ανάγνωση στο WHMCS,
    ο κωδικός με prompt).
  - Φέρνει: `mod_licensing` (ίδια κλειδιά, bindings· τα κενά του WHMCS = null, reissues, status, `lastaccess`,
    `whmcs_id`)· τα προϊόντα (`servertype = licensing` ή με άδειες) στον server **nixpal Licensing**, με ρυθμίσεις από
    configoption 1 (μήκος), 2 (prefix), 3 (reissue), 5 (IP conflict), 8 (PSK, **κρυπτογραφημένο**, ποτέ στην έξοδο· κενό
    δεν σβήνει αυτό που έβαλε το staff) και τα custom fields του προϊόντος (Custom Fields Reported, χωρίς αυτό που
    προσθέτει το `prepare_whmcs_copy.sh`)· τις τιμές τους ως ιδιότητες της υπηρεσίας· `whmcs_billingcycle` (το tag
    του verify.php)· κλειδί ως domain όπου λείπει· τις τελευταίες 90 μέρες του `mod_licensinglog`.
  - Re-runnable: οι άδειες και οι γραμμές του log ταιριάζουν με το id τους στο WHMCS (και κλειδί που άλλαξε εκεί), οι
    ιδιότητες/ρυθμίσεις με upsert (και τιμή που άδειασε), οι γραμμές που έγραψε το zlic.php του MyCS δεν αγγίζονται.
    Άδειες χωρίς εισαγμένη υπηρεσία, ή με υπηρεσία/κλειδί άλλης άδειας, μετρώνται και παραλείπονται.
  - Byte-for-byte: τα bindings και τα ονόματα των custom fields μένουν όπως είναι (χωρίς trim)· όνομα με κόμμα ή κενά
    στις άκρες κρατιέται ως JSON λίστα στο Custom Fields Reported. Ο κύκλος χρέωσης γράφεται και κενός.
  - Parity: `LicensingImportTest` περνά το `WhmcsSchema` από το εργαλείο και συγκρίνει κάθε απάντηση του zlic.php
    (md5hash, localkey) με του WHMCS.
  - Όγκος: οι νεκροί agents (Expired/Suspended, ~47k checks/εβδομάδα στο WHMCS) γράφουν στο access log **μία φορά τη
    μέρα** ανά άδεια, IP και μήνυμα. Η απάντηση δεν αλλάζει (και αν πέσει το cache: γράφεται η γραμμή), και κάθε check
    μετράει (`license_check_days`, `last_access`). Cache απαντήσεων δεν μπήκε: ~5 checks/λεπτό δεν το χρειάζονται, και το md5hash αλλάζει με το token.
- **Zonecloud Agent Bulk License** (σχέδιο 2026-11-01, ενημ. 2026-11-12, περιμένει έγκριση): μία άδεια με πολλές IPs, χρέωση ανά IP-ημέρα με κλίμακες, μετάβαση των πολλών αδειών με τα παλιά κλειδιά ως ψευδώνυμα: `zonecloud-bulk-license.md`.
