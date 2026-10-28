# MyCS: Handoff

> Για όποιον (άνθρωπο ή agent) πιάνει τη δουλειά. Ενημερώνεται σε κάθε βήμα· τελευταία 2026-10-22.

## Κατάσταση
- **Repo:** `chrismfz/mycs`, hard fork που πήρε τον δικό του δρόμο (`../CLAUDE.md`). **Δημόσιο** ως τώρα· γίνεται
  private «όταν ηρεμήσουμε» (ο υπεύθυνος). Ως τότε τα έγγραφα με σήμανση εμπιστευτικού δεν μπαίνουν στο repo.
- **Πού είμαστε και τι ακολουθεί:** `ROADMAP.md`, «Πού είμαστε» και «Επόμενα» (2026-10-20: main στο #232). Καμία
  εταιρεία δεν δουλεύει ακόμα στο MyCS· pilot η nixpal.
- **Αποφάσεις:** όλες στο `decisions.md` και στο `README.md` (γιατί fork, γιατί όχι Blesta/FOSSBilling, Ekdosi μόνο
  τιμολόγηση).
- **Τρόπος δουλειάς:** ένα θέμα ανά PR· code review σε κάθε PR, και adversarial review (με failing tests που μένουν
  στο PR) όπου υπάρχουν χρήματα, lifecycle ή ασφάλεια· το PR μένει draft ώσπου να ανέβουν οι διορθώσεις (`../CLAUDE.md`,
  «Git»).
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
  χωρίς deny στο `/storage` (τα uploads σερβίρονται μέσω του symlink `public/storage`). PHP τρέχουν **μόνο** το
  `index.php` και το `zlic.php`: πρότυπο στο `deploy/nginx.conf.example`· σε Apache/LiteSpeed το ίδιο κάνει το
  `public/.htaccess`.
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
- **Tests (σε worktree, όχι στο `/var/www/mycs`):** `bin/test` (SQLite, παράλληλα· CLAUDE.md, «Tests»). Τα tests με
  locks σε MariaDB: βάση `mycs_test` (ίδιος χρήστης)· `set -a; . /var/www/mycs/.db-credentials; DB_DATABASE=mycs_test; set +a; php artisan test --filter='Concurrency|RaceReview'`.
- **Ενημέρωση:** `su - mycs -c 'cd /var/www/mycs && deploy/update.sh'` (χωρίς `git pull` πριν· το script κάνει fetch/checkout) (το `main`· ή `deploy/update.sh <branch|tag>`).
  Snapshot της βάσης στο `storage/app/db-snapshots/` πριν από κάθε αλλαγή, maintenance mode, queue drain, migrate, build,
  optimize, smoke check. Στο devbox το `.env` έχει `DEPLOY_COMPOSER_DEV=1`, για να μένουν tests/Pint. Δοκιμασμένο 2026-10-11 (10d69f0 → d56adcc).
- **Πρόσβαση στο repo:** ο `mycs` κάνει fetch μέσω `gh` (`gh auth setup-git`, token στο `~/.config/gh/hosts.yml`,
  600)· χρειάζεται όταν το repo γίνει private. Αν λήξει το token: `su - mycs -c 'gh auth login'` και ξανά
  `gh auth setup-git`.
- **CI (GitHub Actions):** σε κάθε PR τρέχουν `Test` (sqlite + MariaDB 10.11, PHP 8.4) και `Lint` (`pint --test` και
  `phpstan analyse`). Το phpstan έχει baseline (`phpstan-baseline.neon`) με τα σφάλματα του upstream: νέο σφάλμα σπάει το
  CI. Τοπικά: `vendor/bin/phpstan analyse --memory-limit=2G`· μετά από καθάρισμα, `--generate-baseline`.
  Τα tests τρέχουν και στο devbox (παραπάνω).
- `.git/info/exclude` κρύβει τα αρχεία του home (`.db-credentials`, `.config/`, `.bash*`, caches).
- **Admin:** ο πρώτος λογαριασμός δημιουργήθηκε (`app:user:create`, 2026-10-11).
- **Δεν έχει γίνει:** mail (SMTP) της παραγωγής (`ROADMAP.md`, «Επόμενα» 6).

Λεπτομέρειες (servers, crons, μεγέθη, inventory του WHMCS): `history/blesta-modules/HANDOFF.md` §1, §4.

## Σχήμα της βάσης (squash, 2026-10-28)
- Μια νέα βάση ξεκινά από τα `database/schema/mariadb-schema.sql` και `sqlite-schema.sql`, όχι από migrations.
  - Τα 224 migrations ως τις 2026-10-28 έγιναν ένα dump για κάθε βάση και σβήστηκαν. Το `migrations` του dump τα έχει
    ως ήδη τρεγμένα.
  - Μια βάση που τα έχει τρέξει ήδη (η παραγωγή) δεν αλλάζει: δεν έχει τίποτα να τρέξει.
- **Έλεγχος ισοδυναμίας:** δύο βάσεις συγκρίθηκαν σε MariaDB και σε SQLite.
  - Η μία χτίστηκε με τα παλιά migrations και seed, η άλλη με το dump και seed.
  - Το σχήμα βγήκε ίδιο και στους 136 πίνακες, και οι 405 γραμμές ίδιες.
  - Μόνη διαφορά, η σειρά των ids στα `notification_templates` και `settings`: εκεί τα migrations έγραφαν πριν από τους
    seeders. Δεν πειράζει, γιατί καμία εγκατάσταση δεν μοιράζεται ids με άλλη.
- **Νέα migrations:** μπαίνουν όπως πάντα στο `database/migrations/`, και τρέχουν μετά το dump.
- **Νέο squash:** όταν μαζευτούν πολλά, ξανά με τον ίδιο έλεγχο.
  1. Μια άδεια βάση: `migrate --seed`, και από αυτήν `php artisan schema:dump --database=mariadb`.
  2. Το ίδιο με `--database=sqlite`.
  3. Από το mariadb dump σβήνεται **κάθε** γραμμή `/*M!999999\- enable the sandbox mode */`: μία στην αρχή, και μία στο
     δεύτερο dump, των γραμμών του `migrations`. Ο client της MySQL και οι παλιοί της MariaDB δεν την καταλαβαίνουν.
  4. Σύγκριση με μια βάση από dump και seed.
  5. Σβήνονται τα migrations και τα tests του upgrade τους.
- **Τι θέλει:**
  - Το dump φορτώνεται με τον client `mariadb` (ή `sqlite3`). Στο CI εγκαθίστανται.
  - Ο orion τα έχει: MariaDB **11.4**, ενώ το CI και ο devbox έχουν 10.11. Το dump της 10.11 φορτώνει στην 11.4· κάποια
    στιγμή το CI να τρέξει και σε 11.4.
- **Η παραγωγή απέναντι στο dump (2026-10-28):** συγκρίθηκε μόνο το σχήμα, χωρίς δεδομένα. Είναι λειτουργικά ίδια.
  - Σε 12 πίνακες διαφέρουν μόνο τα ονόματα και η σειρά των indexes: η παραγωγή κρατά το παλιό όνομα `*_user_id_foreign`
    στη στήλη που έγινε `client_id`.
  - Σε 4 (`credits`, `invoice_snapshots`, `service_cancellations`, `ticket_messages`) η παραγωγή έχει κι ένα περιττό
    απλό index στο foreign key, που μια νέα βάση δεν το χρειάζεται γιατί το καλύπτει ένα σύνθετο.
  - Δεν το προκάλεσε το squash, και δεν χρειάζεται κάτι.
  - Στο αντίγραφο του σχήματός της, το `migrate` της νέας βάσης λέει «Nothing to migrate».
- **PHPStan:** το Larastan μαθαίνει τις στήλες των models από το dump, όχι πια από τα migrations.
  - Ο parser του δεν ξέρει τον τύπο `uuid` της MariaDB, και τότε πετά όλο το dump. Γι' αυτό το `phpstan-schema.php`
    γράφει πριν από κάθε run ένα αντίγραφο στο `storage/framework/larastan/`, με τις δύο στήλες `uuid` ως `char(36)`.
  - Με `parseModelCastsMethod` ο τύπος έρχεται από το `casts()` του model: ένα `tinyint(1)` με cast `boolean` είναι
    `bool`.
  - Ένα PHPStan που περνά με το cache δεν αποδεικνύει τίποτα εδώ. Πριν το push:
    `vendor/bin/phpstan clear-result-cache` (το CI ξεκινά πάντα χωρίς cache).
- **Τα tests που φόρτωναν ένα migration** για να ελέγξουν το upgrade παλιών δεδομένων σβήστηκαν μαζί τους: 76 μέθοδοι,
  από αυτές 3 ολόκληρα αρχεία. Η παραγωγή έχει τρέξει ήδη όλα αυτά τα upgrades.

## Contracts που δεν σπάνε
`../CLAUDE.md` → «Contracts»: `zlic.php` byte-for-byte, bridge προς Ekdosi (HMAC/ops), αρίθμηση τιμολογίων.
Fixtures του zlic και η λογική του: `history/blesta-modules/licensing.md` και το package `nixpal-licensing`
(blesta-modules `lib/nixpal-licensing`, `tests/fixtures/zlic/`).

## Γνωστά ρίσκα
- Hard fork: τα upgrades του Laravel/Filament/Livewire είναι πλέον δική μας ευθύνη.
- Ο importer του νέου σχήματος δεν έχει γραφτεί ακόμα: η πρώτη πραγματική μεταφορά θα δείξει ό,τι δεν πιάνουν τα tests
  (`ROADMAP.md`, «Επόμενα» 4).
- Τα τοπικά tests τρέχουν σε SQLite: ό,τι εξαρτάται από τη MariaDB (row locks, ελληνικά χωρίς διάκριση πεζών/κεφαλαίων
  στο `LIKE`) το ελέγχει μόνο το job της MariaDB στο CI (`../CLAUDE.md`, «Tests»).

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


- **2026-10-15…20:** το sweep χρέωσης A–H, τα domains και το .gr EPP (grepp, DNSSEC δοκιμασμένο ζωντανά στο cdnet.gr
  2026-10-18), το portal του πελάτη P1–P4, el/en παντού, παράλληλα tests (`bin/test`). Σύνοψη στο `ROADMAP.md`,
  «Έγινε»· το roadmap ξαναγράφτηκε 2026-10-20 (το παλιό στο `history/roadmap-2026-10-11-to-20.md`).

- **2026-10-22:** sweep domains/registrars (#305–#308, #310): 16 ευρήματα με failing tests, όλα διορθωμένα με code και
  adversarial review· οι κανόνες που άλλαξαν και τα ανοιχτά για τον υπεύθυνο στο `domains.md`, «Sweep 2026-10-22».

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
