<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## MyCS audit: events, notifications, tickets, queue and runtime (EVT)

Static reading only. Nothing was edited and nothing was run against a database. Line numbers are from `main` @ b67f57e.

**EVT-1. "Invoice created" and "order created" emails ignore the transaction outcome and are deferred to process exit**
- Severity: **High**. Type: bug. Confirmed by reading.
- Where: `app/Observers/InvoiceObserver.php:40-45, 84-89`, `app/Observers/OrderObserver.php:25-30`. Callers: `CronJob.php:67,277-303`, `Livewire/Cart.php:156-169`.
- How it works: `Finalized` is sent through `dispatch(closure)->afterResponse()`, which is just a `terminating()` callback. It is not tied to DB commit.
- Failure scenarios:
  - **Rollback still sends the email.** Cron `billRenewal` saves the invoice, then item create or `payWithCredit` throws. The per-service transaction rolls back, but the closure still runs at the end of the command. The customer gets a "new invoice" email and PDF for an invoice that does not exist. Its number was taken inside the rolled-back transaction (the `InvoiceNumberListener` savepoint), so the next invoice reuses the same number. Checkout has the same problem if anything throws after `$order->save()`.
  - **Cron batching.** Every invoice email of the whole cron run is held in memory until the process ends. A fatal error, OOM or kill means zero invoice emails and no `EmailLog` trace.
  - **Queue workers.** Workers never "terminate", so an invoice created in a queued job would never be emailed until the worker restarts. This is latent today.
  - **Credit-paid invoices.** These get "paid" (sent at commit) before "new invoice" (sent at process end).
- Fix: use `DB::afterCommit` and a queued, `afterCommit` listener per invoice and order. Effort S. **Now.**

**EVT-2. PDF generation and email run inside the payment path; one exception aborts the remaining after-commit callbacks**
- Severity: **High**. Type: bug / fragile design. Confirmed by reading (exception chain in `DatabaseTransactionRecord::executeCallbacks`).
- Where: `NotificationHelper.php:155-176`, `InvoiceObserver.php:91-97` (Paid inside `DB::afterCommit`), `SendMailListener` (synchronous).
- How it works: `invoicePaidNotification` renders the PDF and writes it to `storage/app/invoices/{number}.pdf`. That path has no directory handling, and `invoice_number_format` allows `/` (`Classes/Settings.php:587-594`).
- Failure scenario: staff set a common Greek format such as `ΤΙΜ/{year}/{number}`, so `pdf->save` throws.
  - The paid webhook returns 500.
  - Every after-commit callback queued after this one is skipped. That includes `ProcessPaidInvoiceService` for other invoices in the same allocation, and any job dispatched with `after_commit=true`.
  - Result: a paid invoice whose service is never renewed or provisioned.
- Other problems in the same code:
  - The PDF is generated even when the template is disabled or `mail_disable` is on.
  - PDFs are never deleted.
  - `{id}.pdf` and `{number}.pdf` can collide.
  - A queued "new invoice" email can attach a PDF that a later run has already overwritten as PAID.
- Fix: make the email listeners `ShouldQueue` with `afterCommit`, generate the PDF inside the mail job (`attachData`), and keep notifications out of money flows (try/report). Effort S–M. **Now.**

**EVT-3. Ticket email import (FetchEmails): one bad message blocks all imports, and replies are lost silently**
- Severity: **High** for the nixpal pilot, which uses Paymenter tickets with piping until the Phase 3 port. Type: bug. Confirmed by reading.
- Where: `app/Console/Commands/FetchEmails.php:53-136`, `routes/console.php:11`.
- One bad message blocks everything:
  - `ticket_mail_logs.message_id` is NOT NULL, so a message without a Message-ID throws on insert.
  - A message delivered only via Bcc has an empty `to()`, so `[0]` throws an ErrorException.
  - Either way the outer `catch` aborts the loop. The bad message is never logged, so every run every 5 minutes stops at the same message. Later mail is not imported until it falls out of the `since(1 day)` window.
- Replies lost silently:
  - The From comparison is case-sensitive (`!==`, line 95), so `John@X.gr` against stored `john@x.gr` is rejected.
  - The "processed" log row is written before the message is created, with no transaction. If the create fails, the mail is marked processed and never retried.
  - "Unprocessed" mail has no admin UI and no alert.
  - After an outage of more than one day, older mail is never fetched.
  - Messages are never flagged or moved, so the full bodies of the last day are re-downloaded every 5 minutes.
  - Errors only go to `Log::error`.
  - The `withoutOverlapping` lock (24h expiry) can block imports for a day if `schedule:run` is killed.
