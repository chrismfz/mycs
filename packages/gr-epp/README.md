# gr-epp

The .gr / .ελ registry (ICS-FORTH) over EPP-over-HTTPS, without Laravel (CLAUDE.md: framework-free logic in
`packages/`). Design notes: `docs/domains/grepp.md`; plan G1–G7: `docs/domains.md` §6.

So far (G0) only the registry's material, copied from Ekdosi (2026-10-18):

- `resources/xsd/`: the 14 schemas of the EPP guide v4.3 (requests are validated against them in the tests).
- `tests/fixtures/v4.3/`: the guide's 72 request/response examples, names as the registry gave them. Placeholders only
  (`current-password`, `domain-auth-code`), no credentials, no customer data.
- `resources/ca/`: the registry's CA chain (HARICA root, GEANT intermediate; public). The root is in the system store:
  only for optional pinning.

## G1: transport and session

```php
$session = new Session(new Config($clId, $password), new CurlTransport);
$response = $session->command($xml);   // logs in when needed; a 1xxx Response
$session->logout();
```

- `Http\CurlTransport`: POST over HTTPS only, the peer verified, no redirects, `Content-Type: text/xml;charset=UTF-8`,
  Set-Cookie kept. `Http\Transport` is the seam the tests fake.
- `Session`: a hello for the JSESSIONID cookie (the part before the first «;»), then login; commands with the cookie, one
  at a time (the caller holds a lock per account); a fresh login after `Config::maxIdle` (5 minutes; the registry ends
  a session idle for 15) and once on 2002 (the guide: «invalid session»); logout best effort.
- `Frame`: hello, login (password in CDATA, no `<svcExtension>`, `lang` el or en, optional `newPW`), logout; valid to
  `resources/xsd/epp-1.0.xsd`.
- `Response`: a greeting or a response; first result's code and message, clTRID/svTRID, the registry's comment, the
  DOM and an XPath with the registry's namespaces (for G2). No network or entities when parsing.
- Failures, as the app needs them (D-17):
  - `Refused`: a 2xxx answer: nothing done (its code and the registry's words);
  - `OutcomeUnknown`: sent, no usable answer (timeout, HTTP not 200, not EPP, 2400, 25xx): maybe done, never resent;
  - `NotSent`: never reached the registry (no connection, login refused or failed): safe to send again.
- The password: never in `var_dump`/`print_r` (`__debugInfo`), exceptions or a serialised copy (refused).

Commands and their parsers come with G2; the app's driver with G3.
