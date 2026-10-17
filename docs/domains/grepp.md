> **Αντίγραφο από το Ekdosi** (`docs/domains/grepp/README.md`, 2026-09-25), για αναφορά. Γράφτηκε για το Ekdosi (`company_id`, φορολογικό
> τιμολόγιο, `ServiceContract`, ανανέωση στην έκδοση)· όπου διαφωνεί, ισχύει το [`docs/domains.md`](../domains.md).
> Τα υλικά του μητρώου ήρθαν από το Ekdosi (2026-10-18, G0): XSD στο `packages/gr-epp/resources/xsd/`, τα 72 παραδείγματα v4.3
> στο `packages/gr-epp/tests/fixtures/v4.3/` (fixtures των tests), το CA bundle (HARICA, δημόσιο) στο `packages/gr-epp/resources/ca/`,
> το δείγμα client του μητρώου (`EppClient.java`, PDF) στο `docs/domains/grepp/`. Λείπουν και από τα δύο repos: το PDF του
> «Οδηγού Υλοποίησης EPP v4.3» και η τεκμηρίωση του whois/`plainDomainCheck` (να ζητηθούν από το Μητρώο).

# .gr / .ελ Registry (ICS-FORTH) — Οδηγός Υλοποίησης EPP

Τεχνικές σημειώσεις για το registrar plugin μας. Πηγή: **Οδηγός Υλοποίησης EPP v4.3**
του Μητρώου (παραδείγματα XML + XSD), το reference `EppClient.java` του Μητρώου, και
τα δημόσια κείμενα του `grweb.ics.forth.gr`.

> **Κατάσταση**: γραμμένο πριν την πρώτη σύνδεση σε UAT. Ό,τι είναι σημειωμένο
> `[ΕΠΙΒΕΒΑΙΩΣΗ]` πρέπει να ελεγχθεί ζωντανά πριν βασιστούμε πάνω του.

---

## 1. Πρόσβαση & προϋποθέσεις

- Διαπίστευση καταχωρητή μέσω **ΕΕΤΤ** (η ΕΕΤΤ τηρεί το επίσημο μητρώο καταχωρητών).
- **IP whitelisting**: όλες οι υπηρεσίες προς καταχωρητές είναι προσβάσιμες μόνο από
  στατικές IP δηλωμένες στο σύστημα του Μητρώου. Τουλάχιστον μία ανά καταχωρητή.
  → Δήλωσε **και** την IP του staging, αλλιώς το UAT δεν δουλεύει από dev μηχάνημα.
- Δύο περιβάλλοντα: production και testing (UAT), με ξεχωριστά credentials.
- Επικοινωνία: `hmaster-info@ics.forth.gr`, +30 2810 391450.

### Endpoints

| Περιβάλλον | URL |
|---|---|
| UAT | `https://uat-regepp.ics.forth.gr:700/epp/proxy` |
| Production | `https://regepp.ics.forth.gr:700/epp/proxy` |

Βοηθητικά (εκτός EPP, για availability checks χωρίς session):

```
https://grwhois.ics.forth.gr:800/plainwhois/plainWhois?domainName=
https://grwhois.ics.forth.gr:800/plainDomainCheck?domainName=
https://uat-grwhois.ics.forth.gr:800/plainwhois/plainWhois?domainName=
https://uat-grwhois.ics.forth.gr:800/plainDomainCheck?domainName=
```

Το `plainWhois` επιστρέφει HTML (όχι JSON) — να ζητηθεί το τρέχον WHOIS REST API doc
πριν βασιστούμε σε parsing. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

---

## 2. Transport — ΤΟ ΠΙΟ ΣΗΜΑΝΤΙΚΟ ΣΗΜΕΙΟ

**Δεν είναι RFC 5734 EPP over TCP/700.** Είναι **XML Web service πάνω από HTTPS**.
Το `:700` είναι απλώς η πόρτα του HTTPS listener. Οποιαδήποτε έτοιμη EPP library
που κάνει socket + 4-byte length framing **δεν δουλεύει**.

Χαρακτηριστικά (από το `EppClient.java` του Μητρώου):

- `POST` του EPP XML στο `/epp/proxy`
- `Content-Type: text/xml;charset=UTF-8`
  ⚠️ Το reference client στέλνει `text/xml`, **όχι** `application/epp+xml`.
