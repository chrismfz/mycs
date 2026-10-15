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
- **Or OAuth** (the claude.ai connector, any OAuth MCP client), as gsc-mcp-server does it: see below.

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

## OAuth (`App\Services\Mcp\McpOauth`)
MyCS has its own small OAuth 2.1 server for `/mcp`, for staff only. It is apart from the customers' Passport
(`/oauth/*`), so a customer's token can never be one for the MCP.

- **Discovery:** a 401 from `/mcp` names `/.well-known/oauth-protected-resource/mcp` (RFC 9728). That points to
  `/.well-known/oauth-authorization-server` (RFC 8414).
- **Registration:** `POST /mcp/oauth/register` (RFC 7591).
  - Public clients only (no secret), at most five redirect URIs: https, or http on localhost. A URI every parser reads
    alike: no backslash, space, user or password, an ASCII host (punycode), no fragment.
  - A client still without a grant a day later is pruned.
  - Ten per hour per address.
- **Consent:** `/mcp/oauth/authorize` is a page of its own. It is never framed, and it shows the client's name (its own
  claim) and **the host it returns to**. It runs on the admin's session with the admin's checks (the IP/agent lock, a
  password change). An error is a page with a link back to the client, never an automatic redirect (registration is
  open: no open redirector). Staff approve in one of two ways:
  - **logged in to the admin**, with «Use the MCP Server» (the page offers the admin login and comes back after it);
  - or by **pasting an admin API key** that may open the MCP. Ten wrong keys per address lock this for a quarter of an
    hour.
- **Codes:** PKCE S256 only, one minute, single use (a failed exchange uses it too). Bound to the redirect URI and to
  this resource.
- **Tokens:**
  - An access token (`mcpat_…`) lasts an hour.
  - A refresh token (`mcprt_…`) lasts 90 days and is rotated on every use. **Any** refresh token the grant already
    used, coming back, revokes the grant: someone kept a copy. So does a code used twice. A grant ends a year after
    its approval, however often refreshed.
  - A new password revokes the grants that login approved.
  - Only hashes are stored.
- **Checked on every call:** the member of staff must be active with «Use the MCP Server». A grant approved with a key
  also needs that key alive (enabled, its permission, its allowlist by address). Otherwise the grant is revoked, and
  the call refused.
- **Revoking:** **Other → MCP Authorizations** lists the grants. Each member of staff sees and revokes their own; who
  manages the API keys sees and revokes all. Approvals and revocations are audited.
- **Kept:** codes for a day, revoked or expired grants for 90 days (the cron).

### The claude.ai connector
Settings → Connectors → Add custom connector → `https://mycs.myip.gr/mcp`. claude.ai registers itself and opens the
consent page. Approve it, logged in to the admin or with a key.

## Connecting
- **Claude Code:**
  ```bash
  claude mcp add --transport http mycs https://mycs.myip.gr/mcp --header "Authorization: Bearer <key>"
  ```
- **Any client:** POST JSON-RPC to `/mcp` with `Accept: application/json, text/event-stream`.

## Next
- **Actions as proposals:** retry a module action, resend a mail, test a server module. Staff approve them in the
  admin, with an atomic claim so each runs once. A proposal expires.
