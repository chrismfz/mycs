# Blesta Import Manager (WHMCS → Blesta): τι φέρνει και τι όχι

> Από τον κώδικα του Import Manager του Blesta 6.0.0 (εγκατάσταση `cs.nixpal.com`, account
> `csnixpal` στον orion: `plugins/import_manager/components/migrators/whmcs/`). Ο κώδικας είναι
> ανοιχτός (όχι ionCube), οπότε ό,τι γράφεται εδώ είναι από τον ίδιο τον κώδικα και όχι υπόθεση.
> 2026-10-08.

## Τι εισάγει
`importStaff`, `importClients` (+ contacts, πιστωτικές κάρτες), `importTaxes`, `importCurrencies`,
`importInvoices`, `importTransactions`, `importModules` (servers + registrars ως module rows),
`importPackages` + `importPackageOptions` (configurable options), `importServices`, `importDomains`,
`importSupportDepartments`, `importSupportTickets`, `importAffiliates`, `importKnowledgeBase`,
`importMisc` (calendar/todo, email log, κ.ά.).

**Τα ids του WHMCS κρατιούνται:** `services.id_value = tblhosting.id`, και ανάλογα για πελάτες,
invoices κ.λπ. Άρα κάθε δικό μας import (άδειες, Ekdosi, payments) αντιστοιχίζει WHMCS → Blesta
απευθείας μέσω `id_value`, χωρίς ψάξιμο.

## Πώς διαλέγει module: τα mapping files
Για κάθε WHMCS module ψάχνει `8.0/config/<module>.php` (`Configure::set('<module>.map', [...])`): σε
ποιο Blesta module να πάει, πώς γίνονται τα module rows (servers), τα package meta από τα
`configoptionN`, και τα service fields από τις στήλες του `tblhosting`.
**Αν δεν υπάρχει mapping:** οι υπηρεσίες πάνε στο **Universal Module** (`generic_server`) και τα domains
στο **Generic Domains** (`generic_registrar`). Τα δεδομένα μεταφέρονται (υπηρεσία, πελάτης, τιμή,
ημερομηνίες, domain/username/password, custom fields), αλλά **χωρίς** σύνδεση με τον πραγματικό
server ή registrar: καμία αυτόματη ενέργεια (provision, suspend, renew).

Mappings που υπάρχουν έτοιμα στο 6.0.0:
- **Servers:** cpanel, centovacast, directadmin, plesk, virtualmin, interworx, multicraft, pterodactyl, solusvmpro
- **Registrars:** enom, namecheap, namesilo, opensrs, resellerclub, netearthone, ibs
- **Gateways:** paypal, stripe, authorize

## Τι σημαίνει για εμάς (αποφάσεις 2026-10-08)

