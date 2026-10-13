<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## SVC audit: service lifecycle and provisioning (static read of `main` @ b67f57e)

I only read code; nothing was run. Every finding is "confirmed by reading" unless it says "suspected". Paths are relative to the repo root.

**SVC-1. Server adapter failures are treated as success.** Critical. Type: bug.
- **Where:**
  - `extensions/Servers/CPanel/CPanel.php:148-157`: `createServer` returns `true` whatever `metadata.result` says.
  - `CPanel.php:181-185, 209-213, 237-244, 262-266`: the other actions return `false` on failure.
  - `app/Helpers/ExtensionHelper.php:720-778`, `app/Jobs/Server/*.php`, `app/Admin/Resources/ServiceResource/Pages/EditService.php:94-138`: none of these check the return value.
- **Scenario:** WHM answers HTTP 200 with `result: 0` when it fails (duplicate domain, bad username, missing package), so `->throw()` never fires.
  - The service still becomes active, suspended or cancelled.
  - The customer gets the "server created/suspended/terminated" email.
  - The admin sees "Action triggered successfully".
  - No failed job is recorded.
- **WHMCS:** a module returns "success" or an error string, and an error marks the action failed in the Module Log and in Pending Module Actions.
- **Fix:** the adapter contract is "throw on failure". ExtensionHelper treats `false` as an exception, and cPanel checks `result` and includes `statusmsg` in the error. Effort S. **Now.**

**SVC-2. Status changes before the job succeeds; no module log; nobody is told about failures.** High. Type: fragile design / WHMCS-parity gap.
- **Where:**
  - `app/Services/Service/RenewServiceService.php:115-130`: status becomes active and `CreateJob` is queued.
  - `app/Console/Commands/CronJob.php:134-147`
  - `app/Listeners/CancellationCreatedListener.php:17-24`
  - `EditService.php:105`: `placeOnHold` runs before the adapter.
  - Jobs have `$tries = 1` and no `failed()` method.
  - `app/Providers/AppServiceProvider.php:176`: `Queue::failing` handles only Mail.
  - `ExtensionHelper::recordAudit` logs "extension_action" before the call, with no result.
- **Scenario:** WHM is down when an order is paid. The service shows Active with no account. The only trace is a row in `failed_jobs`; no staff email, no counter.
- **WHMCS:** Module Log (request and response), Pending Module Actions with retry, and an admin email on a failed create.
- **Fix:**
  - Add a `service_module_actions` table: action, status, attempts, redacted request/response, error.
  - Jobs set the status only on success and record failure in `failed()`, then notify staff.
  - Show a dashboard counter.
  - While there, fix `callService` (`ExtensionHelper.php:286-292`): its `mayFail` branch is inverted.
- Effort M. **Now.**
- **Overlap:** ROADMAP Phase 1 "Status υπηρεσίας μόνο μετά την επιτυχία του server job· reconciliation", and the dashboard item "Pending Module Actions".

**SVC-3. An upgrade deletes every config option that is not upgradable.** High. Type: bug.
- **Where:**
  - `app/Services/ServiceUpgrade/ServiceUpgradeService.php:39-53`: deletes configs `whereNotIn` the upgrade's configs.
  - `app/Livewire/Services/Upgrade.php:187-197`: the upgrade gets configs only for `upgradableConfigOptions`.
  - `upgradable` defaults to false, and hidden options are excluded too.
- **Scenario:** a VPS has OS, location and extra-IP options that are not marked upgradable. The customer upgrades the package and those `service_configs` are deleted.
  - `calculatePrice()` drops their price, so the recurring amount falls (lost revenue).
  - Their env variables disappear from `getServiceProperties`, so provisioning no longer sees them.
- **Fix:** only replace the options that were offered in the upgrade form; keep the rest. Effort S. **Now**, together with ROADMAP "Αλλαγή κύκλου/πακέτου".

**SVC-4. Cancelling or terminating one service cancels whole invoices, including other services' lines and paid-in money.** High. Type: bug.
- **Where:** `CancellationCreatedListener.php:26`, `CronJob.php:82`, `CronJob.php:149`. Each does `$service->invoices()->where('status','pending')->update(['status'=>'cancelled'])`.
- **Scenario 1:** an order invoice holds hosting plus a licence. The customer cancels the hosting immediately. The whole invoice is cancelled, the licence can no longer be paid, and the 7-day cron then cancels it.
- **Scenario 2:** a partly paid invoice is cancelled with no observer running and the payment is left stranded.
- **Scenario 3:** this gets worse with the planned Mass Pay and merged invoices.
- **Fix:** work per line. Cancel the invoice through observers only if every line belongs to this service and nothing has been paid. Otherwise remove or credit the line, or flag it for staff. Effort M. **Now.**
- **Overlap:** ROADMAP "Ακυρώσεις τιμολογίων μέσω observers". The line-level problem is new.

