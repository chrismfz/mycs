# myopenprovider (lib)

Our dependency-free client for the Openprovider REST API (v1beta) and the renewal
decision, used by the Blesta module `components/modules/myopenprovider` (symlink `lib`).
No Blesta, no SDK: plain JSON over a `Transport` (cURL in production, a fake in tests).

- `Client`: login + bearer token (kept in a `TokenStore`, re-login once on 401),
  `findDomain` (prefers the live record over a deleted one), `getDomain`, `renewDomain`,
  `restoreDomain`, `setAutorenew`. Logs never contain the password, the token or auth codes.
- `RenewalPlan`: what a paid renewal does (renew / restore from soft quarantine / skip what
  is already done / refuse with a reason), using our pending/done record so a retry never
  charges twice. Pure, fully unit tested.
- `MetaStore`, `MetaTokenStore`: the renewal record and the token in Blesta's module meta.
- `OpenproviderException::isDefinitive()`: false when the outcome of a write is unknown
  (no answer, 5xx, body without Openprovider's envelope).

Endpoints and client behaviour ported from Ekdosi's `OpenproviderRegistrar`; the automatic
soft-quarantine restore follows Openprovider's WHMCS module (in Ekdosi restore is a staff action). Field names are
checked against [openprovider/rest-client-php](https://github.com/openprovider/rest-client-php)
(generated from Openprovider's OpenAPI); we track its releases instead of depending on it,
see `docs/openprovider.md`.

```
composer install
vendor/bin/phpunit
```

`tests/Module` runs the real Blesta module class against Blesta stubs.
