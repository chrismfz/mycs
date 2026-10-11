# blesta-modules — Handoff: WHMCS → Blesta (myip.gr + nixpal)

> Κατάσταση: discovery ολοκληρώθηκε 2026-10-08 (read-only SSH σε rigel/orion + admin UI).
> Σκοπός του repo: όλα τα custom κομμάτια ως Blesta plugins/modules/gateways + scripts μετάπτωσης,
> δουλεμένα σταδιακά. Τίποτα εδώ δεν περιέχει secrets — όλα τα credentials μένουν στα config των servers.

---

## Κατάσταση (ενημερώνεται σε κάθε βήμα· τελευταία 2026-10-10)
Αναλυτικό checklist: `docs/nixpal-cutover.md`.
- **Staging:** Blesta 6.0.3 στο `cs.nixpal.com` (cPanel `csnixpal`, orion), cron, deploy με
  `~/bin/deploy-blesta-modules.sh` (main @ `2ed753a`).
- **Στο repo (merged):** `nixpal_licensing` (plugin + module), `mailcow`, `paypal_vault` (#12),
  `cardlink_eurobank` (#10), `myopenprovider` με ανανεώσεις, κλειδί περιόδου από τα πληρωμένα τιμολόγια,
  cycles και καρτέλα «Ανανέωση» (#14, #15, #16).
- **Ενεργά στο staging:** plugin/module `nixpal_licensing`, `mailcow`, gateways `paypal_vault`,
  `cardlink_eurobank`. Το `myopenprovider` είναι deployed αλλά **όχι εγκατεστημένο** (θέλει sandbox account).
- **Επόμενα:**
  1. Εγκατάσταση `myopenprovider` με sandbox credentials και οι έλεγχοι του checklist.
  2. Πραγματική πληρωμή 1–2 € με κάρτα και IRIS μέσω `cardlink_eurobank`.
  3. MCP `billing` (gsc-mcp): οι προϋποθέσεις του §11 πριν την πρώτη γραφή στο staging.
  4. Script autorenew off στο Openprovider (cut-over), αντίγραφο της βάσης του WHMCS και import.
- **Για αργότερα:** EPP ως feature, additional fields ανά TLD, TLD pricing sync με περιθώριο, `grepp` (.gr)
  με κοινή βιβλιοθήκη ανανεώσεων, mapping Proxmox, vivawallet.

---

> **2026-10-11: ΑΠΟΦΑΣΗ: hard fork του Paymenter ως control plane** (στη θέση του Blesta). Το Ekdosi μένει
> τιμολογιέρα/myDATA. Domains, tickets, υπενθυμίσεις, allocator και MCP έρχονται από το Ekdosi· τα `lib/` αυτού του repo
> μεταφέρονται. Όλα (αποφάσεις, audits, ανοιχτά): **`docs/paymenter/README.md`**. Το §12 παρακάτω (πύλη για το Blesta)
> έχει πλέον μόνο ιστορική αξία.

## 0. Βασικά ευρήματα (διάβασε πρώτα)

1. **`grepp` (.gr registrar) στο WHMCS είναι το εμπορικό module του Liagkos** (`liagkos/greppcore`, v2.3.7,
   **ionCube-encoded**), μαζί με τα `grepp_ownerchange`, `grepp_ownertransfer`, `grepp_twocharsrdomain` και το
   `grwhoisapi`. Εξυπηρετεί **1381 ενεργά domains**. **Απόφαση:** port του δικού μας .gr EPP κώδικα από το Ekdosi
   σε Blesta registrar module (βλ. §6). ⚠ **Ο .gr EPP client ΔΕΝ έχει γραφτεί.** Στο Ekdosi υπάρχουν μόνο docs:
   `docs/domains/grepp/README.md` (711 γρ. οδηγός υλοποίησης, γραμμένος πριν τη σύνδεση στο UAT, με σημεία
   `[ΕΠΙΒΕΒΑΙΩΣΗ]`), 86 XML/XSD παραδείγματα του Οδηγού EPP v4.3, το reference `EppClient.java`, CA bundle.
   Στο BACKLOG είναι το **A4 — grEPP**, ανοιχτό. Κώδικας registrar υπάρχει μόνο για OpenProvider.
2. **Το licensing της nixpal στηρίζεται στο WHMCS Licensing Addon (encoded `verify.php`).**
   Οι agents μιλάνε **μόνο** με `https://clients.nixpal.com/zlic.php` (τα POST στο `verify.php` στα logs
   είναι το loopback του ίδιου του zlic). Άρα το contract που πρέπει να κρατήσουμε είναι **ένα**: το zlic.php.
3. **Το Ekdosi είναι ήδη μισό-έτοιμο για άλλο billing source:** έχει `BillingConnection` +
   `App\Services\Billing\BillingSourceRegistry` + `Sources/WhmcsBillingSource.php`
   (docs: `docs/bridges-connectors.md`). Θέλει ένα `BlestaBillingSource` + ένα Blesta plugin που
   σερβίρει το ίδιο HMAC op-contract με το σημερινό `ekdosi_bridge`.
4. **Πολλά custom addons είναι μπαλώματα για quirks του WHMCS** και δεν μεταφέρονται (§4.4).
5. **Η μετάβαση CNIC → OpenProvider** (hook `DomainMigration.php`) αν ολοκληρωθεί πριν το cut-over,
   γλιτώνουμε εντελώς το CNIC module στο Blesta (209 ενεργά domains σήμερα).
6. **Ασφάλεια του ίδιου του Blesta:** ελάχιστη έκδοση **6.0.3** (κλείνει πλαστογράφηση πληρωμών σε 4 gateways
   και brute force στο 2FA). Ακόμη και στο 6.0.3 κάποια stock gateways **δεν** ενεργοποιούνται. Λεπτομέρειες στην §10.

---

## 1. Περιβάλλοντα

| | myip.gr | nixpal |
|---|---|---|
| Host / path | `rigel.myip.gr:/home/myipgr/www/clients` | `orion.myip.gr:/home/clnixpal/public_html` |
| DB | `myipgr_clients` | `clnixpal_nixcldb` |
| WHMCS | 8.13.7, Business 1000 | 8.13.7, Plus 250 (month-to-month, επόμενη ανανέωση 24/10/2026) |
| Μέγεθος | 900 clients (552 active), 873 services, 1862 domains, 20761 invoices (max id 32453), 17310 tickets | 31 active clients, ~330 licenses active |
| Crons (user) | WHMCS cron, `pop.php` (ticket import), `bank-payments-logging.php` */3, Ekdosi `schedule:run` + `queue:work` (ίδιος host: `/home/myipgr/invoicer/ekdosi`) | WHMCS cron |

**Blesta staging (nixpal):** `orion.myip.gr:/home2/csnixpal/public_html` (cPanel `csnixpal`, ίδιος server με
το WHMCS της nixpal, άλλο account), URL `https://cs.nixpal.com/admin/`, DB `csnixpal_db`, Blesta 6.0.3, PHP
`ea-php83` (`/opt/cpanel/ea-php83/root/usr/bin/php`), uploads `/home2/csnixpal/uploads/`, cron και deploy στην
§«Κατάσταση» και στο `docs/nixpal-cutover.md`.

**Πρόσβαση:**
| Τι | Πώς | Όρια |
|---|---|---|
| SSH orion (WHMCS nixpal + Blesta staging) | gsc-mcp `ssh_run`, host `orion.myip.gr` | συνδέεται ως **root**: μόνο read-only, εκτός από το account `csnixpal` όταν ζητηθεί ρητά (CLAUDE.md)· αρχεία του `csnixpal` γράφονται ως `csnixpal` (π.χ. `su -s /bin/bash - csnixpal -c …`) ή με `chown` μετά |
| SSH rigel (WHMCS myip.gr, Ekdosi) | gsc-mcp `ssh_run`, host `rigel.myip.gr` | μόνο read-only |
| MySQL στον orion | `mysql <db>` ως root μέσω SSH (`/root/.my.cnf`) | μόνο SELECT/EXPLAIN· καμία εκτύπωση credentials |
| Blesta staging API | gsc-mcp `billing` (`blesta_call`), instance «Billing (cs.nixpal.com, blesta)» | γραφές μόνο με `write=true` και τις προϋποθέσεις της §11 |
| WHMCS nixpal API | gsc-mcp `billing` (`whmcs_call`), instance «Billing (clients.nixpal.com, whmcs)» | role μόνο `Get*` + `DecryptPassword` (§11) |
| GitHub στον orion | deploy key read-only (`~csnixpal/.ssh/blesta_modules_deploy`, host alias `github-blesta-modules`) | μόνο clone/fetch του repo |

Ενεργά services ανά payment method (myip): banktransfer 449 · **eurobanklib 163** · paypal 37 · paypal_ppcpv 51 · stripe 1
Ενεργά services ανά module (myip): cpanel 643 · ssl (thesslstorefullv2) 12 · **mailcow 10** · centovacast 9 · pvewhmcs 8 · **ngm 1** · HetznerCloud 1 (module missing)
Ενεργά domains ανά registrar (myip): **grepp 1381** · openprovider 241 · cnic 209 · enom 1

---

## 2. Contracts που ΔΕΝ πρέπει να σπάσουν

### 2.1 `zlic.php` (nixpal licensing) — κρίσιμο
- URL: `https://clients.nixpal.com/zlic.php` — πρέπει να μείνει ίδιο (rewrite προς Blesta plugin).
- Input: `license` + `license_PSK` μέσω POST (νέοι agents) ή GET (legacy). Clients στα logs:
  `cpp-httplib/0.25.0`, `cpp-httplib/0.18.1`, `GuzzleHttp/7` (Web Controller), `zCloudAgent-Go/1.0`.
- Output: JSON = τα tags του WHMCS `verify.php` (status, description, validdomain, validip,
  validdirectory, md5hash, nextduedate, κ.λπ.) + `localkey` + `remotecheck`.
- `md5hash` = `md5(PSK . check_token)`, και το `localkey` παράγεται με τον αλγόριθμο του WHMCS
  (base64 JSON, md5(checkdate.PSK) prefix, strrev, md5 suffix, wordwrap 80). **Byte-for-byte ίδιο** —
  οι agents κάνουν cache/validate τοπικά το localkey.
- 400 + `{"status":"Invalid","description":"Missing license or license_PSK"}` όταν λείπουν πεδία.
- Ο PSK είναι per-product (στο WHMCS: `tblproducts.configoption8`). Mapping των configoptions
  επιβεβαιωμένο στο `docs/licensing.md` §2.
- Όγκος: ~860 checks/μέρα στο access log· `mod_licensinglog` 588k γραμμές· τελευταίο 24ωρο
  5855 «License Expired» + 908 «License Suspended» (πολλοί νεκροί agents συνεχίζουν να ρωτάνε → cache/rate-limit).

### 2.2 Ekdosi ↔ billing
- **Write-back:** `POST /modules/addons/ekdosi_bridge/inbound.php`, HMAC `X-Webhook-Signature: sha256=…`,
  body `{whmcs_invoice_id, mark, invcode?}`· distinct error codes 400/401/404/422/500.
  Path hard-coded στο Ekdosi: `Company::WHMCS_BRIDGE_PATH`.
- **Pull (resolve.php, HMAC):** ops `resolve`, `resellers`, `invoiced_flags`, `legacy_invoice_links`,
  `invoices` (inbox feed), `invoice`, `custom_fields`, `add_payment`.
- **WHMCS API που καλεί το Ekdosi:** GetInvoices, GetInvoice, GetClients, GetClientsDetails,
  GetProducts, GetPaymentMethods, GetActivityLog, AddInvoicePayment.
- Δικοί του πίνακες στο WHMCS: `mod_ekdosi_invoice_marks`, `mod_ekdosi_contacts`, `mod_ekdosi_routing`,
  `mod_ekdosi_bridge_log` (+ διαβάζει legacy `mod_timologia*`, `tblinvoices.invoiced`).

### 2.3 Payments tracking
- `/home/myipgr/bank-payments-logging/bank-payments-logging.php` (IMAP `mail.myip.gr` → PDO)
  γράφει στον πίνακα **`payments_tracking` μέσα στη WHMCS DB** (2276 γραμμές· πεδία bank, amount, sum,
  acknowledged, debug, reason).
- Το addon `PaymentsTrackingMatch` (800 γρ.) κάνει matching σε `AfterCronJob`, εκπέμπει custom hook
  `PaymentsTrackingMatchAfterPaymentMatch`, και έχει 2 `AdminHomeWidgets` (Payments tracking, Bank Totals).

### 2.4 Αρίθμηση προτιμολογίων
- Οι πελάτες γράφουν το invoice # στην αιτιολογία κατάθεσης· το matching και το Ekdosi βασίζονται σε αυτό.
  Το Blesta πρέπει να συνεχίσει από **> 32453** και τα παλιά IDs να αναζητούνται (id map).

---

## 3. Blesta: πού κουμπώνει τι (επιβεβαίωσε στα docs της έκδοσης που θα στήσουμε)

| WHMCS | Blesta |
|---|---|
| Addon module | Plugin (`plugins/<name>/<name>_plugin.php`, MVC controllers/models/views) |
| `add_hook()` | Plugin events (`getEvents()`: π.χ. `Invoices.add/edit`, `Transactions.add/edit`, `Services.add/edit/suspend/cancel`, `Clients.create`, …) |
| `AdminHomeWidgets` | Plugin actions με location `widget_staff_home` / `widget_staff_billing` |
| `DailyCronJob` / `AfterCronJob` | Plugin `getCronTasks()` |
| Server module | `components/modules/<name>` (extends `Module`) |
| Registrar | `components/modules/<name>` (registrar module, Blesta 5.x) |
| Gateway | `components/gateways/merchant|nonmerchant/<name>` |
| `localAPI()` | Blesta API / models απευθείας μέσα σε plugin |
| Custom PHP στο root (zlic.php κ.λπ.) | Public controller plugin + rewrite στο web server |

---

## 4. Inventory και απόφαση ανά κομμάτι

### 4.1 nixpal (orion)

| Κομμάτι | Τι είναι | Απόφαση |
|---|---|---|
| WHMCS Licensing Addon (`addons/licensing`, `servers/licensing`, encoded) | Έκδοση/reissue κλειδιών, binding domain/ip/dir, `mod_licensing` (1339 rows, 326 active), log | **Ξαναγράφεται**: `nixpal_licensing` plugin + `licensing` module (§5) |
| `zlic.php` (206 γρ.) | JSON proxy προς verify.php | Γίνεται public controller του plugin, ίδιο URL |
| Products 4,5,6,7,8,13 (Web Controller 19, Agent 248, Node 58, Custom 1, DEVNODE 1, Firewall 0 active) | licensing products | Μεταφορά με configoptions (prefix, PSK, reissue/conflict flags) |
| `nixpal_license` module + `nixpallicense` addon | Παλιό IP-based HMAC licensing, 0 active | **Drop** |
| `servers/zonecloud` (764 γρ. + tpl) | ZoneCloud provisioning | Port σε Blesta module |
| Hooks `validips.php`, `validdomain.php`, `clientarea_customfields.php`, `service_customfields.php` | Εμφάνιση IP/hostname/bindings στο client area | Μπαίνουν στο client view του licensing module |
| `DomainMigration.php`, `ispapi_transliteration.php`, `sitejetDeactivate.php`, `custom-sidebar.php`, `menu-hide.php` | Domains/UI | Ίδια λογική με myip ή drop |
| Root: `mydomainsearch.php`, `mywhois.php`, `unblocktest2.php`, `dnssec.php`, `ninja/` | Διάφορα | Να ελεγχθούν, πιθανόν drop |
| Gateways | PayPal, Stripe | Core Blesta |
| Registrars | CNIC, OpenProvider | Βλ. §6 |

### 4.2 myip.gr — custom addons

| Addon | Γραμμές | Απόφαση |
|---|---|---|
| `ekdosi_bridge` 0.53 | 7379 | **Port** → plugin `ekdosi_bridge` (ίδιο HMAC contract, §5.2) |
| `PaymentsTrackingMatch` + widgets | 800 | **Port** → plugin `payments_tracking` (ή matching μέσα στο Ekdosi, §5.3) |
| `timologia` (Παραστατικά σε τρίτους) | 1409 | Port: λογική → `ekdosi_bridge` (έχει ήδη `ThirdPartyStore`), client UI → plugin |
| `afm2name` (ΑΦΜ lookup) | 223 | Port μικρό (signup/profile) |
| `domain_migration` (audit) | 185 | Port μόνο αν δεν έχει τελειώσει η μετάβαση από CNIC |
| `domainpricing_table` (Namecheap margin) | 474 | Port (admin tool) |
| `zoneclouddomainsearch` + hook `_searchDomainZcloud` | 260+55 | Port |
| `domain_search` | 140 | Έλεγχος αν χρειάζεται |
| `mobian` (license viewer) | 135 | Port μικρό ή drop |
| `duedate_comparison` | 224 | **Drop** (WHMCS integrity workaround) — επανεκτίμηση μετά το import |
| `relid_remover` | 268 | **Drop** |
| `prepare_for_ekdosi` | 242 | **Drop** (το έχει αντικαταστήσει το bridge) |
| `transfer_invoice`, `invoice_transfer` | 693+169 | **Drop** αρχικά· port μόνο αν το χρειάζεται το staff |
| `bulkdelete` | 91 | Drop |
| `darkblend` | — | Drop (θέμα του admin του WHMCS) |

### 4.3 myip.gr — modules / gateways / registrars

| Κομμάτι | Απόφαση |
|---|---|
| `servers/mailcow` (1214 γρ., δικό μας) | **Port** → Blesta module |
| `servers/ngm` (932 γρ., δικό μας) | **Port** → Blesta module |
| `cpanel`, `centovacast` | Core Blesta |
| `pvewhmcs` (8 VMs) | Core Proxmox module του Blesta· χρειάζεται mapping των service fields (vmid, node) |
| `thesslstorefullv2` (12) | Έλεγχος αν υπάρχει Blesta module από τον vendor |
| `gateways/eurobanklib.php` (119 γρ., Cardlink/Eurobank vPOS redirect + digest) | **Port** → nonmerchant gateway `cardlink_eurobank` (163 services). Πηγές: αυτό + Ekdosi `Services/Payments/Gateways/EurobankGateway.php` (450 γρ.) — το Ekdosi είναι το πιο πρόσφατο/καθαρό reference |
| `gateways/myvivapay.php` (256 γρ.) | Port αν χρησιμοποιείται ακόμα (0 services στο default) |
| PayPal (`paypal`, `paypal_ppcpv`), Stripe, banktransfer | Core Blesta· **προσοχή στα PayPal subscriptions** (IPN πάνε στο URL του WHMCS) |
| `registrars/grepp` (+3 server modules, `grwhoisapi`) | **Encoded vendor** → §6 |
| `registrars/mygr` (2891 γρ.) | 0 active domains → **Drop** |
| `openprovider`, `cnic` | Έλεγχος για Blesta modules (§6) |

### 4.4 myip.gr — hooks

| Hook file | Event | Απόφαση |
|---|---|---|
| `DomainMigration.php` (678) + `DomainMigrationDisableAutoRenew.php` | PreRegistrarRenewDomain, DailyCronJob | Ολοκλήρωση της μετάβασης πριν το cut-over → drop |
| `Post-transfer-nameserver-correction-hook.php` | AfterRegistrarTransfer | Port (event μετά το transfer) |
| `myip_branding.php` (513) | ClientArea Head/Header/Footer, CheckoutComplete | Γίνεται μέρος του Blesta theme |
| `auto-cancellation.php` | AfterModuleTerminate, DailyCronJob | Port (cron task) — έλεγχος αν το καλύπτουν οι ρυθμίσεις του Blesta |
| `overpayment_detection.php` | AddInvoicePayment | Port (event Transactions) |
| `CancelProductOnCancelInvoice.php` | InvoiceCancelled | Port |
| `ExpiredDomains.php`, `expiring_eu_domains.php`, `domain_manual_renewal.php` | DailyCronJob, AfterRegistrarRenewal | Port σε cron tasks του plugin `myip_ops` |
| `suspensions.php` | AfterModuleSuspend | Port |
| `AdminStatsForWHMCSv8.php` | AdminAreaHeaderOutput | Drop / widget |
| `invoicepaid.php` | InvoicePaid | **Νεκρό** (χρησιμοποιεί `mysql_query`) → drop |
| `ispapi_transliteration.php`, `transliterate.php`, `openprovider.js`, `sitejetDeactivate.php` | vendor / UI | Drop ή αντίστοιχο στο Blesta |

### 4.5 myip.gr — widgets (`modules/widgets`)
`PaymentsTracking` (146), `domain_control` (252), `eu_domain_expiration` (63), `income_forecast` (81),
`upcoming_domain_renewals` (38), `upcoming_services_renewals` (55), `DomainSearch` (109)
→ ένα plugin `myip_widgets` με N widgets. Τα queries ξαναγράφονται για το schema του Blesta
(services/service_fields· τα domains μέσω του Domain Manager).

### 4.6 Αρχεία στο root του WHMCS που σερβίρουν το site
`domain-check.php` (live search του /domains στο astro site), `ip-info.php` (/ip page),
`check_domain_pricing.php`, `mydomainsearch.php`, `mywhois.php`, `gr.php`, `newgr.php`, `unblock/`.
→ Τα `domain-check` και `ip-info` χρησιμοποιούν το init του WHMCS· θέλουν δικό τους μικρό service
(ή endpoint σε plugin). Τα υπόλοιπα: έλεγχος, πιθανόν drop.

---

## 5. Σχέδιο για τα κρίσιμα κομμάτια

### 5.1 `nixpal_licensing` (plugin) + `licensing` (module)
> **Ενημέρωση 2026-10-08:** ο τρέχων σχεδιασμός και η κατάσταση είναι στο `docs/licensing.md`. Αλλαγές από το αρχικό σχέδιο: plugin και module λέγονται `nixpal_licensing`· binding μόνο σε IP· το `zlic.php` μένει αρχείο στο docroot (χωρίς rewrite και χωρίς verify.php), πάνω στη βιβλιοθήκη `lib/nixpal-licensing`.
- **Module** (`components/modules/nixpal_licensing`): σε addService δημιουργεί κλειδί (`prefix` + random,
  length από config). Σε suspend/unsuspend/cancel αλλάζει status σε Suspended/Active/Expired.
  Config ανά package: prefix, key length, **PSK**, allow reissue, allow domain/ip/dir conflict.
  Client tab: key, bindings, κουμπί reissue (όριο `maxreissues`).
- **Plugin** (`plugins/nixpal_licensing`): πίνακες `licenses` (service_id, licensekey, validdomain,
  validip, validdirectory, reissues, status, lastaccess) και `license_log`· admin λίστα/αναζήτηση·
  **public controller `verify`** που αναπαράγει το `verify.php` (XML-like tags + `md5hash`) και **`zlic`**
  που επιστρέφει ακριβώς ό,τι σήμερα το zlic.php (μπορεί να ξαναχρησιμοποιήσει τον κώδικα του zlic.php
  σχεδόν αυτούσιο, με in-process lookup αντί για curl).
- Συμπεριφορές προς αναπαραγωγή (από golden fixtures, §7 Phase 0): auto-bind στο πρώτο check όταν τα
  binding fields είναι κενά, κανόνες conflict, «Reissued» = reset bindings, τα description strings,
  `nextduedate`/`regdate`/`productname`/`configoptions`/`customfields`.
- Rewrite: `clients.nixpal.com/zlic.php` → plugin controller. Πρόσθεσε cache/rate-limit για Expired/Suspended.
- Data migration: `mod_licensing` (+ προαιρετικά log) με mapping `serviceid` WHMCS → Blesta.

### 5.2 `ekdosi_bridge` (plugin) + Ekdosi `BlestaBillingSource`
- Plugin: ίδιο HMAC σχήμα και ίδια ops (`resolve`, `invoices`, `invoice`, `custom_fields`, `add_payment`,
  `resellers`, `invoiced_flags`, `legacy_invoice_links`) + inbound write-back του MARK. Πίνακες
  `invoice_marks`, `contacts`, `routing`, `bridge_log` (μεταφορά δεδομένων από `mod_ekdosi_*`).
- Admin/client UI: MARK/ΤΠΥ στο invoice view, routing σε τρίτους (αντικαθιστά `timologia`).
- Ekdosi: νέο `Billing/Sources/BlestaBillingSource.php`· το path του bridge γίνεται per-source config
  (σήμερα σταθερά `Company::WHMCS_BRIDGE_PATH`)· αντιστοίχιση των 8 WHMCS API calls σε Blesta API·
  `whmcs_invoice_id` → γενικό `external_invoice_id` + `source`, με το ιστορικό WHMCS να μένει ως έχει.

### 5.3 Payments tracking
- Ο IMAP parser μένει ως έχει, αλλά ο πίνακας `payments_tracking` **φεύγει από τη WHMCS DB**
  (δική του DB ή πίνακας του plugin).
- Matching: είτε cron task στο plugin `payments_tracking` (Blesta transaction + apply), είτε —καλύτερα—
  μέσα στο Ekdosi (`WhmcsPaymentReconciler` → γενικό reconciler που καλεί `add_payment` του bridge).
- Widgets: Payments tracking (λίστα + load more/pending) και Bank Totals ανά τράπεζα.

### 5.4 `cardlink_eurobank` gateway
Nonmerchant redirect στο `vpos.eurocommerce.gr/vpos/shophandlermpi` (test: `eurocommerce-test.cardlink.gr`),
digest calculation όπως στο `calculate_digest()`, callback/validate → Blesta transaction.
✅ Γραμμένο (2026-10-09): `components/gateways/nonmerchant/cardlink_eurobank` + `lib/cardlink-eurobank` +
mapping `eurobanklib.php`· σχεδιασμός και ανοιχτά στο `docs/importer.md` («Cardlink / Eurobank»).

---

## 6. Domains / registrars — απόφαση πριν από οτιδήποτε άλλο

- **.gr (grepp, 1381 domains) — γράφεται από την αρχή, με βάση τα docs του Ekdosi.**
  Ο EPP client ζει **μέσα σε αυτό το repo** (`lib/gr-epp`, καθαρό PHP χωρίς εξαρτήσεις από Blesta, με δικά του
  tests) και τον χρησιμοποιεί το Blesta registrar module `grepp` (register, transfer, renew, NS, contacts/owner
  change, DACOR token, credits widget). Τα `docs/domains/grepp/*` (README, XML/XSD v4.3, `EppClient.java`,
  CA bundle) μεταφέρονται από το Ekdosi στο `docs/grepp/` εδώ, και το A4 του Ekdosi κλείνει. Owner change / owner transfer / two-char SR γίνονται registrar actions ή products.
  Το transport **δεν** είναι RFC 5734: είναι XML πάνω από HTTPS POST στο `/epp/proxy` με cookie `JSESSIONID`,
  οπότε οι έτοιμες βιβλιοθήκες EPP δεν κάνουν. Πρώτο βήμα: δήλωση της IP του dev/staging στο whitelist του Μητρώου
  και login/hello/check στο UAT, ώστε να κλείσουν τα `[ΕΠΙΒΕΒΑΙΩΣΗ]`.
  Μέχρι να είναι έτοιμο, το myip.gr μένει στο WHMCS, αφού δεν μπορεί να μεταφερθεί χωρίς .gr.
  Data migration: τα domains περνάνε με τον importer· έλεγχος ότι registrar = `grepp` και τα dates ταιριάζουν
  με το registry (sync μετά το import).
  Ανανεώσεις: ίδιοι κανόνες χρημάτων με το `myopenprovider` (pending πριν τη χρέωση, adopt, ανά πληρωμένη περίοδο,
  και «prepaid» για χειροκίνητη ανανέωση πριν την πληρωμή αντί για αφαίρεση relid)· κοινή λογική, `docs/openprovider.md`.
- **OpenProvider (241) / CNIC (209):** υπάρχουν Blesta modules. Αν ολοκληρωθεί η μετάβαση CNIC→OP,
  αρκεί μόνο το OpenProvider. Το module του Blesta δεν ανανεώνει domains: δικό μας fork `myopenprovider`,
  κενά και σειρά δουλειάς στο `docs/openprovider.md`.
- **PayPal / Stripe:** core gateways του Blesta.
- Import: έλεγχος ότι τα expiry/next due dates και το auto-renew περνάνε σωστά (το `domain_control`
  widget σήμερα ελέγχει ακριβώς αυτά — να τρέξει ως validation μετά το import).

---

## 6b. Κώδικας από το Ekdosi που μεταφέρεται στο Blesta

Δικός μας κώδικας, οπότε τον παίρνουμε αυτούσιο. Είναι γραμμένος σε Laravel (Eloquent, `Http` facade, config, events), άρα κατά το port: καθαρή PHP + Guzzle/cURL, persistence μέσω των models/Record του Blesta, settings από το config του gateway/module.

| Από το Ekdosi (`app/Services/…`) | Γραμμές | Πάει σε | Τι κρατάμε |
|---|---|---|---|
| `Payments/Gateways/EurobankGateway.php` | 450 | `gateways/nonmerchant/cardlink_eurobank` | Ολόκληρη τη ροή Cardlink (request, digest, callback/verify, test/prod). **Κύρια πηγή**, το `eurobanklib.php` του WHMCS μόνο για σύγκριση |
| `AadeRegistryLookup.php` | 407 | plugin `myip_tools` (αντικαθιστά το `afm2name`) | Αναζήτηση ΑΦΜ στο μητρώο της ΑΑΔΕ κατά το signup και την επεξεργασία προφίλ |
| `Payments/PaymentAllocator.php` + `Whmcs/WhmcsPaymentReconciler.php` | 613 + … | plugin `payments_tracking` | Κανόνες κατανομής μιας κατάθεσης σε invoices (υπερπληρωμή, μερική πληρωμή, πολλά invoices), μαζί με τα tests |
| `Domains/DomainSyncService.php`, `DomainRenewalService.php`, `DomainTransferService.php` | 216/351/146 | module `grepp` | Ροές και edge cases (renewal σε εξέλιξη, domain που δεν βρέθηκε στον registrar, sync των ημερομηνιών) ως πρότυπο για το .gr module |
| `Domains/DomainCsvImportService.php` | 204 | `migration/` | Import των CNIC domains χωρίς API (απόφαση «χωρίς API» του BACKLOG) |
| `Reminders/*` | 1318 | (reference) | Το Blesta έχει δικά του notices. Κρατάμε μόνο τη λογική για 60/30/15/10/5 ημέρες αν δεν καλύπτεται |
| `docs/domains/grepp/*`, `docs/domains/whmcs-baseline.md` | — | `docs/` | Οδηγός EPP, XML/XSD, baseline παραγωγής (μετρήσεις domains ανά registrar/TLD) |
| `docs/payment-gateways-*.md`, `payment-connectors.md` | — | `docs/` | Σχεδιαστικές αποφάσεις για PayPal/Stripe/Eurobank |

Όχι για port: `Domains/Registrars/OpenproviderRegistrar.php` (το Blesta έχει δικό του module), `Provisioning/*` (μόνο stubs).
Στο Ekdosi μένουν όλα όσα αφορούν τιμολόγηση: myDATA, PEPPOL, ΤΠΥ, `WhmcsInbox` (που γίνεται γενικό inbox του billing source), Φορολογικά.

## 7. Φάσεις

> **Στρατηγική:** η nixpal είναι το pilot. Πρώτο και μοναδικό blocker της είναι το licensing.
> Μόλις κλείσει, «πετάμε» το WHMCS της nixpal και το Blesta της nixpal γίνεται το πεδίο δοκιμών
> για όλα τα υπόλοιπα (gateways, registrars, widgets, theme) πριν αγγίξουμε το myip.gr.

**Phase 0 — Προετοιμασία (μικρή)**
- Repo `blesta-modules` + `docker-compose` (Blesta + MariaDB + PHP).
- **Golden fixtures για το zlic:** καταγραφή πραγματικών responses (Active / Suspended / Expired /
  Invalid key / Reissued / domain-ip-dir conflict / λάθος PSK) με test licenses στο WHMCS της nixpal.
  Ένα από κάθε client (Go agent, cpp-httplib node, Guzzle controller) να τρέξει πάνω στα fixtures.
- Δοκιμαστικό import της **nixpal** DB (copy) με τον Blesta WHMCS importer + ID map.

**Phase 1 — Licensing plugin (PRIORITY #1)**
`nixpal_licensing` plugin + `nixpal_licensing` module (§5.1), import του `mod_licensing`,
public `zlic` controller + rewrite. Acceptance: όλα τα fixtures byte-identical, και shadow-run
(το Blesta απαντάει παράλληλα με το WHMCS για μερικές μέρες, και συγκρίνουμε τα αποτελέσματα στα logs).

**Phase 2 — Cut-over nixpal**
`zonecloud` module, PayPal/Stripe (core), OpenProvider/CNIC (core), βασικό theme + email templates.
Πλήρες import → switch του `clients.nixpal.com` → WHMCS read-only/dump. Δεν υπάρχει σκληρή προθεσμία: η άδεια Plus 250
είναι month-to-month, οπότε απλώς ανανεώνεται ανά μήνα μέχρι να είναι έτοιμο το cut-over.

**Phase 3 — Sandbox πάνω στο Blesta της nixpal** (χωρίς ρίσκο για το myip)
- `grepp` registrar (port από τον .gr κώδικα του Ekdosi, κοινή EPP lib) → δοκιμές με test/OT&E του registry.
- `cardlink_eurobank` (port από `EurobankGateway.php` του Ekdosi) → δοκιμές σε `eurocommerce-test.cardlink.gr`.
- `myip_widgets`, `myip_ops`, `ekdosi_bridge` + `BlestaBillingSource` (με test company στο Ekdosi).

**Phase 4 — myip.gr modules**
`mailcow`, `ngm`, mapping Proxmox, SSL, `payments_tracking`, `afm2name`, `zoneclouddomainsearch`,
`domainpricing_table`, theme με το branding του myip, endpoints για το site (`domain-check`, `ip-info`).

**Phase 5 — Dress rehearsal & cut-over myip.gr**
2–3 πλήρεις δοκιμαστικές μεταφορές· validation (σύνολα invoices/transactions, domain dates έναντι registry,
Ekdosi inbox χωρίς διπλά παραστατικά)· freeze· import· DNS/rewrites· PayPal IPN forwarder·
πλήρες dump του WHMCS για αρχείο.

---

## 8. Προτεινόμενη δομή repo

```
blesta-modules/
  plugins/
    nixpal_licensing/        # licenses, log, verify + zlic public controllers
    ekdosi_bridge/           # HMAC ops + inbound MARK, third-party routing
    payments_tracking/       # matching + widgets (αν δεν πάει στο Ekdosi)
    myip_widgets/            # domain_control, renewals, eu expiry, income forecast
    myip_ops/                # ported hooks/cron tasks
    myip_tools/              # afm2name, pricing table, zonecloud search, mobian
  lib/
    gr-epp/                  # .gr EPP client (HTTPS /epp/proxy + JSESSIONID), framework-agnostic + tests
  components/
    modules/{nixpal_licensing,zonecloud,mailcow,ngm,grepp}/
    gateways/nonmerchant/{cardlink_eurobank,vivawallet}/
  migration/
    idmap/                   # build WHMCS↔Blesta id map μετά το import
    import_licensing.php     # mod_licensing → nixpal_licensing
    import_ekdosi.php        # mod_ekdosi_* , mod_timologia*
    import_payments.php      # payments_tracking
    validate/                # checks μετά το import (σύνολα, domains, fixtures)
  tests/fixtures/zlic/       # golden responses (χωρίς πραγματικά keys)
  docker/
  docs/
```

---

## 9. Ανοιχτά ερωτήματα

1. .gr: ο EPP client δεν υπάρχει, μόνο docs (μεταφέρονται εδώ). Πότε θα ζητηθεί η πρόσβαση στο UAT και το IP whitelist για το dev/staging;
1b. ✅ **ΑΠΟΦΑΣΗ (2026-10-08): Blesta = control plane, Ekdosi = μόνο τιμολόγηση/myDATA.** Τα σχέδια του Ekdosi να γίνει «τιμολογιέρα + κλώνος του WHMCS» (PLAN §6.5, Πυλώνας A/A4, provisioning, portal) έχουν παγώσει. Το Ekdosi μιλάει με το Blesta μόνο μέσω του `ekdosi_bridge` και του `BlestaBillingSource`. Ενέργεια στο repo του Ekdosi: σημείωση «frozen — superseded by blesta-modules» στο PLAN §6.5 και στο BACKLOG A4. Ιστορικό της συζήτησης:
    **Σύγκρουση στρατηγικής:** Το `PLAN.md §6.5` του Ekdosi (2026-09) ορίζει ήδη τον «απογαλακτισμό από το WHMCS» με το **ίδιο το Ekdosi** (portal, cart, services, domains, tickets, hosting) και τη **Nixpal ως canary** (`cs.nixpal.com`). Επίσης το BACKLOG έχει Πυλώνα A (domains), gateways (`EurobankGateway.php` υπάρχει ήδη), provisioning και portal. Πρέπει να αποφασιστεί αν ο στόχος είναι Blesta ή Ekdosi, πριν γραφτεί κώδικας, ώστε να μη χτιστούν δύο φορές τα ίδια (licensing, .gr, gateways). Μια λογική μέση λύση: ο .gr EPP client και η λογική του licensing ως ανεξάρτητα composer packages, με μικρούς adapters για Blesta ή Ekdosi.
2. Ο importer του Blesta κρατάει τα IDs ή χρειαζόμαστε πάντα map; Πώς χειρίζεται τους κωδικούς των πελατών και τα gateway tokens;
3. PayPal subscriptions: πόσα ενεργά; Forwarder του IPN από το παλιό callback URL ή επανεγγραφή;
4. Το matching των πληρωμών: στο Blesta plugin ή στο Ekdosi;
5. Θα μείνουν σε δύο ξεχωριστά Blesta (myip, nixpal) ή ένα με δύο companies; (Το Blesta υποστηρίζει multi-company.)
6. Ποια από τα μικρά admin tools χρησιμοποιεί πραγματικά το staff (dimitrisg, hliasant, haris);
7. Στο dashboard του myip η άδεια WHMCS φαίνεται να λήγει 7/10/2026 — να επιβεβαιωθεί πριν χαθεί η πρόσβαση.

---

## 10. Ασφάλεια Blesta core (diff 6.0.2 → 6.0.3, 2026-10-09)

Σύγκριση των release zip 6.0.2 και 6.0.3 (ανάγνωση κώδικα· τα 6 ionCube αρχεία, π.χ. `app_controller.php`,
`app_model.php`, `license.php`, δεν διαβάζονται). Λεπτομέρειες και PoC κρατιούνται εκτός repo μέχρι να
τα παραλάβει η Blesta (coordinated disclosure).

### 10.1 Τι διορθώνει το 6.0.3 → ελάχιστη έκδοση για staging/παραγωγή
- **Πλαστές πληρωμές:** PayPal Payments Standard (post-back που δεχόταν ό,τι δεν ήταν `INVALID`, και `cmd`
  override), CCAvenue (δεκτό POST χωρίς κρυπτογράφηση), CoinGate (token `md5(order_id)`, ποσό από το request),
  Stripe webhook (χωρίς υπογραφή, ποσό/invoices από το payload).
- `GatewayPayments::processNotification`: πλέον μόνο «προς τα εμπρός» μεταβάσεις status (ένα replay δεν
  ξανακάνει approved ένα refunded) και όριο στο ποσό που εφαρμόζεται στα invoices.
- **2FA:** δεν υπήρχε throttle στο OTP, `ip_address` από το POST στο `order/login/otp`, σύγκριση με `==`.
  Επίσης στο step-up login του admin το `username` ερχόταν από το POST.
- ACH: `status` από το POST παρέκαμπτε την επαλήθευση· Stripe ACH δεχόταν `pm_` άλλου πελάτη.
- Stored XSS (όνομα πελάτη) στο admin «Make Payment» και στην πρόσκληση account manager. Λάθος ACL στα quotations.
  Affiliate payout σε νόμισμα χωρίς ισοτιμία.
- OpenSRS: ο κωδικός end-user ήταν `md5(client id)`· **οι παλιοί κωδικοί δεν αλλάζουν με το upgrade**.
- Web upgrader: προβλέψιμα temp paths και αναγνώσιμα backups (θέμα μόνο σε shared hosting).

### 10.2 Ανοιχτά ακόμη στο 6.0.3 (αναφέρονται στη Blesta)
- **Gateways που ΔΕΝ ενεργοποιούμε:** PayFast, CoinPayments, BitPay, PayPal Checkout, Alipay (πλαστή
  πληρωμή), Skrill, 2Checkout, Razorpay (μικρότερα). Δεν χρειαζόμαστε κανένα· τα δικά μας είναι
  `cardlink_eurobank`, `paypal_vault`, `vivawallet`.
- Stored XSS από πελάτη προς staff στα autocomplete πελατών (domains, services, affiliates, tickets) και
  στο preview του mass mailer.
- Affiliate payout: mass assignment `status=approved` από τον affiliate (`plugins/order/controllers/payouts.php`).
- Stripe `storeCc()`: δεν ελέγχει σε ποιον πελάτη ανήκει το `pm_`.
- Ενέργειες διαγραφής μέσω GET χωρίς CSRF (notes, contacts, payment accounts, staff του support manager,
  uninstall plugin).

### 10.3 Κανόνες για τα δικά μας gateways/plugins (από τα παραπάνω)
- Υπογραφή/digest πάνω σε **κάθε** πεδίο που καταγράφουμε (ποσό, νόμισμα, invoices, client)· `hash_equals`.
- Αποτυχία ελέγχου ⇒ καμία συναλλαγή (ούτε `declined`/`error` με δεδομένα από το request).
- Όπου γίνεται, status/ποσό από τον provider (API), όχι από το callback.
- Έλεγχος ότι τα invoices ανήκουν στον client και στο νόμισμα· όριο στο ποσό.
- Ποτέ μεταβάσεις refunded/void → approved από callback· idempotency στο transaction id.
- ID από τον browser (token, `pm_`, order id) μόνο με έλεγχο ιδιοκτησίας.
  `paypal_vault`: setup token δεμένο με το session (PR #12)· έλεγχος όλων των δικών μας στο `docs/gateway-security-6.0.3.md`.
- Escaping σε κάθε όνομα/πεδίο πελάτη στα views, και σε JS (`textContent`, όχι `innerHTML`).
- Ενέργειες που αλλάζουν δεδομένα μόνο με POST (+ CSRF token του Blesta).
- Μετά το import από WHMCS: reset κωδικών για παλιά md5 hashes (`Users` συγκρίνει τα legacy hashes με `==`).

---

## 11. Εργαλεία: MCP για Blesta και WHMCS (απόφαση 2026-10-10)

- **Απόφαση:** για πειράματα πριν το migration και για τη λειτουργία μετά, το Blesta και το WHMCS
  θα είναι προσβάσιμα από το Claude μέσω του **gsc-mcp** (νέο domain `billing`), όχι μέσω Blesta plugin
  και όχι με γραφές στη βάση μέσω SSH. Spec amendment και design: `chrismfz/gsc-mcp-server` →
  `docs/design/billing.md`. **Δεν έχει υλοποιηθεί ακόμα.**
- Μόνο τα **HTTP APIs** των πλατφορμών (Blesta `/api/<model>/<method>`, WHMCS `includes/api.php`),
  ώστε να τρέχουν τα validations και τα events των models. Το SSH μένει για logs, cron, αρχεία και
  read-only SQL στα audits.
- Τρία εργαλεία: `billing_list_instances`, `blesta_call`, `whmcs_call`. Γραφές μόνο σε instance με
  `write=true` (default false), με preview→confirm και audit log.
- **WHMCS παραγωγής:** API credential με role που επιτρέπει μόνο `Get*` actions και `DecryptPassword`,
  και IP restriction στο egress του gsc-mcp.
- **Ανάκτηση credentials:** τα passwords των registrars, gateways και server modules, που υπάρχουν μόνο
  κρυπτογραφημένα, κρύβονται by default στα αποτελέσματα και εμφανίζονται με `reveal_secrets:true`.
  Blesta: τα meta επιστρέφονται ήδη αποκρυπτογραφημένα. WHMCS: η κρυπτογραφημένη τιμή (π.χ.
  `tblregistrars.value`, read-only SQL μέσω SSH) περνάει από `DecryptPassword`. Ό,τι ανακτηθεί πάει στα
  config των modules του Blesta, **ποτέ στο repo**.
- **Blesta:** ξεχωριστός API user ανά instance. Το API key δένεται σε company, άρα δύο companies =
  δύο instances.
- **Το staging τρέχει με πραγματικά δεδομένα** (απόφαση, όπως στο Ekdosi). Επειδή μια γραφή μέσω API
  ενεργοποιεί πραγματικά side effects, **πριν την πρώτη γραφή** στο staging πρέπει να ισχύουν:
  1. Εξερχόμενα emails κομμένα ή ανακατευθυνόμενα (καμία ειδοποίηση σε πραγματικό πελάτη).
  2. Gateways σε test/sandbox ή χωρίς live credentials (Cardlink, PayPal, Stripe).
  3. Registrar modules σε test endpoints ή απενεργοποιημένα (.gr EPP, OpenProvider, CNIC).
  4. Provisioning modules σε test servers (cPanel, mailcow, Proxmox κ.λπ.).
  5. Το `ekdosi_bridge` να μη δείχνει σε myDATA παραγωγής.
  6. Cron απενεργοποιημένο ή ελεγμένο (θα έκανε τα 1–4 μόνο του).

### 11.1 Προσοχές για τις γραφές μέσω API (από τις ανανεώσεις domains, 2026-10-10)
- **Το API key του Blesta δίνει πρόσβαση σε όλα τα models, χωρίς δικαιώματα ανά method.** Άρα το
  `blesta_call` με `write=true` χρειάζεται **allowlist** από models/methods στο gsc-mcp, όχι γενική κλήση.
- **Κλήσεις που ξοδεύουν πραγματικά χρήματα στον registrar**, ακόμα και χωρίς άμεση κλήση στο module:
  - `Services::renew` → `renewService()` → `POST /renew` στο Openprovider·
  - εξόφληση τιμολογίου (`Transactions::add`/`apply`, `Invoices::setClosed`) → το cron
    «process renewing services» κάνει την ανανέωση μέσα σε 5 λεπτά·
  - `Invoices::createRenewalFromService` (νέο τιμολόγιο) δεν χρεώνει, αλλά μετακινεί αμέσως το
    `date_renews`.
  Σε staging επιτρέπονται μόνο με module row σε **test mode** (sandbox). Κάθε preview να το δείχνει.
- **Ποτέ `ModuleManager::setMeta` στο `myopenprovider`:** σβήνει **όλο** το meta του module, μαζί με τις
  εγγραφές ανανέωσης `myop_renewal_<service id>` (η προστασία από διπλή χρέωση) και τα tokens
  (`docs/openprovider.md`). Να μπει σε deny-list.
- **Το state των ανανεώσεων διαβάζεται, δεν γράφεται με το χέρι:** το `module_meta.myop_renewal_*` αλλάζει
  μόνο από το `renewService` και την admin καρτέλα «Ανανέωση» («Ανανέωση τώρα», «Σήμανση ως ανανεωμένο»,
  «Καθαρισμός εγγραφής»). Αν χρειαστεί από το MCP, να γίνει ειδικό εργαλείο με τους ίδιους κανόνες
  (`RenewalPlan`), όχι raw write.
- **Αλλαγές που επηρεάζουν πόσα έτη θα ανανεωθούν:** `Services::edit` σε `date_renews`/`date_last_renewed`/
  `pricing_id`, void ή διαγραφή τιμολογίου ανανέωσης, `ServiceInvoices` (σύνδεση υπηρεσίας–τιμολογίου). Το
  `myopenprovider` υπολογίζει τα έτη από αυτά (όρος × cycles) και αρνείται όταν δεν είναι σαφή
  (`cycles_unknown`, `multiple_invoices`, `period_ambiguous`).
- **WHMCS:** η εξόφληση τιμολογίου (`AddInvoicePayment`, `UpdateInvoice` σε Paid) στέλνει renew στον
  registrar (γι' αυτό σήμερα βγάζουμε το relid). Το read-only role της παραγωγής καλύπτει αυτό· σε όποιο
  WHMCS instance γίνει `write=true`, ισχύει το ίδιο.
- **Παράλληλη λειτουργία WHMCS + Blesta:** ένα domain ανανεώνεται από **ένα** σύστημα. Πριν το Blesta
  αναλάβει τα domains που εισήχθησαν: στο WHMCS καμία ανανέωση στον registrar για αυτά (τα τιμολόγια
  ανανέωσης ή το registrar module κλειστά), και autorenew off στο Openprovider (script στο
  `docs/openprovider.md`). Αλλιώς ανανέωση και από τα δύο.
- **SSH:** το host `orion.myip.gr` του gsc-mcp συνδέεται ως **root**. Ο orion είναι server παραγωγής (μόνο
  read-only, CLAUDE.md)· για τη δουλειά στο staging χρειάζεται host που συνδέεται ως `csnixpal`.

---

## 12. Πύλη απόφασης πριν το myip.gr (απόφαση 2026-10-10)

- **Απόφαση:** το Blesta παίρνει την ευκαιρία του με το cut-over της **nixpal**. Αλλαγή πλατφόρμας δεν γίνεται
  τώρα. Ξανακρίνεται **πριν** αρχίσει η δουλειά για το myip.gr (Phase 4), με τα κριτήρια που ακολουθούν, γραμμένα από πριν.
- **Γιατί:** τα περισσότερα κομμάτια που γράφουμε (Cardlink/IRIS, .gr EPP, licensing, `ekdosi_bridge`, payments
  tracking, mailcow, ngm) δεν υπάρχουν σε καμία πλατφόρμα, ούτε στο WHMCS (εκεί ήταν δικός μας ή αγορασμένος κώδικας).
  Ελλείψεις του ίδιου του Blesta: Openprovider χωρίς renew, ανανέωση πριν την πληρωμή, PayPal vault, EPP code, whois.
- **Αξιολόγηση Paymenter (2026-10-10): `docs/platform-evaluation.md`** — πρόταση για pilot 2 εβδομάδων με τη nixpal πριν την απόφαση.
- **Εναλλακτική αν αποτύχει η πύλη:** fork του **Paymenter** (MIT, Laravel, όπως το Ekdosi, έχει WHMCS importer:
  paymenter.org/docs/guides/whmcs-importer)· δεύτερη επιλογή το FOSSBilling (0.8.x, το README δηλώνει ακόμη beta).
  Όχι «Ekdosi χωρίς τιμολόγηση» (§9.1b). Η έξοδος μένει φθηνή μόνο αν η λογική μένει στο `lib/` και τα adapters λεπτά.
- **Κριτήρια (να απαντηθούν μετά το cut-over της nixpal):**
  1. Η Blesta απάντησε στο security report και έβγαλε διορθώσεις σε release μέσα σε λογικό χρόνο (στόχος: 3 εβδομάδες από 2026-10-09).
  2. Μέχρι τότε, local patches για όσα ευρήματα του core μας αφορούν (C1, H1–H4, M1, M3, M5–M8), κρατημένα στο repo·
     κανένα stock gateway ενεργό εκτός Stripe και των δικών μας.
  3. Το checklist του `docs/nixpal-cutover.md` κλείνει χωρίς νέα hacks στον core· ό,τι χρειάστηκε καταγράφεται εδώ.
  4. Τα core provisioning modules (cPanel, Centova, Proxmox) δοκιμάζονται στο staging πριν το myip.gr.
  5. Για τη σύγκριση με το Paymenter: τι περνάει ο importer του (αποθηκευμένες κάρτες Stripe, PayPal vault tokens,
     invoice IDs > 32453, tickets, domains με ημερομηνίες) και ποια provisioning/registrar modules έχει πραγματικά.
