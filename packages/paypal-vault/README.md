# lib/paypal-vault

PayPal Vault v3 + Orders v2 χωρίς εξαρτήσεις από το Blesta: αποθήκευση λογαριασμού PayPal (setup
token → payment token) και χρέωση/refund χωρίς τον πελάτη παρόντα. Τη χρησιμοποιεί το gateway
`components/gateways/merchant/paypal_vault` (symlink `lib`).

- `PaypalVaultClient`: OAuth (cache του token), setup/payment tokens, `charge()` με `PayPal-Request-Id`, `refund()`.
- `TransactionResult`: απαντήσεις PayPal → `status/reference_id/transaction_id/message` του Blesta.
- `StoredAccount`: payment token → εγγραφή `accounts_cc`, και idempotency key ανά χρέωση/ημέρα.

```
composer install && vendor/bin/phpunit
```
Τα tests τρέχουν και το ίδιο το gateway πάνω σε stubs του Blesta (`tests/Gateway`).
