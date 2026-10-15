# cardlink-eurobank

Cardlink/Modirum vPOS (Eurobank) hosted payment page, without Blesta: the signed request
(`PaymentRequest`, `Digest`, `OrderId`), the verified return (`ReturnVerifier`, `VposReturn`)
and the ticket we sign into the confirm URL (`ReturnTicket`).

Used by the MyCS gateway `extensions/Gateways/Cardlink` (orders kept in the database, so `ReturnTicket` is not used there); first written for the Blesta nonmerchant gateway `components/gateways/nonmerchant/cardlink_eurobank`
(symlink `lib`). Design notes: `docs/importer.md`, «Cardlink / Eurobank».

```
composer install
vendor/bin/phpunit
```

The gateway tests (`tests/Gateway`) load the real gateway class against Blesta stubs.
