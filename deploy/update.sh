#!/usr/bin/env bash
#
# MyCS — one-step update of an installed instance (devbox, staging, production).
# Adapted from Ekdosi's deploy/update.sh (same flow, MyCS-specific steps only).
#
#   deploy/update.sh [GIT_REF]
#
# GIT_REF = what to deploy. Omit it for the everyday flow: the CURRENT branch's
# pushed tip (origin/main when on main) — the `git pull` workflow. Pass a tag
# (e.g. v1.0.0) or a branch to deploy that instead; tags/shas check out detached.
# A DOWNGRADE (target is an ancestor of the current HEAD) is refused unless
# ALLOW_DOWNGRADE=1.
#
# Steps (safe, idempotent; nothing changes before step 5):
#   1. pre-flight: no uncommitted TRACKED changes
#   2. fetch, resolve the target, refuse a downgrade / a checkout that would fail
#   3. maintenance mode ON, queue worker drained
#   4. DB snapshot (rollback point) → storage/app/db-snapshots/ (keeps $SNAPSHOT_KEEP)
#   5. checkout the target
#   6. composer install (--no-dev unless COMPOSER_DEV=1, e.g. on the devbox)
#   7. migrate --force (and the old Passport keys removed)
#   8. npm ci && npm run build
#   9. optimize (config/route/view/event caches), then private files (deploy/private-files.sh)
#  10. maintenance mode OFF, queue worker back, smoke check of APP_URL
#
# Env overrides:
#   APP_DIR=/var/www/mycs   run against another checkout (default: this repo)
#   PHP=php  COMPOSER=composer  NPM=npm
#   COMPOSER_DEV=1          keep dev dependencies (tests, pint). Default: DEPLOY_COMPOSER_DEV
#                           from .env (set DEPLOY_COMPOSER_DEV=1 in the devbox .env). Refused with
#                           APP_ENV=production, as APP_DEBUG=true is, unless ALLOW_DEV_IN_PRODUCTION=1
#   SKIP_SNAPSHOT=1         no DB snapshot (only for throwaway installs)
#   SNAPSHOT_KEEP=10        snapshots to keep
#   QUEUE_SERVICE=mycs-queue  systemd unit of the worker
#   QUEUE_STOP_CMD / QUEUE_START_CMD  explicit hooks (win over systemd)
#   ALLOW_DOWNGRADE=1
#
# Run from a private copy, under bash: the checkout below replaces this very
# file, and bash reads a script while it runs — editing it mid-run can execute
# half-lines. Also covers `sh deploy/update.sh`.
if [ -z "${MYCS_UPDATE_COPY:-}" ]; then
  APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
  _copy="$(mktemp "${TMPDIR:-/tmp}/mycs-update.XXXXXX")"
  cp "$0" "$_copy"
  MYCS_UPDATE_COPY="$_copy" APP_DIR="$APP_DIR" exec bash "$_copy" "$@"
fi
trap 'rm -f "$MYCS_UPDATE_COPY"' EXIT
set -Eeuo pipefail

cd "$APP_DIR"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"
ART="$PHP artisan"
REF="${1:-}"
QUEUE_SERVICE="${QUEUE_SERVICE:-mycs-queue}"
SNAPSHOT_KEEP="${SNAPSHOT_KEEP:-10}"

log()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; }
warn() { printf '\n\033[1;33m! %s\033[0m\n' "$*"; }

[[ -f artisan && -f .env ]] || { fail "Not an installed MyCS checkout: $(pwd) (artisan/.env missing)."; exit 1; }
_env() { { grep -E "^$1=" .env || true; } | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
COMPOSER_DEV="${COMPOSER_DEV:-$(_env DEPLOY_COMPOSER_DEV)}"
# Production never gets dev packages (Debugbar, Ignition) nor debug output (security audit SEC-13): refused, unless
# ALLOW_DEV_IN_PRODUCTION=1 says it is meant (a one-off on a box that is not really production). APP_ENV and APP_DEBUG
# as Laravel reads them (phpdotenv: quotes, comments, `export`, CRLF, untrimmed inside quotes), and debug on as
# Laravel's env() has it: anything but false/0/empty/null (review of #392).
# Laravel's own Env decides, as config/app.php reads it (`env('APP_ENV', 'production')`, `(bool) env('APP_DEBUG',
# false)`: the process environment over .env, its conversions and quotes), and PHP prints only two digits on a line of
# its own: no value of .env is echoed, so none can shift or forge the answer (review of #392). When PHP cannot tell,
# refused: there is no knowing then whether this is production.
_parsed="$($PHP -d display_errors=stderr -d display_startup_errors=0 -r '
  require "vendor/autoload.php";
  Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), getcwd())->safeLoad();
  $env = Illuminate\Support\Env::get("APP_ENV", "production");
  echo "\nMYCS_GUARD=", strtolower((string) $env) === "production" ? 1 : 0, " ", (bool) Illuminate\Support\Env::get("APP_DEBUG", false) ? 1 : 0, "\n";' 2>&1 || true)"
