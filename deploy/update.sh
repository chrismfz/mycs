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
#   3. a cron run in progress waited for, maintenance mode ON, queue worker drained
#   4. DB snapshot (rollback point) → storage/app/db-snapshots/ (keeps $SNAPSHOT_KEEP)
#   5. the old cached config/events/routes cleared, checkout the target
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
#   QUEUE_WAIT_SECONDS=900  on the queue:restart path, how long to wait for a worker's current job
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
# One deploy at a time (sweep 3, L-DEPLOY-MIGRATIONS-3): a second run's `down` succeeds over the first's, and the first's
# `up` would then open the site, the scheduler and the workers over the second's half-done checkout and migrations. The
# lock is the open file descriptor: it goes with this process (and its children), a crashed run leaves none behind. A
# worker that QUEUE_START_CMD leaves running gets it closed (9>&-), or it would hold the lock after the deploy.
mkdir -p storage/framework
exec 9>>storage/framework/deploy.lock
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || { fail "Another deploy is running on $(pwd) (storage/framework/deploy.lock) — nothing changed. Wait for it, then re-run."; exit 1; }
else
  warn "flock not found: no lock against a second deploy running at the same time."
fi
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
# A site staff had put down before the deploy (the return to WHMCS, cutover-runbook §3; a planned window) stays down
# after it, and workers that were not running then are not started (sweep 6, S6-OPS-2).
# A deploy that failed leaves the site down itself, with a marker: the re-run that succeeds brings it up (REV-767-1).
_was_down=0
_stay_down=0
_had_workers=1
_LEFT_DOWN=storage/framework/deploy-left-down
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
  # queue:restart only sets a flag: a worker exits after its current job (a domain renewal, a mail batch: minutes).
  # Its job must be over before the snapshot and the migrations (sweep 2, F-SCHEDULER-QUEUE-1): this checkout's
  # workers, as they are now, are waited for (their supervisor may bring new ones back; those sleep in maintenance).
  local _pids
  _pids="$(_queue_worker_pids)"
  log "Asking queue workers to exit (queue:restart)"
  $ART queue:restart || { fail "queue:restart failed."; return 1; }
  _stopped_by="restart"
  [[ -z "$_pids" ]] || _wait_queue_workers "$_pids"
}
# This checkout's queue workers: their cwd here, or this checkout's artisan named by its path on their command line,
# wherever they were started from (a unit with no WorkingDirectory: sweep 7, S7-OPS-1). Another user's worker on the host (Ekdosi's `php artisan queue:work`, its cwd unreadable
# to mycs) never sees this checkout's queue:restart: waiting for it failed the deploy (sweep 2 review, REV2-FSQ-1).
_queue_worker_pids() {
  local pid cwd here
  here="$(pwd -P)"
  for pid in $(pgrep -f "${QUEUE_WORKER_MATCH:-[a]rtisan( .*)? queue:(work|listen)}" 2>/dev/null || true); do
    cwd="$(readlink "/proc/$pid/cwd" 2>/dev/null || true)"
    if [[ "$cwd" == "$here" ]]; then echo "$pid"; continue; fi
    # A whole argument (NUL between them in /proc), or a word of a title written as one string: never the end of a
    # longer path, another install under …/backup$here (review of #777, REV-777-7).
    if { tr '\0' '\n' <"/proc/$pid/cmdline" | grep -Fxq -- "$here/artisan"; } 2>/dev/null \
      || { tr '\0 ' '\n\n' <"/proc/$pid/cmdline" | grep -Fxq -- "$here/artisan"; } 2>/dev/null; then echo "$pid"; fi
  done
  return 0
}
_wait_queue_workers() {
  local pid left waited=0 limit="${QUEUE_WAIT_SECONDS:-900}"
  log "Waiting for the queue workers to finish their current job (up to ${limit}s)"
  while :; do
    left=""
    for pid in $1; do
      if kill -0 "$pid" 2>/dev/null || [[ -d "/proc/$pid" ]]; then left="$left $pid"; fi
    done
    [[ -z "$left" ]] && return 0
    if (( waited >= limit )); then
      fail "Queue workers still on a job after ${limit}s (PIDs:${left}) — QUEUE_WAIT_SECONDS=… to wait longer."
      return 1
    fi
    sleep 1; waited=$((waited + 1))
  done
}
start_queue_worker() {
  if [[ $_stay_down == 1 && $_had_workers == 0 ]]; then
    log "Queue: no worker was running before the deploy (the site was already down) — none started"
    return 0
  fi
  case "$_stopped_by" in
    hook)    [[ -n "${QUEUE_START_CMD:-}" ]] && { log "Starting queue worker (QUEUE_START_CMD)"; eval "${QUEUE_START_CMD}" 9>&- || true; } ;;
    systemd) log "Starting queue worker (systemd: ${QUEUE_SERVICE})"; systemctl --no-ask-password start "${QUEUE_SERVICE}" 2>/dev/null \
               || fail "Could not start ${QUEUE_SERVICE} — START IT YOURSELF NOW." ;;
    # A worker its supervisor brought back during maintenance holds the old code, config and settings (AUD8-5):
    # asked to exit once more, now that the new release is up.
    restart) log "Queue: workers asked to exit again, to start on the new release"; $ART queue:restart || true ;;
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
# A cron run in progress finishes first (sweep 9γ): it would go on half in the old code over a migrating database. The
# scheduler starts none in maintenance; one that began just before it is waited for again after. A release without the
# command (the first deploy of it) skips the wait.
cron_wait() {
  if $ART list --raw 2>/dev/null | grep -q '^app:cron-wait'; then
    $ART app:cron-wait --timeout="${CRON_WAIT_SECONDS:-900}"
  fi
}
log "Waiting for a cron run in progress, if any"
cron_wait || { fail "A cron run is still in progress — nothing changed. Re-run the deploy once it is over (or CRON_WAIT_SECONDS=… to wait longer; a crashed run's lock: php artisan app:cron-wait --release-stale)."; exit 1; }
# Up again on a failed second wait only if this deploy put it down (a failed deploy left it down: ADV-CW-4): asked of
# the app, whatever its driver (R2-4, R3-1); the file driver's file on a release without the command (its first deploy).
if $ART list --raw 2>/dev/null | grep -q '^app:is-down'; then
  $ART app:is-down && _was_down=1
