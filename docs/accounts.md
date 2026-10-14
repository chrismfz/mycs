# Λογαριασμοί: staff, multi-user, προφίλ πελάτη

> Κατάσταση: **σχέδιο με αποφάσεις (2026-10-14)** (σημείωση 2026-10-12). Γίνεται **αμέσως μετά τα MT-x** (`money-trail.md`) και **πριν
> τα migrations/cut-over της nixpal**, όσο δεν υπάρχουν ζωντανά δεδομένα. Ο importer (`importer.md`) και το bridge
> προς Ekdosi χτίζονται πάνω στο σχήμα που θα αποφασιστεί εδώ.

## Αρχή: parity με το WHMCS (για «αναίμακτο» migration)
Το σχήμα (users, λογαριασμοί πελάτη, στοιχεία εταιρείας, contacts, staff) σχεδιάζεται ώστε **κάθε πεδίο και σχέση του
WHMCS να έχει ένα σαφές σπίτι εδώ**. Ο importer τότε κάνει απλή αντιστοίχιση, όχι μετάφραση, και ο έλεγχος parity
(`importer.md`) βγαίνει 1:1. Επεκτάσεις επιτρέπονται· απώλειες όχι.

| WHMCS | Εδώ (προς σχεδιασμό) |
|---|---|
| `tblclients` (στοιχεία, `companyname`, `tax_id`, διεύθυνση, `groupid`, `status`, `language`, `currency`, `credit`, `notes`, email preferences) | λογαριασμός πελάτη + προφίλ |
| `tblusers` (login, 2FA, γλώσσα) | users (login) |
| `tblusers_clients` (`owner`, `permissions`) | user ↔ λογαριασμός με ρόλο/δικαιώματα |
| `tblcontacts` (επαφές/παλιοί sub-accounts, email preferences) | επαφές του λογαριασμού |
| `tblcustomfields` / `tblcustomfieldsvalues` (type `client`: ΑΦΜ, ΔΟΥ, «Θα ήθελα τιμολόγιο» κ.λπ.) | πραγματικά πεδία όπου είναι βασικά, αλλιώς custom fields |
| `tblclientgroups` | ομάδες πελατών |
| `tbladmins` / `tbladminroles` | staff / ρόλοι |

- Τα **IDs πελατών του WHMCS κρατιούνται** (όπως τα IDs τιμολογίων), ώστε αναφορές, σημειώσεις και το Ekdosi να
  δείχνουν στο ίδιο νούμερο. Να επιβεβαιωθεί στον σχεδιασμό.
- Πριν τον σχεδιασμό: **απογραφή** (μόνο aggregate SELECTs, read-only) ποια πεδία και custom fields χρησιμοποιούνται
  πραγματικά σε nixpal και myip, με πλήθος μη κενών τιμών. Τα αχρησιμοποίητα δεν μεταφέρονται.
- Οι όροι στο admin ακολουθούν το WHMCS (Client, User, Contact, Company Name, Tax ID), όπως και στη φόρμα υπηρεσίας.

## Απογραφή (2026-10-14)
Μόνο aggregate SELECTs, read-only: myip (rigel, `myipgr_clients`) και nixpal (orion, `clnixpal_nixcldb`). «Μη κενά» =
όχι NULL, `''`, `0` ή μηδενική ημερομηνία.

### Πελάτες (`tblclients`)
| | myip | nixpal |
|---|---|---|
| Πελάτες (ids) | 900 (2–2078) | 233 (1–260) |
| Status | Active 552, Inactive 348 | Active 31, Inactive 200, Closed 2 |
| `companyname` | 641 | 159 |
| `tax_id` (πεδίο WHMCS) | 1 | 44 (27 στην ΕΕ, όλοι taxexempt: reverse charge) |
| `taxexempt` | 11 | 30 |
| Διεύθυνση (`address1`/`city`/`postcode`/`country`) | ~895 | ~232 |
| `address2` / `state` | 90 / 855 | 62 / 232 |
| `phonenumber` | 883 | 231 |
| Χώρα | GR 849, CY 10, GB 8, US 6, … | US 35, GB 22, CA 13, NL 12, … (διεθνείς) |
| `language` | greek 248, english 37, default 614 | english 29, άλλες 6, default 198 |
| `currency` | 1 νόμισμα (+1 πελάτης σε δεύτερο) | ίδιο |
| `credit` > 0 | 22 | 11 |
| `notes` (πεδίο) / `tblnotes` | 127 / 13 σε 6 πελάτες | 3 / 2 |
| `groupid` | 38 (ομάδες με `separateinvoices`, 1 με `susptermexempt`) | 3 (ομάδες έκπτωσης 10% / 50%) |
| `defaultgateway` | 128 | 9 |
| `latefeeoveride` / `overideduenotices` / `separateinvoices` | 2 / 18 / 3 | 0 / 0 / 0 |
| `email_preferences` (JSON general/invoice/support/product/domain/affiliate) | 695 (λίγοι με κάτι κλειστό) | 230 (3 μόνο domain) |
| `emailoptout` / `marketing_emails_opt_in` | 341 / 558 | 76 / 156 |
| `billingcid` (επαφή χρέωσης) | 1 | 0 |
| Αποθηκευμένη κάρτα στο `tblclients` (παλιό σχήμα) | 1 | 0 |
| `tblclientsfiles` | 18 | 1 |
| Affiliates | 5 | 0 |

### Custom fields πελάτη (`tblcustomfields` type `client`)
| Πεδίο (myip) | Τύπος | Τιμές |
|---|---|---|
| Θα ήθελα να εκδοθεί τιμολόγιο | tickbox | 595 `on` (586 από αυτούς με ΑΦΜ, 568 με ΔΟΥ) |
| ΑΦΜ / Vies Vat No | text, εμφανίζεται στο τιμολόγιο | 644 |
| ΔΟΥ | text | 624 |
| Δραστηριότητα επιχείρησης | text | 595 |
| Σημειώσεις | textarea | 52 |
| «Γκρινιάρης» = **άμεση τιμολόγηση** (το Ekdosi κόβει χωρίς αναμονή, `needs_immediate_invoice`) | tickbox, admin only | 415 `on` |

Η nixpal **δεν έχει** custom fields πελάτη· χρησιμοποιεί το `tax_id` του WHMCS (ΑΦΜ/VAT διεθνών). Δηλαδή το ίδιο
στοιχείο ζει σε δύο διαφορετικά σημεία ανάλογα με την εταιρεία.

