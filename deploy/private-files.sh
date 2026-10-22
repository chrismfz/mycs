#!/usr/bin/env bash
#
# MyCS — keep secrets and customer data to the app's own user (run by deploy/update.sh after its caches, and by hand
# after an install). Another user on the host (another site's PHP, another app's pool) cannot read the cached config
# (APP_KEY, the database password), .env, logs, sessions, invoices, ticket files or the database snapshots.
#
#   deploy/private-files.sh [APP_DIR]
#
# The web server still reaches public/ and the uploads under storage/app/public: the folders on the way keep only
# their "x" for others (711), and what it serves stays as it is.
set -Eeuo pipefail
cd "${1:-$(cd "$(dirname "$0")/.." && pwd)}"
[[ -f artisan ]] || { echo "Not a MyCS checkout: $(pwd)" >&2; exit 1; }

# The way to public/ and to storage/app/public: passed through, never listed or read.
chmod 711 . storage storage/app
# Secrets: owner only.
for file in .env .db-credentials storage/oauth-private.key bootstrap/cache/*.php; do
  if [[ -f "$file" ]]; then chmod 600 "$file"; fi
done
# Customer data and the app's working files: owner only, whatever their files' own modes.
for dir in bootstrap/cache storage/logs storage/framework storage/debugbar; do
  if [[ -d "$dir" ]]; then chmod -R go-rwx "$dir"; fi
done
find storage/app -mindepth 1 -maxdepth 1 ! -name public ! -name .gitignore -exec chmod -R go-rwx {} +
echo "Private files: done ($(pwd))."
