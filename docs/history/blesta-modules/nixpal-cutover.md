# nixpal: τι χρειάζεται για να περάσει στο Blesta

> Από τη βάση του WHMCS της nixpal (orion, read-only) και τον κώδικα του Import Manager, 2026-10-08.

## Τι υπάρχει σήμερα

| | Ενεργά | Σημειώσεις |
|---|---|---|
| Πελάτες | 31 active (200 inactive, 2 closed) | EUR default, USD επίσης |
| **Άδειες** (licensing) | 327 active + 9 suspended | Agent 253, Node 61, Web Controller 20, Custom 1, DEVNODE 1 |
| **cPanel** | 12 | 1 server (`Orion`, orion.nixpal.com)· LITESPEED 10/20/60, nixpal1 (free), Wordpress V16 (retired) |
| Χωρίς module | 1 | "MDA1 Managed Dedicated Server" |
| **Domains** | OpenProvider 7, CNIC 3 (+1 grace) | όλα τα TLDs (10) έχουν autoreg OpenProvider |
| Πληρωμές (ενεργές υπηρεσίες) | Stripe 205, PayPal 79, PayPal ppcpv 56 | Stripe: 52 αποθηκευμένες κάρτες (21 πελάτες)· subscriptions: PayPal 2, ppcpv 3 |
| Invoices | 7 unpaid | 1 ticket answered, κανένα ανοιχτό |
| Addons WHMCS | licensing, google_analytics, clientproductdescriptions, paypal_addon, nixpallicense | μόνο το licensing μας αφορά· nixpallicense νεκρό |

Δεν υπάρχει ενεργή υπηρεσία `zonecloud` (server module): **δεν χρειάζεται port για το cut-over της nixpal**.

## Checklist

1. **Blesta 6** στο staging (`csnixpal`, cs.nixpal.com), νέο license (το trial έληξε), ionCube loader για την PHP του account.
2. **Licensing** ✅ γραμμένο: plugin + module `nixpal_licensing`, `zlic.php`, mapping, `prepare_whmcs_copy.sh`, `import_licensing.php`.
   Μένει: εγκατάσταση και δοκιμή στο staging, δοκιμαστικό import, shadow-run με όλα τα ενεργά κλειδιά.
3. **cPanel**: core module του Blesta + έτοιμο mapping του importer. Τίποτα να γραφτεί· έλεγχος του
   module row (Orion) και των πακέτων μετά το import.
4. **Domains**:
   - OpenProvider (7): δικό μας fork `myopenprovider` (το stock module του Blesta δεν ανανεώνει domains· `docs/openprovider.md`)· mapping `migration/importer-mappings/openprovider.php` ✅ (migration/README.md)· έλεγχος TLD pricing μετά το import. Πριν το cut-over χρειάζεται τουλάχιστον το renew/restore.
   - CNIC (3 + 1 grace): είτε transfer στο OpenProvider πριν το cut-over, είτε Generic Domains (χειροκίνητα) μέχρι τη λήξη τους. Για 4 domains δεν αξίζει module.
5. **Stripe** (205 υπηρεσίες): έτοιμο mapping προς `stripe_payments`· ο importer περνάει τις 52 αποθηκευμένες
   κάρτες με το Stripe customer + payment method, άρα οι αυτόματες χρεώσεις συνεχίζουν με τα ίδια keys.
   Τα κλειδιά του gateway περνάνε μόνο αφού τρέξει το `reencrypt_gateway_settings.php` στο αντίγραφο.
   Να επιβεβαιωθεί με μία δοκιμαστική χρέωση στο staging (test keys).
6. **PayPal** (79): έτοιμο mapping· οι 2 subscriptions στέλνουν IPN στο URL του WHMCS → forwarder ή νέα συνδρομή.
7. **PayPal ppcpv** (56 υπηρεσίες, 15 vaulted, 3 subscriptions) ✅ γραμμένο: δικό μας gateway `paypal_vault` +
   mapping `paypal_ppcpv.php`· τα 13 από τα 15 vault tokens μεταφέρονται χωρίς νέα έγκριση (docs/importer.md).
8. "MDA1 Managed Dedicated Server": Universal Module, χωρίς αυτοματισμό.
9. Θέμα, email templates, `clients.nixpal.com` → Blesta, `zlic.php` στο docroot, WHMCS read-only/dump.

## Δοκιμές στο staging (πριν το cut-over)

Όσα δεν μπορούν να επιβεβαιώσουν τα unit tests, γιατί εξαρτώνται από το πραγματικό Blesta. Σειρά: από πάνω προς τα κάτω.

