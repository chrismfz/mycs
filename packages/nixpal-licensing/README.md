# nixpal/licensing

Καθαρή PHP (≥ 7.4), χωρίς εξάρτηση από Blesta: η λογική του licensing server που χρησιμοποιούν
το module `nixpal_licensing` και το plugin `nixpal_licensing`.

| Κλάση | Τι κάνει |
|---|---|
| `LocalKey` | encode/decode του WHMCS-compatible localkey, byte-for-byte ίδιο με το `reference/whmcs/zlic.php` |
| `Zlic` | ό,τι κάνουν σήμερα μαζί zlic.php + verify.php, in-process: έλεγχος κλειδιού/status/IP/PSK, auto-bind, log, απάντηση JSON |
| `License`, `LicenseRepository` | το μοντέλο και το interface αποθήκευσης· το plugin δίνει την υλοποίηση πάνω στη DB του Blesta |
| `ZlicResponse` | HTTP status + body, `json()` με τα ίδια flags με το zlic.php |
| `WhmcsLicenseRepository` | το repository πάνω στους πίνακες του WHMCS Licensing Addon· `read_only` για shadow-run |
| `public/zlic.php` | το αρχείο που μπαίνει στο docroot ως `zlic.php` (ίδιο URL με σήμερα) |

```sh
composer install
composer test
```

Τα tests συγκρίνουν με `tests/Oracle/ZlicReference.php` (αυτούσιος κώδικας του zlic.php) και
χρησιμοποιούν τα fixtures του `tests/fixtures/zlic/`.

## Εγκατάσταση του endpoint

Το `zlic.php` μένει αρχείο στο docroot (σήμερα του WHMCS, αύριο του Blesta), ώστε το
`clients.nixpal.com/zlic.php` να μην αλλάξει ποτέ. Η βιβλιοθήκη και τα credentials μένουν έξω από το docroot.

```
/home/<user>/public_html/zlic.php     ← public/zlic.php
/home/<user>/zlic/                    ← αυτός ο φάκελος (src/, vendor/ από composer install --no-dev)
/home/<user>/zlic/config.php          ← από config.example.php, ΔΕΝ μπαίνει στο git
```

Αν ο φάκελος δεν είναι δίπλα στο docroot, ορίζεται με `NIXPAL_ZLIC_HOME`. Αν αποτύχει η σύνδεση
στη DB ή το config, το endpoint απαντά `Remote Check Failed`, όπως το παλιό zlic.php όταν δεν
απαντούσε το verify.php· οι clients πέφτουν στο αποθηκευμένο due date.

### Shadow-run πάνω στο WHMCS
`schema = whmcs`, `read_only = true`, ως `zlic2.php` δίπλα στο παλιό. Ίδια DB, καμία εγγραφή
(ούτε bind, ούτε log, ούτε lastaccess), ώστε να συγκρίνουμε απαντήσεις χωρίς να αλλάξει τίποτα.
