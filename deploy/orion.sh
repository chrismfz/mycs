#!/bin/bash
# deploy-orion.sh [ref]: MyCS nixpal on orion (clients.nixpal.com, cPanel account mycsnixpal).
# orion has Node 10 and no rsync: vendor and the Vite build are made here at the same commit and copied over (tar on ssh).
# Order: build here, maintenance on, pull, copy, migrate, optimize, worker restart, maintenance off, smoke check.
set -euo pipefail
REF=${1:-origin/main}
W=/root/mycs-work/orion-build
H=root@orion.myip.gr
APP=/home2/mycsnixpal/mycs
PHP=/opt/cpanel/ea-php84/root/usr/bin/php

cd "$W"
git -c safe.directory='*' fetch -q origin
git -c safe.directory='*' checkout -q --detach "$REF"
C=$(git -c safe.directory='*' rev-parse HEAD)
echo "commit $C"
composer install -q --no-dev --optimize-autoloader --no-interaction
npm ci --silent >/dev/null 2>&1
npm run build >/dev/null 2>&1

ssh -o BatchMode=yes $H "su - mycsnixpal -s /bin/bash -c 'cd $APP && $PHP artisan down --retry=30 >/dev/null && git fetch -q && git checkout -q --detach $C && git log -1 --oneline'"
tar -czf - vendor public/build | ssh -o BatchMode=yes $H "cd $APP && \\rm -rf vendor public/build && tar -xzf - && chown -R mycsnixpal:mycsnixpal vendor public/build"
ssh -o BatchMode=yes $H "su - mycsnixpal -s /bin/bash -c 'cd $APP && $PHP artisan migrate --force 2>&1 | tail -3 && $PHP artisan optimize >/dev/null && $PHP artisan queue:restart >/dev/null && $PHP artisan up'"
ssh -o BatchMode=yes $H "for p in / /sysadmin/login /zlic.php; do curl -sS -o /dev/null -w \"\$p %{http_code}\n\" https://clients.nixpal.com\$p; done"
echo "deployed $C"
