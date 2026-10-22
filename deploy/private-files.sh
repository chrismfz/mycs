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
#
# Never fails a deploy: it changes only what the app's user owns (the owner of artisan), says what it left, and does
# nothing at all when run as another user (root: the app could no longer read what it made private).
set -uo pipefail
cd "${1:-$(cd "$(dirname "$0")/.." && pwd)}" || exit 0
[[ -f artisan ]] || { echo "Not a MyCS checkout: $(pwd)" >&2; exit 0; }
me="$(id -un)"
owner="$(stat -c %U artisan)"
if [[ "$me" != "$owner" ]]; then
  echo "! Private files: skipped, run as $me, not as the app's user ($owner): su - $owner -c 'bash $(pwd)/deploy/private-files.sh'" >&2
  exit 0
fi

# The way to public/ and to storage/app/public: passed through, never listed or read.
for dir in . storage storage/app; do
  if [[ -d "$dir" && -O "$dir" ]]; then chmod 711 "$dir"; fi
done
# Secrets: owner only.
for file in .env .db-credentials bootstrap/cache/*.php; do
  if [[ -f "$file" && -O "$file" ]]; then chmod 600 "$file"; fi
done
# Customer data and the app's working files: owner only, whatever their files' own modes.
private=()
for dir in bootstrap/cache storage/logs storage/framework storage/debugbar; do
  if [[ -d "$dir" ]]; then private+=("$dir"); fi
done
while IFS= read -r -d '' dir; do private+=("$dir"); done < <(find storage/app -mindepth 1 -maxdepth 1 ! -name public ! -name .gitignore -print0 2>/dev/null)
if [[ ${#private[@]} -gt 0 ]]; then
  find "${private[@]}" -user "$me" -exec chmod go-rwx {} + 2>/dev/null
  others="$(find "${private[@]}" ! -user "$me" -print 2>/dev/null | head -5)"
  if [[ -n "$others" ]]; then
    echo "! Private files: not the app's, left as they are (chown them to $me):" >&2
    printf '    %s\n' $others >&2
  fi
fi
echo "Private files: done ($(pwd))."
exit 0
