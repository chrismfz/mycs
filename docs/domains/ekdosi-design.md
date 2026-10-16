> **Αντίγραφο από το Ekdosi** (`docs/domains/README.md`, 2026-09-25), για αναφορά. Γράφτηκε για το Ekdosi (`company_id`, φορολογικό
> τιμολόγιο, `ServiceContract`, ανανέωση στην έκδοση)· όπου διαφωνεί, ισχύει το [`docs/domains.md`](../domains.md).


# Domains — αναλυτικό design (Πυλώνας A του `PLAN.md`)

> **STATUS: DESIGN — pre-build, NO code yet.** Το solid σχέδιο πριν το A0, μετά από
> Q&A με τον ιδιοκτήτη + τα πραγματικά WHMCS screenshots + research στο Openprovider
> module/API και στο WHMCS registrar function set / .gr registry. Είναι ο αναλυτικός
> «ξεδίπλωμα» του `PLAN.md §3` (domains) — ο twin του `docs/payment-connectors.md`.
>
> **Γείωση:** κάθε αναφορά σε υπάρχον ekdosi symbol είναι πραγματική· κάθε Openprovider
> endpoint/field από το επίσημο swagger (`openprovider/api-documentation`) + το module
> source (`openprovider/Openprovider-WHMCS-domains`)· οι κανόνες .gr από EETT/FORTH.

---

## 0. TL;DR

Native domain management, **modular ανά registrar** (ίδιο pattern με το `EInvoiceProviderTransport`).
v1 registrars: **`openprovider`** (gTLDs/ccTLDs) + **`grepp`** (direct .gr EPP — το «Forth»),
με **routing ανά TLD**. Feature parity με το WHMCS domain module **φραγμένη στο API**. Το billing
πατά 100% στο υπάρχον **`ServiceContract`** engine (renewals + dunning). Per-tenant opt-in
(`enable_domain_management`), operator-gated (ποτέ auto-file στην ΑΑΔΕ). Χτίζεται σε **6 gates
A0–A5** — σταματάς όπου θες.

---

## 1. Πλαίσιο & scope

**Γιατί:** η MyIP αντικαθιστά σταδιακά το WHMCS (`PLAN.md`, strangler-fig). Τα domains είναι ο
πρώτος πυλώνας. Το ekdosi κάνει ήδη το δύσκολο (invoices/myDATA/recurring/υπόλοιπα)· εδώ
προσθέτουμε registrar integration + domain lifecycle, πατώντας στο υπάρχον billing.

**Κλειδωμένες αποφάσεις (από το Q&A):**

| Θέμα | Απόφαση |
|---|---|
| Registrars v1 | `openprovider` + `grepp` (.gr), **routing ανά TLD** |
| Registrant | **ο πελάτης** (full contacts registrant/admin/tech/billing) |
| DNS | **NS delegation μόνο** v1 (zone hosting + email-forwarding = deferred, flags μένουν) |
| DNSSEC | **ΝΑΙ** (DS records, delegation-level) |
| Operations | **πλήρης κύκλος** incl. transfers in **+** out (async state machine) |
| Currency | **EUR** = settlement/invoice/myDATA· USD = display-only |
| Money timing | register = **post-payment**· renew = **on-issue** |
| Import | **και τα δύο** — WHMCS `tbldomains` (linkage) + registrar sync (verify) |
| Transfer-out | **operator-gated** v1· portal self-service αργότερα |
| Contacts source | **modular** — `domain_contacts` ξεχωριστή οντότητα, pre-fill από Customer με ρητή επιβεβαίωση |

**Per-tenant opt-in:** `companies.enable_domain_management` (boolean, super_admin Toggle στο
`CompanyForm` όπως `ai_assistant_enabled`). Μόνο η MyIP το ενεργοποιεί → οι άλλοι tenants δεν
βλέπουν καθόλου το «Domains» (nav gating, §8).

**Non-goal v1:** DNS zone/record hosting, email forwarding, customer portal (Πυλώνας D), CentralNic,
premium domains (OFF και στη WHMCS), addon pricing (ID protection κ.λπ. ως χρεώσιμα add-ons),
pricing slabs ανά client group (καλύπτεται από το υπάρχον per-customer discount).

---

## 2. Registrars & routing

**Δύο adapters v1**, ο καθένας υλοποιεί το ίδιο `DomainRegistrar` contract (§4):
- **`openprovider`** — gTLDs + ccTLDs (.com/.net/.org/.eu/.io/.de/…). REST API, price-sync ✅.
- **`grepp`** — ΟΛΑ τα `.gr` (`.gr/.com.gr/.net.gr/.org.gr/.edu.gr/.gov.gr`), **direct EPP** στο
  μητρώο (FORTH). Το «Forth» του ιδιοκτήτη = το grEPP module που ήδη τρέχει στη WHMCS του (άρα
  **EETT-accredited με FORTH EPP** — static-IP whitelist + OT&E). Manual pricing (χωρίς sync).

**Routing ανά TLD:** το `domain_tlds.registrar_connection_id` ορίζει ποιός registrar χειρίζεται
κάθε TLD (= το «Auto Registration» dropdown της WHMCS οθόνης). Το ίδιο domain πάντα μέσω του TLD
του → ο registrar προκύπτει ντετερμινιστικά.

**CentralNic = σε έξοδο, ΟΧΙ «έφυγε».** Η μετάβαση `[CNIC→OPENPROVIDER]` γίνεται **στην ανανέωση**
(custom hooks της WHMCS)· τον 2026-09 **226 active** είναι ακόμα στο CNIC (`whmcs-baseline.md` §1/§7).
Το registrar-first import από Openprovider δεν τα βλέπει → εκκρεμεί απόφαση (manual/CSV ως
«χωρίς API», ή read-only CNIC adapter — το capability model το επιτρέπει: one class + one config line).

**Capabilities (mirror του `App\Support\Billing\SourceCapabilities`):** ένα readonly value object
`DomainRegistrarCapabilities` με flags — `supportsPricingSync`, `supportsPrivacy`, `supportsDnssec`,
`supportsTransferLock`, `supportsTransfer`, `supportsGlueHosts` — ώστε το UI/import να προσαρμόζεται
**χωρίς `if ($registrar === 'openprovider')` αλυσίδες**. π.χ. Openprovider `supportsPricingSync=true`,
grEPP `false`· .gr `supportsPrivacy=false`, `supportsTransferLock=false` (§5).

---

## 3. Data model

Όλοι οι πίνακες tenant-owned, house conventions: surrogate `id`, `company_id` FK
`constrained()->cascadeOnDelete()`, `legacy_id` nullable `unsignedInteger` unique-per-tenant,
`timestamps`, `softDeletes` (στα user-facing), tenant-first composite indexes, `decimal(14,2)`
money, **χωρίs** per-migration charset (connection default). Πρότυπο: `customers` migration.

### 3.1 `domain_registrar_connections` — per-tenant registrar λογαριασμοί
Mirror του `billing_connections` (superset· μπορεί να μαζευτεί σε στήλη αργότερα).

| col | τύπος | σημείωση |
|---|---|---|
| `company_id` | FK | |
| `registrar` | string(40) | key: `openprovider` \| `grepp` \| `manual` — το `manual` = ο Null adapter ως πλήρης «offline» registrar (χειροκίνητη διαχείριση χωρίς API· το A1 build-first target) |
| `label` | string | «Openprovider MyIP», «FORTH EPP» |
| `is_active` | bool | |
| `mode` | string(20) | `sandbox` \| `production` \| `off` |
| `config` | **encrypted** json | `encrypted:array` cast (το idiom του `PaymentGatewayConnection` — νέος πίνακας, δεν χρειάζεται το legacy-tolerant `MaybeEncrypted`) — creds (username/password ή EPP host/user/pass/static-IP) |
| timestamps, softDeletes | | |