| WHMCS (ενεργά) | Mapping | Απόφαση |
|---|---|---|
| cpanel (myip 643, nixpal 12) | ✅ | τίποτα· έλεγχος των module rows/πακέτων μετά |
| centovacast (myip 9) | ✅ | τίποτα· έλεγχος |
| **licensing** (μόνο nixpal, 336) | ✅ δικό μας | `migration/importer-mappings/licensing.php` + `prepare_whmcs_copy.sh` + `import_licensing.php` (migration/README.md) |
| **mailcow** (myip 10) | ✅ δικό μας | Blesta module `mailcow` (lib `lib/mailcow`) + mapping `migration/importer-mappings/mailcow.php`. Στο WHMCS μόνο τα όρια (configoption1-7) ίσχυαν: DKIM, κλείδωμα στο suspend και SSO path διαβάζονταν από configurable options που δεν υπάρχουν (το suspend δεν κλείδωνε ποτέ)· στο Blesta διαβάζονται από το πακέτο. Το configurable option «Extra Addon domain» (1 ενεργή υπηρεσία) μένει option χωρίς αυτοματισμό. |
| **ngm** (myip 1) | ❌ | όχι production ακόμα· τελευταίο, με την ησυχία μας |
| **thesslstorefullv2** (myip 12) | ❌ | δεν βιάζει. Υπάρχει **επίσημο** module TheSSLStore για το Blesta ([packagist](https://root.packagist.org/packages/blesta/thesslstore_module), v2.0.0 2026-04)· mapping προς αυτό αργότερα |
| HetznerCloud (myip 1, module λείπει ήδη στο WHMCS) | ❌ | δεν βιάζει· μόνο εμπορικά third-party ([Blesta Club](https://blesta.club/extensions/hetzner_cloud), [Hetzner Cloud Automation](https://www.blesta.com/forums/topic/27675-hetzner-cloud-automation-for-blesta), ionCube), αλλιώς Universal |
| **pvewhmcs** (8 VMs) | ❌ | mapping προς το Proxmox module του Blesta (vmid, node στα service fields) |
| **grepp** (.gr, 1381 domains) | ❌ | το δικό μας registrar module (υλικό στο Ekdosi) + mapping `grepp.php` |
| **openprovider** (myip 241, nixpal 7) | ✅ δικό μας | δικό μας fork `myopenprovider` (`components/modules/myopenprovider`, βάση το v3.0.1 του Blesta, που δεν ανανεώνει domains· `docs/openprovider.md`)· mapping `migration/importer-mappings/openprovider.php` (row: username, password, test_mode, marker `openprovider_module`· service field `domain`) |
| **cnic** (myip 209, nixpal 4) | ❌ στον importer | το module **υπάρχει**: [CentralNic Reseller για Blesta](https://www.centralnicreseller.com/blesta-modules/) (registrar, DNS, SSL)· mapping, ή μετάβαση σε OpenProvider πριν το import (HANDOFF §6) |
| enom (1) | ✅ | τίποτα |
| paypal, stripe (nixpal 205 σε Stripe) | ✅ | προσοχή στα **PayPal subscriptions** (IPN στο URL του WHMCS) |
| **paypal_ppcpv** (myip 51, nixpal 56) | ✅ δικό μας | κανένα core gateway του Blesta 6 δεν κάνει PayPal vault → δικό μας `paypal_vault` + mapping `paypal_ppcpv.php`· τα tokens μεταφέρονται (δες «PayPal vault» πιο κάτω) |
| **eurobanklib** (myip 163) | ✅ δικό μας | nonmerchant gateway `cardlink_eurobank` (lib `lib/cardlink-eurobank`) + mapping `eurobanklib.php` (δες «Cardlink / Eurobank» πιο κάτω) |
| banktransfer | ? | να ελεγχθεί στο staging (offline gateway) |

**Δεν εισάγονται καθόλου** (δεν τα γνωρίζει ο importer):
- πίνακες addon modules: `mod_licensing*`, `mod_ekdosi_*`, `mod_timologia*`, `payments_tracking`
  (μέσα στη DB του WHMCS) κ.λπ.: δικά μας scripts στο `migration/`
- hooks, custom αρχεία στο root του WHMCS (`zlic.php`, `domain-check.php`, `ip-info.php` …), θέμα
- ρυθμίσεις των addons και των module rows όπου δεν υπάρχει mapping

## Η καλή είδηση
Τα mapping files είναι απλά PHP arrays στον φάκελο του importer. Γράφοντας δικά μας
(`licensing.php` ✅, `openprovider.php` ✅, `mailcow.php` ✅, `ngm.php`, `grepp.php`, `pvewhmcs.php` …) και
βάζοντάς τα στο `8.0/config/` **πριν** το import, οι υπηρεσίες και τα domains μπαίνουν κατευθείαν στα
σωστά modules, και δεν χρειάζεται μεταφορά από Universal/Generic μετά. Θα ζουν στο repo
(`migration/importer-mappings/`) και θα αντιγράφονται πριν από κάθε δοκιμαστικό import.

Μετά από κάθε import: έλεγχος ότι ο αριθμός ενεργών υπηρεσιών ανά module και domains ανά registrar
ταιριάζει με το WHMCS (τα νούμερα στο HANDOFF §1).

**Προσοχή (από τον κώδικα):** ο importer παραλείπει όποια υπηρεσία δεν συνδέσει με module row, και
συνδέει μόνο μέσω servers του `tblservers` (ή του Universal Module). Ένα WHMCS module χωρίς servers
(όπως το licensing) θέλει server στο αντίγραφο της βάσης πριν το import, αλλιώς το mapping δεν
χρησιμοποιείται ποτέ (δες `migration/prepare_whmcs_copy.sh`).

## PayPal vault (ppcpv): τι υπάρχει στο Blesta 6 (2026-10-08, από τον κώδικα του staging)
- `paypal_checkout` (nonmerchant, v2.0.1): μόνο Orders v2 με `intent CAPTURE`, μία πληρωμή τη φορά.
  Αγνοεί το `recur`, δεν έχει vault ή subscriptions.
- `paypal_payments_standard` (nonmerchant): classic subscriptions (`_xclick-subscriptions`, IPN). Αυτόματο,
  αλλά μία συνδρομή ανά υπηρεσία με σταθερό ποσό, και ο πελάτης την εγκρίνει ξανά.
- `braintree` (merchant): αποθηκεύει μόνο κάρτες (`storeCc`), όχι PayPal wallet.
- Third-party: Blesta.Club «PayPal Billing Agreements» (εμπορικό).

Στο WHMCS: nixpal 15 ενεργές vaulted μέθοδοι (`tblpaymethods` RemoteCreditCard), 55 μηνιαίες
υπηρεσίες σε 6 πελάτες· myip 48 ενεργές μέθοδοι σε ~61 πελάτες, 51 υπηρεσίες (κυρίως ετήσιες).

**Απόφαση: δικό μας merchant gateway `paypal_vault`** (`components/gateways/merchant/paypal_vault`,
λογική στο `lib/paypal-vault`): PayPal Vault v3 + Orders v2, αποθηκευμένος λογαριασμός PayPal ως
offsite "card" του Blesta, χρέωση από το autodebit cron.

**Έλεγχοι στη nixpal (2026-10-08, read-only, με έγκριση):**
- Με τα REST credentials του ppcpv, **13 από τα 15** ενεργά vault tokens απαντούν σε
  `GET /v3/vault/payment-tokens/{vaultId}` (`payment_source: paypal`, με customer). Ένα δίνει 404
  (σβησμένο στο PayPal), ένα είναι παλιό μη-JSON token. Άρα τα tokens μεταφέρονται χωρίς νέα έγκριση.
- Ο Import Manager αποκρυπτογραφεί σωστά το `card_data` (έλεγχος με τον δικό του αλγόριθμο σε όλες
  τις ενεργές εγγραφές): ppcpv → `remoteToken {vaultId, customerId, transactionIdentifier}`, Stripe →
  `remoteToken {customer, method}`. Το mapping `migration/importer-mappings/paypal_ppcpv.php` τα παίρνει.
- **Ο Import Manager δεν διαβάζει μόνος του τις ρυθμίσεις gateways του WHMCS 8.13:** αποθηκεύονται ως
  `<hex IV>.<hex ciphertext>` και το `decryptGatewaySetting()` κάνει hex-decode όλο το string με
  μηδενικό IV. Λύση: `migration/reencrypt_gateway_settings.php` ξανακρυπτογραφεί στο **αντίγραφο** τις
  ρυθμίσεις στη μορφή που διαβάζει ο importer (ίδιο κλειδί, `cc_encryption_hash`). Έλεγχος σε όλες τις
  ρυθμίσεις των paypal_ppcpv, stripe, paypal της nixpal: ο importer διαβάζει ακριβώς την τιμή του WHMCS.
  Έτσι περνάνε μόνα τους Client ID/Secret του PayPal και τα κλειδιά (και webhook secret) του Stripe.
- Ο Import Manager φέρνει και τις soft-deleted μεθόδους (nixpal: 9 ppcpv, 59 Stripe) ως ενεργές.
  Το `prepare_whmcs_copy.sh` τις σβήνει από το αντίγραφο πριν το import.

**Ασφάλεια σε staging:** το import φέρνει τα live credentials και τα live tokens. Το gateway έχει ρύθμιση
`live_actions` (το mapping τη βάζει πάντα off): χωρίς αυτή, σε live (όχι sandbox) δεν γίνεται χρέωση,
refund ή διαγραφή token στο PayPal. Ενεργοποιείται μόνο στο Blesta παραγωγής, στο cut-over.
Από Blesta 5.13 πολλά merchant gateways μοιράζονται το ίδιο νόμισμα, άρα Stripe και `paypal_vault`
δουλεύουν μαζί σε EUR.

## Cardlink / Eurobank (eurobanklib → cardlink_eurobank)

Δικό μας nonmerchant gateway (`components/gateways/nonmerchant/cardlink_eurobank`, λογική στο
`lib/cardlink-eurobank`). Πηγές: το `eurobanklib.php` + `callback/eurobankreturn.php` του WHMCS (rigel) για
το request, και το `EurobankGateway.php` του Ekdosi για την επαλήθευση του return.

- **Request:** ίδια πεδία και σειρά με το WHMCS (`version, mid, lang, deviceCategory, orderid, …, confirmUrl,
  cancelUrl`), digest `base64(sha256(τιμές . secret))`. Order id `<invoice>Inv<yymmddHHMMSS><2 ψηφία>` (ή
  `<client>Cl…` χωρίς τιμολόγιο), όπως στο WHMCS.
- **Return:** το vPOS στέλνει το αποτέλεσμα **δύο φορές** στο confirmUrl: από τον browser και στο παρασκήνιο
  (`Modirum HTTPClient`, με `_charset_` και `submitButton`). Έλεγχος στο debug log του rigel (2026-10-09,
  μόνο ονόματα πεδίων): κάρτα και IRIS στέλνουν τα ίδια 12 πεδία με `status=CAPTURED`· REFUSED/CANCELED
  χωρίς `payMethod` (και κάποτε `paymentRef`).
- **Επαλήθευση** (από το Ekdosi): άγνωστα πεδία απορρίπτονται, το digest ξαναχτίζεται στη σταθερή σειρά του
  vPOS, το `mid` πρέπει να είναι του τερματικού και το `currency` να υπάρχει. Το WHMCS έκανε hash με τη σειρά
  που ήρθαν τα πεδία, που επιτρέπει να «μετακινηθούν» bytes ανάμεσα σε πεδία μιας γνήσιας πληρωμής.
- **Πελάτης/τιμολόγια:** το confirmUrl είναι το gateway callback του Blesta με query που υπογράφουμε εμείς
  (HMAC με το shared secret, δεμένο με το order id): client, τιμολόγια, ποσό, νόμισμα. Ποσό και νόμισμα του
  return πρέπει να ταιριάζουν. Λίστα τιμολογίων πάνω από 120 χαρακτήρες κόβεται· ό,τι δεν χωράει μένει πίστωση.
- **Μία συναλλαγή ανά πληρωμή:** transaction id = order id. Τα δύο σκέλη σειριοποιούνται με `GET_LOCK` ανά
  order id· το δεύτερο βρίσκει τη συναλλαγή του πρώτου και δεν επιστρέφει τίποτα. Αλλαγή status μόνο προς τα
  μπρος: pending → οτιδήποτε, declined/error → approved. Approved, refunded, void, returned είναι τελικά, άρα
  ένα return που ξαναστέλνει ο πελάτης (το έχει στον browser του) δεν «ξαναπληρώνει» ένα refund.
  Αν το `GET_LOCK` αποτύχει (σφάλμα, όχι timeout), η πληρωμή καταχωρείται χωρίς lock, για να μη χαθεί.
  Το `txId` της τράπεζας μπαίνει στο reference id.
- CAPTURED → approved, REFUSED → declined, ERROR → error, AUTHORIZED → pending, CANCELED → τίποτα.
- Μετά το callback ο browser πάει στο `client/pay/received/cardlink_eurobank/`, όπου το `success()` δείχνει
  μόνο ό,τι κατέγραψε το callback (το Blesta δεν καταχωρεί από το `success()`· το επίσημο PayFast gateway
  επιστρέφει εκεί approved από απλό GET).
- **Mapping** `eurobanklib.php`: merchant id, shared secret, γλώσσα. Το test mode μπαίνει πάντα **on** μετά
  το import (να μη στέλνει πελάτες στο live τερματικό από αντίγραφο)· το `gatewaycurrency`/`debugmode` δεν
  χρειάζονται. Το test mode αλλάζει μόνο το endpoint: τα live mid/secret μένουν στη βάση του staging και
  υπογράφουν τις φόρμες του, άρα το staging Blesta μένει ιδιωτικό όσο έχει live secret. Οι ρυθμίσεις του myip είναι στη μορφή του WHMCS 8.13 → πρώτα `reencrypt_gateway_settings.php`.

- Το `success()` δείχνει ποσό/status με βάση `client_id` + order id από το URL της σελίδας «received» (client
  area, με login). Ένας άλλος συνδεδεμένος πελάτης που μαντεύει order id βλέπει μόνο ποσό και status.

Ανοιχτά (staging): ότι το callback του Blesta καταχωρεί/ενημερώνει με το transaction id όπως περιμένουμε,
ότι υπάρχουν τα `Transactions::getByTransactionId` και `Record::query(...)->fetchColumn()` (αλλιώς γράφεται
σχετικό gateway log), η ανακατεύθυνση του browser μετά το callback, πληρωμή με πολλά τιμολόγια (μήκος
confirmUrl· η Cardlink δεν δίνει όριο στα docs που έχουμε), και μία πραγματική πληρωμή 1-2 € με κάρτα και IRIS.

Ανοιχτά (staging/sandbox): η ροή έγκρισης στο JS SDK (`createVaultSetupToken`) σε client area και admin,
μία πραγματική χρέωση και ένα refund σε sandbox, και ότι το autodebit του Blesta διαλέγει τον εισαγόμενο
λογαριασμό (client_account / autodebit ανά πελάτη μετά το import).
