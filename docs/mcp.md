# MCP server

MyCS has an MCP server at **`https://<host>/mcp`** for staff and their agents (Claude Code, Claude Desktop, another
MCP client). It serves the tools of docs/ops.md and is meant for testing and troubleshooting, the nixpal cut-over first.
The design follows Ekdosi's (laravel/mcp, a server class, one tool per question). Auth uses MyCS's own admin API keys.

## Access
- **Who:** staff only. Customers' Passport tokens never open it.
- **The key:** an **admin API key** (Admin → API Keys) with the permission **«Use the MCP Server»**.
  - Its owner's role needs «Use the MCP Server» too, and the owner must be active.
  - The key's IP allowlist applies.
  - 120 calls a minute per key.
- **How:** the client sends the key as `Authorization: Bearer <key>`, and the request acts as that member of staff.
- **Per tool:** each tool is offered only to a role that may see what it shows. For example, `invoice` needs View
  Invoices.
- **What a key opens:** the MCP, and in it what its owner's role may see. The key's own API permissions do not narrow
  the tools further: most tools have no API permission of their own. For a narrower agent, give it a key of a member of
  staff with a narrower role.
- **Recorded:** every call that reaches a tool goes to **Other → MCP Calls**, kept 90 days. The row has the staff, the
  key, the tool, its own arguments (bounded; secrets masked; license keys as prefix…last4), the outcome and the
  duration. A tool the role may not use is not offered at all, so a call to it ends as «not found», before any tool
  runs.
- **Addresses:** the key's IP allowlist and the recorded address use the connection's address. A caller cannot name
  its own in X-Forwarded-For, even with «trust all proxies» (`App\Support\CallerIp`, as zlic.php and the admin API).
- **No OAuth yet.** The claude.ai web connector needs OAuth. MyCS's Passport serves the customers, so the connector
  needs its own staff guard and consent screen first. That comes later, as its own PR.

## Tools (read-only)
| Tool | Needs | Same as |
|---|---|---|
| `health` | Use the MCP Server | `mycs:health` |
| `service` (id, license key, domain) | View Services | `mycs:service` |
| `client` (id, email) | View Clients | `mycs:client` |
| `invoice` (number, id, #id) | View Invoices | `mycs:invoice` |
| `module_actions` (open, given up) | View Services | Pending Module Actions |
| `email_logs` (status, to, template, account) | View Email Logs | Email Logs |
| `failed_jobs` | View Failed Jobs | failed jobs |
| `logs` (level, laravel / payments) | View Failed Jobs | `mycs:logs` |
| `zlic_probe` (key, ip) | View Services | `mycs:zlic` |

- **Masked:** passwords, PSKs, API credentials in URLs, Authorization headers and the config's secrets come out as
  `********`. A license key comes out as its prefix and last 4.
- **Errors:** a failing tool returns an MCP error with a masked message, never a stack.

## Connecting
- **Claude Code:**
  ```bash
  claude mcp add --transport http mycs https://mycs.myip.gr/mcp --header "Authorization: Bearer <key>"
  ```
- **Any client:** POST JSON-RPC to `/mcp` with `Accept: application/json, text/event-stream`.

## Next
- **Actions as proposals:** retry a module action, resend a mail, test a server module. Staff approve them in the
  admin, with an atomic claim so each runs once. A proposal expires.
- **OAuth for the claude.ai connector.**