- Session state με **cookie `JSESSIONID`**
- Το cookie έρχεται στο `Set-Cookie` και μπορεί να έχει πολλαπλές τιμές χωρισμένες
  με `;` — κρατάμε **μόνο το πρώτο κομμάτι** (`servCook.split(";")[0]`)
- Το reference client κάνει πρώτα ένα "priming" request για να πάρει cookie, και
  μετά στέλνει το `login`. Με `http.Client` + cookie jar γίνεται αυτόματα.
- `hello` χρησιμοποιείται ως **keep-alive** για να μη λήξει η συνεδρία.

### Κύκλος ζωής συνεδρίας

```
POST (κενό/hello)  → Set-Cookie: JSESSIONID=...
POST login.xml     + Cookie: JSESSIONID=...   → 1000
POST <commands>    + Cookie: JSESSIONID=...
POST hello.xml     + Cookie: ...              (keep-alive, όποτε χρειάζεται)
POST logout.xml    + Cookie: ...              → 1500 "ending session"
```

**Συνέπεια για την υλοποίηση**: μία σύνδεση = ένα session = σειριακά commands.
Χρειάζεται lock/mutex ανά session· μην μοιράζεσαι cookie ανάμεσα σε παράλληλα jobs.

### TLS

Server certs από **HARICA** (μέσω GÉANT TCS):

- Root: `HARICA TLS RSA Root CA 2021` (RSA 4096, λήξη 2045-02-13)
- Intermediate: `GEANT TLS RSA 1` (RSA 3072, έκδοση 2025-01-03, λήξη 2039-12-31)

Το root υπάρχει ήδη στα system trust stores (Mozilla/Microsoft/Apple/`ca-certificates`),
οπότε **δεν χρειάζεται** custom CA bundle. Αν κάνουμε pinning για επιπλέον ασφάλεια:
πίναρε το **root**, ποτέ leaf fingerprint, και βάλε alerting για αλλαγή αλυσίδας.

Το `ca-bundle.pem` του Μητρώου = intermediate + root (σε σωστή σειρά).

---

## 3. Namespaces

### Standard (IETF)

```
urn:ietf:params:xml:ns:epp-1.0
urn:ietf:params:xml:ns:domain-1.0
urn:ietf:params:xml:ns:contact-1.0
urn:ietf:params:xml:ns:host-1.0
urn:ietf:params:xml:ns:eppcom-1.0
urn:ietf:params:xml:ns:secDNS-1.1      (DNSSEC, RFC 5910)
```

### FORTH extensions

| Namespace | Χρήση |
|---|---|
| `urn:ics-forth:params:xml:ns:extdomain-1.3` | Ο πυρήνας — create/check/renew/transfer/update/delete + regulator statuses + bundles |
| `urn:ics-forth:params:xml:ns:account-1.1` | Υπόλοιπο λογαριασμού καταχωρητή |
| `urn:ics-forth:params:xml:ns:extcommon-1.0` | Σχόλια σε responses (π.χ. login) |
| `urn:ics-forth:params:xml:ns:extcontact-1.0` | Σχόλια σε contact responses |
| `urn:ics-forth:params:xml:ns:exthost-1.0` | Σχόλια σε host responses |
| `urn:ics-forth:params:xml:ns:extSecDNS-1.0` | Αποτέλεσμα επαλήθευσης DS (read-only) |
| `urn:ics-forth:params:xml:ns:dacor-1.0` | Domain Authorization Code Reset |
| `urn:ics-forth:params:xml:ns:ext-gr-rls-1.0` | Υπηρεσία Αυξημένης Ασφάλειας (Registry Lock) |

> ⚠️ **Το `extdomain` είναι 1.3, όχι 1.2.** Παλιότερος κώδικας που κυκλοφορεί
> (π.χ. το WHMCS module `emavro/eppgr` του 2018) χρησιμοποιεί το 1.2, που έχει
> **διαφορετική δομή** στα `create` / `renew` / `transfer`. Μην τον αντιγράψεις τυφλά.

### ⚠️ Χωρίς `<svcExtension>` στο login

Το επίσημο `03-login-request.xml` δηλώνει **μόνο** τα τρία `objURI` και **κανένα**
`<svcExtension>`. Το greeting επίσης διαφημίζει μόνο `objURI`.

```xml
<svcs>
  <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
  <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
  <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
</svcs>
```

Οι extensions στέλνονται απευθείας μέσα στο `<extension>` κάθε command, χωρίς
προδήλωση. **Αν χρησιμοποιήσουμε metaregistrar, μην καλέσουμε `useExtension()`** —
προσθέτει `<svcExtension>` στο login και πιθανότατα θα απορριφθεί. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

