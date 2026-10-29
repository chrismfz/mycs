# Εγκατάσταση και ενημέρωση

Ένα instance ανά εταιρεία (nixpal, myip). Η εγκατάσταση γίνεται **μόνο από το CLI** (`mycs:install`): δεν υπάρχει web
installer, γιατί ένα `/install` στο internet είναι επιφάνεια επίθεσης μέχρι να κλειδώσει, και τα instances τα στήνουμε
εμείς μέσω ssh (ίδια λογική με τα extensions, CLI/git μόνο).

## Απαιτήσεις
PHP 8.4 (τα extensions του `composer.lock`), MariaDB 10.11 (utf8mb4_unicode_ci), Node 20+, nginx, ένας χρήστης
συστήματος για την εφαρμογή (π.χ. `mycs`). Παράδειγμα vhost: `deploy/nginx.conf.example` (PHP μόνο στα `index.php`
και `zlic.php`)· σε Apache/LiteSpeed το `public/.htaccess`. Systemd: `HANDOFF.md` (devbox).

## Μέγεθος server και PHP (για το migration, 2026-10-21)
- **OPcache: όχι τα defaults της διανομής.** Ένα Laravel/Filament app φορτώνει ~1.600 αρχεία ανά request και έχει
  ~43.000 PHP αρχεία· όλα τα pools ενός php-fpm μοιράζονται **ένα** OPcache. Με τα defaults (128 MB, 8 MB strings,
  10.000 αρχεία) και δύο apps (MyCS + Ekdosi) γέμιζε, έκανε restart (`oom_restarts`) και κάθε request ξαναμεταγλώττιζε
  (~1,4 s, το dashboard με τα ~10 lazy widgets 4–5 s). Στο `/etc/php.d/10-opcache.ini`:
  ```ini
  opcache.memory_consumption=256        ; ~70 MB ανά app ζεστό, με περιθώριο
  opcache.interned_strings_buffer=32
  opcache.max_accelerated_files=60000   ; όλα τα αρχεία των apps του server
  ```
  και `systemctl reload php-fpm`. Έλεγχος: `opcache_get_status()` από το pool (όχι από το CLI, έχει δικό του):
  `oom_restarts` 0, `interned_strings_usage.free_memory` > 0. Μετά από deploy ή reload το πρώτο request ξαναμεταγλωττίζει (~1 s).
- **Ρυθμίσεις PHP για την παραγωγή** (security audit SEC-13, ο έλεγχος «PHP και ρυθμίσεις παραγωγής» στο Automation
  Status τις διαβάζει από το PHP του site):
  ```ini
  expose_php=Off
  opcache.validate_permission=1   ; κοινό OPcache: άλλο pool δεν τρέχει scripts που έκανε cache το MyCS
  opcache.validate_root=1         ; με chroot ανά pool· σε CageFS το καλύπτει ήδη η απομόνωση του χρήστη
  ```
  `APP_ENV=production`, `APP_DEBUG=false` και `composer install --no-dev`: το `deploy/update.sh` αρνείται dev πακέτα ή
  debug στην παραγωγή (εκτός με `ALLOW_DEV_IN_PRODUCTION=1`). Το `zlic.php` παίρνει κλειδί και PSK στο query string
  (contract): στο nginx δικό του access log χωρίς query (`deploy/nginx.conf.example`)· το `error_log` του nginx και τα
  domlogs του cPanel/LiteSpeed κρατούν το query, άρα σύντομη διάρκεια και `0640`. Στον orion (2026-10-23): LiteSpeed/lsphp σε CageFS, `expose_php` off,
  `validate_permission` on, `validate_root` off (το καλύπτει το CageFS).
- **Μέγεθος:** με δεδομένα στο μέγεθος του myip (500 πελάτες, 600 υπηρεσίες, 2.000 domains, 7.200 τιμολόγια) κάθε
  widget του dashboard κάνει 2–16 queries σε ≤ 42 ms SQL, χωρίς N+1: ο όγκος δεν είναι το πρόβλημα, η μνήμη και ο
  δίσκος είναι. Devbox: 16 GB RAM, 16 cores, 42 GB δίσκος (vega, VM 114).
- **Δίσκος:** τα review clones (~800 MB το καθένα με το `vendor` τους) και το cache του phpstan (`/tmp/phpstan`) τον
  γέμισαν στο 100% (2026-10-21), μαζί με το live site. `vendor` με hardlinks (`cp -al`), clones σβήνονται μετά το review.

## Νέο instance
```bash
git clone <repo> /var/www/mycs && cd /var/www/mycs
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && chmod 600 .env     # DB_*, APP_URL, MAIL_*· όχι secrets στο repo
php artisan key:generate                    # κρυπτογραφεί τα αποθηκευμένα credentials: κράτα αντίγραφο του .env
php artisan mycs:install                    # ρωτάει εταιρεία, URL, πρώτο admin, κωδικό (κρυφά)
```
Θέλει τον client `mariadb` (ή `sqlite3` για SQLite) στο `PATH` του χρήστη που τρέχει το artisan. Η νέα βάση
φορτώνεται από το `database/schema/mariadb-schema.sql` μέσω του client (HANDOFF → «Σχήμα της βάσης»). Σε CageFS
(orion) πρέπει να υπάρχει και μέσα στο cage του χρήστη.

