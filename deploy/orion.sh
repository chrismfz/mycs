#!/bin/bash
# deploy/orion.sh [ref]: MyCS nixpal on orion (clients.nixpal.com, cPanel account mycsnixpal). docs/install.md,
# «Σε server cPanel/CloudLinux».
# orion has Node 10 and no rsync: vendor and the Vite build are made here at the same commit and copied over (tar on ssh).
# Order: build here; on orion, fetch and check the commit (and a clean tree) BEFORE maintenance; down, checkout, copy,
# package:discover, migrate, optimize, worker restart, up; smoke check. A step that fails after `down` says so loudly:
# the site is left in maintenance on purpose (new code on the old schema is worse), to be brought up by hand.
set -euo pipefail
REF=${1:-origin/main}
W=/root/mycs-work/orion-build
H=root@orion.myip.gr
APP=/home2/mycsnixpal/mycs
PHP=/opt/cpanel/ea-php84/root/usr/bin/php
URL=https://clients.nixpal.com

# The remote runs as the app's user, in bash with errexit and pipefail (the local ones do not reach it: review of #830).
remote() { ssh -o BatchMode=yes $H "su - mycsnixpal -s /bin/bash -c 'set -eo pipefail; cd $APP && $1'"; }

cd "$W"
git -c safe.directory='*' fetch -q origin
git -c safe.directory='*' checkout -q --detach "$REF"
C=$(git -c safe.directory='*' rev-parse HEAD)
echo "commit $C"
composer install -q --no-dev --optimize-autoloader --no-interaction
npm ci --silent >/dev/null 2>&1
npm run build >/dev/null 2>&1

# Before the site goes down: the remote has the commit (pushed, GitHub reachable) and no tracked file changed by hand
# (MultiPHP writes public/.htaccess), else the checkout would fail with the site down (REV-830-1).
remote "git fetch -q && git cat-file -e $C^{commit} && test -z \"\$(git status --porcelain --untracked-files=no)\"" \
  || { echo "orion: commit $C not reachable there, or tracked files changed by hand (git status): nothing done, the site is up." >&2; exit 1; }

down=1
trap '[[ $down == 1 ]] && echo "!! FAILED with the site DOWN (maintenance). Fix, then: su - mycsnixpal -c \"cd $APP && $PHP artisan up\"" >&2' EXIT
remote "$PHP artisan down --retry=30 >/dev/null && git checkout -q --detach $C && git log -1 --oneline"
tar -czf - vendor public/build | ssh -o BatchMode=yes $H "set -e; cd $APP && \\rm -rf vendor public/build && tar -xzf - && chown -R mycsnixpal:mycsnixpal vendor public/build"
# composer's post-autoload-dump never runs there: the package manifest is rebuilt here (REV-830-3).
remote "$PHP artisan package:discover >/dev/null && $PHP artisan migrate --force && $PHP artisan optimize >/dev/null && $PHP artisan queue:restart >/dev/null && $PHP artisan up"
down=0

fail=0
for p in / /sysadmin/login; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$URL$p") || code=000
  echo "$p $code"
  [[ $code == 200 ]] || fail=1
done
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/zlic.php") || code=000
echo "/zlic.php $code (400 without a license)"
[[ $code == 400 ]] || fail=1
if [[ $fail == 1 ]]; then echo "!! deployed $C but the smoke check failed: look at the site now" >&2; exit 1; fi
echo "deployed $C"