### Greeting (`hello` response)

```xml
<greeting>
  <svID>.gr and .ελ ccTLD EPP Service</svID>
  <svcMenu>
    <version>1.0</version>
    <lang>en</lang><lang>el</lang>
    <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
    <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
    <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
  </svcMenu>
  ...
</greeting>
```

Γλώσσα μηνυμάτων: `el` ή `en` μέσω `<options><lang>`. Τα μηνύματα σφάλματος
έρχονται στη γλώσσα που ζητήσαμε — **χρήσιμο**: με `lang=el` μπορούμε να δείξουμε
το μήνυμα του Μητρώου κατευθείαν στον πελάτη.

---

## 4. Τι ΔΕΝ υποστηρίζεται

### `<poll>` — μη υλοποιημένο

```xml
<result code="2101"><msg>Μη υλοποιημένη εντολή</msg></result>
```

**Δεν υπάρχει message queue.** Κάθε ασύγχρονη κατάσταση πρέπει να ανιχνεύεται με
**polling μέσω `domain:info`**:

- εγκρίσεις ΕΕΤΤ (`pendingRegulatorApproval`)
- ολοκλήρωση `ownerChange` σε .gov.gr / γεωγραφικά
- επαλήθευση DS records (μένουν σε `serverUpdateProhibited` μέχρι να ολοκληρωθεί)
- εκκρεμείς μεταφορές

→ Scheduled job με backoff. Σχεδίασέ το από την αρχή, δεν είναι patch.

---

## 5. Command reference

### 5.1 Session

**login** — `<pw>` σε CDATA. Αλλαγή κωδικού με `<newPW>` μέσα στο ίδιο login:

```xml
<login>
  <clID>my-username</clID>
  <pw>current-password</pw>
  <newPW>new-password</newPW>
  <options><version>1.0</version><lang>el</lang></options>
  <svcs>...</svcs>
</login>
```

Το login response φέρνει `extcommon:comment` («Καλώς ήλθατε στο Μητρώο.»).

**logout** → `1500 Command completed successfully; ending session`.

---

### 5.2 Domain check

Απλό check:

```xml
<domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
  <domain:name>forth.gr</domain:name>
  <domain:name>forth.gov.gr</domain:name>
</domain:check>
```

Με extension (έλεγχος στο πλαίσιο συγκεκριμένου δικαιούχου):

```xml
<extension>
  <extdomain:check xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_cont0343</extdomain:registrantid>
  </extdomain:check>
</extension>
```

Response: `domain:cd/domain:name@avail` + `domain:reason` (ελληνικά), και
`extdomain:comment ref="forth.gr"` ανά όνομα.

---

### 5.3 Domain info

Το response φέρνει, εκτός των standard, στο `<extension>`:

- `extdomain:status s="..."` — **regulator statuses** (βλ. §6)
- `extdomain:protocol` — αριθμός πρωτοκόλλου δήλωσης
- `extdomain:punycode` — για IDN
- `extdomain:bundle/bundleName` με `chargeable` και `recordType`
- `extSecDNS:lastValidationResult` — `wasSuccessful`, `validationOutcome`, `validationDate`

---

### 5.4 Domain create

```xml
<domain:create>
  <domain:name>forth.gr</domain:name>
  <domain:period unit="y">2</domain:period>
  <domain:ns><domain:hostObj>ns1.forth.gr</domain:hostObj></domain:ns>
  <domain:registrant>reg_contact001</domain:registrant>
  <domain:contact type="tech">reg_contact002</domain:contact>
  <domain:contact type="admin">reg_contact003</domain:contact>
  <domain:contact type="billing">reg_contact004</domain:contact>
  <domain:authInfo><domain:pw/></domain:authInfo>
</domain:create>
```

Response `1001` (**εκκρεμούν ενέργειες** — όχι 1000!) με:

```xml
<extdomain:resData>
  <extdomain:protocol>66155</extdomain:protocol>
  <extdomain:comment>Αριθμός Πρωτοκόλλου Αίτησης 66155: Προς Έγκριση...</extdomain:comment>
</extdomain:resData>
```

**Κράτα το `protocol`** — χρειάζεται για `recallApplication`.

#### Homograph (.ελ / IDN bundle)