**Χωρίς** unique σε `(company_id, registrar)` — ο tenant μπορεί να έχει 2 λογαριασμούς ίδιου
registrar. Επεξεργασία creds = **super_admin only** (όπως τα einvoice provider creds).

### 3.2 `domain_tlds` — κατάλογος TLD + κανόνες (γειωμένο στην WHMCS οθόνη)

| col | σημείωση |
|---|---|
| `company_id`, `tld`(30) | `gr`,`com`,`eu`,`ελ`… · unique `(company_id, tld)` |
| `registrar_connection_id` | FK — **ποιός registrar** (= «Auto Registration») |
| `min_years`, `max_years` | .gr → `min_years=2` (§5) |
| `min_chars`, `max_chars` | generic min 3 |
| `allow_idn`, `allow_transfer` | bool |
| `grace_period_days`, `grace_fee`(14,2) | .gr: 15 / 0 |
| `redemption_period_days`, `redemption_fee`(14,2) | .com: 30 / €96.50 |
| `dns_management`, `email_forwarding`, `id_protection`, `epp_code` | capability flags (per-TLD) |
| `is_active` | |

_(.gr row από το screenshot: DNS/Email/ID = off, EPP = on, grace 15 / redemption 0 → επιβεβαιώνει.)_

### 3.3 `domain_tld_prices` — τιμή ανά TLD × operation × έτος × currency
Η ασυμμετρία που το `ProductBillingPrice` δεν εκφράζει (register≠renew≠transfer≠restore).

| col | σημείωση |
|---|---|
| `domain_tld_id` | FK |
| `operation` | `register` \| `transfer` \| `renewal` \| `restore` \| `redemption` |
| `years` | 1–10 — **ρητή** τιμή ανά έτος (όχι πάντα 1yr×N· proof: .eu 2yr=26.50 ≠ 13.50×2) |
| `currency`(3) | default `EUR` |
| `cost`(14,2) | synced από registrar (→ EUR) |
| `price`(14,2) | τιμή πώλησης (derived/editable) |
| `is_enabled` | `transfer` συνήθως 1-year μόνο· `-1`/disabled ανά term |

Unique `(domain_tld_id, operation, years, currency)`.

### 3.4 `domains` — ο πυρήνας

| col | σημείωση |
|---|---|
| `company_id`, `legacy_id` | `legacy_id` = WHMCS `tbldomains.id` (import upsert) |
| `customer_id` | FK **nullable** `restrictOnDelete` — `null` = **αδέσποτο** (imported/unmatched, βλ. σημείωση κάτω) |
| `service_contract_id` | FK `nullOnDelete` — **το billing clock** |
| `domain_tld_id`, `registrar_connection_id` | FK (routing· `domains` value wins, TLD = default hint) |
| `sld`(190), `tld`(30), `fqdn`(190) | unique `(company_id, fqdn)`· `fqdn` = derived, authoritative = `sld`+`tld` |
| `status`(30) | `active\|pending_register\|pending_transfer\|expired\|grace\|redemption\|transferred_away\|cancelled\|deleted` — το `transferred_away` γράφεται όταν το `syncDomain` γυρίσει transferredAway (φύγαμε registrar· κρατάμε το record, σταματά billing/sync) |
| `registered_at`, `expires_at` (date) | **`expires_at` = REGISTRAR truth** (sync clock) |
| `transferred_at` (date, nullable) | πότε μπήκε σε εμάς με transfer-in — γράφεται όταν το `syncTransfer` γυρίσει `completed`· null = registered/imported απευθείας |
| `auto_renew`, `transfer_lock`, `whois_privacy`, `dnssec_enabled`, `consent_publish` | bool |
| `registrar_domain_id` | **Openprovider numeric `{id}`** (όχι το fqdn· resolve via `?full_name=`) |
| `idn_script` | για .ελ / IDN |
| `last_synced_at`, `sync_error` | |
| overrides (nullable) | `grace_days_override`, `redemption_days_override`, `fee_override`, `price_override` → fallback στο TLD |
| `module_meta` json, timestamps, softDeletes | |

Traits: `BelongsToCompany, HasFactory, SoftDeletes, TracksActivity, HasAttachments, HasInternalNotes`
+ `company()` BelongsTo + `customer()`, `serviceContract()`, `tld()`, `registrarConnection()`,
`nameservers()`, `hosts()`, `contacts()`, `reminders()`, `logs()`.

**Δύο ορθογώνια clocks** (όπως `local_status` × `mydata_state`): `domains.expires_at` (registrar
truth, pull από sync) vs `ServiceContract.next_due_date` (billing). Reconciled, ποτέ conflated.

**Αδέσποτα (un-assigned) domains:** `customer_id = null` είναι νόμιμη κατάσταση — π.χ. import
από registrar sync ή WHMCS `tbldomains` που δεν έκανε match σε πελάτη. Αδέσποτο ⇒ **χωρίς
`ServiceContract`** ⇒ ΔΕΝ μπαίνει σε renewal billing / auto-stage (μόνο ορατό στο sync + στη
λίστα). Στη λίστα φέρει badge/filter **«Χωρίς πελάτη»** και λύνεται με το action
**«Ανάθεση σε πελάτη»** (§8.2), που δένει customer + δημιουργεί/συνδέει το ServiceContract
ώστε να ξεκινήσει το billing clock.

### 3.5 `domain_nameservers` — delegation NS
`company_id, domain_id`(cascade)`, host`(190)`, sort_order`. Τα nameservers που **χρησιμοποιεί** το
domain (ns1.myip.gr…).

### 3.6 `domain_hosts` — child/glue hosts («δικά μου nameservers»)
`company_id, domain_id`(cascade)`, host`(190)`, ipv4, ipv6, sort_order`. Glue records
**registered ΣΤΟ registry** για `ns1.thisdomain.gr → IP` (≠ delegation· WHMCS
`RegisterNameserver/ModifyNameserver/DeleteNameserver`).

### 3.7 `domain_contacts` — registrant/admin/tech/billing (MODULAR)
Ξεχωριστή first-class οντότητα που **κατέχει το domain** (όχι το CustomerContact).
`company_id, domain_id`(cascade)`, type`(20 = `registrant|admin|tech|billing`)`, name, org, email,
phone, address1/2, city, postcode, country, registrar_contact_handle`. Το `registrar_contact_handle`
κρατά το **Openprovider handle** (`AB123456-XX`) + κρατάμε δικό μας αντίγραφο των πεδίων.
**Pre-fill από τον ekdosi Customer με ρητή επιβεβαίωση** ανά καταχώρηση — ο Customer είναι
convenience default, NOT hardwired· τα contacts μπορούν να αποκλίνουν.

### 3.8 `domain_reminders` — sent-log υπενθυμίσεων λήξης
`company_id, domain_id, reminder`(π.χ. `15_days`/`10_days`/`5_days`)`, to_email, sent_at`. Reuse του
auto-email infra. (WHMCS MyIP: **60/30/15/10/5** ημέρες, 34.296 αποστολές — `whmcs-baseline.md` §6.)
**⚠ ΔΕΝ έχει χτιστεί** — cutover blocker.

