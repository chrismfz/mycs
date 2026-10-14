# MyCS: Handoff

> Για όποιον (άνθρωπο ή agent) πιάνει τη δουλειά. Ενημερώνεται σε κάθε βήμα· τελευταία 2026-10-11.

## Κατάσταση
- **Repo:** `chrismfz/mycs`, hard fork του upstream στο `5b69d45` (v1.5.8+41), χωρίς ιστορικό upstream.
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
4. `history/audit-billing.md`, `history/audit-security.md`: τι είναι στραβό στον πυρήνα που κληρονομήσαμε.
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
  χωρίς deny στο `/storage` (το upstream σερβίρει uploads μέσω του symlink `public/storage`).
  HTTPS με Let's Encrypt (certbot --nginx, redirect 80→443). Τα `*.mycs.myip.gr` δεν έχουν ακόμα vhost/πιστοποιητικό
  (wildcard θέλει DNS-01· ή ένα πιστοποιητικό ανά subdomain).
- **MariaDB:** βάση `mycs` (utf8mb4_unicode_ci), χρήστης `mycs`@`localhost` με δικαιώματα μόνο στη `mycs`. Τα
  στοιχεία (`DB_*`) είναι στο `/var/www/mycs/.db-credentials` (600, owner `mycs`), για να μπουν στο `.env`.
