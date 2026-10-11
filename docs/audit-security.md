# Paymenter: ασφάλεια (audit 2026-10-10)

> Ανάγνωση κώδικα στο `5b69d45`, χωρίς δοκιμές σε σύστημα. Η σύγκριση γίνεται με το report μας για το Blesta 6.0.3 (εκτός repo). Οι διαδρομές είναι σχετικές με τη ρίζα του Paymenter.

## Critical
Κανένα στον τρέχοντα κώδικα.

**Στο ιστορικό:** μέχρι το v1.5.7 το Stripe webhook δεχόταν κενό `stripe_webhook_secret`, οπότε όποιος ήξερε ότι
λείπει μπορούσε να πλαστογραφήσει `payment_intent.succeeded` και να σημειώσει τιμολόγιο πληρωμένο. Διορθώθηκε στο
`93738a3` (07/2026, `extensions/Gateways/Stripe/Stripe.php:808`). Είναι η ίδια κατηγορία με τα unsigned webhooks του Blesta.

## High
1. **Staff με δικαιώματα σε templates ή ρυθμίσεις εκτελεί PHP.** Τα templates ειδοποιήσεων και email, καθώς και τα
   `mail_header`/`mail_footer`, γίνονται compile ως Blade:
   - `app/Mail/Mail.php:41`
   - `resources/views/components/mail/base.blade.php:2`, `layout.blade.php:1-3`
   - `app/Helpers/NotificationHelper.php:98-100`

   Το ίδιο ισχύει για όποιον έχει `admin.settings.update` (`app/Admin/Pages/Settings.php:89`). Το upload extension
   είναι εκτέλεση κώδικα εκ σχεδιασμού.
   **Fork:** placeholder renderer αντί για Blade (όπως το `MailTemplateRenderer` του Ekdosi), και upload extension
   μόνο από CLI.
   **Διορθώθηκε (2026-10-11)** για templates, header/footer και snippet προϊόντος: `packages/mail-template`
   (`templates.md`). Μένει το upload extension.
2. **Μήνυμα εξαίρεσης γίνεται compile ως template (υποψία).** Το error handler του cron βάζει το `$e->getMessage()`
   σε `SystemMail` που γίνεται Blade (`CronJob.php:231`, `SystemMail.php`). Το ίδιο κάνει το `CheckForUpdates.php:72`
   με την απάντηση του `api.paymenter.org`.
   **Fork:** escape, και αφαίρεση του update check.
   **Διορθώθηκε:** το update check αφαιρέθηκε (Φάση 0)· το μήνυμα της εξαίρεσης γίνεται escape και το system mail
   δεν γίνεται πια compile (2026-10-11).

## Medium
3. **Ο πυρήνας πληρωμών εμπιστεύεται πλήρως το gateway** (`app/Helpers/ExtensionHelper.php:469-510`).
   - Καλό: transaction, row lock, de-dup με `transaction_id` ανά τιμολόγιο.
   - **Δεν** ελέγχει ποσό/νόμισμα, status τιμολογίου (πληρώνεται και ακυρωμένο), ιδιοκτησία ή μεταβάσεις status. Το
     `updateOrCreate` αντικαθιστά status και ποσό, άρα Succeeded → Failed επιτρέπεται. Ένα καθυστερημένο Stripe event
     μπορεί να «υποβαθμίσει» συναλλαγή (`Stripe.php:221/236`).
   - Ίδια κατηγορία με το C1 του Blesta, αλλά ηπιότερο: τα ποσά έρχονται από τον provider.
   - **Fork:** guards για ποσό, νόμισμα, status και μεταβάσεις μόνο προς τα εμπρός.
4. **Συγκρούσεις με κοινό λογαριασμό gateway** (σημαντικό όσο τρέχει παράλληλα το WHMCS).
   - **Mollie** (`Mollie.php:80-86`) και **PayPal IPN** / `PAYMENT.CAPTURE.COMPLETED` (`PayPal_IPN.php:92-105`,
     `PayPal.php:346-356`): αντιστοιχίζουν με `invoice_id` από το αντικείμενο του provider, όχι με δική τους αναφορά.
   - Το Stripe `invoice.payment_succeeded` εφαρμόζεται στο τελευταίο τιμολόγιο της υπηρεσίας (`Stripe.php:297-305`).
   - **Fork:** δική μας αναφορά ανά πληρωμή και έλεγχος νομίσματος.
5. **Κλιμάκωση δικαιωμάτων admin.**
   - Τα API keys έχουν δική τους λίστα δικαιωμάτων. Όποιος έχει `admin.api_keys.create` δίνει δικαιώματα που ο ίδιος
     δεν έχει (`ApiResource.php:52`, `Middleware/Api/AdminApi.php`).
   - Το `users.update` αλλάζει password/email άλλων admins.
   - **Fork:** όριο στο role αυτού που δημιουργεί το key, και προστασία των λογαριασμών admin.
