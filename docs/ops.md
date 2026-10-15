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
| `mycs:service {id\|domain\|license key}` | status, plan, price, server, dates, order, cancellation, license (bindings, last good check, last failure), last invoices, module actions with their errors, mails, audit (fields only). |
| `mycs:client {id\|email}` | the account: login, credit, services, last invoices, open invoices, mails, audit. |
| `mycs:invoice {id\|number}` | lines (with what each bills and its period), transactions, mails, audit. |
| `mycs:zlic {key} [--ip=]` | what zlic.php would answer that license's own client from that address, writing nothing: its status or message, whether it would bind, what it would log. The PSK, md5hash and localkey never leave. |
| `mycs:logs [--limit=20] [--level=error] [--channel=laravel]` | the latest log entries, newest first, with the head of their stack, through `Redact`. |

## Rules
- **Read-only.** Nothing here writes: the zlic probe runs the real `Zlic` over a repository that only reads.
- **No secrets.** No passwords and no PSK. Of the audit, only which fields changed. Log text goes through `Redact`.
- Actions (retry a module action, resend a mail) come with the MCP as **proposals staff approve** in the admin
  (docs/mcp.md), not here.