- Όλα τα PHP extensions που ζητάει το `composer.lock` υπάρχουν.
- **Εγκατάσταση (2026-10-11):** κώδικας από το `main` (`git fetch` ανώνυμα μέσω HTTPS· ο `mycs` έχει και `gh auth`,
  token στο `~/.config/gh/hosts.yml`, 600), `composer install`, `npm ci && npm run build`, `.env` (600) με `APP_URL=https://mycs.myip.gr`,
  `APP_TIMEZONE=Europe/Athens`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database` (χωρίς Redis,
  όπως το Ekdosi), `migrate --seed`, `storage:link`, `app:init` (σήμερα: `mycs:install`, `docs/install.md`).
- **Cron:** `* * * * * php artisan schedule:run` (crontab του `mycs`). **Queue:** `mycs-queue.service` (systemd, όπως
  το `ekdosi-queue.service`).
- **Ποτέ tests ή branches στον φάκελο που σερβίρει το site (`/var/www/mycs`).** Μετά το `deploy/update.sh` το config
  είναι cached (`bootstrap/cache/config.php`): τα env vars (`DB_CONNECTION=sqlite` κ.λπ.) αγνοούνται και ένα test με
  `RefreshDatabase` γράφει στη βάση του site (2026-10-13: έσβησε χρήστη, ρόλους, ρυθμίσεις· επαναφορά από το snapshot
  του deploy). Και ένα `git checkout` εκεί αλλάζει αμέσως τον κώδικα που τρέχει. Δουλειά και tests σε ξεχωριστό clone
  ή worktree (π.χ. `git worktree add /root/mycs-dev <branch>`, `cp -a vendor`, SQLite με `APP_KEY`)· στο site μόνο
  `deploy/update.sh`.
- **Tests (σε worktree, όχι στο `/var/www/mycs`):** βάση `mycs_test` (ίδιος χρήστης)· `set -a; . /var/www/mycs/.db-credentials; DB_DATABASE=mycs_test; set +a; php artisan test`
  → 66 passed· `vendor/bin/pint --test` → καθαρό (2026-10-11, μετά το PR #2).
- **Ενημέρωση:** `su - mycs -c 'cd /var/www/mycs && deploy/update.sh'` (χωρίς `git pull` πριν· το script κάνει fetch/checkout) (το `main`· ή `deploy/update.sh <branch|tag>`).
  Snapshot της βάσης στο `storage/app/db-snapshots/` πριν από κάθε αλλαγή, maintenance mode, queue drain, migrate, build,
  optimize, smoke check. Στο devbox το `.env` έχει `DEPLOY_COMPOSER_DEV=1`, για να μένουν tests/Pint. Δοκιμασμένο 2026-10-11 (10d69f0 → d56adcc).
- **Πρόσβαση στο repo (private από 2026-10-11):** ο `mycs` κάνει fetch μέσω `gh` (`gh auth setup-git`, token στο
  `~/.config/gh/hosts.yml`, 600). Αν λήξει το token: `su - mycs -c 'gh auth login'` και ξανά `gh auth setup-git`.
- **CI (GitHub Actions):** σε κάθε PR τρέχουν `Test` (sqlite + MariaDB 10.11, PHP 8.4) και `Lint` (`pint --test` και
  `phpstan analyse`). Το phpstan έχει baseline (`phpstan-baseline.neon`) με τα σφάλματα του upstream: νέο σφάλμα σπάει το
  CI. Τοπικά: `vendor/bin/phpstan analyse --memory-limit=2G`· μετά από καθάρισμα, `--generate-baseline`.
  Τα tests τρέχουν και στο devbox (παραπάνω).
- `.git/info/exclude` κρύβει τα αρχεία του home (`.db-credentials`, `.config/`, `.bash*`, caches).
- **Admin:** ο πρώτος λογαριασμός δημιουργήθηκε (`app:user:create`, 2026-10-11).
- **Δεν έχει γίνει:** mail (SMTP).

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
- **2026-10-10:** audits του upstream (χρέωση, ασφάλεια, importer, υγεία)· σύγκριση με Blesta.
- **2026-10-11:** DNS `mycs.myip.gr` + `*.mycs.myip.gr` → devbox (84.54.49.31)· η ίδια IP δηλώθηκε στο Μητρώο .GR για τις
  δοκιμές EPP.
- **2026-10-11:** απόφαση για hard fork· σύγκριση με Ekdosi· απογραφή χρήσης του WHMCS· triage του upstream· χάρτης
  ρυθμίσεων· repo `chrismfz/mycs` (fork + docs).
- **2026-10-13:** audit sweep όλου του κληρονομημένου κώδικα (8 reviewers, ~165 ευρήματα) → `audit-sweep.md` και
  «Φάση 1a: Σταθεροποίηση» στο ROADMAP, μετά τα MT-x. 8 κρίσιμα (ένα regression δικό μας: `afterCommit` και συνδρομές).
- **2026-10-14:** λογαριασμοί PR 1–3a (staff, clients, πολλοί users, επαφές). Το MyCS παίρνει οριστικά τον δρόμο του:
  δεν παίρνουμε κώδικα από το upstream· το όνομά του φεύγει από κώδικα, URLs, cookies και docs (namespace
  `MyCS\Extensions`)· μένουν μόνο η σημείωση copyright στο `LICENSE` (MIT) και το `docs/history/`.
  Στο deploy του: άδειασμα της ουράς με τον παλιό κώδικα (`queue:work --stop-when-empty`), όλοι κάνουν ξανά login
  (νέα cookies), και το `company_name` πρέπει να είναι αποθηκευμένο (αλλιώς φαίνεται το default «MyCS»).


### PR #32 — διορθώσεις δεύτερου εξωτερικού review (2026-10-12)

Στο branch `feat/billing-cursor`: replay guard πριν από κάθε μεταβολή, κοινή decimal αναλογία για cron/upgrade,
σταθερή αρχή και μοναδική λήξη κουπονιού, απόδοση πλήρους μη επικαλυπτόμενης περιόδου, απομόνωση σφαλμάτων ανά
γραμμή πληρωμένου τιμολογίου. Νέο migration για `coupon_started_at`/`coupon_expired_at`. Συμπεριφορά και tests:
`billing-cursor.md` → «Επανέλεγχος και διορθώσεις Codex». Οι γενικοί guards/refunds του PR 2 παραμένουν χωριστοί.

Validation (2026-10-12): MariaDB full suite — 424 tests, 2809 assertions, 4 known incomplete, no failures;
SQLite targeted regressions/package — 28 tests, 112 assertions; Pint, PHPStan and composer validate pass.


### PR #32 — τελικές διορθώσεις reference review (2026-10-12)

F1/F2: επανυπολογισμός όλων των pending/draft renewal γραμμών στην αλλαγή κύκλου και μετά το upgrade·
marker `dunning_suspended_at` για οικονομική αναστολή, προστασία manual/unknown holds στην πληρωμή και
στο αυτόματο termination. Staff/API writes υπό service row lock, φρέσκος έλεγχος status στα suspend/unsuspend
jobs. Νέο migration `2026_10_12_000400_add_dunning_suspension_to_services`. Legacy suspended υπηρεσίες χωρίς
αιτία χρειάζονται ρητή απόφαση staff, όχι αυτόματο backfill. Αναλυτική πολιτική και tests στο
`billing-cursor.md` → «Τελικός έλεγχος reference Ekdosi / WHMCS — διορθώσεις».