### Users και πρόσβαση (`tblusers`, `tblusers_clients`)
| | myip | nixpal |
|---|---|---|
| Users | 1.007 | 247 |
| Users χωρίς πελάτη | 108 (93 είχαν login κάποτε, 2 στους 12μ) | 3 |
| Owner ανά πελάτη | ακριβώς 1 σε όλους | ακριβώς 1 σε όλους |
| Μη-owner συνδέσεις | 101 (76 με όλα τα δικαιώματα) | 14 (12 με όλα) |
| Πελάτες με >1 user | 93 (88 με 2, έως 5) | 11 |
| Users με >1 πελάτη | 33 (ένας με 29, ένας με 17) | 2 |
| Users που είναι owner αλλού και μη-owner εδώ | 14 | – |
| 2FA (`second_factor`) | 10 | 1 |
| Invites | 1 | 0 |

Δικαιώματα του WHMCS: `profile, contacts, products, manageproducts, productsso, domains, managedomains, invoices, quotes,
tickets, affiliates, emails, orders`. Στην πράξη: «όλα» ή μικρά υποσύνολα (π.χ. μόνο `invoices`, ή
`products,productsso,managedomains`).

### Επαφές (`tblcontacts`)
| | myip | nixpal |
|---|---|---|
| Επαφές | 434 σε 140 πελάτες (ένας πελάτης έχει 79) | 17 σε 11 πελάτες |
| `companyname` / `tax_id` | 143 / 46 | 11 / 3 |
| Πλήρης διεύθυνση | ~334 | 17 |
| Με ίδιο email όπως κάποιος user | 141 | 13 |
| Παλιοί sub-accounts (`password`) | 231 (`subaccount` = 0 παντού· μετά το WHMCS 8 έγιναν users) | 0 |
| Παραλήπτες emails (invoice / general / support / domain / product) | 108 / 92 / 95 / 88 / 88 | 7 / 4 / 3 / 3 / 3 |

### Ekdosi bridge (μόνο myip)
- `mod_ekdosi_contacts`: ~180 επαφές τιμολόγησης σε 68 πελάτες (ένας πελάτης έχει 62). Επωνυμία, ΑΦΜ (σχεδόν όλες),
  VIES 22, ΔΟΥ, διεύθυνση, email 124, τηλέφωνο 61, σχόλια. Μόνο **13** έχουν το ίδιο ΑΦΜ με τον πελάτη· οι υπόλοιπες
  είναι πραγματικά **άλλοι αποδέκτες** (π.χ. ο πελάτης του reseller). Ένα ΑΦΜ εμφανίζεται σε δύο πελάτες. Το
  `legacy_contact_id` δείχνει στο παλιό plugin, όχι στο `tblcontacts`.
- `mod_ekdosi_routing`: ~180 αναθέσεις «υπηρεσία → επαφή»: domains 140, hosting 44 (9 σε υπηρεσίες που έληξαν).
  `is_receipt` (απόδειξη αντί τιμολογίου) σε 2. 71 επαφές δεν έχουν ανάθεση (χρησιμοποιούνται χειροκίνητα ή είναι
  παλιές). Όλες οι αναθέσεις δείχνουν σε επαφή του ίδιου πελάτη.
- Επίσης `mod_ekdosi_bridge_log` (~12k) και `mod_ekdosi_invoice_marks` (61): ιστορικό του bridge, όχι λογαριασμοί.

### Staff (`tbladmins`)
- myip: 4 ενεργοί (3 Full Administrator, 1 Support Operator), 1 ανενεργός· **όλοι οι ενεργοί με 2FA**. Ρόλοι: Full,
  Sales, Support και 2 custom. 2 admins έχουν το ίδιο email με user πελάτη.
- nixpal: 4 ενεργοί (3 Full, 1 Sales), **κανένας με 2FA**· 1 admin με το ίδιο email με user πελάτη.

### Τι σημαίνει για τον σχεδιασμό
1. **User ≠ πελάτης είναι πραγματικό:** 33 users (myip) βλέπουν πολλούς λογαριασμούς, ως 29. Ο σημερινός importer
   φέρνει μόνο τον owner και χάνει τις 101/14 επιπλέον συνδέσεις και τους 108 users χωρίς πελάτη.
2. **IDs πελατών:** κρατιούνται (ίδια με `tblclients.id`). Τα IDs των users είναι **άλλη αρίθμηση**, που σήμερα
   συμπίπτει τυχαία. Ο importer ήδη γράφει `users.id = tblclients.id`, και στον νέο σχεδιασμό αυτό γίνεται
   `clients.id`.
3. **ΑΦΜ σε δύο σημεία** (custom field στο myip, `tax_id` στη nixpal): εδώ ένα πραγματικό πεδίο και ο importer
   διαλέγει την πηγή ανά instance.
4. **«Θέλω τιμολόγιο»** χρησιμοποιείται πολύ (595)· είναι το είδος παραστατικού της προεπιλεγμένης επαφής
   τιμολόγησης (σημείο 4). Το `is_receipt` του bridge είναι το ίδιο ανά υπηρεσία.
5. **Επαφές:** `tblcontacts` (κυρίως παραλήπτες emails) και `mod_ekdosi_contacts` (αποδέκτες τιμολόγησης) είναι
   σχεδόν ξένα σύνολα· γίνονται ένα: επαφή με ρόλους. Η ενοποίηση ανά ΑΦΜ αφορά λίγες περιπτώσεις.
6. **«Άμεση τιμολόγηση»** (το custom field «Γκρινιάρης», 415) και ομάδες πελατών (`separateinvoices`, έκπτωση, `susptermexempt`) θέλουν
   σπίτι.
7. **Staff 2FA:** στη nixpal κανείς δεν έχει· εδώ θα είναι διαθέσιμο, όχι υποχρεωτικό (απόφαση 3).
8. **Δεν μεταφέρονται** (χωρίς χρήση): security questions, παλιά πεδία κάρτας/τράπεζας στο `tblclients`
   (1 εγγραφή), `authmodule`, `pwresetkey`, invites.