else
  [[ -f storage/framework/down ]] && _was_down=1
fi
# Down without a failed deploy's marker: staff's own window, left down at the end. Found up: a marker is stale. So is
# one older than 24 hours (its mtime): that failed deploy was resolved by hand (`php artisan up` leaves the marker), and
# a site down now is staff's own window (sweep 7, S7-OPS-2).
# One dated in the future (a clock set back) is stale too. A marker that cannot be removed (another user's file) is said,
# and still means nothing to this deploy.
_marker_stale=0
_marker_time="$(stat -c %Y "$_LEFT_DOWN" 2>/dev/null || echo 0)"
_now="$(date +%s)"
if [[ -f "$_LEFT_DOWN" ]] && (( _now - _marker_time > 86400 || _marker_time > _now + 60 )); then
  _marker_stale=1
  if rm -f "$_LEFT_DOWN" 2>/dev/null; then
    log "Ignoring a failed deploy's marker older than 24 hours, or dated in the future (removed)"
  else
    log "Ignoring a failed deploy's marker older than 24 hours, or dated in the future (could not remove $_LEFT_DOWN: another user's file? remove it by hand)"
  fi
fi
if [[ $_was_down == 1 ]]; then
  [[ -f "$_LEFT_DOWN" && $_marker_stale == 0 ]] || _stay_down=1
else
  rm -f "$_LEFT_DOWN" || true
fi
log "Maintenance mode ON"
# Not down, nothing proves a cron run can't start under the migrations: stop, nothing changed (R2-2). Already down is
# no failure (`down` says so and exits 0).
$ART down --retry=15 || { fail "Could not enter maintenance mode — nothing changed (is storage/ writable, the disk full?)."; exit 1; }
cron_wait || {
  [[ $_was_down == 0 ]] && { $ART up || true; }
  fail "A cron run started just before maintenance and is still running — nothing changed$([[ $_was_down == 0 ]] && echo ', the app is up again'). Re-run the deploy once it is over."
  exit 1
}
trap '[[ $_stay_down == 1 ]] || : > "$_LEFT_DOWN" || true; fail "Update FAILED — the app is still in maintenance mode. Fix the error, then re-run, or: php artisan up"; start_queue_worker' ERR
# Already down: were this checkout's workers running (or, with no stop hook, their unit active)? Only then are they
# started again.
if [[ $_stay_down == 1 ]]; then
  _had_workers=0
  [[ -n "$(_queue_worker_pids)" ]] && _had_workers=1
  [[ -z "${QUEUE_STOP_CMD:-}" ]] && _have_unit && systemctl is-active --quiet "${QUEUE_SERVICE}" 2>/dev/null && _had_workers=1