Το `mycs:install` τρέχει migrations και το βασικό seed (ρόλος admin, προεπιλογές ρυθμίσεων, templates), δημιουργεί
το νόμισμα (EUR, `1.000,00 €`), γράφει εταιρεία/URL/γλώσσα (client area `el` + `en`, admin `en`)/ζώνη ώρας
(`Europe/Athens`), το `storage:link` και το πρώτο μέλος του **staff** (πίνακας `staff`, login στο
`/sysadmin/login`, όχι στο client area). Αρνείται αν υπάρχει ήδη staff (`--force` για δεύτερο ή για να ξαναγράψει τις
ρυθμίσεις· **δεν** αλλάζει κωδικό υπάρχοντος). Άλλο μέλος από την κονσόλα: `php artisan app:staff:create`· πελάτης:
`app:user:create`. Το 2FA είναι **υποχρεωτικό** για το staff: κάθε μέλος το στήνει στο πρώτο του login, πριν από
οτιδήποτε άλλο στο admin.

Μέλος του staff κλειδωμένο έξω (χαμένο κινητό και recovery codes, όριο αποτυχιών, ξεχασμένος κωδικός), όταν δεν υπάρχει
άλλος admin να κάνει «Reset 2FA»: `php artisan app:staff:unlock <email>`. Καθαρίζει το όριο αποτυχιών του· με
`--reset-2fa` σβήνει το 2FA του (το ξαναστήνει στο επόμενο login), με `--password` ζητά νέο κωδικό (ποτέ ως flag), με
`--ip=<διεύθυνση>` καθαρίζει και το όριο μιας διεύθυνσης (σπάνια χρειάζεται: λήγει μόνο του σε ένα λεπτό). Ρωτά πριν (εκτός με `--force`), γράφεται στο audit, και το staff
παίρνει email κάθε φορά.

Χωρίς ερωτήσεις (π.χ. από script). Ο κωδικός **ποτέ** ως flag (μένει στο history και στο `ps`):
```bash
MYCS_ADMIN_PASSWORD='…' php artisan mycs:install --no-interaction \
  --company="nixpal" --url=https://my.nixpal.com --first-name=… --last-name=… --email=…
```
Επιλογές: `--language=el`, `--timezone=Europe/Athens`, `--currency=EUR|USD`, `--eu-vat-rates` (οι κανονικοί συντελεστές
ΦΠΑ των 27 χωρών της ΕΕ στο Tax Rates, μόνο όσες χώρες δεν έχουν· χωρίς το flag το ρωτά, προεπιλογή όχι). Αργότερα:
`php artisan mycs:vat-rates:seed` (`docs/eu-vat.md`).

Μετά: `deploy/private-files.sh` (το cached config, το `.env`, τα logs, τα PDF, τα tickets και τα snapshots μόνο για τον
χρήστη της εφαρμογής· το ξανατρέχει κάθε `deploy/update.sh`), cron `* * * * * php artisan schedule:run`, queue worker
(systemd), mail (SMTP/SPF/DKIM), TLS. Σε server cPanel όπου τρέχει και το ίδιο το MyCS: ο λογαριασμός του στα
«Protected accounts» του server, για να μην τον αγγίζει ποτέ ένα module action.

## Ενημέρωση
```bash
su - mycs -c 'cd /var/www/mycs && deploy/update.sh'        # ή deploy/update.sh <tag|branch>
```
Πριν αλλάξει οτιδήποτε, κρατά **snapshot της βάσης** στο `storage/app/db-snapshots/` (τα τελευταία 10), και μετά:
maintenance mode, queue drain, checkout, composer, migrate, build, caches, private files, smoke check.

## Αν κάτι πάει στραβά
Δεν υπάρχει αυτόματο rollback (απόφαση 2026-10-11: προσεκτικές ενημερώσεις + backup). Το όνομα του snapshot
έχει το commit που έτρεχε πριν (`<βάση>-<ημερομηνία>-<sha>.sql.gz`).

**Πρώτα: άλλαξε το σχήμα;** (AUD8-7)
- Αν το deploy **δεν** είχε migrations, αρκεί ο παλιός κώδικας (χωρίς επαναφορά της βάσης).
- Αν **είχε**, ο παλιός κώδικας πάνω στο νέο σχήμα μπορεί να μη δουλεύει: χρειάζεται και η επαναφορά.
- **Η επαναφορά σβήνει ό,τι μπήκε μετά το snapshot:** πληρωμές, τιμολόγια, tickets από την ώρα που το site ξαναάνοιξε,
  και γυρίζει πίσω τους μετρητές αρίθμησης (διπλοί αριθμοί, παραστατικά ήδη στο Ekdosi). Μόνο αμέσως, με το site σε
  `down`, πριν μπουν νέα δεδομένα. Αργότερα: διόρθωση προς τα εμπρός (νέο deploy), όχι επαναφορά.
- **Και με το site σε maintenance μπαίνουν πληρωμές:** το return του Cardlink απαντιέται και τότε (AUD8-1). Πριν από την
  επαναφορά, κράτα όσες μπήκαν μετά το snapshot (η ώρα του είναι στο όνομά του), και ξαναπέρασέ τες μετά, γιατί αλλιώς η
  τράπεζα έχει τα χρήματα και η MyCS όχι (ADV-529-2):
  `select i.id, t.transaction_id, t.amount, t.created_at from invoice_transactions t join invoices i on i.id = t.invoice_id where t.created_at >= '<ώρα του snapshot>';`

Χειροκίνητα:
```bash
cd /var/www/mycs && php artisan down
git checkout <sha του snapshot>                 # η έκδοση που δούλευε
composer install --no-dev --optimize-autoloader && npm ci && npm run build
gunzip -c storage/app/db-snapshots/<snapshot>.sql.gz | mysql <βάση>   # μόνο αν άλλαξε το σχήμα ή τα δεδομένα, και αμέσως (βλ. πάνω)
php artisan optimize && php artisan up
```
Τα snapshots είναι στον ίδιο server: δεν αντικαθιστούν τα backups εκτός server, που είναι το JetBackup του root.
Το `APP_KEY` φυλάγεται και εκτός server (`decisions.md`, «Backups και `APP_KEY`»).
