# Mail audit

Every mail MyCS sends or tries to send has a row in `email_logs`. Staff see it in **Other → Email Logs**, in the «Emails»
tab of an account, an invoice and a service, and as the «Mail» column of the invoice list. The ideas come from Ekdosi's
`invoice_mail_log` (2026-10-15). The code is MyCS's own.

## What a row keeps
- **Body:** as sent, with secrets masked (`NotificationHelper::bodyForLog`, sweep §6 #10). The page shows it in a
  sandboxed iframe.
- **Recipients:** `to`, `cc` / `bcc` (the template's) and `from_address`.
- **Template:** `template_key` (`system` for staff's system mails).
- **What it is about:** `related_type` / `related_id`. That is the invoice, else the service (also a cancellation's),
  else the order, else the ticket (`MailAudit::related`).
- **Who sent it:** `trigger` is `auto` (the system), `manual` (staff's Resend, with `staff_id`) or `batch`
  (`app:mail-resend-failed`). A resend has its own row and points back with `resent_from_id`.
- **Its way out:** `status`, `attempts`, `sending_at` / `sent_at` / `failed_at`, `error`, and the SMTP `message_id`.

## Its way out (`App\Mail\Concerns\LogsDelivery`)
The send itself keeps the row. Queue events do not, except the queue's last failure.

- **Statuses:** `pending` (queued) → `sending` → `sent`, or `failed`.
- **At most once:** the send claims its row: `pending`, or `failed` without ever going out (`sending_at` empty), and
  never a row already resent. A row already `sent`, one left `sending` by a worker that stopped mid-send (it may have
  gone out), and a refused To are not sent again by the queue, `queue:retry` or the batch. A duplicate invoice mail is
  worse than a missing one, which staff resend after checking.
- **A Cc/Bcc refused for good** (5xx at RCPT; SMTP drops the whole mail on the first refusal): the mail goes out once
  more, to the To only. The row is `sent`, its Cc/Bcc cleared, and the error names the refused address.
- **The To refused for good:** `failed` at once, with no retries. Retrying cannot help.
- **Anything else** (4xx, connection, auth, a refused sender): back to `pending` with the error, for the queue's next
  try (5 tries, from 1 minute to 1 hour). The last failure marks it `failed`, but never over `sent`.
- **Stuck rows:** `app:mail-sweep` runs every 15 minutes. A row `sending` for 30 minutes is marked failed («may have gone
  out: check before resending»). A row `pending` for 24 hours is marked failed («never sent»).

## Resend
- **Who:** staff with **Resend Email** (`admin.email_logs.resend`).
- **To whom:** the same address or another, with or without its Cc/Bcc.
- **What goes out:** the body as the log keeps it. A masked password goes out masked. The links that are a login
  (password reset, email verification) are never kept, so they never go out again.
- **Not carried over:** a resent ticket reply loses its threading headers.
- **A mail outage:** `php artisan app:mail-resend-failed [--days=7] [--limit=50] [--dry-run]` resends the customer mails
  that failed without ever going out and were not resent yet. Rows from before the audit are skipped. It runs by hand
  only and is never scheduled.

## Στον πελάτη: «Τα email μου» (2026-10-22)
`/account/emails` (`Client\Emails`), με την άδεια `emails` του WHMCS («Προβολή email»· ο owner την έχει πάντα). Δείχνει
τα **sent** του λογαριασμού, τα νεότερα πρώτα, και ανοίγει το καθένα όπως το κρατά το log: κωδικοί και links σύνδεσης
μασκαρισμένα, σε `iframe sandbox` (κανένα script, τα links σε νέα καρτέλα). Όχι τα failed/pending (δεν έφτασαν),
όχι τα `system` του staff, όχι ό,τι ξαναέστειλε το staff σε διεύθυνση staff.

## Προτιμήσεις email ανά είδος (2026-10-30)
Τα `email_preferences` της **κύριας επαφής** (general, invoice, support, product, domain, affiliate· το
`tblclients.email_preferences` του WHMCS) αποφασίζουν ποια email του λογαριασμού παίρνει ο owner (`App\Support\EmailKinds`).
`null` = όλα τα είδη (ό,τι δεν όρισε ποτέ τίποτα, όπως στο WHMCS)· `[]` = κανένα. Αποφάσεις του ιδιοκτήτη:
- **Παραλήπτης:** μόνο ο owner, όπως πριν. Οι άλλες επαφές δεν παίρνουν ακόμη email ανά είδος (το `tblcontacts` του WHMCS)·
  αυτό είναι ξεχωριστή δουλειά.
- **Είδος κάθε template:** `EmailKinds::KINDS` (τιμολόγια, πληρωμές, κάρτες: invoice· υπηρεσίες και SSL: product· απάντηση
  σε ticket: support· domains: domain· νέα παραγγελία: general). Ένα νέο template του λογαριασμού πρέπει να μπει εκεί
  (το ελέγχει test).
- **Πάντα:** αναστολή και τερματισμός υπηρεσίας, ειδοποίηση ληξιπρόθεσμου, λήξη domain, και η απάντηση του staff σε
  ticket (ο πελάτης τη ζήτησε ανοίγοντάς το, ADV-545-5) (`EmailKinds::ALWAYS`). Άρα το «Υποστήριξη» δεν κόβει ακόμη τίποτα. Επίσης όσα
  πάνε στο login και όχι στον λογαριασμό (reset κωδικού, επιβεβαίωση email, νέα σύνδεση, πρόσκληση), και τα tickets των guests.
- **Το staff στέλνει πάντα:** «Send Email» στο τιμολόγιο, νέο τιμολόγιο από το admin με «Send Email», επιστροφή χρημάτων
  (φόρμα του τιμολογίου ή ακύρωση παραγγελίας· όχι το webhook της πύλης), «Resend welcome email», «Create» και «Unsuspend» με
  «Send notification», «Accept Order» με «Send welcome email», ξαναστάλσιμο από το email log.
- Τα CC/BCC του template κόβονται μαζί με το email του owner (απόφαση 2026-10-30).
- **Μαζικό email:** η σημαντική ενημέρωση είναι το είδος του στόχου της (υπηρεσίες: product, domains: domain, όλοι: general)·
  ο λογαριασμός που δεν το παίρνει γράφεται «skipped» με τον λόγο. Τα «Νέα» μόνο με τη δική τους απεγγραφή.
- Κόβεται μόνο το email· η ειδοποίηση μέσα στην εφαρμογή μένει. Ένα email που κόπηκε δεν γράφεται στο email log.
- **Φόρμες:** επαφή με `null` δείχνεται με όλα τικαρισμένα (αλλιώς η αποθήκευση τα έσβηνε όλα)· νέος λογαριασμός από το
  admin ξεκινά με όλα, νέα επαφή (όχι κύρια) με κανένα. Η σύνοψη του πελάτη δείχνει «Κανένα» ως προειδοποίηση.
- **«Make primary»:** η νέα κύρια επαφή παίρνει τις προτιμήσεις της παλιάς, ώστε τα email του λογαριασμού να μην αλλάζουν
  επειδή τα στοιχεία του πέρασαν σε άλλη επαφή (ADV-545-6).

## Kept
`email_logs` rows are kept for 90 days (`cronjob_delete_email_logs`).

## Not done (2026-10-15)
- Neither Ekdosi nor MyCS has these; each would be new design work:
  - delivery, bounce and complaint events (provider webhooks or DSN mail);
  - open tracking;
  - a suppression list.
- A «test mode» that sends every mail to one address is not built: on live, the only account is staff's own test one.

## Μαζικό email (ROADMAP 6δ, 2026-10-21)

Αντίστοιχο του Mass Mail Tool του WHMCS: **Clients → Mass Mail Tool** (δικαιώματα `admin.bulk_mails.*`· η αποστολή θέλει `send`).
- **Είδη:**
  - **Σημαντική ενημέρωση** (`announcement`): μεταφορά server, αλλαγές δικτύου. Δεν εμφανίζεται στις προτιμήσεις του πελάτη· από τις 2026-10-30 την
    κόβουν οι προτιμήσεις email της κύριας επαφής, κατά τον στόχο της (βλ. «Προτιμήσεις email ανά είδος»).
  - **Νέα και προσφορές** (`announcement_news`): προτίμηση του πελάτη στις Ειδοποιήσεις του λογαριασμού, και link «Διακοπή αυτών των emails» στο email. Το link ανοίγει σελίδα με κουμπί (POST), ώστε ένα φίλτρο που ανοίγει μόνο του τα links να μην απεγγράφει κανέναν.
- **Κείμενο:** template όπως τα email templates, που αποδίδεται από δεδομένα και ποτέ δεν γίνεται compile.
  - Διαθέσιμα: `{{ user.first_name }}`, `{{ client.name }}`, `{% for service in services %}` και `{% for domain in domains %}` με όσα ταιριάζουν.
  - Προαιρετικά στα ελληνικά, για τους λογαριασμούς που διαβάζουν ελληνικά. Η διάταξη (header και footer) είναι των templates `announcement` και `announcement_news`.
- **Φίλτρα:**
  - όλοι οι πελάτες, ή μόνο όσοι έχουν ενεργή υπηρεσία ή domain·
  - ομάδες πελατών·
  - υπηρεσίες ανά κατάσταση (προεπιλογή: active), προϊόν και server (ο δικός της server ή του προϊόντος της)·
  - domains ανά κατάσταση και TLD.
- **Ροή:** draft → «Recipients» (πλήθος και οι πρώτοι 25) → «Send a test» → «Send».
  - Το test πηγαίνει **μόνο** στο email του μέλους του staff, όπως θα το έπαιρνε ο πρώτος παραλήπτης, χωρίς ενεργό link απεγγραφής του πελάτη.
  - Μετά το «Send» το email δεν αλλάζει πια: η αποθήκευση ελέγχεται στη γραμμή της βάσης, με lock.
  - Η αποστολή κρατά την ίδια στιγμή τη λίστα των παραληπτών, μία γραμμή ανά λογαριασμό.
  - Στέλνει μια παρτίδα το λεπτό: 60 το λεπτό από προεπιλογή, όριο που αλλάζει μόνο με τη ρύθμιση `bulk_mail_per_minute` στη βάση, όχι από το admin. Μία μόνο αλυσίδα ανά email (`batch_seq`), ώστε μια αποστολή που ξαναξεκίνησε να μη διπλασιάζει τον ρυθμό.
  - Τα «Νέα» φτάνουν μία φορά σε κάθε άτομο, ακόμα κι αν έχει πολλούς λογαριασμούς. Η σημαντική ενημέρωση φτάνει μία φορά ανά λογαριασμό, γιατί αφορά τις υπηρεσίες του.
  - Όσο τα emails είναι κλειστά (ρυθμίσεις ή template), οι παραλήπτες περιμένουν, δεν παραλείπονται.
  - Τα ονόματα και οι τιμές των πελατών μπαίνουν στο email ως απλό κείμενο: το markdown του staff αποδίδεται πριν από αυτά.
  - Τα «Νέα» έχουν και header `List-Unsubscribe` / `List-Unsubscribe-Post` (one-click, RFC 8058). Το POST δεν θέλει CSRF, γιατί το αποδεικνύει η υπογραφή του link.
  - Κάθε γραμμή δεσμεύεται πριν σταλεί, οπότε κανείς δεν παίρνει το email δύο φορές. Αν η αποστολή διακοπεί, ο cron τη συνεχίζει μετά από 10 λεπτά (`bulk_mails_resumed`).
  - Κάθε email γράφεται στο email log, στον λογαριασμό του. Ένα email που στάλθηκε δεν αλλάζει ούτε σβήνεται.
