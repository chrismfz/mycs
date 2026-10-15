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
| `mycs:health` | build (deploy's `storage/app/build.json`), pending migrations, scheduler heartbeat (5 min), daily cron (25 h), queue (waiting, failed in 24 h), mail (sent / failed / stuck, disabled, SMTP set), module actions staff must see, license checks today and silent licenses. Exit 0 ok, 1 warn, 2 fail. |
| `mycs:service {id\|license key\|domain}` (of several with a domain, a live one) | status, plan, price, server, dates, order, cancellation, license (bindings, last good check, last failure), last invoices, module actions with their errors, mails, audit (fields only). |
| `mycs:client {id\|email}` (a primary contact's, a login's, then any contact's; several accounts are named) | the account: login, credit, services, last invoices, open invoices, mails, audit. |
| `mycs:invoice {number\|id\|#id}` (a number that is another invoice's id is asked) | lines (with what each bills and its period), transactions, mails, audit. |
| `mycs:zlic {key} [--ip=]` | what zlic.php would answer that license's own client from that address, writing nothing: its status or message, whether it would bind, what it would log. The PSK, md5hash and localkey never leave. |
| `mycs:logs [--limit=20, ≤200] [--level=error] [--channel=laravel\|payments]` | the latest log entries, newest first, with the head of their stack, through `Redact`. |

## Rules
- **Read-only.** Nothing here writes: the zlic probe runs the real `Zlic` over a repository that only reads.
- **No secrets.** No passwords and no PSK. A license key shows as its prefix and last 4 characters (the full key is a
  login to zlic.php). Of the audit, only which fields changed. Every error text and log line goes through
  `Ops\Scrub`: `Redact`'s rules, secret query keys of any URL in it (a module's API URL may carry its credentials),
  `Authorization: Bearer/Basic`, and the config's own secrets (APP_KEY, database, mail, Passport, AWS).
- Actions (retry a module action, resend a mail) come with the MCP as **proposals staff approve** in the admin
  (docs/mcp.md), not here.