### 3.9 `domain_registrar_logs` — API history («Bridge logs»-style)
`company_id, domain_id`(nullable)`, registrar, operation, request`(json/text)`, response`(json/text)`,
`status, created_at`. Πλήρες request/response ανά registrar κλήση — audit + debugging. Ίδιο pattern
με `mod_ekdosi_bridge_log` + `mydata_marks`. First-class, όχι optional.

---

## 4. Το `DomainRegistrar` contract (modular seam)

Copy του `app/Contracts/EInvoiceProviderTransport.php` idiom: contract + config registry +
credentials value-object + factory + Null.

### 4.1 `App\Contracts\DomainRegistrar`
Ο σκελετός γεννιέται 1:1 από το **επίσημο WHMCS registrar function index** (κάθε method
αντιστοιχεί σε Filament action). **A0 note (υλοποιημένο):** το interface ξεκίνησε ΣΛΙΜ —
`key/capabilities/ping/checkAvailability` — και επεκτείνεται ανά slice (A2 read-only, A3 write),
ώστε ο Null adapter να μην κουβαλά νεκρές υπογραφές που type-hint-άρουν το ανύπαρκτο-ακόμα
`Domain` model. Ο πλήρης κατάλογος από κάτω παραμένει το blueprint:

```
key(): string
capabilities(): DomainRegistrarCapabilities
ping(creds): bool

checkAvailability(fqdn, creds): AvailabilityResult
register(DomainRegisterRequest, creds): RegistrarResult
renew(domain, years, creds): RegistrarResult
transfer(DomainTransferRequest, creds): RegistrarResult   // create
transferApprove(domain, creds): RegistrarResult           // outgoing/incoming approve
requestDelete(domain, creds): RegistrarResult

getNameservers(domain, creds) / setNameservers(domain, ns[], creds)     // delegation
registerHost/modifyHost/deleteHost(domain, host, creds)                 // child/glue
getContacts(domain, creds) / setContacts(domain, contacts, creds)       // → handles
getEppCode(domain, creds)
getLock(domain, creds) / setLock(domain, bool, creds)
toggleIdProtect(domain, bool, creds)
getDnssec(domain, creds) / setDnssec(domain, keys[], creds)   // OUR addition (WHMCS has none)
getDns(domain, creds) / setDns(domain, records[], creds)      // DEFERRED addon (flag only v1)

syncDomain(domain, creds): DomainSyncResult      // {active, expired, cancelled, transferredAway, expirydate}
syncTransfer(domain, creds): TransferSyncResult  // completed(+expirydate) | failed(+reason) | pending
getTldPricing(creds): TldPricingResult[]         // ImportItem-shape → pricing-sync
```

Value objects (immutable): `AvailabilityResult`, `RegistrarResult`, `DomainSyncResult`,
`TransferSyncResult`, `TldPricingResult` — ενιαία σε όλους τους registrars.

### 4.2 Registry + creds + factory
- `App\Services\Domains\DomainRegistrarRegistry` — config-driven `config('ekdosi.domains.registrars')`
  (νέο sibling των `billing.sources`/`provisioning.modules`/`einvoice.providers`). Idiom
  **«Null που ρίχνει»** (όπως `ProviderTransportRegistry`) — register/renew = money/state path.
- `App\Support\Domains\DomainRegistrarCredentials` — **αυτούσια η λογική του `ProviderCredentials`**:
  free-form encrypted key/value bag + sandbox flag, από το `domain_registrar_connections.config`·
  νέος registrar ποτέ δεν θέλει schema change.
- `App\Services\Domains\DomainRegistrarFactory` — `for(DomainRegistrarConnection): DomainRegistrar`,
  typed `DomainRegistrarNotConfigured`.
- `App\Services\Domains\NullDomainRegistrar` — no-op/throws → καταχώρηση domains by hand χωρίς API (A1).

### 4.3 Openprovider adapter — endpoint mapping (από module source + swagger)
- **Auth:** `POST /v1beta/auth/login` (username + **plaintext** password/HTTPS — το MD5 hash/token
  είναι legacy XML-RPC, ΟΧΙ REST) → bearer, **~24h TTL** (cache + re-auth σε 401). Prod
  `https://api.openprovider.eu`.
- **Sandbox:** `http://api.sandbox.openprovider.nl:8480/v1beta/` (HTTP, :8480, `.nl`) + **ξεχωριστό**
  sandbox account. Το παλιό `*.cte.openprovider.eu` αποσύρθηκε. Επιλογή = swap base URL + creds →
  κουμπώνει στο `DomainRegistrarCredentials` sandbox flag.

| Contract method | Openprovider API |
|---|---|
| `ping` | `POST /v1beta/auth/login` |
| `checkAvailability` | `POST /v1beta/domains/check` |
| `register` | ensure handles (`POST /v1beta/customers`) → `POST /v1beta/domains` |
| `renew` | `POST /v1beta/domains/{id}/renew` |
| `transfer` | `POST /v1beta/domains/transfer` (+ `…/{id}/transfer/approve`, `…/send-foa1`) |
| `syncDomain` | `GET /v1beta/domains/{id}` → `expiration_date`, `status`, `is_locked`, `autorenew` |
| `setNameservers` / lock / privacy / dnssec / contacts / autorenew | **`PUT /v1beta/domains/{id}`** (ένα call τα κάνει όλα: `name_servers`, `is_locked`, `is_private_whois_enabled`, `is_dnssec_enabled`+`dnssec_keys[]`, `owner/admin/tech/billing_handle`) |
| `getEppCode` | `GET /v1beta/domains/{id}/authcode` (reset: `POST …/authcode/reset`) |
| registrant change | `POST /v1beta/domains/trade` (ICANN IRTP) |
| restore/redemption | `POST /v1beta/domains/{id}/restore` |
| glue hosts | `GET|POST /v1beta/dns/nameservers`, `PUT|DELETE …/{name}` |
| `getTldPricing` | `GET /v1beta/domains/prices`, `GET /v1beta/tlds/{name}` (requirements/additional-data) |

**3 gaps για σχεδίαση:** (α) **κανένα arbitrary WHOIS** endpoint — μόνο availability + το δικό-σου
domain → πραγματικό WHOIS = ξεχωριστή port-43/RDAP πηγή (`App\Services\Domains\WhoisLookup`).
(β) **Αλλαγή registrant σε gTLD = ICANN IRTP/`trade`** (FOA email + 60-day lock), ΟΧΙ σιωπηλό
`setContacts` — το μοντελοποιούμε ρητά. (γ) **Contacts = reusable handles**, όχι inline: `setContacts`
= «ensure-or-create handle πρώτα → reference `*_handle`».
**Free upside vs WHMCS:** trade, restore, retry-last-op (`…/last-operation/restart`), approve-transfer,
resend-FOA, NS/DNS templates, autorenew flag.

**Υλοποίηση transport (απόφαση 2026-09-07):** δικός μας thin HTTP client (Laravel `Http`/Guzzle)
πάνω στα ~15 endpoints του πίνακα — ΟΧΙ composer dependency στο επίσημο `openprovider/rest-client-php`:
είναι **beta** («we may still make breaking changes», branch `dev-v1beta`) και **χωρίς δηλωμένη
άδεια** (ούτε LICENSE file ούτε `license` στο composer.json — verify ξανά πριν τυχόν αναθεώρηση).
Το χρησιμοποιούμε ΜΟΝΟ ως reference για request/response shapes, όπως και το WHMCS module
(επίσης χωρίς ορατή άδεια → διαβάζουμε semantics, δεν αντιγράφουμε κώδικα). Τα issues του module
(~28 ανοιχτά) = δωρεάν κατάλογος από real-world gotchas — κοίτα τα πριν το A3.