```xml
<domain:create>
  <domain:name>ιτέ.gr</domain:name>
  <domain:authInfo>
    <!-- authInfo του ΗΔΗ εκχωρημένου ονόματος -->
    <domain:pw>domain-auth-code</domain:pw>
  </domain:authInfo>
</domain:create>
...
<extension>
  <extdomain:create xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:record>dname</extdomain:record>
  </extdomain:create>
</extension>
```

`extdomain:record` ∈ `{domain, dname}`. Το `dname` = ενεργοποίηση δεσμευμένης
ομόγραφης μορφής (DNAME record). Είναι **χρεώσιμο** (`chargeable="1"`).

---

### 5.5 Domain renew

```xml
<domain:renew>
  <domain:name>forth.gr</domain:name>
  <domain:curExpDate>2012-03-19</domain:curExpDate>
  <domain:period unit="y">6</domain:period>
</domain:renew>
```

Όταν ανανεώνουμε **ληγμένο όνομα άλλου καταχωρητή** (μεταφορά μέσω ανανέωσης):

```xml
<extension>
  <extdomain:renew xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_newRegistrnt</extdomain:registrantid>
    <extdomain:currentPW>domain-auth-code</extdomain:currentPW>
  </extdomain:renew>
</extension>
```

⚠️ Στο 1.3 **δεν υπάρχει πια `newPW`** εδώ (υπήρχε στο 1.2).

---

### 5.6 Domain transfer

```xml
<transfer op="request">
  <domain:transfer>
    <domain:name>forth.gr</domain:name>
    <domain:authInfo><domain:pw>123123</domain:pw></domain:authInfo>
  </domain:transfer>
</transfer>
<extension>
  <extdomain:transfer xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
    <extdomain:registrantid>reg_contact001</extdomain:registrantid>
  </extdomain:transfer>
</extension>
```

⚠️ Μόνο `registrantid`. Το `newPW` του 1.2 έφυγε.

---

### 5.7 Domain update

**Standard** (nameservers, contacts) — χωρίς extension:

```xml
<domain:update>
  <domain:name>forth.gr</domain:name>
  <domain:add>
    <domain:ns><domain:hostObj>ns2.forth.gr</domain:hostObj></domain:ns>
    <domain:contact type="tech">reg_contact003</domain:contact>
  </domain:add>
  <domain:rem>
    <domain:ns><domain:hostObj>ns1.forth.gr</domain:hostObj></domain:ns>
    <domain:contact type="tech">reg_contact001</domain:contact>
  </domain:rem>
</domain:update>
```

**Αλλαγή δικαιούχου** — `<domain:chg><domain:registrant>` + extension `op`:

| `extdomain:op` | Σημασία | Εκτέλεση |
|---|---|---|
| `ownerChange` | Μεταβίβαση σε **άλλο** πρόσωπο | Άμεση, **εκτός** .gov.gr / γεωγραφικών (εκεί `1001` + έγκριση ΕΕΤΤ). **Μη ανακλήσιμη.** |
| `ownerNameChange` | Αλλαγή **στοιχείων** του ίδιου δικαιούχου | Άμεση για όλα. **Μη ανακλήσιμη.** |

**Αλλαγή τύπου εγγραφής σε bundle** (ποιο είναι domain και ποιο dname):

```xml
<extdomain:update>
  <extdomain:chg>
    <extdomain:record>
      <extdomain:recordType type="domain">ίτε.gr</extdomain:recordType>
      <extdomain:recordType type="dname">ιτέ.gr</extdomain:recordType>
    </extdomain:record>
  </extdomain:chg>
</extdomain:update>
```

**Regulator statuses**: `<extdomain:add>` / `<extdomain:rem>` με `<extdomain:status>`
— διαχειρίζεται η ΕΕΤΤ, όχι εμείς.

---

### 5.8 Domain delete

```xml
<extdomain:delete xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">
  <extdomain:pw>domain-auth-code</extdomain:pw>   <!-- ή <extdomain:protocol> -->
  <extdomain:op>deleteDomain</extdomain:op>
  <extdomain:reason>...</extdomain:reason>         <!-- προαιρετικό, ≤256 χαρ. -->
</extdomain:delete>
```

`op` ∈ `{deleteDomain, deleteHomograph, recallApplication}`:

- `deleteDomain` — διαγραφή, με `pw`. **Άμεση, μη ανακλήσιμη.**
- `deleteHomograph` — αφαίρεση της ομόγραφης μορφής.
- `recallApplication` — **ανάκληση δήλωσης** που είναι ακόμη `pendingCreate`,
  με `<extdomain:protocol>` αντί για `pw`. Άμεση.

