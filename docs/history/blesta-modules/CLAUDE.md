# CLAUDE.md

Οδηγίες για όποιον (άνθρωπο ή agent) δουλεύει σε αυτό το repo.

## Πλαίσιο
- Πρώτα το `docs/HANDOFF.md`. Ό,τι αποφασίζεται στην πορεία ενημερώνεται εκεί (ή σε `docs/`), όχι μόνο στο chat.
- Απόφαση: **Blesta = control plane, Ekdosi = μόνο τιμολόγηση/myDATA.** Το Ekdosi μιλάει με το Blesta
  μόνο μέσω του `ekdosi_bridge` και του `BlestaBillingSource`. Μην ξαναχτίζεις στο Ekdosi κάτι που ανήκει εδώ.
- Pilot είναι η nixpal· το myip.gr έρχεται τελευταίο. Το WHMCS της nixpal είναι month-to-month, όχι σκληρή προθεσμία.

## Κανόνες ασφάλειας και δεδομένων
- **Ποτέ secrets στο repo:** credentials, API keys, PSKs, HMAC secrets, EPP passwords, vPOS shared secrets.
  Οι ρυθμίσεις μπαίνουν στα config των modules/gateways του Blesta ή σε `.env` που δεν γίνεται commit.
- **Ποτέ πραγματικά δεδομένα πελατών:** dumps, license keys, domains, IPs, emails, ΑΦΜ. Τα fixtures
  ανωνυμοποιούνται πριν το commit (keys/domains/IPs αντικαθίστανται με σταθερές τιμές).
- Αρχεία στο `reference/whmcs/` καθαρίζονται από secrets πριν μπουν. Μόνο δικός μας κώδικας·
  όχι ionCube-encoded αρχεία ή κώδικας vendor (π.χ. Licensing Addon, `greppcore`).
- Στους servers παραγωγής (rigel, orion) μόνο read-only, εκτός αν ζητηθεί ρητά.

## Contracts που δεν σπάνε
- `zlic.php`: ίδιο URL, ίδιο input (`license`, `license_PSK`, POST ή GET), ίδιο JSON output.
  Τα `md5hash` και `localkey` πρέπει να βγαίνουν **byte-for-byte** ίδια με το WHMCS. Κάθε αλλαγή εκεί
  ελέγχεται απέναντι στα `tests/fixtures/zlic/`.
- Ekdosi bridge: ίδιο HMAC σχήμα (`X-Webhook-Signature: sha256=…`), ίδια ops και error codes (HANDOFF §2.2).
- Αρίθμηση invoices: το Blesta συνεχίζει από > 32453 και τα παλιά IDs αναζητούνται μέσω id map.

## Κώδικας
- PHP συμβατή με την έκδοση που απαιτεί η έκδοση του Blesta στο staging (να επιβεβαιωθεί, βλ. HANDOFF §3).
- Ακολούθησε τις συμβάσεις του Blesta: `plugins/<name>/<name>_plugin.php`, `config.json`, MVC,
  `getEvents()`, `getCronTasks()`, `components/modules/<name>/<name>.php` (extends `Module`).
- Η λογική που δεν εξαρτάται από το Blesta (EPP client, αλγόριθμος localkey, digest Cardlink, allocator)
  μπαίνει σε καθαρές κλάσεις με δικά τους unit tests, ώστε να τρέχουν και χωρίς Blesta.
- Κατά το port από Ekdosi (Laravel): χωρίς Eloquent/facades· Blesta models/Record, cURL/Guzzle, config από το module.
- Strings προς τον πελάτη στα language files του Blesta (`language/en_us`, `language/el_gr`).

## Git
- Μικρά, αυτοτελή commits με σαφές μήνυμα. Ένα plugin/module ανά PR όπου γίνεται.