### 4.4 grEPP adapter (.gr direct EPP)
**Πλήρης οδηγός υλοποίησης: `docs/domains/grepp/README.md`** (+ τα επίσημα v4.3 XML examples/XSDs,
το reference `EppClient.java` του Μητρώου και το TLS bundle, δίπλα του). Κρίσιμα σημεία:
- **ΔΕΝ είναι RFC 5734 EPP over TCP/700** — είναι **XML web service over HTTPS**: `POST /epp/proxy`
  με `Content-Type: text/xml;charset=UTF-8` + session cookie `JSESSIONID`. Καμία έτοιμη
  socket-based EPP library δεν δουλεύει ως έχει. Ένα session = σειριακά commands (mutex).
- **Endpoints ΓΝΩΣΤΑ:** UAT `https://uat-regepp.ics.forth.gr:700/epp/proxy`, prod
  `https://regepp.ics.forth.gr:700/epp/proxy`· + plain WHOIS/check HTTP βοηθητικά (`grwhois…:800`).
  IP whitelisting (δήλωσε prod+staging+dev IPs στο Μητρώο).
- Extensions: **`extdomain-1.3`** (ΟΧΙ το 1.2 του παλιού `emavro/eppgr`) + `account-1.1` +
  `dacor-1.0` + `ext-gr-rls-1.0`· **χωρίς `<svcExtension>` στο login**. DNSSEC = standard `secDNS-1.1`.
- **Δεν υπάρχει `<poll>`** (2101) → κάθε async κατάσταση (εγκρίσεις ΕΕΤΤ, DS validation, transfers)
  θέλει **polling με `domain:info`** — τα jobs σχεδιάζονται από την αρχή, όχι patch.
- `create` γυρίζει **1001** (επιτυχία-με-εκκρεμότητα, ΟΧΙ σφάλμα) + **`protocol` number** (κράτα το —
  χρειάζεται για `recallApplication`). Εκχώρηση: ενεργοποίηση ~3h, οριστική στις 5 ημέρες·
  .gov.gr/γεωγραφικά → έγκριση ΕΕΤΤ έως 20 ημέρες (`pendingRegulatorApproval`).
- **DACoR** = reset auth code → token στο email του δικαιούχου (λύνει το «έχασα τον κωδικό»).
- **grRLS (Registry Lock)** = συνδρομητική/χρεώσιμη υπηρεσία του Μητρώου (period/exDate/autoRenew) —
  εμπορική ευκαιρία, μοντελοποιείται ως προϊόν, όχι flag.
- **`account:info`** δίνει balance (μπορεί και αρνητικό) + `suspendedOn` → monitoring job + alert
  (αναστολή λογαριασμού = σταματούν ΟΛΑ τα registrations).
- Regulator statuses (ΕΕΤΤ, υπερισχύουν) + homograph **bundles** (.ελ `dname`, χρεώσιμο) →
  χαρτογράφηση σε ανθρώπινα μηνύματα στο UI.
`capabilities`: `supportsPricingSync=false`, `supportsPrivacy=false`, `supportsTransferLock=false`
(το grRLS είναι άλλο πράγμα από registrar transfer-lock). Τα .gr rules (§5) ζουν στο **validation
layer**, όχι στον adapter. Library: πιθανότερα `metaregistrar/php-epp-client` (έχει HTTPS transport)
ή δικός μας thin client — βλ. grepp/README §11.

---

## 5. Κανόνες .gr / .ελ (validation layer — ισχύουν για OP *και* grEPP)

Πηγές: EETT (regulator) + ICS-FORTH (registry operator).
- **2ετία ΥΠΟΧΡΕΩΤΙΚΗ** (1 έτος ΔΕΝ επιτρέπεται) → `domain_tlds.min_years=2`, πολλαπλάσια 2ετίας.
- **Καμία WHOIS privacy** → `id_protection` off/disabled για .gr (ταιριάζει με το screenshot).
- **Κανένα transfer lock** → κρύψε `setLock` για .gr.
- **Το auth code το βγάζει το REGISTRY και το στέλνει email στον REGISTRANT** — ο gaining registrar
  δεν το τραβά· το transfer-in UX λέει στον πελάτη «πάρε τον κωδικό από το email του μητρώου».
  Χαμένος κωδικός → **DACoR** (issue-token μέσω domain:update· token στο email δικαιούχου).
  Ληγμένο όνομα άλλου καταχωρητή μεταφέρεται και **μέσω renew + auth code** (extdomain:renew).
- **Έλεγχος .gr ↔ .ελ homograph** (`.ελ` = `xn--qxam`, ξεχωριστό ccTLD· cross-check για confusion).
- Label rules: 1(2)–63 chars, alnum/hyphen, όχι leading/trailing/consecutive hyphens.

**Build-time unknowns (verify):** ακριβή μηχανική/τέλη «trade» (registrant change) στο .gr· αν το
public .gr WHOIS auto-redacts natural-person data υπό GDPR (το «no privacy service» είναι βέβαιο).

---

## 6. Lifecycle

### 6.1 Money timing (per operation)
- **`register` = post-payment** (νέο domain, ρίσκο): invoice → πληρωμή → `$registrar->register()`.
- **`renew` = στην ΟΡΙΣΤΙΚΟΠΟΙΗΣΗ — που κανονικά έρχεται ΜΕΤΑ την πληρωμή — ΚΛΕΙΔΩΜΕΝΟ** (ιδιοκτήτης 2026-09-23).
  Η ανανέωση στον registrar δεν έχει undo (απλήρωτη → η MyIP χρεώνεται, ο πελάτης κρατά το domain δώρο), γι' αυτό
  η συνήθης ροή, ίδια με τις υπηρεσίες: staging → **πρόχειρο / προτιμολόγιο** (`offered_at`: όχι απαίτηση, χωρίς
  ΑΑ/myDATA) → πληρωμή → οριστικοποίηση (myDATA) → `InvoiceObserver` domain post-issue hook → `$registrar->renew()`.
  **Εξαίρεση κατά βούληση:** για έμπιστο πελάτη ο operator οριστικοποιεί νωρίτερα / επί πιστώσει → ανανέωση τώρα,
  πληρωμή αργότερα. Άρα **όχι** guard «μόνο εξοφλημένο»: η οριστικοποίηση είναι η απόφαση.
  **Αναλλοίωτο (το WHMCS bug — «δήλωσα την πληρωμή αργότερα και ανανεώθηκε 2η φορά»):** η καταχώριση πληρωμής
  ΠΟΤΕ δεν ανανεώνει. Σήμερα: (1) καμία ανανέωση δεν ξεκινά από πληρωμή — μόνο η οριστικοποίηση και το κουμπί
  «Ανανέωση στον registrar»· (2) μία ανανέωση ανά παραστατικό (`renewForInvoice`: ok/adopted log ⇒ τέλος)·
  (3) §6.6 adopt-on-already-renewed. Αν ποτέ μπει «πληρωμή στην πύλη → αυτόματη οριστικοποίηση», περνά από το
  ΙΔΙΟ `DomainRenewalService` (once-per-invoice + adopt), ποτέ ξεχωριστό renew. (Test που να κλειδώνει ότι η
  πληρωμή μετά την έκδοση δεν ανανεώνει → με το επόμενο PR των domains.)
- **Ποτέ auto-file** στην ΑΑΔΕ — τα renewals είναι πρόχειρα (ίδια πειθαρχία με WHMCS inbox / SC).