---

### 5.9 Contact

- Δύο `postalInfo`: `type="loc"` (ελληνικά) **και** `type="int"` (λατινικά).
- `<contact:voice x="1450">+30.2810391400</contact:voice>` — το `x` είναι extension.
- **ID protection** = `<contact:disclose flag="0">` με λίστα πεδίων προς απόκρυψη.
- Contact IDs έχουν prefix καταχωρητή, μορφή `<prefix>_<id>` (π.χ. `reg_contact001`).
  Έτσι ξεχωρίζουμε δικές μας επαφές από ξένες — μετά από incoming transfer οι ξένες
  επαφές πρέπει να αντικατασταθούν. `[ΕΠΙΒΕΒΑΙΩΣΗ ποιο είναι το δικό μας prefix]`
- `contact:status s="serverDeleteProhibited"` όταν η επαφή χρησιμοποιείται.

---

### 5.10 Host

Standard `host-1.0`. `<host:addr ip="v4|v6">`, default `v4`. Το `host:update`
υποστηρίζει `add` / `rem` / `chg` (μετονομασία).

---

### 5.11 Account info (υπόλοιπο καταχωρητή)

```xml
<account:info xmlns:account="urn:ics-forth:params:xml:ns:account-1.1"/>
```

Response:

```xml
<account:infData>
  <account:roid>ca0009...-gr</account:roid>
  <account:ddPaymentCode>RF00000000000000000000000</account:ddPaymentCode>
  <account:caAllowed>true</account:caAllowed>
  <account:balance currency="EUR">-25</account:balance>
  <account:tbSuspendedOn>2012-01-16T10:00:00Z</account:tbSuspendedOn>
  <account:suspendedOn>...</account:suspendedOn>
</account:infData>
```

⚠️ Το **balance μπορεί να είναι αρνητικό**. Τα `tbSuspendedOn` / `suspendedOn`
δείχνουν επικείμενη / ενεργή αναστολή λογαριασμού.

→ **Monitoring job**: `account:info` σε τακτά διαστήματα, alert κάτω από όριο και
σε οποιοδήποτε `suspendedOn`. Αν μας αναστείλουν, σταματάνε όλα τα registrations.

---

### 5.12 DACoR — Domain Authorization Code Reset

Λύνει το «ο πελάτης έχασε το authInfo». Στέλνεται μέσα σε `domain:update`:

```xml
<update>
  <domain:update><domain:name>example.gr</domain:name></domain:update>
</update>
<extension>
  <dacor:issueToken xmlns:dacor="urn:ics-forth:params:xml:ns:dacor-1.0"/>
</extension>
```

Προαιρετικά με `<dacor:email>` και `<dacor:ref>` (10–256 χαρ.).
Το token πάει στο email του δικαιούχου.

→ Κουμπί στο panel του πελάτη. Γλιτώνει tickets.

---

### 5.13 grRLS — Υπηρεσία Αυξημένης Ασφάλειας (Registry Lock)

Namespace: `urn:ics-forth:params:xml:ns:ext-gr-rls-1.0`

**Είναι συνδρομητική υπηρεσία** με `period` / `exDate` / `autoRenew` — δηλαδή
χρεώσιμο προϊόν, όχι απλό flag. Εμπορική ευκαιρία: ελάχιστοι Έλληνες καταχωρητές
το προσφέρουν καθαρά.

Elements: `create`, `renew`, `update`, `info` (+ `creData`, `renData`, `infData`).

**Operations** (`op` attribute):

| op | Σημασία |
|---|---|
| `activateService` | Ενεργοποίηση υπηρεσίας |
| `deactivateService` | Απενεργοποίηση |
| `cancelServiceDeactivation` | Ακύρωση απενεργοποίησης |
| `lock` | Κλείδωμα |
| `unlock` | Ξεκλείδωμα |
| `cancelUnlock` | Ακύρωση αιτήματος ξεκλειδώματος |

**Τι κλειδώνει** (`object`): `domain` (και τα subordinate hosts) | `registrant`
(η επαφή δικαιούχου) | `all` | `none`

**Κατάσταση υπηρεσίας**: `active` | `inactive` | `pendingCreate` | `pendingDeactivation`

**Κατάσταση αιτήματος**: `pendingAuthorisation` | `authorised` | `authorisationOverridden`

