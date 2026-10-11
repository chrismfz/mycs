# Εγκατάσταση και ενημέρωση

Ένα instance ανά εταιρεία (nixpal, myip). Η εγκατάσταση γίνεται **μόνο από το CLI** (`mycs:install`): δεν υπάρχει web
installer, γιατί ένα `/install` στο internet είναι επιφάνεια επίθεσης μέχρι να κλειδώσει, και τα instances τα στήνουμε
εμείς μέσω ssh (ίδια λογική με τα extensions, CLI/git μόνο).

## Απαιτήσεις
PHP 8.4 (τα extensions του `composer.lock`), MariaDB 10.11 (utf8mb4_unicode_ci), Node 20+, nginx, ένας χρήστης
συστήματος για την εφαρμογή (π.χ. `mycs`). Παράδειγμα vhost και systemd: `HANDOFF.md` (devbox).

## Νέο instance
```bash
git clone <repo> /var/www/mycs && cd /var/www/mycs
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && chmod 600 .env     # DB_*, APP_URL, MAIL_*· όχι secrets στο repo
php artisan key:generate                    # κρυπτογραφεί τα αποθηκευμένα credentials: κράτα αντίγραφο του .env
php artisan mycs:install                    # ρωτάει εταιρεία, URL, πρώτο admin, κωδικό (κρυφά)
```
Το `mycs:install` τρέχει migrations και το βασικό seed (ρόλος admin, προεπιλογές ρυθμίσεων, templates), δημιουργεί
το νόμισμα (EUR, `1.000,00 €`), γράφει εταιρεία/URL/γλώσσα (client area `el` + `en`, admin `en`)/ζώνη ώρας
(`Europe/Athens`), τα passport keys, το `storage:link` και τον πρώτο admin. Αρνείται αν υπάρχει ήδη χρήστης
(`--force` για δεύτερο admin ή για να ξαναγράψει τις ρυθμίσεις· **δεν** αλλάζει κωδικό υπάρχοντος χρήστη).

Χωρίς ερωτήσεις (π.χ. από script). Ο κωδικός **ποτέ** ως flag (μένει στο history και στο `ps`):
```bash
MYCS_ADMIN_PASSWORD='…' php artisan mycs:install --no-interaction \
  --company="nixpal" --url=https://my.nixpal.com --first-name=… --last-name=… --email=…
```
Επιλογές: `--language=el`, `--timezone=Europe/Athens`, `--currency=EUR|USD`.

Μετά: cron `* * * * * php artisan schedule:run`, queue worker (systemd), mail (SMTP/SPF/DKIM), TLS.

## Ενημέρωση
```bash
su - mycs -c 'cd /var/www/mycs && deploy/update.sh'        # ή deploy/update.sh <tag|branch>
```
Πριν αλλάξει οτιδήποτε, κρατά **snapshot της βάσης** στο `storage/app/db-snapshots/` (τα τελευταία 10), και μετά:
maintenance mode, queue drain, checkout, composer, migrate, build, caches, smoke check.

## Αν κάτι πάει στραβά
Δεν υπάρχει αυτόματο rollback (απόφαση 2026-10-11: προσεκτικές ενημερώσεις + backup). Το όνομα του snapshot
έχει το commit που έτρεχε πριν (`<βάση>-<ημερομηνία>-<sha>.sql.gz`). Χειροκίνητα:
```bash
cd /var/www/mycs && php artisan down
git checkout <sha του snapshot>                 # η έκδοση που δούλευε
composer install --no-dev --optimize-autoloader && npm ci && npm run build
gunzip -c storage/app/db-snapshots/<snapshot>.sql.gz | mysql <βάση>   # μόνο αν το migrate άλλαξε δεδομένα
php artisan optimize && php artisan up
```
Τα snapshots είναι στον ίδιο server: δεν αντικαθιστούν τα backups εκτός server (ROADMAP Φάση 0).