6. **Κωδικοί servers χωρίς κρυπτογράφηση:** cPanel, Plesk, Convoy, Enhance, Virtualizor (π.χ. `CPanel.php:57`).
   Των DirectAdmin, Pterodactyl και Virtfusion είναι κρυπτογραφημένοι.
   **Fork:** `encrypted` cast σε όλους.
   **Διορθώθηκε (2026-10-11).** Βρέθηκε και κάτι χειρότερο: η **δημιουργία** server ή gateway (`CreateServer`,
   `CreateGateway`) αποθήκευε όλες τις ρυθμίσεις χωρίς το flag `encrypted`, άρα ακόμα και τα secret του Stripe/PayPal
   και ο κωδικός του DirectAdmin έμεναν καθαρά μέχρι την πρώτη επεξεργασία. Το ίδιο το `stripe_webhook_secret` που
   δημιουργείται αυτόματα. Τώρα create και edit περνούν από το `ExtensionHelper::saveSettings()`, και μια migration
   κρυπτογραφεί όσα έμειναν καθαρά.

## Low
7. Πεδία που γράφει το staff (περιγραφές προϊόντων, announcements) εμφανίζονται ως raw HTML. Είναι εκ σχεδιασμού. Τα
   tickets του πελάτη είναι ασφαλή (`html_input => escape`).
8. Τα συνημμένα tickets μένουν στο `storage/app/tickets/uploads` (εκτός web), με όνομα ULID και policy στη λήψη, αλλά
   κρατάνε την κατάληξη του πελάτη. Δεν υπάρχει διαδρομή διαγραφής από τον χρήστη.
9. Η απενεργοποίηση 2FA (`Client/Security.php:100`) και η αλλαγή email (`Client/Account.php:48`) δεν ζητάνε ξανά
   κωδικό. Το `setup-agreement` του PayPal (`PayPal.php:75`) δεν δένει το token με τον χρήστη.
10. Τα redirects της αλλαγής γλώσσας ακολουθούν το `Referer`. Δεν βρέθηκε εκμεταλλεύσιμο open redirect.

## Σημεία που στέκουν
- **Πελάτης:**
  - Policies ιδιοκτησίας (`InvoicePolicy`, `ServicePolicy`, `TicketPolicy`).
  - Livewire `#[Locked]`, με `legacy_model_binding` off.
  - Αποθηκευμένες κάρτες μέσω `Auth::user()->billingAgreements()`.
  - **Δεν βρέθηκε** IDOR, διαγραφή αρχείων, XSS από πελάτη προς staff, CSRF σε GET ή bypass του email verification.
- **Login:**
  - Rate limit ανά email και IP.
  - 2FA με 5 προσπάθειες και απόρριψη κωδικού που έχει ξαναχρησιμοποιηθεί. `tfa_secret` κρυπτογραφημένο.
  - OAuth μόνο με επιβεβαιωμένο email.
- **CSRF** παντού, εκτός από τα webhooks Stripe και Mollie. Allowlist στα push endpoints (SSRF).

## Ιστορικό
- `SECURITY.md`: υποστηρίζεται μόνο το ≥ 1.5.0, με αναφορές μέσω GitHub advisories ή email.
- CVE-2025-58048: RCE, διορθώθηκε στο 1.2.11 (κατά μνήμη, μάλλον στα συνημμένα tickets).
- Τα πρόσφατα fixes αφορούν πληρωμές και abuse: IPN (`8961efa`), το κενό secret του Stripe (`93738a3`), locks
  (`39bc14f`), race στην αρίθμηση (`4252021`), push SSRF (`e9ff5c5`), κρυπτογράφηση των credentials των gateways
  (`9db492e`), rate limits και policies που απαντούν 404 (`daa0f39`).
- Το project διορθώνει γρήγορα ό,τι του αναφέρεται, αλλά η ορθότητα των πληρωμών σφίγγει ακόμα μέσα στο 2026.

## Ετυμηγορία έναντι Blesta 6.0.3: καλύτερο
Καθαρά καλύτερο στη μεριά του πελάτη και στα gateways. Παρόμοιο, αλλά ηπιότερο, στον πυρήνα πληρωμών (C1). Παρόμοιο ή
χειρότερο στα όρια του staff: API keys, και Blade που επιτρέπει εκτέλεση PHP. Όλα όσα βρέθηκαν διορθώνονται στο fork,
γιατί ο κώδικας είναι ανοιχτός.