**Χρονικό παράθυρο αλλαγών** — το ξεκλείδωμα δεν είναι στιγμιαίο, ορίζεις παράθυρο:

```xml
<grRLS:chgWindow>
  <grRLS:timeSlotStarts at="..."/>
  <grRLS:timeSlotEnds at="..."/>
</grRLS:chgWindow>
```

**`info` με `crl="true"`**: επιστρέφει `registrantLockHeldBy` — ποια **άλλα** domains
(έως 200) κρατούν lock στην ίδια επαφή δικαιούχου. Σημαντικό: ένα registrant lock
επηρεάζει όλα τα domains που μοιράζονται την επαφή.

⚠️ Κλειδωμένο domain θα **απορρίπτει** update/transfer/delete. Το error handling
πρέπει να το ξεχωρίζει από γενικό authorization error, αλλιώς ο πελάτης βλέπει
ακατανόητο μήνυμα.

---

### 5.14 DNSSEC

- Οι DS εγγραφές πάνε με **standard `secDNS-1.1`** (RFC 5910) — όχι custom.
  Άρα υπάρχει έτοιμη υποστήριξη σε libraries.
- Το αποτέλεσμα επαλήθευσης έρχεται read-only στο `extSecDNS-1.0`:
  `wasSuccessful`, `validationOutcome` (κείμενο στα ελληνικά), `validationDate`.
- ⚠️ Ονόματα με **εκκρεμή επαλήθευση DS μένουν σε `serverUpdateProhibited`**
  μέχρι να ολοκληρωθεί. Άλλος ένας λόγος για polling.

---

## 6. Statuses

### Regulator statuses (ΕΕΤΤ)

Διαχειρίζονται **αποκλειστικά** από την ΕΕΤΤ και **υπερισχύουν** των `server` statuses.
Έρχονται ως `<extdomain:status s="...">`:

```
pendingRegulatorApproval          εκκρεμεί έγκριση (γεωγραφικά / .gov.gr)
regulatorUpdateProhibited
regulatorDeleteProhibited
regulatorTransferProhibited
regulatorRenewProhibited
regulatorHold
regulatorOwnerChangeProhibited
regulatorOwnerNameChangeProhibited
regulatorExpirationProhibited
regulatorDelegationChangeProhibited
regulatorDebtOwnership            καθεστώς οφειλής
```

→ Χαρτογράφησέ τα **όλα** σε ανθρώπινα μηνύματα στο UI.

### Standard EPP statuses που μας αφορούν

- `pendingCreate` — η δήλωση δεν έχει εγκριθεί ακόμη· επιτρέπεται `recallApplication`
- `pendingDelete` — μετά τη λήξη (grace period)
- `serverUpdateProhibited` — π.χ. εκκρεμής επαλήθευση DS
- `inactive` — χωρίς nameservers

---

## 7. Result codes που έχουμε δει

| Code | Σημασία |
|---|---|
| `1000` | Επιτυχία |
| `1001` | Επιτυχία, **εκκρεμούν ενέργειες** — το κανονικό για `create` |
| `1500` | Επιτυχία, τερματισμός συνεδρίας (logout) |
| `2101` | Μη υλοποιημένη εντολή (π.χ. `poll`) |

⚠️ Μην θεωρήσεις `1001` ως αποτυχία. Είναι η **αναμενόμενη** απάντηση σε create.

---

### Μηνύματα από την παραγωγή (WHMCS activity log, 2 χρόνια — `../whmcs-baseline.md` §8)
Οι κωδικοί ήταν μασκαρισμένοι, κρατάμε το κείμενο του μητρώου → οδηγία για τον operator στο A4:

| Μήνυμα μητρώου | Πού | Φορές | Συνήθως σημαίνει |
|---|---|---:|---|
| «Μη επαρκή δικαιώματα» | transfer 19 · renew 2 · NS 2 | 23 | λάθος/ληγμένος κωδικός· ή το όνομα δεν είναι (πια) σε εμάς |
| «Λάθος ταυτοποίηση χρήστη» | renew | 9 | login/credentials του καταχωρητή |
| «Η αποδεκτή διάρκεια καταχώρησης … είναι τα δύο (2) έτη» | register | 5 | λάθος περίοδος (βλ. §8) |
| «Η κατάσταση του αντικειμένου απαγορεύει την ενέργεια» | transfer | 3 | status του ονόματος μπλοκάρει |
| «Το αντικείμενο δεν υπάρχει» | transfer | 2 | λάθος όνομα / δεν υπάρχει |
| «Αιτήματα αλλαγής καταχωρητή … μόνο σε ονόματα που δε βρίσκονται στη διαχείρισή σας» | transfer | 2 | ήδη δικό μας |
| «Δεν έχουν συμπληρωθεί τα απαραίτητα στοιχεία του προσώπου επαφής» | register | 2 | ελλιπής επαφή |

