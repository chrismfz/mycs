# domain_tools: έξυπνος έλεγχος domain, προτάσεις και whois (σχέδιο 2026-10-10)

## Γιατί

Το Blesta 6.0.3 κάνει έλεγχο διαθεσιμότητας μέσω registrar (το order form τύπου "Domain"), αλλά δεν έχει:

- **προτάσεις ονομάτων** (το "Suggested domains" του WHMCS),
- **δημόσια σελίδα whois** με την εγγραφή του domain (registrar, ημερομηνίες, nameservers): το myip.gr τη δείχνει
  από το ενσωματωμένο whois του WHMCS (port 43), όχι από custom κώδικα ή από το module του Openprovider,
- **checker εκτός order form** (για το CMS/portal).

Το order form δεν έχει hook ή event για προτάσεις: δείχνει μόνο τις γραμμές που φτιάχνει το ίδιο (όνομα × επιλεγμένα TLDs,
`order_type_domain.php`)· ό,τι επιπλέον επιστρέψει το `bulkCheckAvailability` αγνοείται. Γι' αυτό χρειάζεται δική μας
σελίδα, σε plugin.

Premium domains: **εκτός** (απόφαση 2026-10-10).

## Κομμάτια

### 1. myopenprovider: `getDomainSuggestions($term, array $tlds = [], array $options = [])`

- Openprovider `POST /v1beta/domains/suggest-name` με `name`, `limit`, `language`, `sensitive`, `tlds`
  (ίδιο endpoint με το WHMCS module του Openprovider: `suggestNameDomainRequest`, 9 προτάσεις, ρυθμίσεις γλώσσας και
  «block explicit language»).
- Μετρήθηκε στον λογαριασμό μας: 2–4 s ανά κλήση. Το φίλτρο `tlds` **δεν είναι αυστηρό** (ζητήθηκαν com/eu/net/org,
  ήρθε και `.games`) και οι προτάσεις **δεν ελέγχονται για διαθεσιμότητα** (το WHMCS module τις δείχνει όλες ως
  διαθέσιμες χωρίς έλεγχο).
- Η μέθοδος επιστρέφει πεζά ονόματα (`greekbakery.net`), χωρίς διπλά, φιλτραρισμένα στα `$tlds` όταν δοθούν. Σε σφάλμα
  API: `[]` και log, όχι σφάλμα (η σελίδα δουλεύει χωρίς προτάσεις).
- Γλώσσα και `sensitive` έρχονται από τις ρυθμίσεις του plugin (`$options`)· default `eng` και `true`. Το Openprovider
  δεν έχει ελληνικά στις γλώσσες προτάσεων.

### 2. Plugin `domain_tools`

Δημόσια σελίδα στο client portal (χωρίς login), με το layout του portal (όπως το CMS plugin), και σύνδεσμος στο μενού.

- **Αναζήτηση**: όνομα ή πλήρες domain. Τα TLDs είναι όσα πουλάει το Blesta (Domains plugin, ενεργά, με τιμή στο νόμισμα
  του πελάτη)· προεπιλογή τα "spotlight" TLDs του Domains plugin (`domains_spotlight_tlds`). Διαθεσιμότητα με το
  `bulkCheckAvailability` του module κάθε TLD (μία κλήση ανά module). Τιμές από το TLD pricing.
- **Προτάσεις**: φορτώνουν μετά (AJAX), από το module που ορίζεται στις ρυθμίσεις (myopenprovider) αν έχει
  `getDomainSuggestions`. Κρατάμε μόνο TLDs που πουλάμε και περνούν από `bulkCheckAvailability`: φαίνονται μόνο όσα είναι
  πράγματι ελεύθερα, με τιμή.
- **Παραγγελία**: κουμπί ανά διαθέσιμο domain που κάνει POST (`domain`, `tlds[]`, CSRF token της ίδιας συνεδρίας) στο
  `order/config/preconfig/<order form>`: το order form δέχεται μόνο POST `domain`/`tlds`. Ρύθμιση: ποιο order form.
- **Whois** για κατειλημμένα domains: κάρτα με registrar, ημερομηνίες (εγγραφή, λήξη, τελευταία αλλαγή), status,
  nameservers, DNSSEC.
  - **RDAP** (JSON) με τον κατάλογο της IANA (`https://data.iana.org/rdap/dns.json`, cache 1 μέρα): gTLDs και πολλά ccTLDs.
    Τα προσωπικά στοιχεία τα κόβει ήδη το RDAP· εμείς δείχνουμε μόνο registrar και τεχνικά πεδία.
  - **Port 43** όπου δεν υπάρχει RDAP: ο whois server του TLD από `whois.iana.org` (`whois:`), raw κείμενο με escape,
    χωρίς emails/τηλέφωνα (μάσκα).
  - **.gr**: όχι εδώ· θα έρθει από το grepp module (FORTH). Μέχρι τότε μήνυμα «δεν υποστηρίζεται ακόμα».
