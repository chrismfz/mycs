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

### Redis και cache
- **Κάθε instance έχει το δικό του κομμάτι του Redis** (sweep 8, S8-OPS-1). Το `.env.example` δίνει `CACHE_STORE=redis` και
  `QUEUE_CONNECTION=redis` με `APP_NAME=MyCS`: δύο checkouts στον ίδιο host (παραγωγή και δοκιμαστικό, nixpal και myip)
  που αλλάζουν μόνο `DB_*`, `APP_URL`, `MAIL_*` μοιράζονται την ίδια ουρά (`queues:default`), την ίδια cache ρυθμίσεων,
  το ίδιο lock του `app:cron-job` και τα ίδια mutexes του scheduler. Στο `.env` κάθε instance ορίζονται δικοί του αριθμοί
  βάσης: **`REDIS_DB`** (ουρά, locks και sessions, προεπιλογή `0`) και **`REDIS_CACHE_DB`** (cache, προεπιλογή `1`), π.χ.
  `2` και `3` για το δεύτερο. Επιπλέον ή, όπου δεν φτάνουν οι αριθμοί, αντί γι' αυτούς: **`REDIS_PREFIX`** (όλα τα κλειδιά
  του Redis, προεπιλογή `mycs_database_` από το `APP_NAME`) και **`CACHE_PREFIX`** (τα κλειδιά της cache, προεπιλογή
  `mycs_cache_`). Το prefix χωρίζει τα κλειδιά αλλά όχι ένα flush: το `cache:clear` και το import του WHMCS
  (`docs/importer.md`) κάνουν `FLUSHDB` σε όλη τη `REDIS_CACHE_DB`: αδειάζουν ό,τι έχει εκεί **οποιαδήποτε** άλλη εφαρμογή
  μοιράζεται αυτή τη βάση του Redis, όχι μόνο ένα δεύτερο checkout του MyCS. Γι' αυτό προτιμώνται δικοί του αριθμοί, που
  δεν τους χρησιμοποιεί τίποτε άλλο στον host. Μετά την
  αλλαγή: `php artisan config:cache` και restart του queue worker.
- **Στην παραγωγή η cache είναι Redis, όχι `file`** (S8-OPS-3· το store `database` θέλει πίνακα `cache`, που το σχήμα δεν έχει): στο store `file` τίποτα δεν σβήνει τα
  ληγμένα κλειδιά (π.χ. τα δύο αρχεία ανά επισκέπτη κάθε throttle), μόνο όταν ξαναζητηθεί το ίδιο κλειδί· ο φάκελος
  `storage/framework/cache` μεγαλώνει χωρίς όριο. Το `file` μένει για το devbox.

### Σε server cPanel/CloudLinux (orion, nixpal, 2026-11-13)
Ο λογαριασμός cPanel του MyCS (στον orion ο `mycsnixpal`, home στο `/home2`), με CageFS, LiteSpeed και ea-php. Τι
χρειάστηκε πέρα από το «Νέο instance»:
- **PHP 8.4 στο vhost:** `whmapi1 php_set_vhost_versions version=ea-php84 vhost=<domain>`. Το default του server
  ήταν 7.4.
- **`disable_functions`:** το `php.ini` του ea-php84 έκλεινε τα `proc_open`, `exec`, `popen` κ.λπ., που το MyCS θέλει
  στο CLI (ο scheduler τρέχει κάθε εργασία σε δική της διεργασία, το σχήμα της βάσης φορτώνεται με τον client
  `mariadb`, composer, `app:cron-wait`). Τα άνοιξε ο ιδιοκτήτης στο ea-php84 και στο ea-php85 και έκανε `cagefsctl`
  (force update, remount).
- **Το `php` του χρήστη:** στο shell ήταν το 7.4. Στο `~/bin/php` μπαίνει ένα script που κάνει
  `exec /opt/cpanel/ea-php84/root/usr/bin/php "$@"`, και στο `~/.bash_profile` το `PATH=$HOME/bin:$PATH`.
- **Document root:** ο κώδικας στο `~/mycs`. Το `public_html` γίνεται symlink: `mv public_html public_html.orig &&
  ln -s mycs/public public_html`, με `chmod 711 mycs` και `755 mycs/public`. Το block του cPanel για την PHP
  (`AddHandler application/x-httpd-ea-php84`) αντιγράφεται στο `~/.htaccess`, ώστε να ισχύει και κάτω από το `public/`.
  **Προσοχή:** μια αλλαγή PHP από το cPanel (MultiPHP) γράφει μέσα στο `mycs/public/.htaccess`, που είναι αρχείο του git.
