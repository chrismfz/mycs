# MyCS: αποφάσεις, σημειώσεις, reports

> Ξεκίνησε 2026-10-10/11 στο `chrismfz/blesta-modules` (`docs/paymenter/`) και μετακόμισε εδώ στις 2026-10-11.
> Ό,τι αποφασίζεται γράφεται εδώ, όχι μόνο στο chat (`../CLAUDE.md`).
> Το «HANDOFF §…» στα κείμενα παραπέμπει στο `history/blesta-modules/HANDOFF.md` (inventory του WHMCS, contracts,
> ιστορικό της απόφασης). Το «`lib/…` αυτού του repo» σημαίνει το `lib/` του blesta-modules, που μεταφέρεται εδώ ως
> `packages/` (`open-questions.md`).

## Η απόφαση (2026-10-11)
**Πρόταση A: hard fork του Paymenter ως control plane. Το Ekdosi μένει τιμολογιέρα/myDATA.**
- Δικό μας branch/repo που παίρνει τον δρόμο του. **Δεν ακολουθεί το upstream.** Από το Paymenter παίρνουμε μόνο ό,τι
  θέλουμε, με cherry-pick (κυρίως security fixes και extensions).
- Η κουλτούρα του Ekdosi ισχύει από την πρώτη μέρα: tests για κάθε ροή χρημάτων, phpstan στο CI.
- Η ροή χρημάτων μένει όπως σήμερα με το WHMCS: το fork εισπράττει, το Ekdosi εκδίδει φορολογικό παραστατικό μέσω
  δικού μας plugin, όπως σήμερα τα «Εισερχόμενα» (`decisions.md` §23).
- Η απόφαση §9.1b του HANDOFF (Blesta/WHMCS = control plane, Ekdosi = τιμολόγηση) ισχύει, με το fork στη θέση του Blesta.

**Πρακτικά (2026-10-11):**
- Όνομα εργασίας **MyCS**, νέο repo.
- Ένα instance ανά εταιρεία.
- Νέο staging, π.χ. `mycs.nixpal.com`. Το Blesta στο `cs.nixpal.com` μένει ανέγγιχτο.
- Τα `lib/*` μεταφέρονται ως `packages/` στο MyCS. Η λογική του Ekdosi αντιγράφεται με προσαρμογή (`open-questions.md`).

## Γιατί (σύντομα)
- Το Blesta έχει κλειστό τον κώδικα (ionCube) ακριβώς εκεί που μετράει για ασφάλεια (ACL, CSRF, API), λειψά modules,
  και 20+ ευρήματα στο report μας. Ό,τι ιδιαίτερο χρειαζόμαστε (.gr, Cardlink, licensing, bridge) το γράφουμε έτσι κι αλλιώς.
- Το Paymenter είναι MIT, στο **ίδιο stack με το Ekdosi** (Laravel 13, Filament 5), πιο ασφαλές στη μεριά του πελάτη,
  και έχει όσα λείπουν από το Ekdosi: portal, cart, provisioning, αυτόματη χρέωση.
- Το Ekdosi έχει όσα λείπουν από το Paymenter: domains, tickets, υπενθυμίσεις, allocator, Cardlink, tests.

## Αρχεία
| Αρχείο | Τι |
|---|---|
| `decisions.md` | Απόφαση ανά περιοχή (πελάτες, portal, χρέωση, κύκλος ζωής, πληρωμές, …) |
| `ekdosi-vs-paymenter.md` | Συγκριτικός πίνακας 23 περιοχών, λίστα αφαίρεσης, φάσεις |
| `platform-evaluation.md` | Blesta vs Paymenter, πώς ανανεώνουμε σήμερα (WHMCS, 92% / 8%) |
| `audit-billing.md` | Μηχανή χρέωσης του Paymenter: ευρήματα με file:line |
| `audit-security.md` | Ασφάλεια του Paymenter: ευρήματα με file:line, σύγκριση με Blesta |
| `audit-importer-health.md` | WHMCS importer, υγεία project, οικοσύστημα registrars |
| `whmcs-usage.md` | Τι χρησιμοποιούμε στο WHMCS (myip, nixpal) και parity backlog ανά φάση |
| `settings-map.md` | Ρυθμίσεις WHMCS → MyCS ανά instance (αρίθμηση, proforma, mass pay, domains, αυτοματισμοί) |
| `upstream-triage.md` | Issues/PRs/advisories του Paymenter: τι μας επηρεάζει, τι είναι ήδη μέσα, τι θέλουμε |
| `importer.md` | WHMCS importer με 1:1 parity (πρότυπο: `migrate:firebird` του Ekdosi) |
| `open-questions.md` | Ανοιχτά ερωτήματα και σκέψεις |
| `history/blesta-modules/` | Τα docs του blesta-modules (HANDOFF, licensing/zlic, Openprovider, importer Blesta, nixpal cut-over, ασφάλεια gateways, domain-tools) και το CLAUDE.md του |
| `history/paymenter-README.md` | Το README του upstream στο `5b69d45` |

Όλα τα audits είναι **ανάγνωση κώδικα** στο Paymenter `5b69d45` (v1.5.8+41, 2026-10-10) και στο Ekdosi `3dd87fe`.
Τίποτα δεν εκτελέστηκε.
