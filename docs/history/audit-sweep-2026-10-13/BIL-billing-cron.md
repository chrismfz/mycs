<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

MyCS billing engine and cron audit (BIL). Static reading of main @ b67f57e. Nothing was edited or run.
Known items I only mention and do not re-report: days-before per cycle, invoice reminders, "never suspend", status changing before the server job succeeds (#1186), invoice cancellations done as mass `->update()`, splitting the scheduler, auto-close in hours instead of days. Late fees are skipped because they are not used.

**BIL-1. "End of period" cancellations are never processed.** High · WHMCS-parity gap / bug · confirmed by reading
- Where: `app/Listeners/CancellationCreatedListener.php:31` says "handled by the cron job", but `app/Console/Commands/CronJob.php` has no such task. `billRenewal` only skips billing (`CronJob.php:226`).
- Failure: the service runs on unpaid until `expires_at + suspend days`. It is then suspended **with the dunning marker**, as if the customer had not paid (`:135`), and terminated after `terminate days`. For myip that is 15 free days and then 120 days of retained data. Open renewal invoices are not cancelled. If the customer pays a leftover invoice, the service is reactivated even though the cancellation stands.
- Volume: myip has 476 such cancellations a year, nixpal 57.
- WHMCS: "Process Cancellation Requests" terminates on the next due date. Unpaid invoices are cancelled when the request is made (setting is on in both instances).
- Fix: a cron task for end-of-period requests where `expires_at <= today`. It terminates the service and cancels its open invoices through observers, and does not set the dunning marker. Effort S–M. **Now.**

**BIL-2. Stripe/PayPal subscriptions are no longer cancelled or updated from transactional saves.** High · bug (money) · confirmed by reading
- Where: `app/Observers/ServiceObserver.php:78` now fires `Service\Updated` in `DB::afterCommit` (commit 02bd7ed). The listeners check `isDirty()` at `extensions/Gateways/Stripe/Stripe.php:52,59` and `extensions/Gateways/PayPal/PayPal.php:143,149`.
- Why it breaks: by commit time `syncOriginal()` has run, so `isDirty()` is always false.
- Affected paths: cron termination (`CronJob.php:147`, inside a transaction), `applyStaffChanges` and `RenewServiceService`. The subscription is not cancelled when a service is terminated, and the gateway keeps charging. Price and expiry changes are not synced either. The immediate-cancellation path still works only because it runs outside a transaction.
- Fix: use `wasChanged()` / `getChanges()`, or pass the changes captured in `updated()` into the event. Add a test that cancels a subscription from cron termination. Effort S. **Now.**

**BIL-3. Card/agreement auto-charge runs once, at invoice creation, and is never retried.** High · parity gap / fragile · confirmed by reading
- Where: `CronJob.php:305-321`.
- Failure: the card is charged `cronjob_invoice` days before due (7 at nixpal, 10–20 at myip). A declined charge is never retried. If the process dies between the commit and the `afterCommit` closure, the charge never happens. Either way the service drifts into suspension.
- nixpal relies on charging at expiry (Stripe is 62% of its payments).
- WHMCS: "Process Days Before Due" (default 0, i.e. on the due date) plus daily "CC retry attempts".
- Fix: a separate idempotent "capture due invoices" task. It charges pending invoices that have an agreement and `due_at <= today + X`, retries N times, and keeps a per-invoice attempt counter or timestamp. Effort M. **Now** (Phase 1, with the scheduler split).

**BIL-4. A service can be suspended and terminated in the same run.** High · bug (destructive) · confirmed by reading
- Where: `CronJob.php:144`. Termination uses only `expires_at`, never how long the service has actually been suspended.
- Failure: `suspend_hold_until` (myip has 170 such services) expires after the termination threshold, or staff move the Next Due Date backwards, or the cron was down for days. The suspend task sets `dunning_suspended_at = now()` and the terminate task deletes the account minutes later, with zero grace and no suspension notice period.
- Fix: also require `dunning_suspended_at <= now - (terminate days - suspend days)`, or a minimum number of days in suspension. Effort S. **Now.**

**BIL-5. Suspension does not check that an overdue invoice exists, while billing can silently not happen.** Medium-High · fragile · confirmed by reading
- Where: `CronJob.php:226` (any pending or draft invoice containing the service blocks renewal forever) and `:127-131` (suspension is based on `expires_at` only).
- Failure:
  - A forgotten **draft**, or a manual pending invoice that references the service, means no renewal invoice is ever sent, yet the service is suspended and then terminated.
  - Any exception in `billRenewal` (see BIL-6) has the same outcome.
- WHMCS: `nextinvoicedate` means drafts and other invoices do not block generation.
- Fix:
  - Suspend only when an unpaid invoice for the service is overdue by N days.
  - Do not let drafts or non-renewal lines block renewal.
  - Alert staff about services past expiry that have no open invoice.
- Effort M. **Now.**

**BIL-6. Deleting a plan or product breaks billing and cron.** Medium-High · fragile · confirmed by reading (that the UI deletes plans is suspected)
- Where: `database/migrations/2024_07_05_143851_create_services_table.php:23,28` uses `nullOnDelete` for `product_id` and `plan_id`. There is no delete guard (the plans repeater is at `app/Admin/Resources/ProductResource.php:176`).
- Failure:
  - `plan = null`: `billRenewal` throws every day, so the service is never invoiced but still gets suspended and terminated (BIL-5). A payment also fails in `RenewServiceService`.
  - `product = null`: `$service->product->stock` (`CronJob.php:86,151`) throws inside the single-transaction job, so **no** service is ever cancelled or terminated again.
- Fix: `restrictOnDelete`, a UI guard (archive or hide instead of delete), null-safe cron code. Effort S. **Now.**

**BIL-7. A cancelled renewal invoice is regenerated the next day.** Medium · parity gap / design · confirmed by reading
- Where: `Service::livePeriodLines` excludes cancelled invoices, so the chain stops at that period and the cron bills it again and emails the customer.
- WHMCS: `nextinvoicedate` has already advanced, so "cancel invoice" stops billing. myip staff do this a lot (3,154 cancelled invoices).
- Fix: decide the workflow. Either a "period skipped / not to be billed" marker, or cancelling the invoice prompts a cancellation request. Effort M. With billing-cursor PR 3 / the "cancel product with invoice" roadmap item.

**BIL-8. One invoice per service; WHMCS groups by client.** Medium · parity gap · confirmed by reading
- Where: `CronJob.php:277-294`.
- Impact: more invoices, emails and gateway fees, and Mass Pay becomes necessary.
- WHMCS: groups everything due for a client on the same date into one invoice (only 3 myip clients are set to "separate invoices").
- Fix: group by user + currency + `due_at`, with a per-client "separate invoices" flag. Effort M. Before cut-over, with the "days before per cycle" item.

**BIL-9. No recovery for lines that were paid but never applied.** Medium · fragile · confirmed by reading
- Where: `InvoiceObserver.php:94` runs `ProcessPaidInvoiceService` in `afterCommit`.
- Failure: a crash or exception after the payment commits leaves the invoice paid with `applied_at` NULL. The cron later suspends and terminates a customer who paid.
- Fix: a cron sweep that re-runs `RenewServiceService` for paid Service lines with `applied_at IS NULL` (it is idempotent), and add that rule to `mycs:money-check`. Effort S. **Now**; overlaps MT-3.

**BIL-10. Non-renewal cron tasks still run as one transaction each.** Medium · fragile · confirmed by reading
- Where: `CronJob.php:339-342` for orders_cancelled, upgrades, suspend, terminate and tickets.
- Failure: one bad row (exception, null product) rolls back the whole task every day, and every candidate row stays locked for the duration.
- Fix: a transaction per row, as already done for renewals. Effort S. **Now**; overlaps the ROADMAP scheduler item.

**BIL-11. The upgrade re-pricing task touches paid and cancelled invoices.** Medium · bug · confirmed by reading
- Where: `CronJob.php:96-123`.
- Failure:
  - It re-prices the upgrade line daily (raw `update`, no audit) without checking the invoice status. A paid invoice whose upgrade stayed pending (`ServiceUpgradeService` threw) changes its total.
  - When the service has expired it cancels the invoice even if it is paid, which creates a credit note on a paid invoice.
  - `expires_at` NULL (one-time plans): `null < now()` is true in PHP, so pending upgrades and their invoices are cancelled at the next run.
- Fix: act only on pending invoices with no payments, skip NULL `expires_at`, go through observers. Effort S. **Now.**

**BIL-12. The auto-cancel of pending orders is too broad.** Medium · bug / parity · confirmed by reading
- Where: `CronJob.php:77-94`.
- Failure: it cancels any pending service with no *paid* invoice that is 7 days old. That includes admin-created pending services with no invoice and orders with a partial payment (no credit note, known issue).
- Fix: act only on services whose order invoice is unpaid with no transactions; add an on/off setting. Effort S. **Now.**

**BIL-13. Termination and immediate cancellation cancel shared invoices.** Medium · bug · confirmed by reading
- Where: `CronJob.php:149` and `CancellationCreatedListener.php:26`.
- Failure: every pending invoice that contains the service line is cancelled, including manual, merged or Mass Pay invoices that carry other services. Those services lose their renewal.
- Fix: remove just that line, or cancel only single-service invoices. Effort S–M. With the Mass Pay/merge item.

**BIL-14. Credit is auto-applied only when it covers the whole invoice.** Medium · parity gap · confirmed by reading
- Where: `CronJob.php:333` (`onlyIfCovers: true`).
- WHMCS: with auto-apply (nixpal) it applies partial credit.
- Fix: a setting for partial application. Effort S. With the credits/ledger item (MT-1b).

**BIL-15. Exclusive tax is baked into `services.price`.** Medium · parity gap · confirmed for the coupon-expiry and upgrade paths (`Service.php:596-599`, `CronJob.php:248`), suspected for checkout
- Failure: when tax is exclusive, renewals carry the rate that applied when the price was calculated. A change of VAT country, VIES or exemption does not change renewal invoices.
- WHMCS: calculates tax when it generates each invoice.
- Fix: store net prices and compute tax per invoice. Effort M. With the EU VAT item.

**BIL-16. No `withoutOverlapping`, and DST can skip or repeat the run.** Low · fragile · confirmed by reading
- Where: `routes/console.php:9` has no `withoutOverlapping`.
- Failure: a manual run overlapping the scheduled one can double-increment stock in orders_cancelled and duplicate CronStat rows. A `cronjob_time` between 03:00 and 04:00 Athens is skipped or repeated on DST days.
- Fix: `->withoutOverlapping(120)` and validate the time setting. Effort S. **Now.**

**BIL-17. Auto-close of tickets can skip rows.** Low · bug · confirmed by reading
- Where: `CronJob.php:163`.
- Failure: `each()` pages by offset while changing the column it filters on, so rows are skipped beyond 1,000. The customer is not notified (WHMCS notifies). Effort S. With Tickets (Phase 3).

**BIL-18. Active recurring services with `expires_at` NULL are never billed or suspended.** Low/Medium · suspected
- Where: the importer turns `0000-00-00` into null (`ImportFromWhmcs.php:856`), and the admin `required` rule reads `$get('plan')`.
- Fix: a parity count before cut-over and a cron warning. Effort S. With the importer.

**BIL-19. The "Do Not Suspend Until" date suspends on that day itself.** Low · parity · confirmed by reading
- Where: `CronJob.php:130` (`suspend_hold_until < now()` at 00:00:xx). Check that this matches WHMCS's "suspend until". Effort S.

**BIL-20. Run bookkeeping is skipped when one item fails.** Low · fragile · confirmed by reading
- Where: `CronJob.php:182-210`.
- One failed item means `last_cron_run`, the debug-log cleanup and the `invoice_charged` stat are all skipped, so the heartbeat looks stale.
- `successFullCharges` counts attempts, not successes.

**BIL-21. Timezone can silently fall back to UTC.** Low · fragile
- Where: `SettingsProvider.php:59`. The timezone comes from a DB setting, and any exception during loading is swallowed, which leaves UTC. Web and cron could then compute dates in different zones.
- Fix: also set `APP_TIMEZONE=Europe/Athens` in `.env`.

**BIL-22. Small issues in the immediate-cancellation listener.** Low · bug · confirmed by reading
- `if ($product->stock)` skips a stock of 0 (the cron uses `!== null`). There is no idempotency guard (stock is returned twice on a repeat) and no transaction.

**BIL-23. Invoice emails are fragile in console runs.** Low
- The "Invoice created" email is still sent after credit or a zero total has already marked the invoice paid.
- Emails are queued from terminating callbacks (`afterResponse` in console), so they are lost if the process dies.

**Overall health.** After PR #32 the renewal core is solid: per-service transactions, a period on each line, idempotent `applied_at`, and the `packages/billing-period` maths, which is DST-safe because Carbon 3 `diffInDays` counts calendar days in one zone and the month-end anchor is handled. The weak parts are around it:
- the lifecycle tasks (end-of-period cancellations, suspension without an invoice, suspend and terminate in one run, single-transaction tasks);
- auto-charge timing and retries;
- the subscription-sync regression from `afterCommit` (BIL-2);
- no recovery for interrupted post-payment processing.

**Fix before building features on top:** BIL-1, 2, 3, 4, 5, 6, 9, 10, 11. Most are small (S). The WHMCS parity gaps (BIL-7, 8, 14, 15) need product decisions before the myip cut-over.