- **Node 10, χωρίς rsync:** το `npm run build` δεν τρέχει εκεί. Το `deploy/orion.sh [ref]` χτίζει το `vendor` και τα
  assets στον devbox στο ίδιο commit, τα στέλνει με tar πάνω από ssh, και τρέχει down, checkout, migrate, optimize,
  `queue:restart`, up και ένα smoke check. Το `deploy/update.sh` δεν τρέχει εκεί.
- **`deploy/private-files.sh`:** μέσα στο CageFS δεν υπάρχει `/dev/fd`, οπότε δεν γίνεται process substitution. Από τις
  2026-11-13 το script δεν το χρειάζεται.
- **Βάση και Redis:** η βάση δημιουργείται με `uapi --user=<user> Mysql create_database/create_user/set_privileges_on_database`.
  Ο κωδικός μπαίνει κατευθείαν στο `.env`, και ακολουθεί `ALTER DATABASE … utf8mb4_unicode_ci`. Το `REDIS_DB` και το
  `REDIS_CACHE_DB` παίρνουν αριθμούς που δεν χρησιμοποιεί κανείς στον host (`redis-cli INFO keyspace`· στον orion 10/11).
- **Cron και worker:** το crontab του χρήστη με το πλήρες path:
  `* * * * * cd ~/mycs && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1`. Ο worker
  τρέχει ως unit του systemd (`/etc/systemd/system/<user>-queue.service`) με `User=<user>`, `WorkingDirectory` το `~/mycs`,
  και `ExecStart` το ea-php84 με `artisan queue:work --tries=3 --max-time=3600`.
- **https:** cPanel → Domains → «Force HTTPS Redirect» στο domain. Το MyCS δεν ανακατευθύνει μόνο του. Οι agents του
  ZoneCloud καλούν το `zlic.php` πάντα με https (logs του Οκτωβρίου 2026: 0 κλήσεις με http).
- **WHM με reseller token:** ο server της nixpal μπαίνει ως reseller (`nxpulsar`), όχι ως root. Όλες οι ενέργειες
  δουλεύουν. Μόνο το `listresellers` απαντά «Permission denied», και η απογραφή συνεχίζει χωρίς αυτό (ένα ERROR στο log
  σε κάθε συγχρονισμό).

## Ενημέρωση
```bash
su - mycs -c 'cd /var/www/mycs && deploy/update.sh'        # ή deploy/update.sh <tag|branch>
```
Πριν αλλάξει οτιδήποτε, κρατά **snapshot της βάσης** στο `storage/app/db-snapshots/` (τα τελευταία 10), και μετά:
maintenance mode, queue drain, checkout, composer, migrate, build, caches, private files, smoke check.
**Site που ήταν ήδη σε maintenance μένει σε maintenance** (sweep 6, S6-OPS-2): το deploy δεν τρέχει `up`, δεν ξεκινά
workers που δεν έτρεχαν πριν, δεν κάνει smoke check, και τελειώνει με «site was in maintenance before the deploy: left
down; run artisan up when ready». Το `app:cron-wait` περιμένει και το `schedule:run` αυτής της εγκατάστασης να τελειώσει,
όχι μόνο τα tasks που τρέχουν εκείνη τη στιγμή (S6-OPS-1)· το βλέπει όταν το deploy τρέχει ως ο χρήστης του cron ή ως root,
είτε ξεκίνησε με `cd <app> && php artisan …` είτε με το πλήρες path του artisan από άλλον φάκελο (S7-OPS-1· το ίδιο και οι queue workers). Το path μετρά ως ολόκληρο όρισμα: εγκατάσταση που το path της απλώς τελειώνει όπως το δικό μας (αντίγραφο σε `/backup/…`) δεν είναι η δική μας.
Deploy που **απέτυχε** αφήνει το site κάτω με σημάδι (`storage/framework/deploy-left-down`): το επόμενο που πετυχαίνει το
ανεβάζει, όπως πάντα. Αν στο μεταξύ το site πρέπει να μείνει κάτω (παράθυρο επιστροφής στο WHMCS), σβήσε το σημάδι πριν.
Το σημάδι γράφεται **πριν** το `down` και σβήνεται όταν το ίδιο deploy ξανανεβάσει το site (sweep 10, S10-OPS-1), όχι μόνο
όταν μια εντολή αποτύχει: έτσι μένει και όταν το deploy σκοτωθεί από σήμα (έπεσε το SSH, `kill`, OOM), και το επόμενο
deploy ανεβάζει το site και ξεκινά τους workers αντί να το περάσει για παράθυρο του staff. Site που ήταν ήδη κάτω χωρίς
σημάδι (παράθυρο του staff) δεν παίρνει σημάδι.
Το σημάδι μετρά **24 ώρες** (sweep 7, S7-OPS-2): παλιότερο, ή με ώρα στο μέλλον, αγνοείται και σβήνεται (το `php artisan up` με το χέρι δεν το
σβήνει, και ένα site που είναι κάτω μέρες μετά το έχει κατεβάσει το staff). Deploy που απέτυχε πάνω από μια μέρα πριν και
το site είναι ακόμη κάτω: μετά το επόμενο deploy, `php artisan up` με το χέρι (το λέει στις τελευταίες γραμμές του).