**SVC-5. Moving the `Service\Updated` event to after-commit broke the Stripe and PayPal listeners.** High. Type: bug, a fork regression from `02bd7ed`.
- **Where:**
  - `app/Observers/ServiceObserver.php:341`: `DB::afterCommit(fn () => event(...))`.
  - `extensions/Gateways/Stripe/Stripe.php:52-64` and `extensions/Gateways/PayPal/PayPal.php:143-152`: they test `isDirty('status'|'price'|'expires_at')`.
- **Scenario:**
  - Inside a transaction, the callback runs after `save()` has already synced the originals, so `isDirty` is always false.
  - Transactions are used by: cron termination (`runCronJob` transaction), `applyStaffChanges`, `placeOnHold` and renewals.
  - Result: a Stripe or PayPal subscription is not cancelled when the cron terminates or staff set Cancelled, and a staff price change is not pushed to the subscription.
  - That matters here: PayPal legacy subscriptions are 77 services, 5 active.
- **Fix:** listeners use `wasChanged()`/`getChanges()`, or the observer captures the changes into the event. Add a test. Effort S. **Now.**

**SVC-6. Pending upgrade invoices survive cancellation; a late payment upgrades a dead service.** Medium-High. Type: bug.
- **Where:**
  - `Service::invoices()` filters `reference_type = Service`, so upgrade invoices are never cancelled by SVC-4's code.
  - `CronJob.php:98-104` cancels them only when `expires_at < now`, by mass `update` (a partial payment is lost).
  - `ServiceUpgradeService::handle` does not lock or check the service status.
  - `app/Services/Invoice/ProcessPaidInvoiceService.php:31` checks the upgrade status without a lock.
- **Scenario:** the customer cancels immediately while an upgrade invoice is open and `expires_at` is still in the future. They pay later. The plan and price change on a cancelled service, `UpgradeJob` runs `modifyacct` on a deleted account, and the money is kept.
- **WHMCS:** the upgrade order and invoice are cancelled along with the service.
- **Fix:** cancellation and termination also cancel pending upgrades. `ServiceUpgradeService` locks the service and refuses anything not active (turn the payment into credit or flag staff). Effort S-M. **Now.**
- **Overlap:** ROADMAP #1266.

**SVC-7. Deleting services, orders or users leaves live hosting accounts behind.** Medium-High. Type: bug / fragile design.
- **Where:**
  - `EditService.php:62-77`: `$record->delete()` runs even when `terminateServer` threw.
  - `app/Http/Controllers/Api/Admin/ServiceController.php:92-98` and `app/Admin/Resources/ServiceResource.php:272` (bulk delete): no deprovisioning at all.
  - `services.order_id` and `user_id` are `cascadeOnDelete` (`database/migrations/2024_07_05_143851_create_services_table.php`), so deleting an order or a user wipes services silently.
- **Scenario:** a cPanel account keeps running with no record and no billing. Paid lines are left pointing at a deleted service.
- **Fix:** only cancelled services can be deleted, abort if terminate fails, and block cascades (use restrict).
- Effort S. **Now.**
- **Overlap:** money-trail decision "no deletion of customers with money movements".

**SVC-8. The admin "Trigger Extension Action" never updates the status, and Create can report failure after succeeding.** Medium. Type: WHMCS-parity gap / bug.
- **Where:** `EditService.php:97-120`.
- **Scenario 1 (Create):** the service stays pending. If it has no paid invoice, `orders_cancelled` (`CronJob.php:79-84`) cancels it after 7 days, with no terminate, so the account stays live and unbilled.
- **Scenario 2 (Terminate):** the service stays active. It keeps getting invoiced, and the later `SuspendJob` fails on a missing account.
- **Scenario 3 (notification):** it uses `$record->order->user`, and `order_id` is nullable for admin/API services. That raises an error after a successful create, staff see "Error", and they click Create again.
- **WHMCS:** Create sets Active, Suspend sets Suspended, Terminate sets Terminated, each with a result message.
- **Fix:** set the status after success, use `$record->user`, and add Change Password and Change Package as separate buttons. Effort S. **Now.**