**Προετοιμασία**
- [x] Blesta 6.0.3 στο `cs.nixpal.com` (cPanel `csnixpal` στον orion, 2026-10-09): ea-php83 + ionCube 15.5,
      `memory_limit`/`max_input_vars`/uploads στην αρχή του `public_html/.htaccess` (το `.user.ini` δεν
      διαβάζεται από τον LiteSpeed), uploads στο `/home2/csnixpal/uploads/` (έξω από το docroot), cron κάθε
      5 λεπτά με `/opt/cpanel/ea-php83/root/usr/bin/php`. Λείπει το `mailparse` (μόνο για email piping).
- [x] Deploy: `~/bin/deploy-blesta-modules.sh [branch]` στο account (read-only deploy key, clone στο
      `~/blesta-modules`, `rsync -aL` των modules/gateways/plugin που είναι έτοιμα· το `zlic.php` όχι ακόμα).
- [ ] Deploy με `rsync -aL` (τα `lib` symlinks ως αρχεία)· `plugins/nixpal_licensing/lib/` και
      `components/gateways/merchant/paypal_vault/lib/` και
      `components/gateways/nonmerchant/cardlink_eurobank/lib/` περιέχουν αρχεία.
- [ ] Αντίγραφο της βάσης του WHMCS: `prepare_whmcs_copy.sh`, μετά `reencrypt_gateway_settings.php --dry-run`
      και χωρίς `--dry-run`.
- [ ] Mappings στο `plugins/import_manager/components/migrators/whmcs/8.0/config/`: `licensing.php`,
      `openprovider.php`, `paypal_ppcpv.php`.
- [x] `components/modules/myopenprovider` στη λίστα του `~/bin/deploy-blesta-modules.sh` (2026-10-10, deploy
      `2ed753a`).
- [ ] Εγκατάσταση του «Openprovider (myip/nixpal)» **πριν** το import, με account σε sandbox/test mode· το
      stock `openprovider` μένει μη εγκατεστημένο.
- [ ] `myopenprovider` σε **sandbox** Openprovider: ανανέωση πληρωμένου domain (1 και 2 έτη), δεύτερη
      εκτέλεση της ίδιας ανανέωσης χωρίς δεύτερη χρέωση, restore από soft quarantine· στο module log το
      `renewal_plan` και καμία εμφάνιση password/token.
- [ ] `myopenprovider` σε sandbox: μετά από renew, σύγκριση `expiration_date` και `renewal_date` (ποιο
      μετακινείται και πότε)· ένα `DEL` domain εμφανίζεται στο `GET /domains?full_name=` (αλλιώς το restore
      δεν βρίσκει το domain)· `token_*`/`myop_*` keys στο `module_meta` μένουν όλα μετά από νέο login.
- [ ] `myopenprovider` σε sandbox, κλειδί περιόδου και cycles: admin «Renew» για 2 cycles → ανανέωση 2 ετών·
      δύο πληρωμένα τιμολόγια ανανέωσης μαζί → `multiple_invoices` χωρίς χρέωση· νεότερο ανοιχτό τιμολόγιο
      πολλών ετών → `cycles_unknown` χωρίς χρέωση.
- [ ] Καρτέλα «Ανανέωση» (admin): «Ανανέωση τώρα» πριν την πληρωμή → εγγραφή `prepaid`· η πληρωμή που ακολουθεί
      δεν χρεώνει ξανά· «Σήμανση ως ανανεωμένο»· «Καθαρισμός εγγραφής». Πρώτη φορά που τρέχει σε πραγματικό
      Blesta η καρτέλα και το query των τιμολογίων.
- [ ] Ρύθμιση «process paid service changes»: αν ενεργοποιηθεί, έλεγχος ότι οι ημερομηνίες της υπηρεσίας
      έχουν γραφτεί πριν το `renewService` (αλλιώς τα cycles διαβάζονται λάθος).
- [ ] Domains plugin → Settings: «days before expiration» (`domains_renewal_days_before_expiration`) τουλάχιστον 15
      (σήμερα 30)· η μεγαλύτερη διαφορά λήξης/ανανέωσης στο Openprovider είναι 6 ημέρες (.es)· docs/openprovider.md H4.
- [ ] Domains plugin → TLDs: «EPP Code» ενεργό στα TLDs του `myopenprovider` (αλλιώς οι πελάτες δεν βλέπουν
      τον κωδικό στην καρτέλα «Settings»)· δοκιμή του «Αποστολή του κωδικού EPP» με κωδικό που έχει `<` ή `&`.
- [ ] Πριν την πρώτη ανανέωση στο Blesta: `SELECT \`key\`, value FROM module_meta WHERE \`key\` LIKE
      'myop_renewal_%'` (δεν αναμένονται εγγραφές).
