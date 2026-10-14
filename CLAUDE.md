# CLAUDE.md

Οδηγίες για όποιον (άνθρωπο ή agent) δουλεύει σε αυτό το repo.

## Πλαίσιο
- Πρώτα το `docs/HANDOFF.md` και το `docs/ROADMAP.md`. Ό,τι αποφασίζεται στην πορεία γράφεται εκεί (ή σε `docs/`), όχι μόνο στο chat.
- **MyCS = control plane** (πελάτες, κατάλογος, χρέωση, provisioning, domains, support). **Ekdosi = μόνο
  τιμολόγηση/myDATA.** Το Ekdosi μιλάει με το MyCS μόνο μέσω του bridge (HMAC). Μην ξαναχτίζεις εδώ ό,τι ανήκει στο
  Ekdosi (φορολογικά παραστατικά, myDATA, λογιστικά), ούτε στο Ekdosi ό,τι ανήκει εδώ.
- Hard fork (2026-10-10) που πήρε τον δικό του δρόμο: **δεν ακολουθούμε το upstream ούτε παίρνουμε κώδικά του**
  (2026-10-14: έχουμε αποκλίνει τόσο που ένα cherry-pick δεν εφαρμόζει). Ιστορικό στο `docs/history/`.
- Pilot είναι η nixpal· το myip.gr έρχεται μετά. Ένα instance ανά εταιρεία. Ό,τι διαφέρει ανάμεσα στις εταιρείες
  (αρίθμηση, proforma, πιστώσεις, φόροι, υπενθυμίσεις) είναι **ρύθμιση**, όχι κώδικας (`docs/settings-map.md`).

## Κανόνες ασφάλειας και δεδομένων
- **Ποτέ secrets στο repo, ούτε στο ιστορικό:** credentials, API keys, PSKs, HMAC secrets, EPP passwords, vPOS shared
  secrets. Οι ρυθμίσεις μπαίνουν στο `.env` (δεν γίνεται commit) ή κρυπτογραφημένες στη βάση (`encrypted` cast).
- **Ποτέ πραγματικά δεδομένα πελατών:** dumps, license keys, domains, IPs, emails, ΑΦΜ. Τα fixtures ανωνυμοποιούνται.
- Όχι κώδικας vendor ή ionCube, όχι κλειστά extensions του marketplace.
- Στους servers παραγωγής (rigel, orion) και στις βάσεις του WHMCS μόνο read-only, εκτός αν ζητηθεί ρητά.
- Τίποτα που «τηλεφωνεί σπίτι»: telemetry, update checks, marketplace και self-updater αφαιρούνται και δεν επιστρέφουν.
- Templates που επεξεργάζεται το staff **δεν** γίνονται compile ως Blade/PHP (`docs/history/audit-security.md`).

## Contracts που δεν σπάνε
- `zlic.php` (licensing nixpal): ίδιο URL, ίδιο input (`license`, `license_PSK`, POST ή GET), ίδιο JSON output. Τα
  `md5hash` και `localkey` πρέπει να βγαίνουν **byte-for-byte** ίδια με το WHMCS, ελεγμένα απέναντι στα fixtures.
- Bridge προς Ekdosi: ίδιο HMAC σχήμα (`X-Webhook-Signature: sha256=…`), ίδια ops και error codes
  (`docs/history/blesta-modules/HANDOFF.md` §2.2).
- Αρίθμηση: τα IDs των τιμολογίων του WHMCS κρατιούνται. Στο myip ο αριθμός = id και συνεχίζει από τον τελευταίο του
  WHMCS (στις 2026-10-11 > 32.476· να ξαναδιαβαστεί τη μέρα του cut-over). Στη nixpal: `{YEAR}-{NUMBER}` με proforma.

## Κώδικας
- PHP 8.3+/8.4, Laravel 13, Filament 5, Livewire 4.
- Λογική χωρίς εξάρτηση από framework (EPP client, localkey/zlic, digest Cardlink, PayPal vault, allocator,
  domain-tools) → `packages/<όνομα>` με δικό της `composer.json` και tests (composer `path` repositories). Λεπτά
  adapters στο `app/` και στα `extensions/`.
- **Κάθε ροή χρημάτων έχει tests** (πληρωμές, πιστώσεις, ανανεώσεις, αλλαγές πακέτου ή κύκλου, refunds,
  υπερπληρωμές). Ποσά σε decimal ή minor units, όχι float. Locks όπου δύο διεργασίες μπορούν να γράψουν το ίδιο ποσό.
- phpstan και Pint στο CI.
- Κατά το port από Ekdosi: χωρίς `company_id` (ένα instance ανά εταιρεία), και το τιμολόγιο εδώ είναι **προτιμολόγιο**,
  όχι φορολογικό παραστατικό.
- Strings προς τον πελάτη σε `lang/` (ελληνικά και αγγλικά τουλάχιστον).

## Git
- Μικρά, αυτοτελή commits με σαφές μήνυμα. Ένα θέμα ανά PR.
- Ο importer του WHMCS είναι re-runnable και ελέγχεται με parity (`docs/importer.md`).
- **Reviews:** κάθε PR με κώδικα περνά ανεξάρτητο code review πριν το merge. Τα PRs που αγγίζουν χρήματα (πληρωμές,
  πιστώσεις, ανανεώσεις, refunds, τιμολόγια, αλλαγές πακέτου/κύκλου) περνούν **και adversarial review**: ξεχωριστός
  reviewer, χωρίς το context του συγγραφέα, γράφει failing tests για ό,τι βρει. Τα tests του μένουν στο PR και ο πίνακας
  ευρημάτων (τι διορθώθηκε, τι όχι και γιατί) γράφεται ως σχόλιο στο PR. Ό,τι μένει ανοιχτό πάει στον υπεύθυνο.