### 6.2 Renewals (reuse `ServiceContract` 100%) — **1:1 ΚΛΕΙΔΩΜΕΝΟ** (ιδιοκτήτης 2026-09-07)
Κάθε domain δένει στο ΔΙΚΟ του ServiceContract (1:1)· η τιμή του SC από το
`domain_tld_prices` (operation=renewal) του TLD του (ποτέ term < `min_years`), με το per-domain
`price_override` να νικά. `next_due_date` (= λήξη registrar· ανάθεση χωρίς λήξη ΑΠΑΓΟΡΕΥΕΤΑΙ) →
`StageServiceRenewal` κόβει **πρόχειρο** («ΠΡΟΣΧ» semantics: ΧΩΡΙΣ ΑΑ — gapless-at-send, αόρατο
στην πύλη, μηδενικό ίχνος αν ακυρωθεί). **`auto_renew` ΚΛΕΙΔΩΜΕΝΟ (επιλογή β, ιδιοκτήτης
2026-09-07):** on → auto-stage draft (η ανάθεση το ανάβει — assignment = intent to bill)· **off →
ΤΙΠΟΤΑ — ούτε προσχέδιο ούτε γκρίνια** (σιωπηλό skip στο sweep, μία συνολική info γραμμή), μόνο το
worklist «Λήγουν σύντομα»· το domain απλά λήγει. **«Προσχέδιο ανανέωσης τώρα»**
(`StageRenewalNowAction`, το «Invoice Selected Items» της WHMCS — κουμπί σε Υπηρεσίες + Domains
λίστα/View): on-demand early staging για τον πελάτη που θέλει να ανανεώσει νωρίς· η ρητή πρόθεση
ΠΑΡΑΚΑΜΠΤΕΙ το auto_renew=off, ΠΟΤΕ το dead-set (νεκρό όνομα = αχρέωτο από κάθε μονοπάτι)· ο
cursor προχωρά στην ΕΚΔΟΣΗ, όχι στο staging (open-draft guard κόβει τα διπλά).
First-Payment vs Recurring = SC `setup_fee` + `amount`.

### 6.3 Transfer async state machine (drive από `syncTransfer`)
```
[create transfer] → status: pending_transfer
   nightly domains:sync-transfer → syncTransfer():
     completed(+expirydate) → status: active,  expires_at := expirydate
     failed(+reason)        → status: <prev>,  sync_error := reason,  operator alert
     empty (still pending)  → keep polling (+ FOA resend αν χρειάζεται)
```
Incoming: auth-code + `POST /domains/transfer`. Outgoing (φυγή): **operator-gated** v1 —
«Get EPP Code» + unlock μόνο από operator (anti-abuse, έλεγχος οφειλών)· portal self-service αργότερα.

### 6.4 Sync (nightly `domains:sync`)
Command `domains:sync --tenant=SLUG`, gated `EKDOSI_SCHEDULE_DOMAIN_SYNC`, φιλτράρει
`Company::where('enable_domain_management', true)`, per-domain `$registrar->syncDomain()` →
update `expires_at`/`status`/NS, catch+log στο `sync_error` + `domain_registrar_logs`. Bounded
`withoutOverlapping(30)`. Καταχώρηση σε **3 σημεία** (§8 του PLAN.md).

### 6.5 Grace / redemption (configurable ανά customer/TLD)
Transitions `active → expired(grace) → redemption → deleted`, οδηγούμενες από `expires_at` + sync.
Χρέωση μέσω `domain_tld_prices` (`restore`/`redemption` flat). **Configurable:** άλλοι customers/TLDs
auto-stage restore invoice, άλλοι operator-decides (κρατά τη «ποτέ auto-file» πειθαρχία όπου θες).

### 6.6 Idempotency (money-safety — lands ΜΑΖΙ με A3)
register/renew/transfer = εξωτερικά money+state. **adopt-on-retry** (ίδιο μοτίβο με
`InvoSignTransport::status()` που υιοθετεί MARK αντί να ξαναφάιλάρει): idempotency key +
`syncDomain`/`GET /domains?full_name=` πριν το ξανακαλέσεις, για το «ο registrar χρέωσε, timeout
πριν το καταγράψει το ekdosi» → διπλοχρέωση/διπλο-renew. Ο reconciler (A5) έρχεται ΜΑΖΙ με το A3.

**Adopt-on-already-renewed — ΔΕΣΜΕΥΤΙΚΟ, ✅ ΥΛΟΠΟΙΗΜΕΝΟ στο A3a (war story ιδιοκτήτη, WHMCS
2026-09-07):** το κλασικό WHMCS bug που «θεραπεύεται» με χειροκίνητο relid στη MySQL: operator
πατά Renew τώρα («ανανέωσέ το και σε πληρώνω τέλος του μήνα»), ο πελάτης πληρώνει αργότερα, το
mark-paid του invoice ξανα-πυροδοτεί το registrar renew → **διπλή ανανέωση/διπλό κόστος**.
Υλοποίηση (`DomainRenewalService` — ο ΜΟΝΟΣ δρόμος προς `DomainRegistrar::renew()`):
- Το on-issue hook (`InvoiceObserver`, ΠΡΙΝ το cursor advance) υπολογίζει την περίοδο του
  παραστατικού από το **SC cursor** (`next_due_date` + έτη κύκλου — ΟΧΙ από την τρέχουσα λήξη
  του domain: αν ο operator έχει ήδη πατήσει «Ανανέωση», η τρέχουσα λήξη είναι ήδη η νέα και θα
  στόχευε μία περίοδο παραπέρα = ξανά το bug). Μετά **sync-first**: αν η λήξη του registrar ήδη
  καλύπτει την περίοδο → **ΥΙΟΘΕΤΕΙ** (log `adopted`, ΚΑΜΙΑ κλήση renew)· αλλιώς renew.
- **Το κουμπί «Ανανέωση στον registrar» ΔΕΝ προωθεί το billing cursor** (διόρθωση της αρχικής
  διατύπωσης εδώ — αν προωθούσε, η μετέπειτα χρέωση του πελάτη ΔΕΝ θα γινόταν ποτέ): το cursor
  προχωρά ΜΟΝΟ στην έκδοση. Η ροή «ανανέωσε τώρα, πλήρωσε μετά»: κουμπί → registrar renew →
  αργότερα staging+έκδοση → ο πελάτης χρεώνεται κανονικά → το on-issue hook ΥΙΟΘΕΤΕΙ (μηδέν
  δεύτερο renew). Ο μόνος τρόπος διπλής ανανέωσης = να τη ζητήσεις ρητά δύο φορές από το κουμπί
  (το modal δείχνει την τρέχουσα λήξη).
- Αποτυχία renew στην έκδοση: η έκδοση ΣΤΕΚΕΙ (best-effort hook), log `failed` + καμπανάκι
  operators + το κουμπί είναι το retry path. Αποτυχία του προ-ελέγχου (sync) = ΔΕΝ γράφουμε
  τυφλά στον registrar — abort. Κάθε απόπειρα (ok/adopted/failed) → `domain_registrar_logs`.
Ο reconciler (A5) έρχεται ΜΑΖΙ με τα υπόλοιπα A3 slices.

---

## 7. Pricing (cost-sync + margin engine)

3-tier resolution: **TLD price → per-customer discount (υπάρχει) → per-domain override (nullable)**.