- **Anti-abuse**: όριο αναζητήσεων ανά IP (π.χ. 30/10 λεπτά για έλεγχο, 10/10 λεπτά για whois), πίνακας
  `domain_tools_hits`, καθάρισμα με cron· cache αποτελεσμάτων (διαθεσιμότητα 5 λεπτά, whois 1 ώρα, προτάσεις 1 ώρα).
  Captcha (του Blesta, αν έχει ρυθμιστεί) όταν ξεπεραστεί το όριο: φάση 2.
- **Ρυθμίσεις (admin)**: module προτάσεων, γλώσσα και «block explicit», πλήθος προτάσεων, order form, όρια, ενεργό whois.
- Strings σε `language/en_us` και `language/el_gr`.

### Κώδικας εκτός Blesta (με unit tests)

`lib/domain-tools/src` (namespace `Nixpal\DomainTools`), symlink `plugins/domain_tools/lib` όπως στο nixpal_licensing:

- `DomainName`: κανονικοποίηση εισόδου (πεζά, IDN → punycode, χωρίς `http://`/`www.`), διάσπαση σε όνομα + TLD με
  βάση τη λίστα TLDs που πουλάμε (πιο μακρύ ταίριασμα: `co.uk` πριν `uk`).
- `Suggestions`: φιλτράρισμα/διπλά/όριο των προτάσεων.
- `RdapBootstrap`, `RdapClient`, `RdapRecord`: κατάλογος IANA, ερώτημα, ανάλυση σε απλή δομή.
- `WhoisClient` (port 43) με μάσκα προσωπικών στοιχείων.
- `RateLimiter` πάνω σε store interface (στο Blesta: πίνακας DB).
- HTTP/sockets πίσω από interfaces, για tests χωρίς δίκτυο.

## PRs

1. myopenprovider `getDomainSuggestions` + αυτό το doc.
2. Plugin `domain_tools` (lib + plugin + tests), deploy στο staging.

## Κατάσταση (2026-10-10)

Εγκατεστημένο στο staging (cs.nixpal.com, plugin id 11) από το branch του PR. Δοκιμάστηκαν πραγματικά:
- αναζήτηση `nixpal`: 8 TLDs με τιμές EUR, το nixpal.com «κατοχυρωμένο» με Whois/Μεταφορά·
- προτάσεις: `nixpal` → nixpals.com/.net/.eu…, `greek bakery` → greek-bakery.com, greekbakery.org/.uk… (3.8–5.5 s, μόνο
  ελεύθερα σε TLDs που πουλάμε)·
- whois: nixpal.com και google.com από RDAP (registrar, ημερομηνίες, NS, DNSSEC), .eu από port 43 (whois.eu)·
- «Παραγγελία»: το POST περνά στο order form → «Congratulations, that domain is available», με την τιμή·
- όρια ανά IP (πίνακας `domain_tools_hits`), επικύρωση ρυθμίσεων, σύνδεσμος «Domain Search» στο μενού.

Το WAF του server (CFM) κόβει με challenge αιτήματα με `<script>`/`onmouseover=` πριν φτάσουν στο plugin.

**Εύρημα για το H2 (μεταφορές):** το nixpal.com στο μητρώο δείχνει σε ns1–3.nixpal.com (glue), ενώ η ίδια η zone δηλώνει
ns1–3.myip.gr (ίδιες IPs). Το `transferNameServers` διαβάζει τα NS της zone (DNS), άρα στη μεταφορά θα έστελνε τα myip.gr.
Χωρίς διακοπή (ίδιοι servers), αλλά αλλάζει τα ονόματα της delegation. Για να μείνει ακριβώς ίδια: γράψτε τα NS στη φόρμα
μεταφοράς. Βελτίωση για αργότερα: προτίμηση της delegation του μητρώου (RDAP) αντί για τα NS της zone.

## Ανοιχτά

- Captcha μετά το όριο (φάση 2).
- .gr whois μέσω grepp.
- Σύνδεσμος/κουτί στο CMS: το CMS δέχεται HTML· ένα μικρό form που κάνει GET στη σελίδα του plugin αρκεί.