9. **Bugs του σημερινού importer** (θα ξαναγραφτεί με το νέο σχήμα):
   - `importAdmins()` μαζεύει τους admins αλλά δεν τους γράφει πουθενά;
   - τα custom properties ελέγχονται με το κλειδί του MyCS αντί του WHMCS (`isset($record[$key])`) και η αντιστοίχιση
     είναι ελλιπής, οπότε τηλέφωνο, επωνυμία, διεύθυνση (`address1`) και ΤΚ δεν μεταφέρονται ποτέ (περνούν μόνο
     `address2`, `city`, `state`, `country`)· τα custom fields του WHMCS (ΑΦΜ, ΔΟΥ κ.λπ.) δεν διαβάζονται καθόλου;
   - πελάτης χωρίς owner παραλείπεται σιωπηλά (σήμερα 0 τέτοιοι).

## Σήμερα (κληρονομιά Paymenter)
- Ένας πίνακας `users` για όλους. Admin = user με `role_id` (`User::canAccessPanel()`).
- Ίδιο login, session και «ξέχασα τον κωδικό» για πελάτες και staff· τίποτα δεν υποχρεώνει το staff σε 2FA.
- Χρήστης = πελάτης: ένας login βλέπει μόνο τον εαυτό του, χωρίς χρήστες με πρόσβαση σε πολλούς λογαριασμούς.
- Προφίλ πελάτη: όνομα, email και ό,τι custom properties οριστούν. Δεν υπάρχει έννοια επιχείρησης, ΑΦΜ/VAT, ΔΟΥ ή
  «θέλω τιμολόγιο».
- Ο importer φέρνει τους admins του WHMCS (`tbladmins`, roleid 1) στον ίδιο πίνακα και παραλείπει όποιον έχει το ίδιο
  email με πελάτη.

## Γιατί αλλάζει
- Ένα πεδίο (`role_id`) χωρίζει πελάτη από admin: λάθος ή bug σε φόρμα δίνει πρόσβαση στο admin.
- Η επιφάνεια επίθεσης του staff είναι ίδια με των πελατών (login, reset, rate limits).
- Λογαριασμός που είναι και πελάτης και staff μπερδεύει audits, money trail («ποιος», `money-trail.md`) και αναφορές.
- GDPR/απενεργοποίηση (`money-trail.md` §9) δεν πρέπει να αγγίζουν staff.
- myip: 33 χρήστες με πρόσβαση σε >1 λογαριασμό, 93 λογαριασμοί με >1 χρήστη· nixpal 14 / 2 (2026-10-11).
- Τα στοιχεία τιμολόγησης (επωνυμία, ΑΦΜ, ΔΟΥ) χρειάζονται για το bridge προς Ekdosi και το myDATA.

## Εύρος
1. **Staff χωριστά από πελάτες** (όπως WHMCS `tbladmins`, Blesta, Ekdosi): δικός τους πίνακας και guard για το admin
   panel (Filament `authGuard()`), δικό τους login, 2FA (διαθέσιμο), roles/permissions μόνο εκεί. Αγγίζει: απαντήσεις
   staff στα tickets, audits («ποιος»), ειδοποιήσεις προς admins, API keys, impersonation, importer.
2. **Ένας χρήστης, πολλοί λογαριασμοί πελάτη** (Users του WHMCS 8): login (user) ↔ λογαριασμός πελάτη (client) με
   ρόλο/δικαιώματα ανά λογαριασμό (μοτίβο του Ekdosi: χρήστης ↔ εταιρείες), πρόσκληση χρήστη, επιλογέας λογαριασμού
   στο client area. Υπηρεσίες, τιμολόγια, πιστώσεις και tickets ανήκουν στον **λογαριασμό**, όχι στον login. Importer
   από `tblusers_clients`.
3. **Προφίλ πελάτη «σαν WHMCS/Ekdosi»:** ιδιώτης/επιχείρηση, επωνυμία, ΑΦΜ/VAT (+ VIES για ΕΕ), ΔΟΥ, δραστηριότητα,
   διεύθυνση χρέωσης, «Θέλω τιμολόγιο» (για αργότερα, myip), γλώσσα, νόμισμα, σημειώσεις staff, σήμανση, κατάσταση
   όπως στο WHMCS (Active / Inactive όταν δεν έχει ενεργές υπηρεσίες / Closed από το staff· `money-trail.md` §8–§9).
   Πελάτης με κινήσεις χρημάτων δεν σβήνεται (ήδη από το MT-1a)· κλείνει. Πραγματικά πεδία με validation, όχι μόνο custom properties. Οργανωμένη
   φόρμα στο admin και στο client area.
4. **Πλήρεις επαφές, και επιχειρήσεις** (απόφαση 2026-10-13). Στο WHMCS ένας πελάτης έχει πολλά contacts, αλλά
   μόνο ένα σύνολο στοιχείων εταιρείας (`companyname`, `tax_id`, custom fields ΑΦΜ/ΔΟΥ). Γι' αυτό το Ekdosi bridge
   κράτησε δικούς του πίνακες (`mod_ekdosi_contacts`, `mod_ekdosi_routing`), ώστε π.χ. reseller/agency να παίρνει
   τιμολόγιο για την υπηρεσία «χ» στο όνομα του πελάτη του «ψ». Εδώ η επαφή είναι **πλήρης οντότητα του
   λογαριασμού**:
   - ιδιώτης ή επιχείρηση, ονοματεπώνυμο, επωνυμία, ΑΦΜ/VAT, ΔΟΥ, δραστηριότητα, διεύθυνση, χώρα, email, τηλέφωνο;
   - χρήσεις: **αποδέκτης τιμολόγησης** (ο λογαριασμός ή μια υπηρεσία, αργότερα και domain, δείχνει σε επαφή
     τιμολόγησης· αλλιώς ισχύει το προφίλ του λογαριασμού), **επαφή domain** (registrant/admin/tech/billing, ό,τι
     ζητά ο registrar ή το EPP του .gr), αποδέκτης emails;
   - το προφίλ του λογαριασμού (σημείο 3) είναι η προεπιλεγμένη επαφή, όχι ξεχωριστό σχήμα.
   - όλα τα πεδία προαιρετικά, με validation όταν συμπληρώνονται (μορφή ΑΦΜ, VIES για ΕΕ)· μόνο επαφή που ζητά
     **τιμολόγιο** χρειάζεται τα ελάχιστα (επωνυμία, ΑΦΜ, ΔΟΥ, διεύθυνση);
   - το **είδος παραστατικού** (τιμολόγιο / απόδειξη / τίποτα) είναι επιλογή της επαφής τιμολόγησης· το MyCS το
     μεταφέρει με τον αποδέκτη και το Ekdosi φιλτράρει, όπως σήμερα το plugin στο WHMCS.

   Έτσι το Ekdosi δεν χρειάζεται δικό του πίνακα επαφών ή routing: διαβάζει τον αποδέκτη του τιμολογίου. Το bridge
   (MARK/ΤΠΥ, write-back) και η αναζήτηση ΑΦΜ στην ΑΑΔΕ χτίζονται αργότερα· το σχήμα τα προβλέπει από τώρα.

