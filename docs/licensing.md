# nixpal licensing στο MyCS

Ο πυρήνας είναι το `packages/nixpal-licensing` (`Zlic`, `LocalKey`, με tests απέναντι στον αυθεντικό κώδικα του
zlic.php και shadow-run 9/9 στον orion). Ο σχεδιασμός και τα δεδομένα της nixpal: `docs/history/blesta-modules/licensing.md`.
Εδώ μόνο ό,τι ισχύει στο MyCS.

## Contract (CLAUDE.md)
`clients.nixpal.com/zlic.php`: ίδιο URL, ίδιο input (`license`, `license_PSK`, POST πρώτα, GET για τους C++ clients),
ίδιο JSON, `md5hash`/`localkey` byte-for-byte. Route `/zlic.php` (`routes/zlic.php`, χωρίς session/cookie/CSRF) και
`public/zlic.php` που απλώς φορτώνει το `index.php`, ώστε να δουλεύει και με web server που εκτελεί τα `*.php` απευθείας.
Το `LicensingParityTest` τρέχει τις ίδιες άδειες στους πίνακες του WHMCS και του MyCS και συγκρίνει κάθε απάντηση.

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
unsuspend → Active (ένα Reissued μένει Reissued), terminate → Expired. Reissue (πελάτης: Active, το προϊόν το επιτρέπει,
κάτω από το όριο· staff: πάντα): καθαρίζει τα bindings, status Reissued, +1, log «License Reissued»· το επόμενο check
δένει την άδεια στη νέα IP.

## Πέρα από το WHMCS: σημεία ζωής
Το WHMCS κρατά μόνο `lastaccess`, που αλλάζει και στις αποτυχίες. Εδώ, ανά άδεια: `last_ok_at` (τελευταία απάντηση
Active), `last_seen_ip`, `last_seen_client` (μέθοδος + User-Agent: πόσοι GET/C++ clients ζουν ακόμα), `last_failure_at`
και `last_failure`· και `license_check_days` (ok / failed ανά ημέρα). Οι οθόνες έρχονται στο L2.

## Επόμενα
- **L2 (έγινε):**
  - Πελάτης, σελίδα υπηρεσίας: «License» με κλειδί (αντιγραφή), Valid IPs, status, τα πεδία που αναφέρει (Custom Fields
    Reported, π.χ. `comments`), **Reissue** (όποιος διαχειρίζεται την υπηρεσία, αν το προϊόν το επιτρέπει, κάτω από το
    όριο). Registration Date σε κάθε υπηρεσία. Το domain (= κλειδί) δεν δείχνεται δεύτερη φορά.
  - Staff, Edit Service: ενότητα «License» (κλειδί, status, reissues / όριο, IP/domain/directory, τελευταίο καλό check,
    από πού και με τι client, τελευταία αποτυχία, checks 30 ημερών)· **Reissue license** (όποιο κι αν είναι το όριο, όπως
    το WHMCS) και **Edit license** (status, bindings, με audit)· το access log της άδειας.
  - Κονσόλα «Licenses» (Services, και top menu): όλες, **Silent** (ενεργές χωρίς καλό check 7 μέρες), **Expired but
    calling** (ληγμένες/ανεσταλμένες που ρώτησαν τις 7 τελευταίες μέρες)· η γραμμή ανοίγει την υπηρεσία. Δικαιώματα
    των υπηρεσιών.
- **L3:** το **εργαλείο** import (έτοιμο και ελεγμένο με parity, **όχι** import στο live πριν το cut-over: απόφαση
  2026-10-15) από `mod_licensing` (ίδια κλειδιά, bindings, reissues, status, `whmcs_id`· PSK από `configoption8`
  κρυπτογραφημένο στο προϊόν· `comments` στις ιδιότητες της υπηρεσίας), cache/rate-limit για Expired/Suspended
  (~209k checks τον μήνα).
