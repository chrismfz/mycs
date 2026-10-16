> Από το branch `spike/domains` (2026-10-11), που δεν γίνεται merge. Το σχέδιο: [`docs/domains.md`](../domains.md).

# Spike: port των domains από το Ekdosi (2026-10-11)

> Ερώτημα (ROADMAP Φάση 0, `open-questions.md`): πόσο κοστίζει το port του υποσυστήματος domains του Ekdosi
> (multi-tenant `company_id`, φορολογικό Invoice, `ServiceContract`) στο MyCS (ένα instance ανά εταιρεία,
> προτιμολόγιο, `Service`). Branch `spike/domains`.

## Τι μεταφέρθηκε
Contract `DomainRegistrar` και DTOs, `DomainStatus`, models (domain, nameservers, contacts, registrar connection,
registrar log), `OpenproviderRegistrar`, `DomainSyncService`, `DomainTransferService`, `DomainRenewalService`,
factory/registry/null registrar, migration, `config/domains.php`, listener ανανέωσης στην πληρωμή.

| Κομμάτι | Γραμμές | Αλλαγές |
|---|---|---|
| `OpenproviderRegistrar` | 884 | **0** |
| Contract `DomainRegistrar` + DTOs | ~420 | **0** |
| `DomainSyncService` | 214 | ~6 (`company_id`, 2 μικροδιορθώσεις phpstan) |
| `DomainTransferService` | 144 | ~2 (`company_id`, cross-tenant guard) |
| `DomainRenewalService` | 350 | ~105: η είσοδος (τιμολόγιο → υπηρεσίες) ξαναγράφτηκε· **ο πυρήνας adopt-or-renew αυτούσιος** |
| Models | ~430 | `company_id`, customer→user, ServiceContract→Service, χωρίς activity log/notes/attachments |

**Tests:** 56 του Ekdosi → **45 μεταφέρθηκαν**, + 3 νέα για το on-paid = **48, όλα πράσινα** (σύνολο suite 345).
Χρειάστηκαν μόνο μηχανικές αλλαγές (Company/TLD scaffolding, `ServiceContract`→`Service`, `Invoice` → προτιμολόγιο
με item). Έφυγαν 11: cross-tenant (4: δεν υπάρχει στο MyCS), ο κύκλος έκδοσης/επαναφοράς του φορολογικού
τιμολογίου (6: δεν υπάρχει, η ανανέωση γίνεται στην πληρωμή), το command `domains:sync` (1: δεν μεταφέρθηκε ακόμα).
phpstan καθαρό, χωρίς baseline.

## Τι μάθαμε
1. **Ο κώδικας του Ekdosi μεταφέρεται σχεδόν αυτούσιος.** Το `company_id` ήταν μηχανική αφαίρεση· ο driver του
   Openprovider, το sync και το transfer δεν άλλαξαν. Η «αντιγραφή με προσαρμογή» (`open-questions.md`) επιβεβαιώνεται.
2. **Το πραγματικό εμπόδιο είναι στο Paymenter, όχι στο Ekdosi.** Το Paymenter δεν ξέρει **ποια περίοδο πληρώνει**
   ένα τιμολόγιο: στην πληρωμή μετακινεί το `services.expires_at` (`RenewServiceService`) και μετά στέλνει το
   `Invoice\Paid`. Η ανανέωση του domain χρειάζεται τον μετρητή χρέωσης (Ekdosi: `ServiceContract.next_due_date`),
   αλλιώς ένα domain που ανανεώθηκε με το χέρι πριν την πληρωμή **ανανεώνεται δεύτερη φορά** (το bug του WHMCS).
   Το spike πρόσθεσε `invoice_items.period_start` (το γράφει το cron) — είναι η μικρή εκδοχή του «ανανέωση δεμένη με
   τιμολόγιο και περίοδο» της Φάσης 1 (`audit-billing.md`). **Τα domains εξαρτώνται από αυτή τη διόρθωση.**
3. **On-paid αντί για on-issue.** Στο Ekdosi η ανανέωση γίνεται στην έκδοση του φορολογικού· στο MyCS στην πληρωμή
   του προτιμολογίου (ROADMAP Φάση 2, default). Αποτυχία στον registrar **δεν** χαλάει την πληρωμή: γράφεται
   `failed` στο `domain_registrar_logs` για retry.