## Δύο εταιρείες, δύο ροές παραστατικών (2026-10-14)
- **nixpal (Εσθονία):** το PDF που βγάζει το MyCS **είναι** το invoice· πάει στον λογιστή ή σε ετήσιο report. Χωρίς
  Ekdosi. Αρίθμηση `{YEAR}-{NUMBER}` με proforma. Οι πελάτες είναι κυρίως διεθνείς: χρειάζεται EU VAT με VIES και
  reverse charge, όχι ΔΟΥ/δραστηριότητα.
- **myip (Ελλάδα):** το MyCS βγάζει **προτιμολόγιο**· το φορολογικό παραστατικό κόβεται στο Ekdosi μέσω του bridge
  («Εισερχόμενα»). Μετρούν ο αποδέκτης ανά υπηρεσία, το είδος (τιμολόγιο/απόδειξη), ΑΦΜ, ΔΟΥ, δραστηριότητα, η
  «άμεση τιμολόγηση» και το «τιμολόγιο πριν την πληρωμή».

Ένα σχήμα για όλους, με όλα τα πεδία προαιρετικά. Ανά instance αλλάζουν **ρυθμίσεις**: ποια πεδία ζητούνται, αν
υπάρχει bridge, ποια αρίθμηση (`settings-map.md`).

## Πώς το διαβάζει το Ekdosi σήμερα (κώδικας στο `chrismfz/ekdosi`, production = main `79e5313`)
- Το Ekdosi **τραβάει** τα πληρωμένα τιμολόγια του WHMCS κάθε 5 λεπτά (`whmcs:fetch-pending`, μέσω του plugin
  `resolve.php op=invoices` ή του API) στα «Εισερχόμενα» (`PendingWhmcsInvoice`). Το κουμπί «Send to ekdosi» του
  plugin κάνει το ίδιο για ένα τιμολόγιο.
- **Πελάτης:** ταίριασμα με `whmcs_client_id`, αλλιώς με ΑΦΜ· ποτέ αυτόματη σύνδεση. Τα στοιχεία από τη ΓΓΠΣ
  υπερισχύουν· email και τηλέφωνο έρχονται μόνο από το WHMCS.
- **Custom fields** του WHMCS ανά ρόλο (`whmcs_custom_field_map`): `vatno`, `taxoffice`, `occupation`,
  `wantsinvoice`, `griniaris`. Χωρίς ΑΦΜ ή με `wantsinvoice` = όχι → απόδειξη· «θέλει τιμολόγιο αλλά δεν έχει ΑΦΜ»
  → hold «Αναμονή για ΑΦΜ».
- **Άμεση τιμολόγηση** (`griniaris` → `needs_immediate_invoice`): `whmcs:auto-issue` κόβει χωρίς χειριστή όταν
  όλα είναι καθαρά (ένας αποδέκτης, όχι mass pay, όχι 0% ΦΠΑ κ.λπ.)· τα υπόλοιπα περιμένουν έλεγχο.
- **Τιμολόγιο πριν την πληρωμή** (`needs_invoice_before_payment`): απλήρωτα, επί πιστώσει· όταν πληρωθούν στο Ekdosi,
  το `PushWhmcsPaymentJob` γράφει την πληρωμή πίσω στο WHMCS.
- **Παραστατικά σε τρίτους:** `op=resolve` δίνει ανά γραμμή την επαφή (`mod_ekdosi_routing`, μία επαφή ανά υπηρεσία,
  `is_receipt` ανά ανάθεση). Ένας αποδέκτης → τιμολογείται αυτός (ταίριασμα με ΑΦΜ)· πολλοί → hold και
  «Διαχωρισμός σε προσχέδια» από τον χειριστή. Τα mass-pay/ενοποιημένα κρατιούνται πάντα.
- Ανά γραμμή χρειάζεται `product_id`/`group_id` (κατηγορία εσόδων), περιγραφή, ΦΠΑ· ανά τιμολόγιο gateway,
  transactions, νόμισμα (μόνο EUR), inclusive/exclusive.

**Συμπέρασμα για εδώ:** αν το MyCS βγάζει **ένα προτιμολόγιο ανά αποδέκτη**, με τα στοιχεία του αποδέκτη και το
είδος παραστατικού μέσα, το Ekdosi δεν χρειάζεται ούτε routing ούτε split: ο «διαχωρισμός» γίνεται εδώ, πριν
την πληρωμή, όχι από τον χειριστή μετά.

## Σχέδιο (πρόταση προς έγκριση)

### Τρεις ταυτότητες, τρεις πίνακες
| Πίνακας | Τι είναι | WHMCS | IDs |
|---|---|---|---|
| `staff` | το προσωπικό· μόνο αυτοί μπαίνουν στο admin | `tbladmins` (+ `tbladminroles`) | κρατιούνται |
| `users` | πρόσωπα που κάνουν login στο client area | `tblusers` | κρατιούνται |
| `clients` | ο λογαριασμός πελάτη: υπηρεσίες, τιμολόγια, πιστώσεις, tickets | `tblclients` | κρατιούνται |
| `client_user` | ποιος user βλέπει ποιον λογαριασμό, owner ή με δικαιώματα | `tblusers_clients` | νέα |
| `contacts` | επαφές του λογαριασμού: στοιχεία, αποδέκτες τιμολόγησης, επαφές domain, παραλήπτες emails | `tblcontacts` + `mod_ekdosi_contacts` | νέα + χάρτης παλιών ids |

- Το ίδιο email μπορεί να υπάρχει σε `staff` και `users`: είναι δύο ξεχωριστοί λογαριασμοί και ποτέ δεν
  συγχωνεύονται (σήμερα 2 + 1 τέτοιοι).
- Ό,τι ανήκει στον **λογαριασμό** περνά σε `client_id`: orders, services, invoices, credits, money_events, carts,
  billing_agreements, tickets, email_logs, properties, affiliates. Ό,τι ανήκει στο **login** μένει σε `user_id`:
  sessions, 2FA, auth logs, notification preferences, push subscriptions, OAuth.

