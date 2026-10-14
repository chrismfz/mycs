# Paymenter: WHMCS importer, υγεία project, οικοσύστημα (2026-10-10)

> [V] = ελέγχθηκε στον κώδικα ή στο git · [D] = docs ή web · [G] = εκτίμηση. Οι διαδρομές είναι σχετικές με τη ρίζα του Paymenter.

## WHMCS importer: demo-grade, ξαναγράφεται
Το `app/Console/Commands/ImportFromWhmcs.php` (1000 γρ., 3 commits, το τελευταίο 2026-08-27) γράφει απευθείας με raw
PDO και `DB::table()->insert`.

**Τι περνάει [V]:**
- **Currencies.** Πρώτα αδειάζει τα νομίσματα του Paymenter.
- **Clients**, με το id τους:
  - το bcrypt hash περνάει αυτούσιο·
  - η διεύθυνση και τα υπόλοιπα στοιχεία μπαίνουν σε custom properties, πιθανότατα χάνονται λόγω bug στο `isset`·
  - το credit balance περνάει·
  - clients χωρίς owner user παραλείπονται σιωπηλά.
- **Admins:** bug. Το `$data` δεν γίνεται ποτέ insert, άρα δεν εισάγεται κανένας.
- **Products, groups, pricing, config options και upgrade paths.**
- **Tickets και replies**, **χωρίς συνημμένα**. Το τμήμα γίνεται απλό string.
- **Orders, services** (χωρίς server και credentials) και **cancellations**.
- **Invoices**, με id και `invoicenum`:
  - μόνο status και νόμισμα· **χωρίς** due date, φόρο, σύνολο, χρησιμοποιημένη πίστωση ή τρόπο πληρωμής·
  - γράφει status `'unpaid'`, που η εφαρμογή δεν αναγνωρίζει (περιμένει `pending`).
- **Invoice items.** Οι γραμμές Domain, Addon και Upgrade μένουν με reference `null`.
- **Payments**, από το `tblaccounts`, **χωρίς** gateway, fees ή refunds.

**Τι δεν περνάει [V]:**
- domains, contacts, addons, custom fields, promotions, tax
- servers, module settings και service credentials
- email templates, notes, credit log, quotes, affiliates
- **gateway tokens**

**Ευρωστία [V]:**
- Τρέχει `migrate:fresh` σε **κάθε** εκτέλεση, δηλαδή σβήνει τα πάντα.
- Κλείνει τα FK checks. Δεν έχει transaction, dry-run ή id map.
- Batches 500 γραμμών, με N+1 ερωτήματα.
- Ένα σφάλμα σταματάει την εισαγωγή στη μέση, με τα FK checks κλειστά.

**Αρίθμηση [V]:** ο μετρητής μπαίνει σε `max(id)+1`, άρα **χάνεται ένα νούμερο**: η ρύθμιση κρατάει τον τελευταίο
αριθμό, όχι τον επόμενο.

**Αποθηκευμένοι τρόποι πληρωμής:**
- **Stripe:** ο πελάτης στο user property `stripe_id` (`Stripe.php:598`) και το pm στο
  `billing_agreements.external_reference` (`Stripe.php:675-682`). Οι 52 κάρτες της nixpal περνάνε με δικό μας script,
  στον ίδιο λογαριασμό Stripe [G].
- **PayPal:** Vault v3 payment tokens (`PayPal.php:43, 83, 122`). Τα tokens του `paypal_ppcpv` του WHMCS ανήκουν στο
  partner app του WHMCS και **πιθανότατα** δεν περνάνε σε άλλο REST app [G]. Θέλει ερώτηση στην PayPal. Οι υπάρχουσες
  συνδρομές δεν υιοθετούνται χωρίς δική μας δουλειά.

## Υγεία project
- **Git (depth 200, 2025-12 → 2026-10) [V]:** περίπου 20 commits τον μήνα. Ένας developer («Corwin»/«CorwinDev») έχει
  το **72%** των commits, χωρίς το dependabot. Ο επόμενος άνθρωπος έχει 7. **Bus factor ≈ 1.**
- **Releases [V]:** v1.5.0 (2026-05-09) έως v1.5.8 (2026-08-19), ανά 2–4 εβδομάδες. Στο master υπάρχουν μεγάλες αλλαγές
  που δεν έχουν βγει σε release: Laravel 13 και immutable invoices / credit-debit notes (#1263).
- **Ιστορία:** το V1 ήταν πλήρες rewrite του V0 (αρχές 2025 [G]). Το `update.sh` αρνείται εγκαταστάσεις V0.
- **Άδεια [V]:** MIT, χωρίς CLA. Τα πληρωμένα extensions πωλούνται σε marketplace και BuiltByBit (π.χ. Proxmox 24,99 $).
- **Tests και CI [V]:** 14 αρχεία tests, περίπου 66 tests. Workflows: test, lint (Pint), release, docker, beta, api-docs.
  Το phpstan (level 5) είναι **σχολιασμένο** στο CI.
- **Stack [V]:** PHP ^8.3|^8.4, Laravel ^13, Filament ^5, Livewire ^4, Passport 13. Οι ενημερώσεις έρχονται με
  `update.sh`, που κατεβάζει tarball και αντικαθιστά τα πάντα.
- **Μέγεθος [V]:** `app/` 514 αρχεία, περίπου 30k γραμμές· extensions περίπου 7,4k· Blade περίπου 5,5k.

## Οικοσύστημα
- **Bundled [V]:**
  - Gateways: Stripe, PayPal, PayPal_IPN, Mollie
  - Servers: cPanel, DirectAdmin, Plesk, Enhance, Pterodactyl, Convoy, Virtfusion, Virtualizor
  - Others: Affiliates, Announcements, DiscordNotifications
- **Registrars (όλοι πληρωμένοι, κανένας ανοιχτός, όλοι «server-type») [D]:**
  - Enom: 7,99 $ στο marketplace / 19,99 $ στο BuiltByBit
  - Namecheap Domain Manager: 14,99 $
  - Domain Registration for Paymenter: 23,99 $, EULA, από 03/2026, μία αξιολόγηση· μία έκδοση για Internet.bs
- **Δεν βρέθηκε [D]:** Openprovider, ResellerClub, Centova, mailcow, .gr EPP, licensing.
- **Proxmox:** πληρωμένο, μόνο QEMU [D].

## FOSSBilling (για την ιστορία)
Δεν είναι υποψήφιο:
- fork του BoxBilling, με δικό του framework και RedBeanPHP, όχι Laravel·
- έκδοση 0.8.x, που το ίδιο το README χαρακτηρίζει beta·
- CVE-2026-43928 (διορθώθηκε στο 0.8.0).

Το μόνο υπέρ του είναι ότι έχει domains στον πυρήνα, αλλά αυτό το καλύπτει ήδη το Ekdosi.
