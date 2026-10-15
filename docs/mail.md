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
- **At most once:** the send claims its row (pending/failed → sending). A row already `sent`, or left `sending` by a
  worker that stopped mid-send, is not sent again. A duplicate invoice mail is worse than a missing one, which staff
  resend.
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
- **What goes out:** the body as the log keeps it. A masked password goes out masked.
- **A mail outage:** `php artisan app:mail-resend-failed [--days=7] [--limit=50] [--dry-run]` resends the customer mails
  that failed and were not resent yet. It runs by hand only and is never scheduled.

## Kept
`email_logs` rows are kept for 90 days (`cronjob_delete_email_logs`).

## Not done (2026-10-15)
- Neither Ekdosi nor MyCS has these; each would be new design work:
  - delivery, bounce and complaint events (provider webhooks or DSN mail);
  - open tracking;
  - a suppression list.
- A «test mode» that sends every mail to one address is not built: on live, the only account is staff's own test one.