- Smaller issues:
  - No Auto-Submitted, Precedence or bounce detection, so out-of-office replies reopen tickets.
  - Attachments have no size or extension limits.
  - `EmailReplyParser` does not recognise Greek quote headers ("Στις … έγραψε:"), so the whole quoted history is imported.
- Fix: per-message try/catch that logs the failure, nullable or fallback ids, case-insensitive compare, a transaction, a failure alert, flag `\Seen`, and an admin list of unprocessed mail. Effort S–M. **Now (pilot).**

**EVT-4. Anyone can post a message into any customer's ticket by email**
- Severity: **Medium**. Type: security. Confirmed by reading.
- Where: `FetchEmails.php:77-99`, `TicketReplyEmailListener.php:29-36`.
- How it works:
  - The only check is that the `From` header equals the ticket owner's email.
  - The ticket is found from `In-Reply-To` = `{ticketMessageId}@host`. Ids are sequential and the host part is not checked.
  - `References` uses `{ticketId}@host`, the same format as message ids, so the two id spaces are ambiguous.
- Failure scenario: an attacker forges From = victim and In-Reply-To = `<1234@anything>`. A message is appended to the victim's ticket as the victim, for example "send the EPP code to …".
- WHMCS behaviour: also checks the sender, but uses random ticket masks.
- Fix: unguessable per-ticket token (HMAC in the Message-ID or a `reply+token@` address), check the host, and check DMARC/SPF via `Authentication-Results`. Design it now so threads already in customers' mailboxes keep working after cut-over. Effort M. With the tickets port (ROADMAP Phase 3), with the token design now.

**EVT-5. No payment reminders or overdue notices before suspension**
- Severity: **High**. Type: WHMCS-parity gap. Confirmed by reading.
- Where: `CronJob.php`. The `cronjob_invoice_reminder` setting (`Settings.php:459`) is unused, but the admin UI shows it as if it worked.
- Failure scenario: customers get one invoice email, then are auto-suspended after `cronjob_order_suspend` days and terminated later, with no warning in between.
- WHMCS behaviour: invoice payment reminder before due, then first, second and third overdue notices.
- Fix: port Ekdosi `Reminders` (decisions §10); meanwhile hide the dead setting. Effort M. **Before the nixpal go-live** (ROADMAP Phase 1 "υπενθυμίσεις"; overlaps decisions.md §10).

**EVT-6. Support notifications are almost entirely missing**
- Severity: **High**. Type: WHMCS-parity gap. Confirmed by reading.
- Where: `TicketMessageCreatedListener.php`. `Ticket\Created` has no listener at all.
- What is missing:
  - No confirmation to the customer when a ticket is opened.
  - **No staff email when a ticket is opened, when a customer replies, or when a ticket is assigned.**
  - No auto-close notice.
  - No internal notes: every staff message goes to the customer.
  - Statuses are only open, replied and closed (no Customer-Reply, On Hold or In Progress).
  - Auto-close uses days, not hours.
- Failure scenario: nixpal staff only learn about replies by polling the admin list.
- Fix: for the pilot, a minimal staff notification on customer messages (email to a department address). The rest comes with the tickets port. Effort S now, then L. Overlaps ROADMAP 181 and 199 (Mattermost).

**EVT-7. Many WHMCS email templates have no equivalent**
- Severity: **Medium**. Type: WHMCS-parity gap. Confirmed by reading.
- Where: `database/seeders/EmailTemplateSeeder.php`. There are 12 templates.
- Missing: service unsuspended, refund confirmation, credit added, upgrade/downgrade confirmation, all domain emails (registration, transfer, renewal, expiry), admin notifications (new order, cancellation request, payment received, module failure), welcome/signup, ticket opened or auto-closed.
- Invoice and ticket emails are `choice_on`, so customers can opt out of invoice emails.
- Fix: write the template matrix against the WHMCS list. Effort M. With ROADMAP 196 and Phase 2 (domains).