### Staff
- Δικός του guard `staff` και provider. Το Filament panel παίρνει `authGuard('staff')` και δικό του login στο
  `/admin/login`. Ξεχωριστά password resets, throttle και session cookie.
- **2FA (TOTP) διαθέσιμο, όχι υποχρεωτικό** (απόφαση 3). Τα 2FA του WHMCS δεν μεταφέρονται (κρυπτογραφημένα με
  κλειδί του WHMCS)· όποιος το θέλει, το στήνει ξανά.
- Οι roles και τα permissions υπάρχουν μόνο εδώ (`staff.role_id`). Το `users.role_id` φεύγει.
- Ό,τι σήμερα δείχνει «ποιος από το staff» γίνεται `staff_id` ή morph: `tickets.assigned_to`, τα API keys, ο causer
  στα audits, ο `actor` στα money_events (υπάρχει ήδη morph), οι συντάκτες των σημειώσεων.
- Ειδοποιήσεις προς staff: σήμερα πάνε σε μία διεύθυνση (`system_email_address`), και μένει έτσι. Ειδοποιήσεις
  ανά μέλος του staff έρχονται αργότερα.

### Users και πρόσβαση σε λογαριασμούς
- `client_user`: `owner` (ακριβώς ένας ανά λογαριασμό), `permissions` με τα ονόματα του WHMCS (`profile`,
  `contacts`, `products`, `manageproducts`, `productsso`, `domains`, `managedomains`, `invoices`, `quotes`, `tickets`,
  `affiliates`, `emails`, `orders`), `last_login_at`. Ο owner έχει όλα.
- Το client area δουλεύει πάντα με έναν **τρέχοντα λογαριασμό** (session) και επιλογέα όταν ο user έχει πολλούς.
  Policies: ο user ανήκει στον λογαριασμό **και** έχει το δικαίωμα.
- Πρόσκληση user σε λογαριασμό (email) και αφαίρεση· ο owner δεν αφαιρείται και αλλάζει μόνο από το staff.
- Από το Ekdosi (`CustomerUserAccess`, ίδιο μοτίβο): η σύνδεση user ↔ λογαριασμός κρατά **ποιος την έδωσε και πότε**
  (`granted_by`, `granted_at`) και **ανακαλείται χωρίς διαγραφή** (`revoked_at`), ώστε να μένει ιστορικό· ισχύουν μόνο
  οι ενεργές.
- **Login as client** (staff): ανοίγει το client area στον λογαριασμό, ως ο owner, με σήμανση. Τα audits γράφουν
  το μέλος του staff ως causer, όχι τον πελάτη.

### Λογαριασμός πελάτη (`clients`)
- Μόνο ό,τι αφορά τον λογαριασμό: `status` (Active / Inactive / Closed, `money-trail.md` §8–§9), `group_id`,
  γλώσσα, νόμισμα, `credit_auto_apply`, `tax_exempt`, **`needs_immediate_invoice`** (Γκρινιάρης),
  **`needs_invoice_before_payment`**, σημειώσεις staff (ξεχωριστός πίνακας, πολλές, με συντάκτη· εκεί πάνε και
  το custom field «Σημειώσεις» και το `tblnotes`).
- `client_groups`: όνομα, χρώμα, ποσοστό έκπτωσης, `separate_invoices`, `suspend_terminate_exempt`.
- Τα στοιχεία ταυτότητας (όνομα, επωνυμία, ΑΦΜ, διεύθυνση…) **δεν** είναι στήλες του `clients`· ζουν στην
  **κύρια επαφή** (ακριβώς μία ανά λογαριασμό). Ένα σχήμα, μία φόρμα, ίδιο validation. Είναι το μοτίβο του Blesta
  (`contacts.contact_type = primary`) και του Odoo (`res.partner`)· το WHMCS είναι η εξαίρεση.
  - **Η βάση εγγυάται το «ακριβώς μία»:** στήλη `contacts.primary_of_client_id` = `client_id` μόνο στην κύρια, NULL
    στις άλλες, με **unique** (η MariaDB δεν έχει partial unique index· τα πολλά NULL επιτρέπονται σε MariaDB και
    SQLite). Ο λογαριασμός δημιουργείται μαζί με την κύρια επαφή του σε ένα transaction.
  - Η κύρια επαφή δεν σβήνεται ούτε αρχειοθετείται· ο ρόλος μεταφέρεται σε άλλη επαφή μέσα σε transaction (πρώτα
    NULL στην παλιά, μετά στη νέα).
  - Λίστες και αναζήτηση: eager loading της κύριας επαφής· αν χρειαστεί, αντίγραφο του ονόματος στον `clients`
    μόνο για αναζήτηση, ποτέ ως πηγή.

### Επαφές (`contacts`)
- Πεδία, όλα προαιρετικά: `type` (ιδιώτης/επιχείρηση), ονοματεπώνυμο, επωνυμία, **`tax_id`** (ελληνικό ΑΦΜ ή
  ξένο), **`vat_number`** (EU VAT για VIES, ξεχωριστό από το ΑΦΜ, όπως `gr_vatno`/`vies_vatno` στο bridge),
  ΔΟΥ, δραστηριότητα, διεύθυνση 1/2, πόλη, νομός, ΤΚ, χώρα (ISO), email, τηλέφωνο, σχόλια.
- **`document_type`**: `invoice` (τιμολόγιο) / `receipt` (απόδειξη) / `none`. Μόνο το myip το χρησιμοποιεί· η κύρια
  επαφή το παίρνει από το «Θα ήθελα τιμολόγιο». Επαφή με `invoice` πρέπει να έχει επωνυμία, ΑΦΜ, ΔΟΥ και διεύθυνση
  (το «Αναμονή για ΑΦΜ» του Ekdosi γίνεται validation εδώ).
- **Παραλήπτης emails** ανά κατηγορία (general, invoice, support, product, domain, affiliate), όπως στο `tblcontacts`.
- Επαφή που χρησιμοποιείται (σε τιμολόγιο ή domain) δεν σβήνεται· **αρχειοθετείται** (`archived_at`).
- Validation όταν συμπληρώνεται: μορφή ΑΦΜ, VIES για EU VAT, ISO χώρα. Αναζήτηση ΑΦΜ στην ΑΑΔΕ αργότερα.