**Ένα τρέξιμο του cron σε εξέλιξη τελειώνει πρώτα** (sweep 9γ, `app:cron-wait`): το deploy το περιμένει πριν το
maintenance και ξανά αμέσως μετά (ο scheduler δεν ξεκινά νέο σε maintenance), ως 15 λεπτά (`CRON_WAIT_SECONDS`). Αν δεν
τελειώσει, το deploy σταματά χωρίς να έχει αλλάξει τίποτα: το ξανατρέχεις όταν τελειώσει. Lock τρεξίματος που
κόπηκε (OOM, reboot· μένει ως 2 ώρες): `php artisan app:cron-wait --release-stale`, μόνο όταν δεν τρέχει κανένα
`app:cron-job`. Περιμένει και τις άλλες προγραμματισμένες εργασίες που τρέχουν (το mutex του `withoutOverlapping`, sweep 2): μιας που κόπηκε, το mutex λήγει μόνο του (κάθε εργασία έχει λήξη ως 2 ώρες)· νωρίτερα, όταν δεν τρέχει καμία, `php artisan schedule:clear-cache`. Στο `queue:restart` περιμένει μόνο τους workers αυτού του checkout (όχι άλλου χρήστη, π.χ. του Ekdosi), ως 15 λεπτά (`QUEUE_WAIT_SECONDS`). Ένα τρέξιμο του cron που παίρνει το lock ενώ το site είναι σε maintenance δεν κάνει τίποτα (το
ημερήσιο ειδοποιεί το staff να ξανατρέξει)· και αν η ώρα του ημερήσιου πέσει μέσα σε deploy, ο scheduler το
παραλείπει, και μια εργασία στην ίδια ώρα (τρέχει και σε maintenance) ειδοποιεί το staff να το τρέξει. Αν δεν μπει σε
maintenance (`down` απέτυχε), το deploy σταματά χωρίς αλλαγές.

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

Χειροκίνητα, όλα μέσα στο ίδιο παράθυρο maintenance (L-DEPLOY-MIGRATIONS-4): ούτε cron ούτε worker της νεότερης
έκδοσης πάνω στο παλιό σχήμα, και η επαναφορά πριν από το `up`, ποτέ με το site ανοιχτό (όχι `deploy/update.sh <sha>`
και μετά επαναφορά: εκείνο, σε site που ήταν πάνω, τελειώνει με `up` και τους workers πίσω).
```bash
cd /var/www/mycs && php artisan down
php artisan app:cron-wait --timeout=900          # ένα cron run σε εξέλιξη τελειώνει πρώτα
php artisan queue:restart                        # οι workers (νεότερη έκδοση στη μνήμη) βγαίνουν μετά την τρέχουσα δουλειά
pgrep -af 'artisan queue:work'                   # ξανά, ώσπου να μη μείνει κανένας από πριν (ή: systemctl stop mycs-queue)
git checkout <sha του snapshot>                 # η έκδοση που δούλευε
composer install --no-dev --optimize-autoloader && npm ci && npm run build
# μόνο αν άλλαξε το σχήμα ή τα δεδομένα, και αμέσως (βλ. πάνω): πρώτα το query των πληρωμών, μετά η επαναφορά
gunzip -c storage/app/db-snapshots/<snapshot>.sql.gz | mysql <βάση>
php artisan optimize
php artisan queue:restart                        # όσοι workers ξεκίνησαν στο μεταξύ βγαίνουν: ξεκινούν στην παλιά έκδοση
php artisan up                                   # (ή, αν σταμάτησε το unit: systemctl start mycs-queue, πριν από αυτό)
```
Μια πληρωμή που μπήκε **όσο έτρεχε το `mysql`** (το return του Cardlink, AUD8-1) δεν τη βρίσκει το query: μετά την
επαναφορά κοίτα και τις συναλλαγές της τράπεζας για εκείνα τα λεπτά, και πέρασέ τες με το χέρι.
Τα snapshots είναι στον ίδιο server: δεν αντικαθιστούν τα backups εκτός server, που είναι το JetBackup του root.
Το `APP_KEY` φυλάγεται και εκτός server (`decisions.md`, «Backups και `APP_KEY`»).