**Cost-sync (A2c-1, SHIPPED):** command `domains:sync-pricing [--tenant] [--tld]` (manual-run —
τα κόστη αλλάζουν σπάνια) → `DomainPricingSyncService` καλεί `getTldPricing()` για κάθε ενεργό
TLD δρομολογημένο σε usable σύνδεση με `supportsPricingSync`. Πειθαρχία γραφής (το ουσιώδες):
γράφεται **ΜΟΝΟ το `cost`** — sell `price`/`is_enabled` δεν κινούνται ποτέ από sync· γραμμή που
λείπει γεννιέται cost-only (**is_enabled=false, price=null** → αχρέωτη εκ κατασκευής, το assign
απαιτεί enabled+priced)· το κόστος προσγειώνεται στη γραμμή του **ελάχιστου term**
(`max(1, min_years)` — ο registrar κοστολογεί την ελάχιστη περίοδο, .gr = 2ετία). Από OP
διαβάζουμε το `reseller` block (τι χρεώνεται ο λογαριασμός ΜΑΣ, στο νόμισμά του — συνήθως EUR)·
operation που δεν κοστολογήθηκε απλώς λείπει, ποτέ 0.00. grEPP = manual pricing.

**Margin engine (ΔΕΝ χτίστηκε — συνειδητά):** τα πραγματικά sell prices είναι manual per-TLD
(§7.1 — το 20% default της WHMCS δεν το ακολουθεί κανένα υπάρχον TLD), οπότε ο operator βάζει
τιμή πώλησης με το χέρι δίπλα στο συγχρονισμένο cost. Αν ποτέ χρειαστεί: `margin_type`
(percentage/fixed) + `margin_value` + `round_to` → derived sell, per-TLD override.
Explicit per-year τιμές (1–10) στηρίζονται ήδη από το schema.

### 7.1 Γείωση σε πραγματικά WHMCS δεδομένα (screenshots MyIP, 2026-09)

Από τις live οθόνες «TLD Import & Pricing Sync», «Domain Pricing for .gr» και «Domains/TLDS»:

- **Routing επιβεβαιωμένο:** `.gr/.com.gr/.net.gr/.org.gr/.edu.gr/.gov.gr` → grEPP module·
  ~29 gTLDs/ccTLDs (`.com/.net/.org/.eu/.biz/.info/.name/.cc/.tv/.co.uk/.asia/.me/.de/.es/
  .nl/.io/.dev/.co/.uk/.vip/.studio/.online/.shop/.ai/.club/.it/.be/.ch/.at`) → Openprovider.
  **Lookup Provider = Openprovider** (το availability lookup της WHMCS).
- **Margin defaults σήμερα:** Percentage **20%**, No rounding, Sync redemption/grace fee = **No**.
  Τα πραγματικά sell prices όμως αποκλίνουν πολύ από το 20% (π.χ. .com cost 9.45 → sell 16.50 =
  74.6%· .eu 4.60 → 13.50 = 193%· .me 7.49 → 28.50 = 280%) → το 20% είναι απλώς default για νέα
  TLDs, τα υπάρχοντα είναι manual per-TLD → **το per-TLD override του engine είναι ο κανόνας,
  όχι η εξαίρεση**.
- **Automatic Registration = No** στη WHMCS → η MyIP ΗΔΗ δουλεύει operator-gated (δεν
  auto-register-άρει με την πληρωμή) — το money-timing μας (§6.1) είναι η υπάρχουσα πρακτική.
- **.gr pricing (πραγματικό):** EUR μόνο, ενεργά ΜΟΝΟ ζυγά έτη 2/4/6/8/10 = 19/38/57/76/95,
  renewal = ίδια τιμή με register, **transfer = 0.00** (το μητρώο δεν χρεώνει μεταφορά),
  redemption «—» → επιβεβαιώνει `min_years=2`/πολλαπλάσια 2ετίας/χωρίς redemption fee, και το
  WHMCS idiom «-1 = disabled term» → το δικό μας `is_enabled` ανά (operation, years).
- **Redemption fees (OP):** πραγματικά δεδομένα π.χ. .com €96.50, .eu €25.50, .me €112.50,
  .nl €107.24 — χοντρά ποσά, γι' αυτό το redemption pricing είναι first-class (§3.3).
- **ID Protection addon:** πωλείται €5.50/EUR στη WHMCS — το addon pricing (DNS mgmt / email
  fwd / ID protection ως χρεώσιμα add-ons) μένει **deferred μαζί με τα ίδια τα addons**.
- **Premium Domains = OFF** στη WHMCS → non-goal (§11).

---

## 8. Filament UI

### 8.1 Gating (per-tenant + permission) — **cluster, όχι trait**
Πρότυπο ο **Πυλώνας E**: `app/Filament/Clusters/SupportCluster.php` + `companies.support_enabled`
(η πειθαρχία του `docs/menu-ia.md`: «κάθε νέος πυλώνας = ΕΝΑ top nav entry, κρυφό αν δεν είναι
enabled»). Άρα: **`DomainsCluster`** με `canAccess()` = `Filament::getTenant()?->enable_domain_management
&& canAccessClusteredComponents()` — όλα τα Domains resources/pages γίνονται μέλη του cluster και
κληρονομούν το gate· κανένα trait ανά resource. Το toggle στο `CompanyForm` σε **δικό του Tab
«Domains»** (όπως το Tab «Υποστήριξη» με το `support_enabled`), super_admin-only.
_(Το αρχικό σχέδιο για shared trait `GatesOnDomainManagement` προϋπήρχε του cluster pattern —
ξεπερασμένο.)_

### 8.2 `DomainResource` + η πλούσια per-domain View (RICHER από WHMCS)
Ο ιδιοκτήτης: η WHMCS per-domain οθόνη είναι «φτωχή» (NS + dates + buttons). Η δική μας View
προσθέτει inline:
- **Contacts PARSING** (η βασική ένσταση): registrant/admin/tech/billing **parsed + editable
  inline** (`DomainContactsRelationManager`/structured section από `getContacts`), όχι bare button.
- Identity/dates: registrar, status, registered/expiry/next-due· **NS1-5 + «reset to default»** +
  glue hosts· toggles **Registrar Lock / DNSSEC / ID-Protection** (+ DNS-Management/Email-Fwd
  deferred)· **IDN script**· **«Consent to Publish»** (GDPR redaction, default redacted).
- **Registrar command actions** (1:1 με το contract): Έλεγχος διαθεσιμότητας · Καταχώρηση · Μεταφορά ·
  Ανανέωση · Modify Contacts · Get EPP Code · Request Delete · ID Protection on/off · Auto-renew Sync · Sync.
- **Αδέσποτα + «Ανάθεση σε πελάτη»**: filter/badge «Χωρίς πελάτη» στη λίστα (default tab/ένδειξη
  ώστε τα imported-unmatched να μη χάνονται)· action **«Ανάθεση σε πελάτη»** με customer search
  (όνομα/ΑΦΜ/email — ίδιο UX με τη «Μεταφορά ιδιοκτησίας» §9, απλώς από null → πελάτη), που
  δένει και το `ServiceContract` για να ξεκινήσει το renewal billing. Ημερομηνίες στη View:
  registered / **transferred** (`transferred_at`) / expiry / next-due.
- **Καρτέλα πελάτη — tab «Domains»**: `DomainsRelationManager` στο `CustomerResource` (ίδιο
  pattern με invoices/contracts RMs) — ο operator βλέπει ανά πελάτη τα assigned domains του με
  status/expiry, με link στην πλήρη View. Gated με το ίδιο flag (RM `canViewForRecord()` ελέγχει
  `enable_domain_management` — ο tenant χωρίς domain management δεν βλέπει καν το tab· τα RMs δεν
  είναι cluster members, θέλουν δικό τους check).
