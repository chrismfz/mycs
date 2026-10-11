# zlic golden fixtures

`verify_responses.json`: what WHMCS `verify.php` returns per case (captured, anonymised).

What `zlic.php` (see `reference/whmcs/zlic.php`) turns each case into. This is what the new
plugin must emit:

| Case | HTTP | zlic JSON |
|---|---|---|
| missing `license` or `license_PSK` | 400 | `{"status":"Invalid","description":"Missing license or license_PSK"}` |
| unknown key | 200 | `{"status":"Invalid","remotecheck":true}` |
| IP not bound (IP conflict not allowed) | 200 | `{"status":"Invalid","message":"IP Address Invalid","remotecheck":true}` (no `md5hash` from verify, so no PSK check) |
| wrong PSK (any case that carries `md5hash`) | 200 | all verify tags, `status` overwritten to `Invalid`, + `"description":"MD5 Checksum Verification Failed"`; **no** `remotecheck` |
| Expired / Suspended | 200 | all verify tags (incl. `md5hash`) + `"remotecheck":true` |
| Active | 200 | all verify tags (incl. `md5hash`) + `"checkdate":"YYYYMMDD"` + `"localkey":"…"` + `"remotecheck":true` |

Notes:
- verify.php never sends `description` for Expired/Suspended; the "License Expired" /
  "License Suspended" strings exist only in the access log.
- `localkey` = `wordwrap(strrev(md5(checkdate.PSK) . base64(json_encode(results_with_checkdate))) . md5(that . PSK), 80, "\n", true)`,
  where `results_with_checkdate` still contains `md5hash`.
- `nextduedate` is `0000-00-00` for free accounts; the Go client treats `00000000` as "no due date".