**EVT-8. Emails and PDFs have no per-customer language**
- Severity: **Medium**. Type: WHMCS-parity gap. Confirmed by reading.
- Where: `users` has no locale column; `SetLocale` is session-only; mail is rendered in the worker with the app locale.
- Failure scenario: the invoice PDF and any `__()` strings come out in the customer's checkout session language when triggered from the web, and in the default language when triggered from cron. The same customer gets mixed languages. Templates exist in one language only.
- Fix: add `users.locale` (imported from WHMCS `tblclients.language`), have mailables use `->locale($user->locale)`, and keep el/en templates per key. Effort M. Overlaps decisions.md 76-77 and ROADMAP 196.

**EVT-9. A failed provisioning action leaves the database and the server out of step, and nobody is told**
- Severity: **Medium**. Type: fragile design. Confirmed by reading.
- Where: `app/Jobs/Server/*.php`, `config/queue.php:51`.
- How it works:
  - All jobs have `tries=1` and no `failed()` hook, and there is no admin alert or retry list (WHMCS has the Module Queue).
  - The service status changes before the job runs, so a failed suspend leaves the DB saying "suspended" while the server keeps running. A failed create leaves an active service with no account.
  - `TerminateJob` does not re-check status (Suspend and Unsuspend do), so a staff reactivation between dispatch and run is ignored and the account is still terminated.
  - Job `$timeout=120` is greater than `retry_after=90`. With more than one worker, a long create is marked failed (MaxAttemptsExceeded) while it is still running.
  - Failed jobs are never pruned or monitored.
- Fix: `failed()` writes a module-failure record and emails the admin, a retry action, a fresh status check in TerminateJob, and `retry_after` above 120. Effort M. Overlaps ROADMAP 57 (Pending Module counter) and 139 (monitoring).

**EVT-10. Email delivery is not robust**
- Severity: **Medium**. Type: fragile design. Confirmed by reading.
- Where: `AppServiceProvider.php:160-178`, `Mail.php`, `NotificationHelper.php:33-55`.
- Problems:
  - `Mail` has no tries or backoff, so with the worker default of 1 try a transient SMTP error means the email is permanently failed, with no resend.
  - `Queue::after` and `failing` only handle `App\Mail\Mail`, so `SystemMail` logs stay "pending" forever.
  - The `EmailLog` body is rendered at queue time, but the template is rendered again in the worker, so the log can differ from what was sent. CC and BCC are not logged.
  - `SerializesModels` does not recurse into the `$data` array. Whole `User` and `Invoice` models, including the password hash, are written into `jobs` and kept in `failed_jobs`.
  - Email logs are deleted after 90 days; WHMCS keeps them.