- **Reminder history** tab (`domain_reminders`) · **API history** tab (`domain_registrar_logs`,
  «Bridge logs»-style) · shared `ActivityLog`/`Attachments`/`InternalNotes` RMs.
- Filament-5 idioms: `Schema`/`configure`, `recordActions`/`toolbarActions`, `extends
  BaseListRecords`, strip `SoftDeletingScope` στο `getEloquentQuery`.

**Lookup resources:** `DomainTldResource` (+ related `DomainTldPrice`, με το «Pricing/margin» panel) =
company_admin/operator· `DomainRegistrarConnection` creds = **super_admin only**. Widget «Domains
που λήγουν» (mirror `UpcomingRenewalsTable`).

### 8.3 Permissions (Shield)
`company_admin` κληρονομεί αυτόματα τα `Domain*` perms (default-allow, `ADMIN_FORBIDDEN_RESOURCES`
— πλην `DomainRegistrarConnection` που είναι ρητά forbidden/super_admin). `operator` θέλει ρητή
εγγραφή στο `OPERATOR_PERMISSION_MAP` — **απόφαση ιδιοκτήτη 2026-09-07: Create/Update ΝΑΙ**
(`'Domain' => ['ViewAny','View','Create','Update']`, ίδια για `DomainTld` — όχι Delete).
Μετά deploy: `shield:generate` + re-provision.

---

## 9. ekdosi-native features (πέρα από WHMCS parity)

- **«Μεταφορά ιδιοκτησίας»** — action στο `Domain` που αλλάζει `domains.customer_id` (+ του linked
  `ServiceContract.customer_id`, ώστε οι μελλοντικές ανανεώσεις να χρεώνουν τον νέο πελάτη), με search
  πελάτη (όνομα/ΑΦΜ/email). **Τα ιστορικά τιμολόγια μένουν** στον παλιό (νομικό record)· προαιρετικά
  προσφέρει και αλλαγή registrant (= registrar `trade`/IRTP, όχι αυτόματα). Audit-logged.
  **≠** «Μεταφορά μητρώου/registrar» (EPP σε άλλον registrar) — ξεχωριστά ονόματα στο UI.
  Sibling action: **«Ανάθεση σε πελάτη»** (§8.2) = η ίδια μηχανική από `customer_id=null`
  (αδέσποτο) → πελάτη, + δέσιμο ServiceContract.
- **Expiry reminders** — 15/10/5 ημέρες πριν, reuse του auto-email infra, sent-log στο `domain_reminders`.
- **API history** — `domain_registrar_logs` (request/response/status ανά κλήση), «Bridge logs» tab.
- **Import — REGISTRAR-FIRST bootstrap** (απόφαση ιδιοκτήτη 2026-09-07 — «να μην εξαρτιόμαστε
  από WHMCS db») — **A2c-2 SHIPPED**:
  - **Κύρια πηγή = ο registrar**: `domains:import-registrar [--tenant] [--connection]` →
    Openprovider `GET /v1beta/domains` (paginated, 100/σελίδα) + resolve των
    **registrant/admin/tech/billing handles** (`GET /v1beta/customers/{handle}`, cached ανά run —
    κοινά handles = ΜΙΑ κλήση· 404 = σιωπηλό «χωρίς aid», 5xx = warn+continue) → κάθε domain
    μπαίνει **αδέσποτο με γεμάτα `domain_contacts`**, και ο operator κάνει το assign ΧΕΙΡΟΚΙΝΗΤΑ
    από το worklist «Χωρίς πελάτη». Υλοποίηση: `DomainImportService` — υπάρχοντα rows παίρνουν
    ΜΟΝΟ registrar truth μέσω του ΙΔΙΟΥ `DomainSyncService::apply` με το nightly sync·
    auto_renew ΜΟΝΟ στο create (OP `autorenew` on/off, αλλιώς OFF)· επαφές ΜΟΝΟ σε αδέσποτα
    (μετά την ανάθεση = χώρος του operator, §3.7)· tombstones/διαγραμμένα TLD ποτέ δεν
    ανασταίνονται· TLD που λείπει auto-δημιουργείται δρομολογημένο στη σύνδεση του import·
    το domain καρφώνει `registrar_connection_id` στη σύνδεση που αποδεδειγμένα το έχει.
  - ⚠ **.gr caveat:** το EPP ΔΕΝ έχει list-my-domains command — η αρχική .gr λίστα έρχεται από
    export του grweb portal: `domains:import-csv <file> --tenant=SLUG` (auto-detect
    delimiter ,/;/TAB + στηλών από headers «domain/όνομα» & «λήξη/expiry», overrides
    `--domain-col`/`--expires-col`/`--no-header`, ημερομηνίες Y-m-d και d/m/Y κ.ά., BOM-safe).
    First-dot split κρατά τα com.gr/net.gr σωστά. TLD που λείπει → manual (η grEPP δρομολόγηση
    είναι A4)· ΔΕΝ πατά `last_synced_at` (ένα CSV δεν είναι το ρολόι του registrar).
    `[ΕΠΙΒΕΒΑΙΩΣΗ μορφής export στο πρώτο πραγματικό αρχείο — οι στήλες είναι ρυθμιζόμενες]`
  - **WHMCS = προαιρετικό βοήθημα**, όχι εξάρτηση: `GetClientsDomains` (πάνω στον υπάρχοντα
    `WhmcsClient` — «Plugin-API is THE path», ποτέ raw `tbldomains`) μπορεί να προτείνει linkage
    (userid → `customers.whmcs_client_id`) ως ΥΠΟΔΕΙΞΗ στο assign UI + να γεμίσει `legacy_id`.
    Το status/expiry ΔΕΝ έρχεται ποτέ από WHMCS — μόνο από registrar sync.
  - Ό,τι δεν γίνεται assign δεν μπλοκάρει τίποτα — μένει **αδέσποτο** (`customer_id=null`, §3.4),
    εκτός billing, ορατό στο worklist.
  - Manual entry (A1) = ο τρόπος να δουλέψει το σύστημα ΠΡΙΝ καν συνδεθεί registrar.
  - **Per-domain flow (το WHMCS idiom «γράφεις domain → φέρνει στοιχεία»):** δημιουργείς το
    row (ή υπάρχει ήδη) → «Συγχρονισμός από registrar» στο View τραβά λήξη/NS/status/id ΚΑΙ
    τις επαφές του (ίδιο pull, χωρίς δεύτερο fetch — `DomainSyncResult.contactHandles` →
    `DomainImportService::refreshContacts`). Επαφές ΜΟΝΟ σε αδέσποτα (μετά την ανάθεση =
    χώρος operator, §3.7). Τα κουμπιά Register/Transfer/Renew/EPP-code/Recall = A3/A4.

---

## 10. Phase gates (A0–A5 — σταματάς όπου θες)