4. **Τι δεν έγινε (το υπόλοιπο κόστος της Φάσης 2):** πίνακας TLD (δρομολόγηση ανά TLD + τιμές, `domain_tlds`),
   admin UI (Filament: domains, συνδέσεις registrar, TLD), `domains:sync` command + schedule, importer από WHMCS,
   driver CNIC, registration, management (NS/lock/privacy/contacts), portal πελάτη, ειδοποιήσεις λήξης.

## Εκτίμηση
Ο πυρήνας (registrar, sync, transfer, renewal) είναι ουσιαστικά **έτοιμος**· το κόστος της Φάσης 2 είναι κυρίως
UI, TLD/τιμές, importer και CNIC. Η εκτίμηση 2–4 εβδομάδες στέκει, προς το κάτω όριο για Openprovider-only, με
προϋπόθεση το «ανανέωση δεμένη με περίοδο» της Φάσης 1. Η ίδια προσέγγιση (αντιγραφή με προσαρμογή) αναμένεται να
δουλέψει και για tickets/υπενθυμίσεις.

## Live δοκιμή (nixpal.com, CNIC → Openprovider)
Σε βήματα, με τα credentials **κρυπτογραφημένα στη βάση του devbox** (ποτέ στο repo/logs/chat):
1. μόνο ανάγνωση: login, check διαθεσιμότητας/κατάστασης, τιμή μεταφοράς·
2. **ρητή έγκριση** πριν το `transferIn` (χρεώνει ανανέωση ενός έτους, δεν αναιρείται)·
3. παρακολούθηση με sync μέχρι να ολοκληρωθεί· έλεγχος nameservers, contacts, ημερομηνιών.

### Αποτέλεσμα (2026-10-11)
Η μεταφορά ξεκίνησε (`REQ`, €9.80). Τα read-only (login, availability, τιμές) δούλεψαν
αμέσως· η πρώτη **εγγραφή** στον registrar έβγαλε τρία bugs του driver του Ekdosi, που προφανώς δεν είχε γράψει ποτέ live:
1. **Κενό `area_code`** στη δημιουργία επαφής → 500 «Invalid telephone region code». Διόρθωση: τα 3 πρώτα ψηφία.
2. **Nameservers μέσα στο ίδιο domain** (`ns1.nixpal.com`) χωρίς IP → 500 «IP is not specified». Διόρθωση: glue IP
   από το τρέχον DNS· άρνηση **πριν** το χρεωμένο POST αν δεν επιλύεται. (Το ίδιο είχε πατήσει και το WHMCS: αν
   παραλειφθούν οι NS, το Openprovider βάζει τους δικούς του και πέφτουν τα sites. Γι' αυτό στέλνουμε **πάντα** ρητά
   τους τρέχοντες NS στη μεταφορά.)
3. **Προσωρινή λήξη** σε `REQ` (σήμερα + 1 έτος) έγραφε πάνω στην πραγματική. Διόρθωση: όσο το domain είναι pending,
   το sync δεν υιοθετεί λήξη· την παίρνει με το `ACT`.

Οι δύο πρώτες αποτυχίες έγιναν πριν τη χρέωση (log `failed`, χωρίς διπλή επαφή). Οι NS στο registry δεν άλλαξαν.
Ο registrant δημιουργήθηκε ως νέο handle (κανένα domain της nixpal δεν ήταν ακόμα στο Openprovider).
**Ολοκλήρωση:** approve στο CNIC → η μεταφορά εκτελέστηκε σε ~35″ (16:04:31 → 16:05:05 UTC). Το επόμενο sync βρήκε
`ACT` και πέρασε το domain σε `active`, λήξη **2027-11-17** (2026-11-17 + 1 έτος), NS ns1/2/3.nixpal.com, registrant σε νέο handle. Η ροή «pending_transfer → sync → active με νέες ημερομηνίες» δουλεύει χωρίς χειροκίνητη επέμβαση.
Μάθημα: οι drivers θέλουν **ένα live write test ανά ενέργεια** πριν το cut-over, όχι μόνο mocked tests.
