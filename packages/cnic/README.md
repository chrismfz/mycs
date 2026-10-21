# mycs/cnic

CentralNic Reseller's API (fka RRPproxy), as MyCS speaks it: written from its public command reference
(kb.centralnicreseller.com/api/api-command) and the MIT php-sdk's description of the wire format. No code of theirs.

- A request is a form POST to `https://api.rrpproxy.net/api/call.cgi` (OTE: `api-ote.rrpproxy.net`) with `s_login`, `s_pw`
  and `s_command`, the command as `KEY=value` lines (`Command::form()`).
- An answer is `[RESPONSE]`, `code = 200`, `description = …`, then `property[NAME][i] = value` lines, `EOF` (`Response::parse()`).
- Codes: 2xx done; 4xx temporary (try later, nothing done); 5xx refused (545: no such object in the account).