### Αποδέκτης τιμολόγησης και προτιμολόγια
- `services.billing_contact_id` (null = η κύρια επαφή)· αργότερα το ίδιο στα domains. Αντιστοιχεί 1:1 στο
  `mod_ekdosi_routing` (μία επαφή ανά υπηρεσία). Το `is_receipt` της ανάθεσης γίνεται `document_type` της επαφής
  (σήμερα 2 περιπτώσεις).
- **Ένα προτιμολόγιο ανά (λογαριασμός, αποδέκτης):** ο cron και οι παραγγελίες ομαδοποιούν ανά αποδέκτη. Έτσι κάθε
  προτιμολόγιο έχει έναν αποδέκτη και το Ekdosi δεν χρειάζεται split.
- Το **snapshot** του τιμολογίου (`CreateInvoiceSnapshotService`) κρατά τα στοιχεία του αποδέκτη και το
  `document_type` τη στιγμή της έκδοσης. Αλλαγή της επαφής μετά δεν αλλάζει εκδομένο τιμολόγιο.
- Mass pay (ενοποίηση πολλών τιμολογίων σε μία πληρωμή) δεν ανήκει εδώ· είναι θέμα πληρωμών και, για το Ekdosi,
  πληρωμή πολλών προτιμολογίων και όχι νέο παραστατικό.

### Bridge προς Ekdosi (αργότερα, μόνο myip)
- Ανά προτιμολόγιο: `client_id` (το Ekdosi το κρατά όπως σήμερα το `whmcs_client_id`), ο αποδέκτης (snapshot),
  `document_type`, `needs_immediate_invoice`, `needs_invoice_before_payment`, γραμμές με `product_id`/`category_id`,
  περιγραφή και ΦΠΑ, πληρωμές με gateway και transaction ids, νόμισμα, inclusive/exclusive.
- Στο Ekdosi μένει ένα `MycsBillingSource` πάνω στο υπάρχον `BillingSource` contract και στους ουδέτερους
  `/webhooks/billing/{slug}` (`bridges-connectors.md` §5, §9.5 του Ekdosi). Οι κανόνες «τι κόβεται αυτόματα» μένουν
  στο Ekdosi.

### Importer (ξαναγράφεται μαζί με το σχήμα)
- `tblclients` → `clients` + κύρια επαφή. Το ΑΦΜ έρχεται από το custom field (myip) ή το `tax_id` (nixpal), με
  ρύθμιση ανά instance όπως το `whmcs_custom_field_map` του Ekdosi. «Θα ήθελα τιμολόγιο» → `document_type`,
  «Γκρινιάρης» → `needs_immediate_invoice`.
- `tblusers` → `users` (ίδιοι κωδικοί· το WHMCS 8 κρατά bcrypt, να επιβεβαιωθεί στο parity). `tblusers_clients` →
  `client_user`. Και οι users χωρίς λογαριασμό (108 στο myip), για να μη χαθεί κανένα login.
- `tbladmins` → `staff`, όλοι οι ρόλοι (όχι μόνο Full Administrator)· οι ανενεργοί ως ανενεργοί.
- `tblcontacts` → επαφές· οι παλιοί κωδικοί sub-account αγνοούνται (οι users υπάρχουν ήδη). `mod_ekdosi_contacts` →
  επαφές τιμολόγησης· `mod_ekdosi_routing` → `services.billing_contact_id` (και domains όταν υπάρξει το πεδίο).
  Ενοποίηση ανά ΑΦΜ μόνο μέσα στον ίδιο λογαριασμό.
- `tblnotes` και το custom field «Σημειώσεις» → σημειώσεις staff· `tblclientgroups` → `client_groups`.
- Τα tickets του WHMCS: οι απαντήσεις admin δείχνουν σε `staff` (ταίριασμα με `tbladmins`) αντί για ψεύτικους
  users. Οι guests (περίπου τα μισά tickets του myip) μένουν tickets με email και χωρίς λογαριασμό.

### Σειρά δουλειάς (PRs)
1. **Staff χωριστά** (guard, Filament, 2FA, tickets/audits/API keys/assigned). Είναι το μεγαλύτερο
   κέρδος ασφάλειας και δεν εξαρτάται από τα υπόλοιπα.
2. **Clients και users:** `clients` + `client_user` και μετονομασία `user_id` → `client_id` στα δεδομένα του
   λογαριασμού (~146 αρχεία εκτός tests), τρέχων λογαριασμός και δικαιώματα στο client area. Μηχανικό αλλά μεγάλο·
   αγγίζει χρήματα, οπότε code + adversarial review.
3. **Επαφές:** κύρια επαφή, πεδία, validation, φόρμες admin/client, `billing_contact_id`, προτιμολόγιο ανά αποδέκτη,
   snapshot.
4. **Importer** νέου σχήματος και parity (`importer.md`).
5. **Bridge** προς Ekdosi (μαζί με την πλευρά του Ekdosi).

Τα tickets ξεχωρίζουν πια απάντηση staff/πελάτη με τον συντάκτη (morph `staff`/`user`) και όχι με τη σύγκριση
`message.user_id ≠ ticket.user_id`.

### Αποφάσεις (2026-10-14)
1. **Η κύρια επαφή κρατά τα στοιχεία του λογαριασμού.** Το WHMCS τα έχει ως στήλες στο `tblclients` και τα
   contacts χωριστά· το parity μένει 1:1: κάθε `tblclients` → ένας `clients` + μία κύρια επαφή, πεδίο προς πεδίο
   (και αντίστροφα σε export).
2. **Users χωρίς λογαριασμό μεταφέρονται** (κανένα login δεν χάνεται). Γίνεται με το πλήρες import, όταν έρθει η ώρα
   κάθε εταιρείας, όχι τώρα.
3. **2FA staff: διαθέσιμο, όχι υποχρεωτικό.** Κάθε μέλος του staff μπορεί να το ενεργοποιήσει. Τα 2FA του WHMCS δεν
   μεταφέρονται.
4. **IDs του WHMCS κρατιούνται** για staff, users και λογαριασμούς. Το import γίνεται σε **άδεια εγκατάσταση**, όταν
   είμαστε έτοιμοι και tested· η myip έρχεται πολύ αργότερα, πρώτα στρώνουμε το έδαφος.

## Reviews
Αλλάζει ιδιοκτησία χρημάτων (τιμολόγια/πιστώσεις → λογαριασμός) και auth: code review **και** adversarial review.