_answer="$(grep -E '^MYCS_GUARD=[01] [01]$' <<<"$_parsed" | tail -1 || true)"
if [[ -z "$_answer" ]]; then
  if [[ "${ALLOW_DEV_IN_PRODUCTION:-0}" != "1" ]]; then
    fail "Cannot read .env as Laravel does ($PHP with vendor/autoload.php): APP_ENV and APP_DEBUG unknown. Fix PHP/vendor or .env (or ALLOW_DEV_IN_PRODUCTION=1). PHP said:"
    printf '%s\n' "$_parsed" | tail -5 >&2; exit 1
  fi
  _answer="MYCS_GUARD=0 0"
fi
_app_env="$([[ "${_answer:11:1}" == "1" ]] && echo production || echo other)"
_app_debug="$([[ "${_answer:13:1}" == "1" ]] && echo true || echo false)"
if [[ "$_app_env" == "production" && "${ALLOW_DEV_IN_PRODUCTION:-0}" != "1" ]]; then
  if [[ "${COMPOSER_DEV:-0}" == "1" ]]; then
    fail "APP_ENV=production with COMPOSER_DEV/DEPLOY_COMPOSER_DEV=1: dev packages would be installed. Unset it (or ALLOW_DEV_IN_PRODUCTION=1)."; exit 1
  fi
  if [[ "$_app_debug" == "true" ]]; then
    fail "APP_ENV=production with APP_DEBUG=true: error pages would show code and settings. Set APP_DEBUG=false (or ALLOW_DEV_IN_PRODUCTION=1)."; exit 1
  fi
fi

# --- queue worker -----------------------------------------------------------
# Stop the worker before touching the schema so no job runs against a
# half-migrated database. Order: explicit hook → systemd unit (if we may stop
# it) → portable `queue:restart` (each worker exits after its current job; the
# supervisor brings it back, and a worker started without --force sleeps while
# the app is in maintenance mode).
_stopped_by=""
_have_unit() { command -v systemctl >/dev/null 2>&1 && systemctl cat "${QUEUE_SERVICE}.service" >/dev/null 2>&1; }
stop_queue_worker() {
  if [[ -n "${QUEUE_STOP_CMD:-}" ]]; then
    log "Stopping queue worker (QUEUE_STOP_CMD)"
    if eval "${QUEUE_STOP_CMD}"; then _stopped_by="hook"; return 0; fi
    warn "QUEUE_STOP_CMD failed — falling back to queue:restart."
  elif _have_unit && systemctl --no-ask-password stop "${QUEUE_SERVICE}" 2>/dev/null; then
    log "Stopped queue worker (systemd: ${QUEUE_SERVICE})"
    _stopped_by="systemd"; return 0
  fi
  log "Asking queue workers to exit (queue:restart)"
  $ART queue:restart
  _stopped_by="restart"
}
start_queue_worker() {
  case "$_stopped_by" in
    hook)    [[ -n "${QUEUE_START_CMD:-}" ]] && { log "Starting queue worker (QUEUE_START_CMD)"; eval "${QUEUE_START_CMD}" || true; } ;;
    systemd) log "Starting queue worker (systemd: ${QUEUE_SERVICE})"; systemctl --no-ask-password start "${QUEUE_SERVICE}" 2>/dev/null \
               || fail "Could not start ${QUEUE_SERVICE} — START IT YOURSELF NOW." ;;
    restart) log "Queue: workers were asked to exit — their supervisor (systemd/cron) restarts them." ;;
  esac
  return 0
}

# --- 1. pre-flight ----------------------------------------------------------
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  fail "Working tree not clean — commit/stash changes first (don't edit code on the server)."
  git status --short --untracked-files=no | sed 's/^/    /' >&2
  exit 1
fi

# --- 2. target --------------------------------------------------------------
log "Fetching"
git fetch --tags --prune origin '+refs/heads/*:refs/remotes/origin/*'

if [[ -z "$REF" ]]; then
  REF="$(git symbolic-ref --quiet --short HEAD || echo main)"
  log "No ref given — deploying branch tip: $REF"
fi
if git rev-parse --verify --quiet "origin/${REF}^{commit}" >/dev/null; then
  TARGET_REF="origin/${REF}"; ON_BRANCH=1
else
  TARGET_REF="$REF"; ON_BRANCH=0
fi
TARGET_SHA="$(git rev-parse --verify "${TARGET_REF}^{commit}" 2>/dev/null)" || { fail "Unknown ref: $REF"; exit 1; }
CURRENT_SHA="$(git rev-parse HEAD)"
echo "Current: ${CURRENT_SHA:0:8}   →   Target: $REF (${TARGET_SHA:0:8})"

if [[ "$CURRENT_SHA" == "$TARGET_SHA" ]]; then
  ok "Already on ${TARGET_SHA:0:8} — rebuilding caches only."
