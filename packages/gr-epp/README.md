# gr-epp

The .gr / .ελ registry (ICS-FORTH) over EPP-over-HTTPS, without Laravel (CLAUDE.md: framework-free logic in
`packages/`). Design notes: `docs/domains/grepp.md`; plan G1–G7: `docs/domains.md` §6.

So far (G0) only the registry's material, copied from Ekdosi (2026-10-18):

- `resources/xsd/`: the 14 schemas of the EPP guide v4.3 (requests are validated against them in the tests).
- `tests/fixtures/v4.3/`: the guide's 72 request/response examples, names as the registry gave them. Placeholders only
  (`current-password`, `domain-auth-code`), no credentials, no customer data.
- `resources/ca/`: the registry's CA chain (HARICA root, GEANT intermediate; public). The root is in the system store:
  only for optional pinning.

The client itself (transport, session, commands) comes with G1 and G2.
