# Paymenter upstream: issues/PRs που μας αφορούν (triage 2026-10-11)

> Πηγές: όλα τα ανοιχτά issues (66) και PRs (9), κλειστά από 11/2025 περίπου, η σελίδα security advisories και
> κώδικας στο `5b69d45`. Το `5b69d45` είναι το HEAD του upstream: **δεν υπάρχουν μεταγενέστερα commits**, οπότε όλα τα
> πρόσφατα fixes είναι ήδη μέσα. Παρακολούθηση: https://github.com/Paymenter/Paymenter/security/advisories (πιθανά
> GHSA ακόμα υπό embargo για τα 8 security merges της 2026-10-02).

## Μας επηρεάζουν (κώδικας που κρατάμε)
| # | Θέμα | Τι κάνουμε |
|---|---|---|
| 1258 | Stripe: σταθερό `*100`/`/100`, άρα λάθος ποσό σε νομίσματα χωρίς δεκαδικά (JPY κ.λπ.). Το PR #1259 έκλεισε χωρίς merge | Δικός μας helper για minor units. Για EUR δεν μας αφορά άμεσα |
| (δικό μας) | Stripe `pay()` (`Stripe.php:184`) και `charge()` (:763) στέλνουν `$total*100` **χωρίς `round()`**· το :837 στρογγυλεύει | Στρογγύλευση σε ακέραιο. Δεν επιβεβαιώθηκε σε σύστημα |
| 1186 | DirectAdmin: διπλό domain → το job αποτυγχάνει σιωπηλά, η υπηρεσία φαίνεται Active | Ίδια ρίζα με το «status πριν πετύχει το job» (`audit-billing.md`). Status μόνο μετά την επιτυχία, έλεγχος domain στο checkout |
| 1266 | Upgrade «κολλάει» όσο το τιμολόγιο αναβάθμισης είναι απλήρωτο, και δεν ακυρώνεται | Μέσα στη δουλειά για upgrade και αλλαγή κύκλου: ακύρωση ή λήξη εκκρεμών upgrades |
| 1224 | PayPal: το subscription webhook πληρώνει το `$service->invoices()->latest()` (`PayPal.php:341` περίπου), όχι συγκεκριμένο τιμολόγιο | Ανανέωση δεμένη με τιμολόγιο (`decisions.md` §5). Δεν επιβεβαιώθηκε |
| 1260 | Το τιμολόγιο μένει pending αν το Stripe webhook δεν φτάσει | Fallback: επιβεβαίωση του PaymentIntent κατά την επιστροφή του πελάτη |
| 1119 | PDF: «Bill to» και «Issued to» δείχνουν και τα δύο τον πελάτη | Μάλλον άνευ σημασίας, αφού το νόμιμο παραστατικό το εκδίδει το Ekdosi. Δεν επιβεβαιώθηκε |
| 1263 (merged 10/7) | Immutable invoices, credit/debit notes | **Πολύ νέο** κομμάτι. Review απέναντι στο bridge προς Ekdosi και στην αρίθμηση μετά το 32453 |
| 2b46478 | Laravel 13, τρεις ημέρες πριν το fork | Πλήρη regression tests· προσοχή σε ασυμβατότητες Filament/Livewire |
| 1284–1290 | Δέσμη hardening (headers, cookies, sandbox iframe email, password policy) | Δεν ξέρουμε αν διορθώθηκαν. Έλεγχος στο δικό μας deploy (secure cookies, HSTS/CSP) |

## Ήδη μέσα στο fork (διορθώθηκαν πριν το `5b69d45`)
- **#1320:** το `remaining` σε float άφηνε τιμολόγια pending (cd9dea2). Στον δικό μας allocator: decimal ή minor units.
- **GHSA-pgcq / GHSA-5gmm:** double-spend πιστώσεων (a42e7f8, e5c15b7, με `lockForUpdate`). Κρατάμε τα locks στην
  επανεγγραφή του allocator.
