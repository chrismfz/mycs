# Ops: health, dossiers, probes

Tools for testing and for troubleshooting live (the nixpal cut-over first): **read-only**, with **no secrets** in
their output, and the same answer everywhere. The logic is in `app/Services/Ops`. The commands below, and later the
MCP server (docs/mcp.md) and a health endpoint, are thin layers over it.

Run them as the app's user on the server (never root in the site dir):

```bash
su - mycs -c 'cd /var/www/mycs && php artisan mycs:health'
```

Every command takes `--json` (for scripts and agents).

| Command | What it says |
|---|---|
| `mycs:health` | build (deploy's `storage/app/build.json`), pending migrations, scheduler heartbeat (5 min), daily cron (25 h), queue (waiting, failed in 24 h), mail (sent / failed / stuck, disabled, SMTP set), module actions staff must see, license checks today and silent licenses, the weekly EU VAT rates check against TEDB (`docs/eu-vat.md`). Exit 0 ok, 1 warn, 2 fail. |
| `mycs:service {id\|license key\|domain}` (of several with a domain, a live one) | status, plan, price, server, dates, order, cancellation, license (bindings, last good check, last failure), last invoices, module actions with their errors, mails, audit (fields only). |
| `mycs:client {id\|email}` (a primary contact's, a login's, then any contact's; several accounts are named) | the account: login, credit, services, last invoices, open invoices, mails, audit. |
| `mycs:invoice {number\|id\|#id}` (a number that is another invoice's id is asked) | lines (with what each bills and its period), transactions, mails, audit. |
| `mycs:zlic {key} [--ip=]` | what zlic.php would answer that license's own client from that address, writing nothing: its status or message, whether it would bind, what it would log. The PSK, md5hash and localkey never leave. |
| `mycs:logs [--limit=20, ≤200] [--level=error] [--channel=laravel\|payments]` | the latest log entries, newest first, with the head of their stack, through `Redact`. |

## Automation Status (admin, #258)
The cron page («Automation Status», as WHMCS's): the whole state (the worst check) beside the scheduler and cron runs,
a card per check with a link (only for staff who may open it), then the daily actions. The page reads the checks now
(`Health::fresh()`, once per request); the menu badge («Attention»/«Failing», only for `admin.cron_stats.view`) reads
the last minute's (`Health::cached()`: one reader at a time under a lock, the others keep the last report; a cache
failure is no badge, never an error page). The details are English, as in `mycs:health`.

## GET /health (for a monitor)
`https://<instance>/health`, no session or cookie, `text/plain`, `Cache-Control: no-store`:
- **the status code is whether the instance works:** 503 when a critical check fails (`Health::CRITICAL`: the database
  and its migrations, the scheduler) or the report cannot be read; 200 otherwise. For an uptime monitor. Not for a
  load balancer either way (one instance);
- **the body is the whole state in one word:** `ok`, `warn` or `fail` (the worst check). A keyword monitor on «fail»
  also sees what needs staff without the instance being down (a ticket mailbox not read, a stuck mail, a queue backlog).

With `Authorization: Bearer <MYCS_HEALTH_TOKEN>` (`.env`, 32 characters or more; shorter or unset, never) the full
report as JSON, the same as `mycs:health`, never through a query string. It holds the build, PHP version, department
names and business counts: the token is for our own tools; an external monitor gets the plain URL only. The checks are
the last minute's (`Health::cached()`, one reader at a time): any number of calls runs them at most once a minute. A
cache that fails is `fail`/503 (said in the log once a minute). A database that cannot be reached at boot answers
Laravel's 500 before this runs: a 5xx all the same; `APP_DEBUG` stays off in production (its error page would show
the request's headers). During a deploy the maintenance mode answers 503 (the instance is down then).

## Rules
- **Read-only.** Nothing here writes: the zlic probe runs the real `Zlic` over a repository that only reads.
- **No secrets.** No passwords and no PSK. A license key shows as its prefix and last 4 characters (the full key is a
  login to zlic.php). Of the audit, only which fields changed. Every error text and log line goes through
  `Ops\Scrub`: `Redact`'s rules, secret query keys of any URL in it (a module's API URL may carry its credentials),
  `Authorization: Bearer/Basic`, and the config's own secrets (APP_KEY, database, mail, AWS).
- Actions (retry a module action, resend a mail) come with the MCP as **proposals staff approve** in the admin
  (docs/mcp.md), not here.