- Fix: tries and backoff on mail, store ids (not models) in the payload, handle `SystemMail`, plus a resend action. Effort S–M. Overlaps ROADMAP 196 (#1204, #1033).

**EVT-11. Settings cache holds decrypted secrets in plaintext**
- Severity: **Medium**. Type: security. Confirmed by reading.
- Where: `Providers/SettingsProvider.php:26-30` with `Events/Setting/Retrieved.php`.
- What happens: the `settings` cache entry stores values after decryption, including the SMTP password, IMAP password and VAPID private key. With `CACHE_STORE=file` they sit in plaintext under `storage/framework/cache`, which goes into `storage/` backups.
- Fix: cache the raw encrypted value and decrypt when loading into config, or exclude encrypted keys. Effort S. **Now.**

**EVT-12. Failures at process start are silently swallowed**
- Severity: **Medium**. Type: fragile design. Confirmed by reading.
- Where: `SettingsProvider::getSettings` (`catch (Exception) {}`) and the extension boot loop in `AppServiceProvider` (outer `catch` with "Fail silently").
- Failure scenarios (a DB blip while a long-running worker starts up):
  - The worker runs until restart on code defaults: suspend after 2 days instead of the configured value, credits off, `mail_disable` false.
  - Stripe and PayPal `Service\Updated` and `ServiceCancellation\Created` listeners are not registered, so subscriptions are not cancelled when a service is cancelled.
- Fix: report and rethrow in console and worker; a health check. Effort S. **Now.**

**EVT-13. Every invoice number restarts the queue workers**
- Severity: **Medium**. Type: fragile design. Confirmed by reading.
- Where: `InvoiceNumberListener.php:43`, `FlushCacheListener.php`.
- What happens:
  - The invoice counter is stored as a global `Setting`. Every invoice or credit-note number triggers `Cache::forget('settings')` and `queue:restart`, so every checkout and admin invoice restarts the workers.
  - Inside a worker, the terminating flush never runs, and the static `$flushed` blocks any later flush.
- Fix: move counters to a `sequences` table, or skip flushing for counter keys. Effort S. With the numbering work.

**EVT-14. Duplicate "payment failed" emails, and PDF rendering inside payment locks**
- Severity: Low. Type: bug. Confirmed by reading.
- Where: `SendMailListener.php:34-40`, `CronJob.php:316-319`.
- What happens:
  - The listener fires on any `Updated` of a failed transaction, with no `wasChanged('status')` check: a fee update, processing→failed, or a staff edit sends it again.
  - A billing-agreement failure in cron sends its own email while the gateway webhook may also record a failed payment, so the customer gets two.
  - The PDF is rendered synchronously inside `RecordPaymentService`'s locked transaction.
- Fix: check `wasChanged('status')`, keep one sender, and queue it (see EVT-2). Effort S.

**EVT-15. Ticket auto-close can skip tickets**
- Severity: Low. Type: bug. Confirmed by reading.
- Where: `CronJob.php:161-172`.
- What happens: `Ticket::where('status','replied')->each()` pages by offset while updating the column it filters on. With more than 1,000 replied tickets, some are skipped.
- Fix: `chunkById` or `lazyById`. Effort S.

**EVT-16. Debug HTTP log only hides top-level password and authorization fields**
- Severity: Low. Type: security. Confirmed by reading.
- Where: `Listeners/RequestListener.php:19`.
- What leaks when debug mode is on: the captcha `secret` (`Traits/Captchable.php`), DirectAdmin `passwd`/`passwd2` (customer passwords), PayPal `access_token` in responses, and any nested keys. All of it is visible in the admin HTTP log.
- Fix: recursive denylist (secret, token, key, passwd, pass). Effort S.

**EVT-17. Discord notifications (the planned model for Mattermost) run synchronously inside transactions**
- Severity: Low. Type: fragile design. Confirmed by reading.
- Where: `extensions/Others/DiscordNotifications/DiscordNotifications.php:105-257`.
- What happens:
  - The network call runs inside the cron's per-service transaction and inside checkout, with the default 30s HTTP timeout, while row locks are held.
  - "Invoice Created" is posted before the items exist (total 0), and is posted twice when an invoice goes draft→pending.
  - "Ticket Created" is posted before the first message exists.
  - The "updated" payloads include email and `tfa_secret` changes.
- Fix: build Mattermost as queued and after-commit. Effort S. With ROADMAP 199.

**EVT-18. Verification email sent too often; new-login email cannot be turned off**
- Severity: Low. Type: parity / UX. Confirmed by reading.
- Where: `SendMailListener.php:41-44, 59-80`.
- What happens: `UserCreated` always sends the verification email, even when `email_verified_at` is already set (admin, API or OAuth-created users). The "new login" email is `force` (no opt-out) and fires on every new IP, which is noisy for Greek dynamic IPs.
- Fix: skip verification when already verified; make the login email a choice. Effort S.

**EVT-19. Immediate cancellation has edge cases**
- Severity: Low. Type: bug. Confirmed by reading.
- Where: `Listeners/CancellationCreatedListener.php`.
- What happens: it is queued, so if the cancellation is deleted before the worker runs, the job fails. It does not re-check the service status, so stock can be incremented twice. It bulk-cancels invoices with `->update()`.
- Overlap: ROADMAP 81 and money-trail §3.

**EVT-20. Timezone has two sources of truth**
- Severity: Low. Type: fragile design. Suspected.
- Where: `SettingsProvider` calls `date_default_timezone_set(settings.timezone)` over `APP_TIMEZONE`.
- What happens: datetimes are stored without a timezone, in local time. Changing the setting later shifts the meaning of every stored timestamp, and the DST fall-back hour is ambiguous.
- Fix: lock the setting after install, or store UTC (bigger change). Effort S (lock). Later.

**Overall health:** the notification layer inherited from Paymenter works for the simple path but is not transaction-safe. Emails are tied to request or process end rather than DB commit, and they run synchronously inside payment flows, where one failure can stop renewals or provisioning (EVT-1, EVT-2). Queue jobs, email delivery and IMAP import have no retry, alerting or per-item isolation, so failures are silent. WHMCS parity is weakest in dunning reminders, support notifications and per-customer language. EVT-1, 2, 3, 11 and 12 are small fixes and should land before building features; EVT-5 and a minimal EVT-6 are needed before the nixpal go-live.