fi
stop_queue_worker || {
  [[ $_was_down == 0 ]] && { $ART up || true; }
  fail "The queue workers did not stop (a job still running?) — nothing changed$([[ $_was_down == 0 ]] && echo ', the app is up again'). Re-run the deploy once it is over."
  exit 1
}

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
# The old release's cached config, events and routes out first: composer's artisan scripts (step 6) and the migrations
# (step 7) must boot the new release as it is, not with a config key or a listener map it no longer matches (sweep
# SETTINGS-OPS-6). Cleared by the old release (its code and vendor still agree), before the checkout. Not optimize:clear:
# that also empties the cache store (locks, the settings, rate limits). Rebuilt at step 9.
log "Clearing the cached config / events / routes"
$ART config:clear
$ART event:clear
$ART route:clear
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
# --isolated=1: a migrate already running (another process, whatever the lock above misses) is a failure, not a
# silent success with its migrations still half applied.
$ART migrate --force --isolated=1
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
# The workers told first, then the site up (sweep 3, L-DEPLOY-MIGRATIONS-2): on the queue:restart path a worker its
# supervisor brought back during maintenance holds the old release and only sleeps; told after `up`, it could wake in
# between and take a job on the old code. Told before, it exits at its next pause check, having taken none. The ones
# started after the signal start on the new release; a unit or hook started here sleeps until `up`.
start_queue_worker
if [[ $_stay_down == 0 ]]; then
  log "Maintenance mode OFF"
  $ART up
  rm -f "$_LEFT_DOWN" || true
fi
# The console relay, where it runs on this server (the devbox; docs/proxmox.md, B6), told now, with the new release in
# place: it sees its `restart_file` touched, exits, and systemd starts the new code. Its consoles drop; the customer
# reconnects. Where none runs (a cPanel host: the relay is next to the cluster), the file is only a file.
touch storage/framework/console-relay.restart 2>/dev/null || true

# A site left down answers 503 by design: no smoke check of it.
if [[ $_stay_down == 0 ]]; then
  # The site's address as .env has it, quotes off (AUD8-6: a quoted APP_URL was «no answer»).
  _url="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d "\"'" | sed 's:/*$::')"
  if [[ -n "$_url" ]] && command -v curl >/dev/null 2>&1; then
    _code="$(curl -sL --max-time 20 -o /dev/null -w '%{http_code}' "$_url/" || true)"
    _health="$(curl -sL --max-time 20 -o /dev/null -w '%{http_code}' "$_url/health" || true)"
    if [[ "$_code" != "200" ]]; then
      # Never «Deployed» over a site that does not answer (AUD8-6): the release is in place, the site is up, look now.
      fail "Smoke check: $_url → ${_code:-no answer}. The new release is live: check it NOW (rollback: docs/install.md)."
      exit 1
    fi
    ok "Smoke check: $_url → 200"
    # /health is 503 while the scheduler has not run since the maintenance window: told, not a failed deploy.
    [[ "$_health" == "200" ]] || warn "/health → ${_health:-no answer}: look at it in a few minutes (docs/ops.md)."
  fi
fi
ok "Deployed $(git rev-parse --short HEAD) ($REF). Previous: ${CURRENT_SHA:0:8} — rollback: docs/install.md («Αν κάτι πάει στραβά»: the snapshot restored inside the maintenance window, the workers restarted before up)."
if [[ $_stay_down == 1 ]]; then
  [[ $_had_workers == 0 ]] && warn "The queue workers were not running before the deploy: not started. Start them when you bring the site up."
  warn "site was in maintenance before the deploy: left down; run artisan up when ready"
fi
