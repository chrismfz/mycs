# Λογαριασμοί: staff, multi-user, προφίλ πελάτη

> Κατάσταση: **σχέδιο εκκρεμεί** (σημείωση 2026-10-12). Γίνεται **αμέσως μετά τα MT-x** (`money-trail.md`) και **πριν
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
   panel (Filament `authGuard()`), δικό τους login, υποχρεωτικό 2FA, roles/permissions μόνο εκεί. Αγγίζει: απαντήσεις
   staff στα tickets, audits («ποιος»), ειδοποιήσεις προς admins, API keys, impersonation, importer.
2. **Ένας χρήστης, πολλοί λογαριασμοί πελάτη** (Users του WHMCS 8): login (user) ↔ λογαριασμός πελάτη (client) με
   ρόλο/δικαιώματα ανά λογαριασμό (μοτίβο του Ekdosi: χρήστης ↔ εταιρείες), πρόσκληση χρήστη, επιλογέας λογαριασμού
   στο client area. Υπηρεσίες, τιμολόγια, πιστώσεις και tickets ανήκουν στον **λογαριασμό**, όχι στον login. Importer
   από `tblusers_clients`.
3. **Προφίλ πελάτη «σαν WHMCS/Ekdosi»:** ιδιώτης/επιχείρηση, επωνυμία, ΑΦΜ/VAT (+ VIES για ΕΕ), ΔΟΥ, δραστηριότητα,
   διεύθυνση χρέωσης, «Θέλω τιμολόγιο» (για αργότερα, myip), γλώσσα, νόμισμα, σημειώσεις staff, σήμανση, κατάσταση
   (ενεργός/ανενεργός, `money-trail.md` §9). Πραγματικά πεδία με validation, όχι μόνο custom properties. Οργανωμένη
   φόρμα στο admin και στο client area.

## Ανοιχτά για τον σχεδιασμό
- Ονόματα: `users` (login) / `clients` (λογαριασμός) / `staff`; τι γίνεται με τα σημερινά `user_id` (rename ή νέο
  `client_id` παντού).
- Ποιος staff μπορεί να είναι και πελάτης: δύο ξεχωριστοί λογαριασμοί, ποτέ ο ίδιος.
- Δικαιώματα ανά χρήστη λογαριασμού (WHMCS: profile, contacts, products, domains, invoices, tickets, orders).
- Ποια στοιχεία «παγώνουν» στο snapshot του τιμολογίου (σήμερα `CreateInvoiceSnapshotService`).
- Η απογραφή και το πλήρες mapping πεδίο-προς-πεδίο (βλ. «Αρχή: parity με το WHMCS»).
- Ελληνικά και αγγλικά strings σε `lang/`.

## Reviews
Αλλάζει ιδιοκτησία χρημάτων (τιμολόγια/πιστώσεις → λογαριασμός) και auth: code review **και** adversarial review.