- [ ] Πριν το cut-over: autorenew **off** σε όλα τα domains του Openprovider (docs/openprovider.md), και στο
      WHMCS καμία ανανέωση στον registrar για τα domains που περνούν στο Blesta (HANDOFF §11.1).

**Licensing**
- [ ] Εγκατάσταση plugin (πρώτα) και module `nixpal_licensing`. Αν είχε μπει η 0.1.0: upgrade σε 0.2.0
      (στήλη `whmcs_id` στο `nixpal_license_log`, εγγραφή των events `Services.addAfter/editBefore/editAfter`).
- [ ] **Παραγγελία → πληρωμή → ενεργοποίηση** ενός πακέτου licensing, και από τη φόρμα του πελάτη και από admin:
      - μετά την παραγγελία η υπηρεσία έχει κλειδί, αλλά **δεν** υπάρχει γραμμή στο `nixpal_licenses`
        και το `zlic.php` απαντά Invalid για αυτό·
      - μετά την ενεργοποίηση το κλειδί είναι **το ίδιο** και η άδεια Active.
      Αν το κλειδί αλλάξει, το Blesta δεν διαβάζει τα vars που επιστρέφει το `Services.editBefore`
      (το `executeAndParseEvent` είναι ionCube): στοπ και διόρθωση πριν προχωρήσουμε.
- [ ] Ακυρωμένη / fraud παραγγελία: καμία άδεια.
- [ ] Πεδία από τη φόρμα παραγγελίας: φτάνει στο `addService()` ένα `license_key` που στέλνει ο πελάτης;
      Αν ναι, να μην το δέχεται το module από πελάτη (παλιότερο θέμα, εκτός PR #6).
- [ ] Suspend / unsuspend / cancel από admin με και χωρίς την ενέργεια του module: το `zlic.php` ακολουθεί.
- [ ] Reissue από τον πελάτη, και πρώτο bind από νέα IP.
- [ ] Δοκιμαστικό import (`import_licensing.php --whmcs-timezone=Europe/Athens --dry-run`, μετά κανονικά):
      `unmatched`, `conflicts`, `missing_secret` άδεια· αριθμοί ανά status ίδιοι με το WHMCS.
- [ ] Shadow-run: παλιό και νέο endpoint δίνουν ίδιες απαντήσεις για όλα τα ενεργά κλειδιά.

**Πληρωμές**
- [ ] Stripe: τα κλειδιά ήρθαν από το import· μία χρέωση με test keys.
- [ ] `paypal_vault` σε sandbox: έγκριση λογαριασμού με το κουμπί (client area και admin), μία χρέωση, ένα refund.
      Η αποθήκευση δέχεται μόνο setup token που εξέδωσε η φόρμα στο ίδιο session (docs/gateway-security-6.0.3.md).
- [ ] Εισαγόμενοι λογαριασμοί PayPal: φαίνονται στους πελάτες, και το autodebit του Blesta διαλέγει αυτόν τον λογαριασμό.
- [ ] Το `live_actions` μένει **κλειστό** σε όλο το staging (live credentials και tokens των πελατών).
      Ανοίγει μόνο στο Blesta παραγωγής, στο cut-over.
- [ ] `cardlink_eurobank`: η nixpal δεν έχει eurobanklib στο WHMCS, άρα στο staging ρυθμίζεται με το χέρι
      (mid, shared secret)· στο myip έρχεται από το `eurobanklib.php` με test mode on. Αν το τερματικό δέχεται
      μόνο γνωστά domains/IP για τα confirm URLs, να δηλωθεί το `cs.nixpal.com` στην Cardlink.
- [ ] **Πραγματική πληρωμή 1-2 €** σε τιμολόγιο του trial, χωρίς test mode, μία με **κάρτα** και μία με **IRIS**:
      - στο gateway log του Blesta δύο returns ανά πληρωμή (browser και `Modirum HTTPClient`), και τα δύο
        `result: ok`·
      - **μία** συναλλαγή approved με transaction id το order id και reference το `txId`· το τιμολόγιο Paid·
      - ο browser καταλήγει στη σελίδα «payment received» με το σωστό ποσό.
      Αν το log γράφει `unknown_fields` ή `digest_mismatch`, τα `posted_keys` δείχνουν τι έστειλε το vPOS.
- [ ] Ακύρωση στη σελίδα της τράπεζας και απορριφθείσα κάρτα: καμία approved συναλλαγή, μήνυμα στον πελάτη.
- [ ] Reload/back στη σελίδα επιστροφής (GET στο callback): πάει στο client area χωρίς σφάλμα.

