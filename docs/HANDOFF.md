# MyCS: Handoff

> Για όποιον (άνθρωπο ή agent) πιάνει τη δουλειά. Ενημερώνεται σε κάθε βήμα· τελευταία 2026-10-11.

## Κατάσταση
- **Repo:** `chrismfz/mycs`, hard fork του Paymenter στο `5b69d45` (v1.5.8+41), χωρίς ιστορικό upstream.
  Αλλαγές μέχρι τώρα: αφαίρεση όσων «τηλεφωνούν σπίτι» (Φάση 0, `ROADMAP.md`).
- **Φάση:** 0 σε εξέλιξη (`ROADMAP.md`). Το devbox στήνεται στο VM του dev του Ekdosi (MariaDB, PHP, git, gh).
- **Αποφάσεις:** όλες στο `decisions.md` και στο `README.md` (γιατί fork, γιατί όχι Blesta/FOSSBilling, Ekdosi μόνο
  τιμολόγηση).
- **Το παλιό σχέδιο (Blesta):** εγκαταλείφθηκε 2026-10-11. Το staging `cs.nixpal.com` μένει ανέγγιχτο. Ό,τι αξίζει
  (το `lib/`) μεταφέρεται εδώ ως `packages/`. Ιστορικό στο `history/blesta-modules/`.

## Από πού ξεκινάς
1. `README.md` (αυτού του φακέλου): η απόφαση και ευρετήριο.
2. `ROADMAP.md`: τι γίνεται, με ποια σειρά.
3. `whmcs-usage.md` + `settings-map.md`: τι χρησιμοποιούν πραγματικά οι δύο εταιρείες και με ποιες ρυθμίσεις.
4. `audit-billing.md`, `audit-security.md`: τι είναι στραβό στον πυρήνα που κληρονομήσαμε.
5. `../CLAUDE.md`: κανόνες (secrets, δεδομένα πελατών, contracts).

## Περιβάλλοντα και πρόσβαση
| Τι | Πού | Όρια |
|---|---|---|
| WHMCS myip.gr | `rigel.myip.gr:/home/myipgr/www/clients`, DB `myipgr_clients` (μέσω gsc-mcp `ssh_run`, `mariadb`) | **μόνο read-only** |
| WHMCS nixpal | `orion.myip.gr:/home/clnixpal/public_html`, DB `clnixpal_nixcldb` (gsc-mcp `ssh_run`, συνδέεται ως root) | **μόνο read-only** |
| WHMCS API | gsc-mcp `whmcs_call` | μόνο `Get*` |
| Ekdosi (κώδικας) | `chrismfz/ekdosi` (Laravel 13, Filament 5) | πηγή για port· καμία αλλαγή χωρίς λόγο |
| Ekdosi (εφαρμογή) | rigel, `/home/myipgr/invoicer/ekdosi` | παραγωγή |
| Blesta staging (παλιό) | `cs.nixpal.com` | δεν το αγγίζουμε |
| Devbox MyCS | VM του dev του Ekdosi | στήνεται |
| Staging MyCS | π.χ. `mycs.nixpal.com` | **δεν έχει στηθεί ακόμα** |

Λεπτομέρειες (servers, crons, μεγέθη, inventory του WHMCS): `history/blesta-modules/HANDOFF.md` §1, §4.

## Contracts που δεν σπάνε
`../CLAUDE.md` → «Contracts»: `zlic.php` byte-for-byte, bridge προς Ekdosi (HMAC/ops), αρίθμηση τιμολογίων.
Fixtures του zlic και η λογική του: `history/blesta-modules/licensing.md` και το package `nixpal-licensing`
(blesta-modules `lib/nixpal-licensing`, `tests/fixtures/zlic/`).

## Γνωστά ρίσκα
- Κόστος port domains/tickets από Ekdosi: άγνωστο μέχρι το spike της Φάσης 0.
- .gr EPP: δεν έχει γραφτεί· χρειάζεται whitelist στο UAT του Μητρώου.
- Hard fork: τα upgrades του Laravel/Filament/Livewire είναι πλέον δική μας ευθύνη. Το Laravel 13 μπήκε στο upstream
  3 ημέρες πριν το fork.
- Φάση 1: η εκτίμηση ανέβηκε σε 3–5 εβδομάδες μετά την απογραφή (EU VAT, mass pay, promotions/ομάδες, αλλαγή κύκλου).

## Ημερολόγιο
- **2026-10-10:** audits του Paymenter (χρέωση, ασφάλεια, importer, υγεία)· σύγκριση με Blesta.
- **2026-10-11:** απόφαση για hard fork· σύγκριση με Ekdosi· απογραφή χρήσης του WHMCS· triage του upstream· χάρτης
  ρυθμίσεων· repo `chrismfz/mycs` (fork + docs).