## PR 1: τι έγινε (staff χωριστά)
- **Πίνακας `staff`**, guard `staff`, provider και password broker δικά του (`staff_password_reset_tokens`, γιατί το ίδιο
  email μπορεί να είναι και πελάτης). Το Filament panel: `authGuard('staff')`, δικό του login (`/admin/login`), reset,
  προφίλ και **προαιρετικό 2FA** (MFA του Filament με TOTP και recovery codes, απόφαση 3). Rate limit στο login και
  στο 2FA από το Filament.
- **Ρόλοι μόνο στο staff:** το `users.role_id` φεύγει· `User::hasPermission()` είναι πάντα `false`. Το users API δεν
  δέχεται `role_id` (ήταν δρόμος να γίνει πελάτης admin).
- **Middleware `UseStaffGuard`** στο panel, και persistent για τα Livewire requests του (`/paymenter/update`): στο admin
  ο `auth()->user()` και οι policies παίρνουν Staff, ποτέ πελάτη του ίδιου browser.
- **Ids που συμπίπτουν:** staff και πελάτες έχουν δική τους αρίθμηση. Οι policies ελέγχουν «ανήκει σε» μόνο για
  πελάτη (`BasePolicy::owns`) και δικαίωμα ρόλου μόνο για staff (`adminPermission`), ώστε ένα μέλος με το ίδιο id με
  πελάτη να μη «κατέχει» τίποτα δικό του.