- **2026-10-02, 8 security merges:** PayPal (dfd99d4), policies (7ded7f6, aeb6695), API permissions (4ed3916,
  542d6e1), cart (05ce881). Για τα API keys: έλεγχος αν το όριο στο role αυτού που τα δημιουργεί έχει καλυφθεί πλήρως.
- **GHSA-prhx** (Livewire method invocation) και **GHSA-hf99** (SSRF μέσω push): μάλλον e0c04af και e9ff5c5. Η
  αντιστοίχιση είναι συμπέρασμα.
- **GHSA-7wwh** (SSRF στο PayPal IPN): 8961efa.
- #1341/1342, #1271, #1207, #1332, #1313/1315 (`suspend_hold_until`).
- **Cherry-picks (2026-10-11):** #1338 (captcha reset hook) αυτούσιο· από το #1295 **μόνο** το guard για αρνητικό
  υπόλοιπο (734058c) και το enforcement του `credits_enabled` (σελίδα τιμολογίου). Προσθέσαμε και το cron
  (`credits_auto_use` αγνοούσε το `credits_enabled`). **Δεν** πήραμε το «cap at max credit»: χάνει χρήματα του πελάτη
  σιωπηλά (top-up ή διαφορά downgrade πάνω από το όριο) και με κενό όριο μηδενίζει το ποσό.

## Τα αντικαθιστούμε με δικά μας (ALREADY OURS)
| Issues | Τι | Δικό μας |
|---|---|---|
| #1280 | Υπερπληρωμή που χάνεται | `PaymentAllocator` |
| #1252, #983, #1172, #1275 | Tickets: ειδοποιήσεις admin, piping, guest tickets | Tickets του Ekdosi |
| #820 | Domains | Domains του Ekdosi |
| #1239 | Credit note σε downgrade | Credit notes, που χρειάζονται και για το myDATA |
| #1163 | Importer | Δικός μας importer (`importer.md`) |

## Θέλουμε (parity με WHMCS, από τα issues)
- **#1137:** Mass Pay, δηλαδή πολλά τιμολόγια με μία πληρωμή και μία προμήθεια gateway. Μέσα στον allocator.
- **#1301:** ακύρωση τιμολογίου από τον πελάτη· due dates που ακολουθούν το τιμολόγιο.
- **#1248, #1167, #1109, #1179:** το τιμολόγιο ανανέωσης μέσα στο email suspend, ανανέωση νωρίτερα κατά παραγγελία,
  ξεχωριστό cron χρέωσης, αναίρεση αιτήματος ακύρωσης.
- **#1090:** πολλοί συντελεστές ΦΠΑ ανά χώρα (μειωμένοι, νησιά).
- **#1214, #825, PR #1349/#1344/#1345:** CSV export τιμολογίων/συναλλαγών, φίλτρα admin. Τα PRs είναι γραμμένα από bot,
  θέλουν προσεκτικό review. Σχετίζεται και με το export για τον λογιστή της nixpal.
- **#1195:** χειροκίνητη σύνδεση τιμολογίου με υπηρεσία.
- **#1333, #985:** περιορισμοί κουπονιών ανά περίοδο και νόμισμα. **PR #1311:** τιμή ανά μονάδα σε numeric config
  options, υποψήφιο για cherry-pick.
- **#1306, #1018:** templates ανά γλώσσα (el/en), **αφού** φύγει το Blade (`audit-security.md` H1).
- **#1204, #1033:** retry αποτυχημένων emails, ιστορικό emails.
- Αλλαγή κύκλου χρέωσης: δεν υπάρχει issue· είναι δικό μας (`decisions.md` §7).

## Αγνοούμε
Virtualmin, Pterodactyl/Convoy/Proxmox upstream, Discord, themes, affiliates UI, Docker, i18n issues (#1240, #1274),
web installer, μετάβαση από Blesta.