## 8. Επιχειρησιακοί κανόνες

- **Διαδικασία εκχώρησης**: το όνομα ελέγχεται αυτόματα, **ενεργοποιείται εντός 3 ωρών**
  και **εκχωρείται με την παρέλευση 5 ημερών** από την υποβολή.
- **.gov.gr και γεωγραφικοί όροι**: δεν ενεργοποιούνται προσωρινά — εξετάζονται από
  την ΕΕΤΤ και εγκρίνονται/απορρίπτονται **εντός 20 ημερών**.
- **Ισχύον πλαίσιο**: απόφαση ΕΕΤΤ **1110/6/29-04-2024**.
- 2ου επιπέδου: `com.gr`, `edu.gr`, `net.gr`, `org.gr`, `gov.gr` (+ `co.gr` ιστορικά).
- **Περίοδος**: τα παραδείγματα δείχνουν `unit="y"` με τιμές 2 και 6. Ο παλιός κώδικας
  επέβαλλε **άρτια πολλαπλάσια, 2–10 έτη**. Τα δεδομένα της WHMCS το επιβεβαιώνουν: 1381 .gr domains
  αποκλειστικά σε 2/4/6/8 έτη (`../whmcs-baseline.md` §3)· το 10 δεν εμφανίζεται. `[ΕΠΙΒΕΒΑΙΩΣΗ του 10 στον Οδηγό v4.3]`
- Ονόματα 2 χαρακτήρων έχουν ειδικούς κανόνες. `[ΕΠΙΒΕΒΑΙΩΣΗ]`

---

## 9. Παγίδες υλοποίησης — checklist

- [ ] **Μην** χρησιμοποιήσεις EPP library που κάνει TCP/700 framing
- [ ] `Content-Type: text/xml;charset=UTF-8` (όχι `application/epp+xml`)
- [ ] Cookie `JSESSIONID`, κόψε στο πρώτο `;`
- [ ] **Μην** στείλεις `<svcExtension>` στο login
- [ ] Ένα session = σειριακά commands (mutex)
- [ ] `hello` ως keep-alive σε μακροχρόνια sessions
- [ ] `poll` **δεν** υπάρχει → polling με `domain:info`
- [ ] `1001` = επιτυχία, όχι σφάλμα
- [ ] Κράτα το `extdomain:protocol` κάθε create (χρειάζεται για recall)
- [ ] Δύο `postalInfo` (loc + int) σε κάθε contact
- [ ] UTF-8 παντού· προσοχή σε IDNA/punycode και normalization ελληνικών
- [ ] `ownerChange` / `ownerNameChange` / `deleteDomain` είναι **μη ανακλήσιμα** —
      βάλε επιβεβαίωση στο UI
- [ ] Monitoring `account:balance` και `suspendedOn`
- [ ] Χειρισμός grRLS locks στα error paths
- [ ] Δήλωσε **όλες** τις IP (prod, staging, dev) στο Μητρώο

---

## 10. Αρχιτεκτονική (Laravel)

Ενοποίηση σε επίπεδο **registrar driver**, όχι σε επίπεδο πρωτοκόλλου — γιατί για
όλα τα άλλα TLD (.com/.net/.org/.eu) πάμε μέσω **OpenProvider REST**, όχι EPP.

```
DomainRegistrar (interface)
├── check(domain): Availability
├── register(DomainOrder): Result
├── renew(domain, period)
├── transfer(domain, authInfo)
├── delete(domain, reason)
├── getNameservers / setNameservers
├── getContacts / updateContacts
└── getInfo(domain): DomainInfo

ForthEppDriver          → EPP/HTTPS + extdomain-1.3 + grRLS + dacor
OpenproviderRestDriver  → https://api.openprovider.eu/v1, bearer token
```

Γιατί όχι «όλα EPP»: ο OpenProvider έχει EPP, αλλά είναι shim πάνω από το REST τους,
καλύπτει μόνο domains+contacts (όχι SSL/licenses), και οι ίδιοι συνιστούν το REST.

