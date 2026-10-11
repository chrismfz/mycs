# MyCS: Handoff

> Για όποιον (άνθρωπο ή agent) πιάνει τη δουλειά. Ενημερώνεται σε κάθε βήμα· τελευταία 2026-10-11.

## Κατάσταση
- **Repo:** `chrismfz/mycs`, hard fork του Paymenter στο `5b69d45` (v1.5.8+41), χωρίς ιστορικό upstream.
  Αλλαγές μέχρι τώρα: αφαίρεση όσων «τηλεφωνούν σπίτι» (Φάση 0, `ROADMAP.md`).
- **Φάση:** 0 σε εξέλιξη (`ROADMAP.md`). Το devbox είναι το VM `devbox` (το dev του Ekdosi: MariaDB, PHP, git, gh),
  στο `mycs.myip.gr` (+ wildcard `*.mycs.myip.gr` για δοκιμές).
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
| **Devbox MyCS** (εγκατεστημένο, https://mycs.myip.gr) | VM `devbox` (84.54.49.31, ίδιο με το dev του Ekdosi)· gsc-mcp `ssh_run` host `devbox` | δοκιμές και η πρώτη εγκατάσταση· τρέχει και το dev του Ekdosi, προσοχή στα κοινά (MariaDB, php-fpm, cron) |
| **DNS δοκιμών** | `mycs.myip.gr` και `*.mycs.myip.gr` → A 84.54.49.31 (TTL 600, στη ζώνη myip.gr, 2026-10-11) | ελεύθερη χρήση για δοκιμές (π.χ. `test.mycs.myip.gr`, ένα subdomain ανά σενάριο/instance) |
| Staging MyCS (μετά) | ό,τι αποφασιστεί (π.χ. `mycs.nixpal.com`) | δεν έχει στηθεί |

### Devbox: τι έχει στηθεί (2026-10-11)
Ίδιο μοτίβο με το Ekdosi στο ίδιο VM (AlmaLinux 10.2, nginx, PHP 8.4.21, MariaDB 10.11):
- **Χρήστης** `mycs` (system, home = `/var/www/mycs` = ρίζα της εφαρμογής).
- **php-fpm pool** `/etc/php-fpm.d/mycs.conf` → `/run/php-fpm/mycs.sock` (user `mycs`, `pm.max_children = 10`),
  log `/var/log/php-fpm/mycs-error.log`.
- **nginx** `/etc/nginx/conf.d/mycs.myip.gr.conf`: root `/var/www/mycs/public`, security headers όπως του Ekdosi,
  χωρίς deny στο `/storage` (το Paymenter σερβίρει uploads μέσω του symlink `public/storage`).
  HTTPS με Let's Encrypt (certbot --nginx, redirect 80→443). Τα `*.mycs.myip.gr` δεν έχουν ακόμα vhost/πιστοποιητικό
  (wildcard θέλει DNS-01· ή ένα πιστοποιητικό ανά subdomain).
- **MariaDB:** βάση `mycs` (utf8mb4_unicode_ci), χρήστης `mycs`@`localhost` με δικαιώματα μόνο στη `mycs`. Τα
  στοιχεία (`DB_*`) είναι στο `/var/www/mycs/.db-credentials` (600, owner `mycs`), για να μπουν στο `.env`.
- Όλα τα PHP extensions που ζητάει το `composer.lock` υπάρχουν.
- **Εγκατάσταση (2026-10-11):** κώδικας από το `main` (`git fetch` ανώνυμα μέσω HTTPS· ο `mycs` έχει και `gh auth`,
  token στο `~/.config/gh/hosts.yml`, 600), `composer install`, `npm ci && npm run build`, `.env` (600) με `APP_URL=https://mycs.myip.gr`,
  `APP_TIMEZONE=Europe/Athens`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database` (χωρίς Redis,
  όπως το Ekdosi), `migrate --seed`, `storage:link`, `app:init`.
- **Cron:** `* * * * * php artisan schedule:run` (crontab του `mycs`). **Queue:** `mycs-queue.service` (systemd, όπως
  το `ekdosi-queue.service`).
- **Tests:** βάση `mycs_test` (ίδιος χρήστης)· `set -a; . ./.db-credentials; DB_DATABASE=mycs_test; set +a; php artisan test`
  → 66 passed· `vendor/bin/pint --test` → καθαρό (2026-10-11, μετά το PR #2).
- **Ενημέρωση:** `su - mycs -c 'cd /var/www/mycs && deploy/update.sh'` (χωρίς `git pull` πριν· το script κάνει fetch/checkout) (το `main`· ή `deploy/update.sh <branch|tag>`).
  Snapshot της βάσης στο `storage/app/db-snapshots/` πριν από κάθε αλλαγή, maintenance mode, queue drain, migrate, build,
  optimize, smoke check. Στο devbox το `.env` έχει `DEPLOY_COMPOSER_DEV=1`, για να μένουν tests/Pint. Δοκιμασμένο 2026-10-11 (10d69f0 → d56adcc).
- **Πρόσβαση στο repo (private από 2026-10-11):** ο `mycs` κάνει fetch μέσω `gh` (`gh auth setup-git`, token στο
  `~/.config/gh/hosts.yml`, 600). Αν λήξει το token: `su - mycs -c 'gh auth login'` και ξανά `gh auth setup-git`.
- **CI (GitHub Actions):** σε κάθε PR τρέχουν `Test` (sqlite + MariaDB 10.11, PHP 8.4) και `Lint` (`pint --test`).
  Τα tests τρέχουν και στο devbox (παραπάνω).
- `.git/info/exclude` κρύβει τα αρχεία του home (`.db-credentials`, `.config/`, `.bash*`, caches).
- **Δεν έχει γίνει:** πρώτος admin (`php artisan app:user:create`, με δικό σου κωδικό), mail (SMTP).

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
- **2026-10-11:** DNS `mycs.myip.gr` + `*.mycs.myip.gr` → devbox (84.54.49.31)· η ίδια IP δηλώθηκε στο Μητρώο .GR για τις
  δοκιμές EPP.
- **2026-10-11:** απόφαση για hard fork· σύγκριση με Ekdosi· απογραφή χρήσης του WHMCS· triage του upstream· χάρτης
  ρυθμίσεων· repo `chrismfz/mycs` (fork + docs).
