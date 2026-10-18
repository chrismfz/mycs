# .gr / .ελ WHOIS RESTful API v1.0

Η τεκμηρίωση του Μητρώου (`https://grwhois.ics.forth.gr:800/api/spec.html` και `whois.yaml`, OpenAPI 3.1.0, «Last updated
2024-06-21»), διαβασμένη στις 2026-10-18 από δηλωμένη IP με τον λογαριασμό WHOIS (Basic auth). Σύνοψη για εμάς. Ο
adapter είναι το `app/Services/Domains/Lookup/GrWhois.php` και ο parser το `packages/gr-epp/src/Whois/WhoisRecord.php`.

## Πρόσβαση
- **Basic auth** (`Authorization: Basic base64(user:pass)`), χρήστης με ρόλο `WHOIS_API_ACCESS`. Είναι **ξεχωριστός
  λογαριασμός** από το EPP (στη σύνδεση «GR»: «WHOIS API username/password»). Χωρίς στοιχεία: 401 «Λάθος ταυτοποίηση
  χρήστη», ακόμα και για τη σελίδα της τεκμηρίωσης.
- **Μόνο από δηλωμένες στατικές IP** (όπως το EPP: orion, rigel, mycs.myip.gr).
- Γλώσσα: ελληνικά by default· `Accept-Language: en` για αγγλικά μηνύματα.
- Base URL: παραγωγή `https://grwhois.ics.forth.gr:800`, δοκιμαστικό `https://uat-grwhois.ics.forth.gr:800`.

## Όρια
- **10.000 κλήσεις ανά 24ωρο**, **burst 2 ανά δευτερόλεπτο**. Κεφαλίδες `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
  και `X-RateLimit-Retry` μόνο όταν ξεπεραστεί όριο.
- Ζωντανά (2026-10-18): το τρίτο αίτημα μέσα στο ίδιο δευτερόλεπτο πήρε **429**. Ο adapter στέλνει ένα τη φορά, με 0,6 s
  απόσταση, για όλες τις διεργασίες (cache lock· αναμονή ως 3 s, timeout 5 s: η σελίδα είναι δημόσια).
- **Προστασία του λογαριασμού** (reviews του #197): δικό μας όριο **5.000 την ημέρα** (UTC)· 429 ή `X-RateLimit-Retry`:
  παύση όσο λέει το μητρώο (τουλάχιστον 1 λεπτό)· `X-RateLimit-Remaining` κάτω από 500: παύση 1 ώρα· χωρίς απάντηση ή
  5xx: παύση 1 λεπτό. Οι αρνήσεις (400/401/403/429) γράφονται στο log χωρίς credentials.
- **Μόνο η σύνδεση παραγωγής** χρησιμοποιείται: οι απαντήσεις του δοκιμαστικού μητρώου δεν φαίνονται ποτέ ως αληθινές.

## Πόρος
`GET {baseURL}/api/v1/domain/{domainName}`: όνομα .gr ή .ελ, **όχι punycode** (`xn--` → 400). Ο adapter μετατρέπει σε
Unicode. **Δεν κάνει έλεγχο διαθεσιμότητας**: γι' αυτό το EPP `<domain:check>`.

| HTTP | Σημασία |
|---|---|
| 200 | Βρέθηκε: λίστα JSON με ένα domain |
| 400 | Μη έγκυρη σύνταξη (π.χ. punycode): κρατιέται μία ώρα όπως απάντηση |
| 401 / 403 | Μη εξουσιοδοτημένη / απαγορευμένη πρόσβαση |
| 404 | Δεν βρέθηκε (`{"error":404,"message":"The domain name … was not found."}`): στη σελίδα «δεν υπάρχει στο WHOIS του μητρώου», **όχι «ελεύθερο»** (το API δεν ελέγχει διαθεσιμότητα)· 404 που δεν είναι αυτό το JSON = σφάλμα |
| 405 | Μέθοδος που δεν επιτρέπεται |
| 429 | Υπέρβαση ορίων |

## Απάντηση 200
```json
[ {
  "handle": "…-gr", "name": "example1.gr", "protocol": 1234567,
  "status": [ "ok" ], "bundles": [ "example1.gr" ],
  "nameservers": [ "ns1.example.com", "ns2.example.com" ],
  "secureDNS": { "delegationSigned": false, "dsData": [ { "keyTag": 0, "algorithm": 0, "digestType": 0, "digest": "…" } ] },
  "createDate": "01-03-2005", "updateDate": "18-10-2026", "expireDate": "01-03-2029",
  "registrar": { "name": "MYIP NETWORKS", "email": "…", "voice": "+30.…", "url": "https://myip.gr" }
} ]
```
- **Οι ημερομηνίες ζωντανά είναι `dd-mm-yyyy`**, ενώ το παράδειγμα της τεκμηρίωσης γράφει `yyyy-mm-dd`· ο parser δέχεται
  και τα δύο.
- **Χωρίς επαφές**: το μητρώο δεν δημοσιεύει στοιχεία κατόχου.
- Το WHOIS δείχνει την αλλαγή NS αμέσως (όπως το `domain:info`), ενώ η delegation του .gr αργεί (βλ. `grepp.md`).