- **Ποιος:** `App\Support\Actor` (staff στο admin και στο «Login as client», αλλιώς ο πελάτης) για τα audits
  (`ActorResolver`) και τον `actor` του money trail. Το `MoneyHistory` γράφει «Όνομα (staff #id)».
- **Tickets:** `ticket_messages.staff_id` (restrict), `user_id` nullable· η απάντηση staff ξεχωρίζει από τον
  συντάκτη (`isFromStaff()`), όχι από σύγκριση ids. `tickets.assigned_to` → staff (AUTH-14).
- **API keys:** `api_keys.staff_id` (cascade: φεύγουν με το μέλος)· κλάδεμα στα δικαιώματά του, τίποτα όταν
  απενεργοποιηθεί.
- **«Login as client»** (AUTH-19): μόνο με `admin.users.impersonate`, καταγράφεται ως audit `impersonated` στον πελάτη με
  causer το μέλος του staff· η «Αποσύνδεση» στο client area γυρίζει στο admin, και κάθε σελίδα του admin το τερματίζει.
  Τρέχει πριν από το `auth` του client area (priority list στο `bootstrap/app.php`), γιατί το staff δεν είναι συνδεδεμένο
  στον guard `web`.
- **Συνημμένα tickets στο admin:** δικό τους route στο panel (`filament.admin.ticket-attachments.show`), με τον guard του
  staff· το route του client area είναι των πελατών.
- **LockSession** (κλείδωμα IP/agent) και στα Livewire requests του panel (persistent), όχι μόνο στις σελίδες του.
- **API:** απάντηση σε ticket ως staff μόνο ως ο κάτοχος του key (`staff_id` = του key).
- **Staff με ιστορικό** (απαντήσεις, κινήσεις χρημάτων) δεν σβήνεται, απενεργοποιείται (DATA-3/AUTH-7)· κανείς δεν
  σβήνει τον εαυτό του ή όποιον έχει περισσότερα δικαιώματα (`StaffPolicy`, `PermissionGuard::outranks`).
- **AUTH-15** (φίλτρο «Email Verified» που δεν φίλτραρε), **AUTH-18** (νέος κωδικός από staff τερματίζει τις sessions
  του πελάτη), **AUTH-20** (2FA πελάτη: 5 λάθη = 15 λεπτά κλείδωμα).
- **Migration:** όποιος είχε ρόλο πάει στο `staff` με το ίδιο id· απαντήσεις, αναθέσεις, API keys, `actor` και audits
  μεταφέρονται· η γραμμή του στο `users` σβήνεται, εκτός αν έχει και δεδομένα πελάτη (τότε δύο λογαριασμοί).
  Τρέχει εκτός transaction με τα foreign keys κλειστά (στη SQLite το rebuild του `tickets` αλλιώς σβήνει σε cascade όλες
  τις απαντήσεις)· γι' αυτό ελέγχει πρώτα ό,τι μπορεί να αποτύχει (admins με ίδιο email). Το `down()` αρνείται όταν
  ένα staff id ανήκει σε άλλον πελάτη (θα τον έκανε admin). Γνωστό και αποδεκτό: σε admin που ήταν και πελάτης, όλες οι
  κινήσεις και τα audits του γράφονται στο staff, και όσες έκανε ως πελάτης· δεν ξεχωρίζουν από τα δεδομένα.
- **Importer:** όλοι οι admins του WHMCS → staff (Full Administrator → `admin`, άλλοι ρόλοι με το όνομά τους και χωρίς
  δικαιώματα), οι απενεργοποιημένοι ως ανενεργοί· απαντήσεις admin σε tickets → `staff_id` (άγνωστο όνομα → ανενεργό
  μέλος με αυτό το όνομα, όχι ψεύτικος πελάτης). Δύο admins με το ίδιο email: ο δεύτερος παίρνει
  `duplicate-<id>+<email>` και warning, για διόρθωση με το χέρι.
- **Εντολές:** `app:staff:create` (νέα)· το `app:user:create` φτιάχνει πλέον μόνο πελάτη· το `mycs:install` φτιάχνει staff.

## PR 2a: τι έγινε (λογαριασμοί πελάτη χωριστά από τους users)
- **Πίνακες `clients` και `client_user`** (WHMCS `tblclients` / `tblusers_clients`, με το ιστορικό του Ekdosi
  `CustomerUserAccess`):
  - `client_user`: `owner`, `permissions` (ονόματα του WHMCS, NULL = όλα), `granted_by` (staff), `granted_at`,
    `revoked_at` (ανάκληση χωρίς διαγραφή), `last_login_at`.
  - Ένας owner ανά λογαριασμό με unique `owner_of_client_id` (ίδιο μοτίβο με το `primary_of_client_id` των επαφών).
- **Migration:** κάθε user γίνεται λογαριασμός με το **ίδιο id** και owner του.
  - Τα δεδομένα του λογαριασμού περνούν από `user_id` σε `client_id`: orders, services, invoices, credits, money_events,
    carts, billing_agreements, tickets, email_logs, ext_affiliates.
  - Τα properties (χώρα, διεύθυνση, `stripe_id`, `plesk_id`…) και το `credit_auto_apply` είναι πλέον του λογαριασμού.
  - Όσα ανήκουν στον login μένουν σε `user_id`: sessions, 2FA, ειδοποιήσεις, auth logs, ο συντάκτης απάντησης σε
    ticket, ο `actor` του money trail.
  - Οι ρόλοι και τα API keys που είχαν `admin.users.*` παίρνουν και τα αντίστοιχα `admin.clients.*`.
  - Το `down()` γυρίζει κάθε λογαριασμό στον owner του και αρνείται όταν ένας user έχει δύο λογαριασμούς.
- **Τρέχων λογαριασμός** (`App\Support\CurrentClient`, σαν τη session του WHMCS 8):
  - Το client area δουλεύει πάντα σε έναν λογαριασμό από αυτούς που βλέπει ο user. Ένα ξένο ή παλιό id στη session
    δεν γίνεται δεκτό.
  - Στο «Login as client» ο λογαριασμός είναι αυτός από τον οποίο το staff πάτησε το κουμπί (αν ο πελάτης έχει ακόμα
    ενεργό grant σε αυτόν), αλλιώς ο προεπιλεγμένος του πελάτη· ποτέ αυτός που έχει η session του πελάτη.
  - Login χωρίς λογαριασμό αποκτά δικό του στο client area (`EnsureClientAccount`), όπως το WHMCS ζητά client profile.
    Αυτό ισχύει και όταν το staff μπαίνει ως αυτός: χωρίς λογαριασμό το client area δεν έχει τι να δείξει.
  - Μια σελίδα που ανοίγει εγγραφή με URL (τιμολόγιο, υπηρεσία) δουλεύει με τον λογαριασμό **της εγγραφής**, όχι με
    τον τρέχοντα: οι αποθηκευμένοι τρόποι πληρωμής και η πίστωση που φαίνεται είναι του λογαριασμού του τιμολογίου.
- **Δικαιώματα:** το `BasePolicy::owns` ελέγχει ενεργή σύνδεση του user με τον λογαριασμό (`client_user`, χωρίς
  `revoked_at`). Οι άδειες ανά όνομα (`invoices`, `tickets`…) ελέγχονται στο 2b.
- **Χρήματα:** οι διαδρομές κλειδώνουν τη γραμμή του `clients` (`Client::lock`), με την ίδια σειρά όπως πριν.
  - Ένας τρόπος πληρωμής πληρώνει μόνο τιμολόγια του λογαριασμού του (`ExtensionHelper::charge`) και μπαίνει μόνο σε
    υπηρεσίες του (`ServiceObserver`). Υπηρεσία που μεταφέρεται σε άλλο λογαριασμό αφήνει πίσω την κάρτα του παλιού.
- **Mails:** όσα αφορούν τον λογαριασμό (τιμολόγια, υπηρεσίες, tickets) πάνε στον owner και το `email_logs` γράφεται
  στον λογαριασμό. Όσα αφορούν τον login (επιβεβαίωση email, reset, νέα σύνδεση) πάνε στον user. Στο PR 3 οι
  παραλήπτες ανά είδος έρχονται από τις επαφές.
- **Admin:**
  - «Clients»: υπηρεσίες, τιμολόγια, πιστώσεις, ιστορικό, tickets, τρόποι πληρωμής, properties, οι users του
    λογαριασμού, «Login as client» ως ο owner. Ο νέος λογαριασμός δημιουργείται μαζί με τον owner του.
  - «Users»: όνομα, email, κωδικός, 2FA και οι λογαριασμοί που βλέπει ο user.
  - Ο owner ενός λογαριασμού δεν σβήνεται όσο υπάρχει ο λογαριασμός. Owner με ανακληθέν grant δεν είναι owner (δεν
    παίρνει mails, δεν δίνει όνομα στον λογαριασμό).
  - Ένας login που σβήνεται αφήνει τις απαντήσεις του στα tickets του λογαριασμού, χωρίς συντάκτη.
  - Τα properties του API (`properties.view`) ακολουθούν το `admin.clients.viewAny`.
- **API:** νέο `/api/v1/admin/clients` (με `admin.clients.*`). Τα orders, services, invoices, tickets και credits
  παίρνουν `client_id` και include `client`. Το `users` δείχνει μόνο τον login και το include `clients`.
- **Extensions:** οι gateways αποθηκεύουν τρόπους πληρωμής στον λογαριασμό. Οι servers χρησιμοποιούν το id του
  λογαριασμού ως εξωτερικό id (όπως το WHMCS το client id), και τα ids δεν αλλάζουν. Τα affiliates ανήκουν στον
  λογαριασμό.
- **Importer:** για κάθε `tblclients` δημιουργούνται user, λογαριασμός με το ίδιο id και owner. Το πλήρες import των
  `tblusers`/`tblusers_clients` γίνεται στο PR 4.
- **Tests:** στα tests ο λογαριασμός ενός user έχει άλλο id από τον user (+100000), ώστε ένα `user_id` στη θέση ενός
  `client_id` να μην περνά κατά τύχη.

**Απόφαση (2026-10-14):** το «Login as client» δίνει πλήρη έλεγχο του λογαριασμού, όπως και το admin. Δεν
περιορίζεται: ό,τι κάνει το staff εκεί μπορεί να το κάνει ήδη από το admin.

## Από το audit sweep (Σ5), για αυτό το κομμάτι

Μικρά ευρήματα δικαιωμάτων που τα ξαναγράφει ο διαχωρισμός staff/users, οπότε δεν διορθώθηκαν χωριστά (2026-10-14):
- Φίλτρο «Assigned To» στα tickets (γίνεται `staff_id`). AUTH-14
- Φίλτρο «Email Verified» στους users. AUTH-15
- Sessions του πελάτη όταν το staff αλλάζει τον κωδικό του: να τερματίζονται. AUTH-18
- Impersonation → «Login as client», με τον staff ως causer στα audits και καταγραφή της ενέργειας. AUTH-19
- Back-off στις αποτυχίες 2FA (και του staff, με το δικό του guard). AUTH-20
- Διαγραφή μέλους staff που σβήνει τις απαντήσεις του σε tickets· πελάτης με υπηρεσίες ή κινήσεις χρημάτων. DATA-3, AUTH-7