| Φάση | Τι | Gate (done-when) |
|---|---|---|
| **A0** Θεμέλιο | `enable_domain_management` flag + nav-gating trait + `DomainRegistrar` contract/registry/creds/Null + `ekdosi.domains.registrars` + `domain_registrar_connections` (super_admin creds) | Ενεργοποιείς tenant → βλέπεις **κενή, gated** περιοχή «Domains» |
| **A1** Data model + manual CRUD | Όλοι οι πίνακες + `DomainResource` (rich View) + link σε `ServiceContract` + **import** (`tbldomains`) + αδέσποτα/«Ανάθεση σε πελάτη» + Customer tab «Domains» | Το υπάρχον portfolio φορτώνεται/καταχωρείται, expiry+renewals ορατά, **μηδέν registrar API** |
| **A2** Openprovider read-only | `checkAvailability` + WHOIS (`WhoisLookup`) + `syncDomain`/`syncTransfer` + `getTldPricing` (sandbox) | Nightly `domains:sync` + pricing-sync «ανάβουν»· καμία state-changing εγγραφή |
| **A3** Openprovider write ✅ (πλην A5-reconciler & DNSSEC keys) | register(post-pay) ✅ /renew(on-issue) ✅ /transfer(in+out, state machine) ✅ /NS ✅ /contacts(handles) ✅ /privacy ✅ /lock ✅ + **Get EPP/auth code** ✅ (το «Get EPP Code» της WHMCS — transfer-out aid) + renewal billing ✅ + redemption **restore** ✅ (sync-first adopt, χρέωση πελάτη χειροκίνητη v1) + **idempotency + adopt-on-already-renewed (§6.6 BINDING)** ✅ — όλα πάνω στο κοινό `GuardsRegistrarWrites` skeleton· DNSSEC key-management + glue → BACKLOG· reconciler → A5 | Πλήρης κύκλος end-to-end (sandbox→prod), operator-gated invoices |
| **A4** grEPP (.gr) | 2ος adapter (EPP), .gr validation rules (2ετία/no-privacy/no-lock/registry-auth-code/homograph) + **Recall** (ανάκληση διαγραφής εντός **5 ημερών** — δουλεύει στο .gr, το 'Recall (5 days)' κουμπί της WHMCS/grEPP· owner-confirmed χρήσιμο) + **auth code (πληροφοριακό)** για transfer-out | .gr/.ελ end-to-end· το abstraction αποδεδειγμένο (2ος registrar = adapter, όχι rewrite) |
| **A5** Polish | bulk availability search, portfolio dashboard, **registrar↔local reconciliation** (mirror myDATA reconcile), «Μεταφορά ιδιοκτησίας», reminders polish | Δύο clocks reconciled· worklist ασυμφωνιών |

---

## 11. Non-goals / deferred / build-time unknowns

**Deferred (flags/hooks μένουν, λειτουργία μετά):**
- **DNS zone/record hosting** (A/AAAA/MX/TXT) + **email forwarding** — capability flags στο
  `domain_tlds` τώρα, `getDns/setDns` στο contract, αλλά UI/λειτουργία post-v1.
- **Customer portal «Τα domains μου»** (`/user`, Flux UI — Πυλώνας D): λίστα/expiry/NS view,
  αργότερα self-service renew-pay + transfer-out. **Build-ready ΑΠΟ ΤΩΡΑ (A1 discipline):**
  (α) κάθε domain write = `App\Actions\*` καλέσιμο κι εκτός Filament, (β) read-scoping με το
  grant-scoped fail-closed μοτίβο της πύλης (όπως «Τα αιτήματά μου»/παραστατικά — ρητό
  company+customer, 404 σε ξένο id), (γ) τίποτα panel-coupled στα services. Έτσι το portal
  γίνεται «μία σελίδα», όχι refactor.
- **CentralNic** adapter — legacy (η MyIP έφυγε)· μπαίνει αργότερα ως drop-in αν χρειαστεί.
- **Multi-currency invoicing** — EUR settlement v1· USD = display.

**Build-time unknowns (verify πριν το coding):**
- ~~Μέγεθος/σύνθεση portfolio~~ → **λύθηκε** (`whmcs-baseline.md`): 1833 active, ~75% .gr (grepp 1381 ·
  cnic 226 · openprovider 224).
- grEPP: **UAT credentials + IP whitelisting** στο Μητρώο (endpoints πλέον ΓΝΩΣΤΑ —
  `docs/domains/grepp/README.md` §1)· + τα `[ΕΠΙΒΕΒΑΙΩΣΗ]` εκείνου του οδηγού (contact-ID prefix
  μας, άρτια έτη 2–10 από τον Οδηγό v4.3, WHOIS REST doc, login χωρίς `<svcExtension>`).
- Openprovider bearer token TTL (~24h· cache + re-auth σε 401 ανεξαρτήτως).
- .gr αλλαγή δικαιούχου: μηχανική ΓΝΩΣΤΗ (`ownerChange`/`ownerNameChange`, μη ανακλήσιμα,
  .gov.gr/γεωγραφικά → ΕΕΤΤ) — **τέλη** άγνωστα· .gr WHOIS GDPR auto-redaction.
- Sandbox accounts (Openprovider `*.sandbox.openprovider.nl` + grEPP UAT) πριν το A2/A4.

**Λυμένα (αποφάσεις/δεδομένα — μην ξανανοίξουν χωρίς λόγο):**
- DNS Management + Email Forwarding φαίνονται ενεργά στα OP TLDs της WHMCS, αλλά ΑΠΟΦΑΣΗ
  ιδιοκτήτη (2026-09-07): εμπορικές υπηρεσίες για όποιον τις παρέχει — **τα αγνοούμε για τώρα**,
  το deferred ισχύει.

**Πάντα:** operator-gated legal docs (ποτέ auto-AADE)· sandbox-first + mock-HTTP tests (mirror
`MyDataSubmitterSafetyTest`)· κάθε shipped slice → `FEATURES.md` + `CHANGELOG.md` + move BACKLOG item.

---

## Πηγές
- ekdosi templates: `app/Contracts/EInvoiceProviderTransport.php` · `app/Support/EInvoice/ProviderCredentials.php`
  · `app/Services/EInvoice/ProviderTransportRegistry.php` · `app/Models/BillingConnection.php` ·
  `app/Support/Billing/SourceCapabilities.php` · `app/Actions/StageServiceRenewal.php` ·
  `app/Casts/MaybeEncrypted.php` · `app/Services/TenantRoleProvisioner.php` · `config/ekdosi.php`.
- Openprovider (org `github.com/openprovider`, χαρτογραφημένο 2026-09-07):
  - `api-documentation` — το swagger/δημόσιο API reference (**η κύρια πηγή** του §4.3 mapping)·
    ενεργό (upd. 2026-09).
  - `Openprovider-WHMCS-domains` — το module που τρέχει η MyIP σήμερα· ενεργό (1141 commits)·
    reference για real-world semantics (sync statuses, transfer completion→renewal, TLD pricing
    sync μέσω WHMCS cron)· **χωρίς ορατή άδεια → όχι copy κώδικα**.
  - `rest-client-php` — επίσημος PHP client (Guzzle ^7.4)· **beta + χωρίς άδεια → reference only**,
    όχι dependency (βλ. §4.3 απόφαση transport).
  - `openprovider-mcp` (MIT) — MCP server για το OP API· άχρηστο για την app, πιθανώς χρήσιμο
    ως dev tooling σε AI sessions.
  - Λοιπά (WHMCS SSL/PremiumDNS/Email/Plesk, Blesta, billmanager, το αρχαίο `op-whmcs` 2020) —
    εκτός scope v1.
- WHMCS registrar function index: developers.whmcs.com/domain-registrars (Function Index, Domain
  Syncing, TLD & Pricing Sync).
- .gr/.ελ: EETT (regulator) + ICS-FORTH (registry) — 2yr term, no privacy, registry-emailed auth code, .ελ = xn--qxam.
- **grEPP υλοποίηση: `docs/domains/grepp/`** — οδηγός (`README.md`), επίσημα v4.3 XML examples +
  XSDs, reference `EppClient.java` + PDF του Μητρώου, HARICA/GEANT TLS bundle (δημόσια certs).