elif git merge-base --is-ancestor "$TARGET_SHA" "$CURRENT_SHA" && [[ "${ALLOW_DOWNGRADE:-0}" != "1" ]]; then
  fail "Target is OLDER than the current HEAD (downgrade). Set ALLOW_DOWNGRADE=1 if you mean it."
  exit 1
fi

# Would the forced checkout fail (e.g. skip-worktree files the target changes)?
git update-index -q --refresh || true
if ! git read-tree -n -m -u HEAD "$TARGET_SHA" >/dev/null 2>&1; then
  fail "git says the checkout of ${TARGET_SHA:0:8} would fail — nothing changed. Inspect: git status, git ls-files -v | grep '^S'"
  exit 1
fi

_untracked="$(git ls-files --others --exclude-standard | head -20)"
[[ -n "$_untracked" ]] && { warn "Untracked files (left as they are):"; echo "$_untracked" | sed 's/^/    /'; }

# --- 3. maintenance + worker -------------------------------------------------
log "Maintenance mode ON"
$ART down --retry=15 || true
trap 'fail "Update FAILED — the app is still in maintenance mode. Fix the error, then re-run, or: php artisan up"; start_queue_worker' ERR
stop_queue_worker

# --- 4. DB snapshot ----------------------------------------------------------
if [[ "${SKIP_SNAPSHOT:-0}" != "1" ]]; then
  log "DB snapshot (rollback point)"
  mkdir -p storage/app/db-snapshots
  _snap="storage/app/db-snapshots/$(_env DB_DATABASE)-$(date +%Y%m%d-%H%M%S)-${CURRENT_SHA:0:8}.sql.gz"
  ( umask 077
    MYSQL_PWD="$(_env DB_PASSWORD)" mariadb-dump --single-transaction --quick --routines --triggers \
      -h "$(_env DB_HOST)" -P "$(_env DB_PORT)" -u "$(_env DB_USERNAME)" "$(_env DB_DATABASE)" | gzip > "$_snap" )
  ok "Snapshot: $_snap ($(du -h "$_snap" | cut -f1))"
  ls -1t storage/app/db-snapshots/*.sql.gz 2>/dev/null | tail -n +$((SNAPSHOT_KEEP + 1)) | xargs -r rm -f
fi

# --- 5. checkout -------------------------------------------------------------
log "Checkout $REF (${TARGET_SHA:0:8})"
if [[ "$ON_BRANCH" == "1" ]]; then
  git checkout --force -B "$REF" "$TARGET_REF"
else
  git checkout --force "$TARGET_SHA"
fi
mkdir -p storage/app
printf '{"sha":"%s","committed_at":"%s","ref":"%s","previous":"%s"}\n' \
  "$(git rev-parse --short HEAD)" "$(git log -1 --format=%cI)" "$REF" "${CURRENT_SHA:0:8}" > storage/app/build.json

# --- 6. composer ---------------------------------------------------------------
if [[ "${COMPOSER_DEV:-0}" == "1" ]]; then
  log "composer install (with dev)"
  $COMPOSER install --optimize-autoloader --no-interaction
else
  log "composer install (--no-dev)"
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
fi

# --- 7. database ---------------------------------------------------------------
log "Database migrations"
$ART migrate --force
# No Passport any more (security audit AUTHN-10): its keys, if a host still has them, are no use to anyone.
rm -f storage/oauth-private.key storage/oauth-public.key

# --- 8. assets -----------------------------------------------------------------
if [[ -f package-lock.json ]]; then
  log "Building front-end assets"
  $NPM ci --no-audit --no-fund
  $NPM run build
fi

# --- 9. caches -----------------------------------------------------------------
log "Caching config / routes / views / events"
$ART optimize

# The cached config holds APP_KEY and the database password: never readable by another user of the host.
log "Private files"
bash deploy/private-files.sh "$APP_DIR"

# --- 10. back up ---------------------------------------------------------------
trap - ERR
log "Maintenance mode OFF"
$ART up
start_queue_worker
# The console relay (docs/proxmox.md, B6), told now, with the new release in place: it exits and systemd starts
# the new code. Its consoles drop; the customer reconnects.
$ART proxmox:console-relay --restart >/dev/null 2>&1 || true

_url="$(grep -E '^APP_URL=' .env | cut -d= -f2-)"
if [[ -n "$_url" ]] && command -v curl >/dev/null 2>&1; then
  _code="$(curl -s -o /dev/null -w '%{http_code}' "$_url/" || true)"
  [[ "$_code" == "200" ]] && ok "Smoke check: $_url → 200" || warn "Smoke check: $_url → ${_code:-no answer}"
fi
ok "Deployed $(git rev-parse --short HEAD) ($REF). Previous: ${CURRENT_SHA:0:8} — rollback: deploy/update.sh ${CURRENT_SHA:0:8} with ALLOW_DOWNGRADE=1 (+ restore the snapshot if migrations changed data)."