**SVC-9. The cPanel adapter is not ready for the nixpal cut-over.** Medium. Type: bug / WHMCS-parity gap.
- **Where:** `CPanel.php:129-158` and `CPanel.php:247-259`.
- **Problems found:**
  - Usernames are a random 16-character mixed-case string, stored lowercased. That WHM rejects the uppercase is **suspected**.
  - No password is set, stored or emailed, so the welcome email has no credentials; the customer only has SSO.
  - `upgradeServer` uses `$settings['package']` and ignores `$properties['package']`, which `createServer` honours. A package chosen through a config option never changes, and a product upgrade resets it.
  - `$properties['domain']` is undefined for admin-created services.
  - There is no check whether the account already exists, so Create can run twice.
  - Terminate deletes the `cpanel_username` property, so the history is lost.
  - No ChangePassword.
- **WHMCS:** username from the domain (8 characters, unique), password encrypted in `tblhosting`, credentials in the welcome email.
- **Fix:** effort M, as part of ROADMAP "cPanel: έλεγχος create/suspend/…/αλλαγή πακέτου στο devbox".

**SVC-10. The status model is narrower than WHMCS (no Terminated, Completed or Fraud), and imported services have no module properties.** Medium. Type: WHMCS-parity gap.
- **Where:** `app/Models/Service.php:39-45`, `app/Console/Commands/ImportFromWhmcs.php:840-848`.
- **Scenario:**
  - Terminated, Cancelled and Fraud all import as `cancelled`; Completed (and anything unknown) imports as `pending`.
  - Those pending services inflate the Pending badge and can be auto-cancelled by `orders_cancelled` if they have no paid invoice.
  - Imported cPanel services get no `cpanel_username` or `domain`, so every suspend, unsuspend or terminate throws "Service has not been created" while the status changes anyway.
  - `mycs:parity` cannot tell Terminated from Cancelled.
- **Fix:** add `terminated` (and `completed`, `fraud`) statuses, and have the importer write `username`, `domain` and an encrypted password into properties. Effort M. **Before the importer cut-over.**
- **Overlap:** ROADMAP "Ο importer κρατά server και username".

**SVC-11. A retry can provision twice or act on the wrong state.** Medium. Type: fragile design.
- **Where:**
  - The Laravel HTTP client's default 30 s timeout applies to `createacct`.
  - Job timeout is 120 s, longer than the database queue's `retry_after` of 90 s (`config/queue.php`, `.env` uses `database`).
  - `CreateJob` and `TerminateJob` do not re-check the status (Suspend and Unsuspend do).
  - `FailedJobResource` has a bulk retry.
- **Scenario 1:** the HTTP call times out while WHM finishes creating the account. The property is never saved; a retry uses a new random username, so you get an orphan account or a second one.
- **Scenario 2:** retrying an old failed `TerminateJob` after staff reactivated the service deletes a live account.
- **Scenario 3:** retrying a `CreateJob` creates an account for a service that has since been cancelled.
- **Fix:**
  - Guard on status in Create and Terminate.
  - Make Create idempotent: if the username property exists, check the account on the server.
  - Set explicit HTTP timeouts, and make `retry_after` larger than `timeout`.
- Effort S. **Now.**

**SVC-12. A customer's "immediate" cancellation deletes data instantly, and the `cancellable` check is not enforced on the server.** Medium. Type: WHMCS-parity gap / bug.
- **Where:** `CancellationCreatedListener.php:17-24`, `app/Livewire/Services/Cancel.php:20-31` (no `cancellable` check).
- **Scenario:** an immediate request runs `TerminateJob` within seconds, with no staff review and no undo. A direct Livewire call can cancel a one-time or free service, or file a second request.
- **WHMCS:** cancellation requests are processed by the cron only if auto-terminate is enabled; staff can review or delete them first.
- **Fix:** a setting for immediate requests (instantly / next cron / staff approval), and a server-side `cancellable` check. Effort S. **With Phase 1 settings.**

**SVC-13. "End of period" cancellation is not processed at the end of the period.** Medium. Type: WHMCS-parity gap.
- **Where:** only `billRenewal` looks at a cancellation (`CronJob.php:226`). `RenewServiceService` ignores it.
- **Scenario 1:** a departing customer is suspended N days after expiry and receives the "suspended" email. Termination comes after the terminate days (60 at nixpal, 120 at myip).
- **Scenario 2:** the renewal invoice was issued before the request. If the customer pays it, the service renews despite the request.
- **WHMCS:** terminated or cancelled at the due date; whether the open invoice is also cancelled automatically is **suspected, to verify**.
- **Fix:** a cron step that processes cancellation requests at `expires_at` (terminate, set status) and cancels open renewal lines when a request is filed. Effort M.
- **Overlap:** already noted as [G] in `audit-billing.md`.

