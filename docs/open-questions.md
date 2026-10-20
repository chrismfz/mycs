# Ανοιχτά ερωτήματα και σκέψεις (fork upstream)

## Απαντημένα (2026-10-11)
1. **Repo/όνομα:** **MyCS** («my client system»), όνομα εργασίας. Νέο repo. Κρατάμε το MIT copyright notice του upstream.
2. **PayPal vault tokens:** **μεταφέρονται.** Ήδη ελεγμένο στις 2026-10-08 (`history/blesta-modules/importer.md`): με τα REST
   credentials του ppcpv, 13 από τα 15 ενεργά tokens απαντούν στο `GET /v3/vault/payment-tokens/{id}` (ένα είναι
   σβησμένο στο PayPal, ένα είναι παλιό μη-JSON). Δένονται με τον merchant και τα credentials, όχι με το WHMCS. Ίδια
   credentials στο MyCS → καμία νέα έγκριση. Η λογική είναι στο `lib/paypal-vault`.
3. **Φορολογικά της nixpal:** **όχι μέσω Ekdosi.** Ο λογιστής (Εσθονία) θέλει μόνο ένα Excel εσόδων/εξόδων στο τέλος
   της χρονιάς. Η Φάση 1 δεν χρειάζεται bridge, χρειάζεται όμως **export εσόδων ανά έτος** (CSV/XLSX: πληρωμές,
   τιμολόγια, refunds), που το upstream δεν έχει.
4. **Instances:** **ένα instance ανά εταιρεία** (nixpal, myip). Καμία multi-company λογική.
5. **Blesta staging:** το `cs.nixpal.com` **μένει ως έχει** (δεν το αγγίζουμε, δεν το συντηρούμε). Νέο staging για
   το MyCS, π.χ. `mycs.nixpal.com`.
6. **Κοινός κώδικας με το Ekdosi:** απόφαση Claude, παρακάτω.

## Ανοιχτά
- **CNIC της nixpal (4 domains):** driver CNIC πριν το cut-over ή μεταφορά τους στο Openprovider; Ο driver
  χρειάζεται ούτως ή άλλως για το myip (209).
- ~~**Παραγωγή της nixpal**~~ **απαντήθηκε (2026-10-20):** όταν είμαστε σίγουροι, στον **orion**, δίπλα στο WHMCS, ως
  `mycs.nixpal.com`· στο migration το `clients.nixpal.com` περνά στο MyCS (στον ίδιο server: αλλαγή vhost, όχι IP· το
  `zlic.php` μένει στο ίδιο URL). Mail από το SMTP που χρησιμοποιεί σήμερα το WHMCS (`support@nixpal.com`, «NixPal»· τα
  στοιχεία τα βάζει ο υπεύθυνος στο `.env`). Off-site backups σε δικό μας server (ποιος: ανοιχτό). Έλεγχος του orion
  (μόνο αναγνώσεις): CloudLinux 8, `ea-php84` με τα extensions του MyCS, MariaDB 11.4, ο λογαριασμός `clnixpal` με
  CageFS πίσω από angie, χωρίς composer. Τίποτα δεν στήνεται εκεί πριν το πει ο υπεύθυνος.
- **Ρόλοι staff (για αργότερα):** στο WHMCS του myip υπάρχουν 5 ρόλοι, ενεργοί admins μόνο σε 2: Full Administrator (3)
  και Support Operator (1)· Sales Operator χωρίς ενεργό admin (2026-10-11). Ένας ρόλος ανά χρήστη αρκεί, άρα κρατάμε το
  σύστημα του upstream (ρόλος με λίστα δικαιωμάτων) και όχι `spatie/laravel-permission` όπως στο Ekdosi.

## Κοινός κώδικας: απόφαση
**Δύο κατηγορίες, δύο τρόποι:**

| Κώδικας | Τρόπος | Γιατί |
|---|---|---|
| Τα καθαρά `lib/*` αυτού του repo (`nixpal-licensing`, `cardlink-eurobank`, `paypal-vault`, `domain-tools`, `myopenprovider`, `mailcow`, μελλοντικό `gr-epp`) | **Μεταφέρονται στο repo του MyCS ως `packages/<όνομα>`** (monorepo, composer `path` repositories). Το MyCS γίνεται το σπίτι τους. | Είναι ήδη ανεξάρτητα από framework, με δικό τους `composer.json` και tests. Monorepo = **όχι** repo ανά package. Αν κάποτε τα χρειαστεί και το Ekdosi, γίνεται split τότε. |
| Λογική του Ekdosi (domains, tickets, υπενθυμίσεις, `PaymentAllocator`, MCP) | **Αντιγραφή με προσαρμογή** στο MyCS, όχι κοινό package (προς το παρόν). | Είναι δεμένη με `company_id` και φορολογικό `Invoice`. Για package θα έπρεπε **πρώτα** να ξεδεθεί μέσα στο Ekdosi, δηλαδή refactor σε εφαρμογή παραγωγής που εκδίδει myDATA, πριν ξέρουμε τι σχήμα θέλει το MyCS. Αντιγράφουμε, τα αφήνουμε να «κάτσουν», και εξάγουμε κοινό package μόνο αν η διπλή συντήρηση πονέσει. |

Κανόνας στο MyCS (όπως στο CLAUDE.md εδώ): λογική χωρίς εξάρτηση από framework → `packages/`, με δικά της tests·
λεπτά adapters στο `app/` και στα `extensions/`.

## Σκέψεις και ρίσκα
- **Το μεγαλύτερο άγνωστο στις εκτιμήσεις:** πόσο κοστίζει το port των domains και των tickets από το `company_id`
  και το φορολογικό `Invoice` του Ekdosi σε προτιμολόγιο upstream. Να μετρηθεί πρώτο, σε ένα spike 1–2 ημερών.
- **Ο .gr EPP** είναι το μακρύτερο κομμάτι και δεν εξαρτάται από την πλατφόρμα. Να ξεκινήσει παράλληλα: whitelist
  IP στο UAT του Μητρώου, `lib/gr-epp`, driver του `DomainRegistrar`.
- **Hard fork = εμείς οι συντηρητές.** Upgrades του Laravel/Filament/Livewire είναι πλέον δική μας ευθύνη. Η
  κουλτούρα tests του Ekdosi είναι η ασφάλειά μας.
- **Portal:** χρειάζεται σύγκριση στην οθόνη (screenshots δίπλα-δίπλα) πριν αποφασίσουμε ποιες σελίδες κρατάμε από ποιο.
- **Υπενθυμίσεις λήξης υπηρεσίας και domain** (10/5 ημέρες πριν, όπως στο WHMCS) διαφέρουν από τις υπενθυμίσεις
  ανεξόφλητου τιμολογίου του Ekdosi. Χρειάζονται και τα δύο.
- **Αλλαγή κύκλου χρέωσης** (μήνας → έτος) δεν υπάρχει ούτε στο Ekdosi ούτε στο upstream. Είναι νέα δουλειά (`decisions.md` §7).
