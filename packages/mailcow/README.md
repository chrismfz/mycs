# lib/mailcow

mailcow API v1 χωρίς εξαρτήσεις από το Blesta: domain + domain admin ανά υπηρεσία, DKIM, overview
(mailboxes/aliases όλων των domains του admin), SSO, και η πολιτική suspend/terminate. Port του δικού μας
WHMCS module (`modules/servers/mailcow` στον rigel). Το χρησιμοποιεί το `components/modules/mailcow`
(symlink `lib`).

- `MailcowClient`: τα calls· σφάλματα του mailcow μέσα σε HTTP 200 γίνονται `MailcowException`· το log
  δεν βλέπει ποτέ API key ή κωδικούς.
- `DomainLimits`: τα όρια του πακέτου (quotas σε MB, rate limit) και έλεγχος συνέπειας.
- `Policy`: suspend κλειδώνει μόνο τον domain admin· terminate `lock_only` ή `delete_all`· admin με πολλά
  domains δεν κλειδώνει χωρίς force.
- `Sso`, `Credentials`, `Usage`: token/URL του SSO, username/κωδικός νέας υπηρεσίας, bytes για τους πίνακες.

```
composer install && vendor/bin/phpunit
```
Τα tests τρέχουν και το ίδιο το Blesta module πάνω σε stubs (`tests/Module`).