**SVC-14. The upgrade proration is calculated on the wrong price.** Medium. Type: bug.
- **Where:** `app/Models/ServiceUpgrade.php:55-80` and `:148-159`; `Upgrade.php:200-221`.
- **Scenario 1:** the difference is computed from catalog net prices, not the service's real recurring amount. That amount may be a staff price or a coupon price, and it is gross when tax is exclusive. Quantity is ignored.
- **Scenario 2:** a downgrade credit is given even when `credits_enabled` is off (only `credits_on_downgrade` is checked). It goes to `Auth::id()` with no ledger entry.
- **Scenario 3:** after any upgrade, a staff-set price is replaced by the catalog price.
- Effort M. **With ROADMAP "Αλλαγή κύκλου και πακέτου με proration".**
- **Overlap:** the billing-cursor PR 4 known issues; this adds the price basis and quantity.

**SVC-15. A product has exactly one server, so an upgrade across servers acts on the wrong one.** Medium. Type: fragile design.
- **Where:** `ExtensionHelper::checkServer` uses `$service->product->server`; `ServiceUpgradeService.php:69`.
- **Scenario:** upgrading to a product on another server or extension runs `upgradeServer` on a server that has no such account. Changing a product's `server_id` re-routes every existing service.
- **Fix:** for now, block upgrades across servers. Effort S. The full fix is Phase 3 "Server groups / `services.server_id`".

**SVC-16. Secrets are exposed by some server extensions.** Medium. Type: security.
- **Where:**
  - TLS verification is off: `extensions/Servers/Plesk/Plesk.php:29` and `extensions/Servers/Virtualizor/Virtualizor.php:34,37` use `withoutVerifying()`.
  - Virtualizor puts `adminapikey` and `adminapipass` in the URL (`Virtualizor.php:27-29`).
  - `app/Listeners/RequestListener.php:18` redacts only `authorization`, `password` and `password_confirmation`. With `settings.debug` on, the URL keys and `passwd`, `rootpass`, `newpass`, `ftp_password` and `account_password` land in `debug_logs`.
  - DirectAdmin keeps the customer password in plain text in `properties` (`extensions/Servers/DirectAdmin/DirectAdmin.php:204-209`). `Property` is Auditable, so the value is copied into `audits`.
- **Fix:** turn TLS verification on (with a per-server CA option), widen the redaction (including the URL query), use an encrypted cast for secret properties and exclude them from audits. Effort S-M. **Before DirectAdmin or Virtualizor go live at myip.**

**SVC-17. Some extensions have no `upgradeServer`.** Low. Type: bug.
- **Where:** DirectAdmin, Plesk and Virtfusion lack it; `ServiceUpgradeService.php:69-71` queues `UpgradeJob` anyway.
- **Scenario:** the customer pays, the database changes and the server does not. The only trace is "Server does not support the action" in `failed_jobs`.
- **Fix:** check `hasFunction` before queuing, and create a staff to-do otherwise. Effort S.

**SVC-18. Stock is restored inconsistently.** Low. Type: bug.
- **Where:** `CancellationCreatedListener.php:28` uses a truthy `if (stock)`, so stock at 0 is never restored. Other places use `!== null`. A staff status change to cancelled restores nothing.
- Effort S.

**Overall health**

Provisioning is the weakest part of the money path, and it should be fixed before features are built on it.
- **The contract:** adapters report failure by returning `false`, and nobody checks it (SVC-1). Statuses change before the job runs (SVC-2). There is no module log or failure alert, and retries are neither idempotent nor status-checked (SVC-11).
- **Cancellation and upgrades:** whole invoices are cancelled with bulk updates (SVC-4). Pending upgrades are orphaned (SVC-6). An upgrade wipes config options (SVC-3).
- **A regression from our own fork:** the after-commit `Service\Updated` event silently disabled subscription cancellation (SVC-5).
- **For the nixpal pilot:** the cPanel adapter (SVC-9) and the imported properties (SVC-10) are the blockers.
- **Scope:** SVC-1, 2, 3, 5 and 11 are small-to-medium fixes and should land in one "provisioning contract" PR with tests.