Κοινά (γράφονται μία φορά): DTOs, jobs, retries, sync expiry dates, billing hooks,
audit log. Τα .gr-specific (2ετίες, homographs, grRLS, regulator statuses) ζουν
μέσα στον `ForthEppDriver`.

### Jobs που χρειαζόμαστε

| Job | Συχνότητα | Τι κάνει |
|---|---|---|
| `PollPendingDomains` | κάθε 15–30' | `domain:info` σε ό,τι είναι `pendingCreate` / `pendingRegulatorApproval` |
| `PollDsValidation` | ωριαία | ονόματα σε `serverUpdateProhibited` λόγω DS |
| `SyncExpiryDates` | ημερήσια | ευθυγράμμιση `exDate` με το billing |
| `MonitorAccountBalance` | ωριαία | `account:info`, alert σε χαμηλό/αρνητικό ή `suspendedOn` |

---

## 11. Επιλογές βιβλιοθήκης

| Package | HTTP transport | Κρίση |
|---|---|---|
| `metaregistrar/php-epp-client` | **Ναι** (`eppHttpsConnection`, curl + cookies) | Η μόνη έτοιμη· παλιάς σχολής κώδικας |
| `struzik-vladislav/epp-client` | Όχι (socket/RabbitMQ) | Καθαρότερο, PSR-3, έτοιμα secDNS/IDN — γράφουμε ~60 γρ. `HttpConnection` |
| `epptools/sdk` | Όχι (TLS/700 framing) | Δεν κάνει |
| `ywatchman/laravel-epp` | wrapper metaregistrar | Εγκαταλελειμμένο, SIDN-focused |
| `pstoute/laravel-domain-registrar` | — | Δεν κάνει EPP· REST σε Namecheap/GoDaddy |

Αν πάμε metaregistrar, το subclass:

```php
class ForthEppConnection extends \Metaregistrar\EPP\eppHttpsConnection
{
    public function __construct($logging = false, $settingsfile = null)
    {
        parent::__construct($logging, $settingsfile);
        parent::setServices([
            'urn:ietf:params:xml:ns:domain-1.0'  => 'domain',
            'urn:ietf:params:xml:ns:contact-1.0' => 'contact',
            'urn:ietf:params:xml:ns:host-1.0'    => 'host',
        ]);
        // ΠΡΟΣΟΧΗ: ΧΩΡΙΣ useExtension() — το Μητρώο δεν θέλει <svcExtension>
    }

    protected function initCurl($postMode = true)
    {
        $ch = parent::initCurl($postMode);
        curl_setopt($ch, CURLOPT_URL, config('epp.gr.endpoint'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml;charset=UTF-8']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // η base κλάση απενεργοποιεί την επαλήθευση αν δεν δοθεί client cert — το διορθώνουμε
        return $ch;
    }
}
```

---

## 12. Πρώτα βήματα σε UAT

1. Δήλωσε IP, πάρε credentials UAT.
2. `hello` → **κράτα το greeting**. Επιβεβαίωσε ότι τα namespaces είναι αυτά που
   λέει το v4.3 και ενημέρωσε αυτό το αρχείο αν διαφέρουν.
3. `login` → επιβεβαίωσε ότι περνάει χωρίς `<svcExtension>`.
4. `account:info` → επιβεβαίωσε parsing.
5. `domain:check` σε γνωστό ελεύθερο + γνωστό πιασμένο όνομα.
6. `contact:create` → `domain:create` → δες το `1001` + `protocol`.
7. `recallApplication` με το protocol → επιβεβαίωσε ότι καθαρίζει.
8. Μόνο μετά: transfer, renew, grRLS.

---

## 13. Παραπομπές

- Παραδείγματα v4.3: `epp-guide-examples-v4.3/` (66 XML + 14 XSD, δίπλα σε αυτό το αρχείο)
- Reference client Μητρώου: `EPP_client_sample/EppClient.java` (+ `epp-client-sample.pdf`)
- TLS bundle: `ca-bundle-2025/` (HARICA root + GEANT intermediate — δημόσια certs)
- Δημόσιες σελίδες: `https://grweb.ics.forth.gr/public/registrars`
- Κανονισμός ΕΕΤΤ: `https://grweb.ics.forth.gr/public/domains/regulation`
- Παλιό reference (⚠️ **extdomain-1.2**, μόνο για ιδέες): `github.com/emavro/eppgr`
