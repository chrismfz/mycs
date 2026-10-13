<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## AUTH audit: authorization and correctness in admin, API and client area (static reading at b67f57e)

### AUTH-1: The admin API user endpoints skip PermissionGuard, so staff can escalate to full admin or take over an admin account
- **Severity:** High. **Type:** security. Confirmed by reading.
- **Where:**
  - `app/Http/Requests/Api/Admin/Users/UpdateUserRequest.php`
  - `CreateUserRequest.php`
  - `DeleteUserRequest.php`
  - `app/Http/Controllers/Api/Admin/UserController.php:72-90`
- **Failure scenario:** a staff role has `users.update`, `roles.update` and `api_keys.create`, but not `*`.
  1. They create an API key with `admin.users.update` and `admin.roles.update`. PermissionGuard allows this, because they hold both permissions.
  2. `PUT /api/v1/admin/users/{self}` with `role_id=<the * role>` makes them a full admin.
  3. Even without `roles.update`, `PUT /users/{superadmin}` with a new `password` or `email` takes over the account. `DELETE /users/{admin}` also works.
- The Filament side has `outranks` and `canGrant`; the API side has neither. The M5 fix (2026-10-11) missed this path.
- **Fix:**
  - In `authorize()`: `PermissionGuard::outranks($key->user, $target)` for update and delete.
  - Run `canGrant` on the new `role_id`.
  - Legacy keys without a creator: treat them as non-admin for these endpoints.
  - Add tests.
- **Effort / when:** S, now.

### AUTH-2: Policies check permission names that the role config never defines
- **Severity:** Medium-High. **Type:** bug / fragile design. Confirmed by reading.
- **Where:**
  - `app/Policies/*` compared with `config/permissions.php`. About 38 names are used but do not exist.
  - All `*.view`, e.g. `admin.invoices.view`, `admin.tickets.view`, `admin.users.view`
  - `admin.audit.viewAny` (the config has `audits`)
  - `admin.notification_templates.*` (the config has `email_templates.*`)
  - `admin.users.deleteAny`, `admin.api_keys.deleteAny`
- **Failure scenario:** only `*` roles can:
  - open ViewRecord pages, such as ViewInvoice when immutable invoices are on;
  - see audits;
  - manage email templates;
  - download ticket attachments (`TicketMessageAttachmentPolicy` checks `admin.tickets.view`).
- With `immutable_invoices_enabled`, a non-`*` staff member cannot open a pending or paid invoice at all. Edit requires draft and View requires a permission that cannot be granted, so they cannot record a payment.
- In practice everyone ends up with `*`, which cancels the role model and PermissionGuard.
- **Fix:** align the names. Map `view` to `viewAny`, or add the permissions to the config. Add a test that every string passed to `hasPermission`/`adminPermission` exists in the config.
- **Effort / when:** S, now.

### AUTH-3: In Filament, a policy without the method means "allow"
- **Severity:** Medium. **Type:** security / fragile design. Confirmed by reading.
- **Mechanism:** `vendor/filament/filament/src/helpers.php:60-92`.
- **Where:**
  - `AuditPolicy` has no `delete`/`deleteAny`, so `AuditsTable.php:82` DeleteBulkAction is allowed. Audits can be deleted, which breaks the append-only intent of the money trail.
  - `FailedJobPolicy` has only `viewAny`, but `FailedJobResource.php:57-110` retry/delete are custom actions with no authorization. Anyone who can view failed jobs can re-queue them, including payment and provisioning jobs.
  - Uninstall (`EditExtension.php:19`) depends only on `update`, not on `extensions.delete`.
  - `CreditPolicy` has no `deleteAny`; `BillingAgreementPolicy` and `TicketMessagePolicy` are partial.
- **Fix:**
  - Turn on `Filament::strictAuthorization()`, or complete every policy with an explicit `false`.
  - `->authorize()` on custom actions.
  - Remove delete from audits.
- **Effort / when:** S–M, now.

### AUTH-4: Client cancellation has no checks and cancels whole invoices
- **Severity:** Medium-High. **Type:** bug. Confirmed by reading.
- **Where:**
  - `app/Livewire/Services/Cancel.php:21-38` checks only `view`. It does not check `cancellable` (that check is only in the UI, `Show::updatedShowCancel`), and it does not check for an existing request.
  - `app/Listeners/CancellationCreatedListener.php:17-28`
- **Failure scenario:**
  - A client can submit "immediate" on a free/one-time service, or several times, so several TerminateJobs run.
  - The immediate path does `service->invoices()->where('status','pending')->update(['status'=>'cancelled'])`, which:
    - cancels the **whole** invoice, including lines for other services (Mass Pay or a multi-service renewal), so those services are never renewed;
    - also cancels invoices that are partly paid with credit;
    - skips observers, so no cancellation credit note is created.
- **WHMCS:** cancels only that service's invoice, or removes the line (the `CancelProductOnCancelInvoice` hook).
- **Fix:**
  - Check `cancellable` and pending requests on the server side.
  - Cancel only invoices whose every line belongs to the service. Otherwise remove the line and recompute.
  - Go through the model/observer.
- **Effort / when:** M, now.
- **Overlap:** ROADMAP Φάση 1 «Ακυρώσεις τιμολογίων μέσω observers».

### AUTH-5: Upgrade accepts unvalidated config values; the shown price differs from the charge; downgrade credit skips the guards
- **Severity:** Medium. **Type:** bug / security. Confirmed by reading.
- **Where:** `app/Livewire/Services/Upgrade.php:120-131` (checkbox: no rule; text/number: only `required`), `:199-210`, `:157-179`.
- **Failure scenario:**
  - The client sets `configOptions[<checkbox id>]` to the id of any ConfigOption, even one from another product.
  - `calculatePrice()` prices that foreign value through `configValue`, then the value is saved to the service.
  - `totalToday()` skips invalid values, so the client sees one total and is charged or credited another.
- **Credit on downgrade:**
  - The negative price becomes credit through a plain `increment`.
  - It ignores `credits_enabled` and has no money-trail entry.
  - A 0 € upgrade creates an empty credit row.
- **Fix:**
  - Validate every option with `Rule::in(children)`, including checkbox.
  - Give credit only through the credit service.
  - Add tests.
- **Effort / when:** S–M, now (money).

### AUTH-6: Clients see draft invoices
- **Severity:** Medium. **Type:** bug / WHMCS-parity gap. Confirmed by reading.
- **Where:**
  - `InvoicePolicy::view` (owner only)
  - `app/Livewire/Invoices/Index.php:15`
  - `Invoices/Show::downloadPDF`
- **Failure scenario:** staff prepare a draft and the client already sees it in the list and as a PDF, without a number. WHMCS hides drafts from the client.
- **Fix:** add `status != draft` to the client queries and to `view` outside the admin panel.
- **Effort / when:** S, now.

### AUTH-7: Deleting a user cascades in the database, including a staff member's ticket replies
- **Severity:** Medium. **Type:** bug. Confirmed by reading.
- **Where:**
  - Migrations: `ticket_messages.user_id` cascadeOnDelete; invoices, services and credits cascade too.
  - `UserObserver` has no `deleting`.
  - `UserController::destroy`
- **Failure scenario:**
  - Deleting a former employee silently deletes **every reply they wrote in every customer's tickets**.
  - Deleting a customer deletes invoices and transactions with no event. It bypasses `InvoiceObserver::deleting` and does not terminate services on the servers.
- **Fix:** add a `deleting` guard (refuse when there is money or messages), deactivate instead of deleting, and use nullOnDelete for `ticket_messages`.
- **Effort / when:** M, with MT-1.
- **Overlap:** `money-trail.md` §8 covers the money part only. The ticket replies are new.

### AUTH-8: API invoice items and the admin invoice repeater trust `reference_type`/`reference_id`
- **Severity:** Medium. **Type:** security / fragile design. Confirmed by reading.
- **Where:**
  - `app/Http/Requests/Api/Admin/InvoiceItems/Create|UpdateInvoiceItemRequest.php`: `class_exists` + `app($input)` instantiates any class through the container.
  - `InvoiceResource.php:131-132`: Hidden fields that the browser can change.
- **Failure scenario:**
  - Staff or a key with invoice permissions but no credit permissions turns a line into `Credit::class`, marks the invoice paid, and credit is created.
  - Or they point the line at another customer's service, and paying renews that service.
  - Item endpoints ignore `immutable_invoices_enabled` (the invoice endpoint respects it), and they do not recompute the invoice status.
- **Fix:**
  - Allowlist of morph types.
  - Check that the reference belongs to `invoice.user_id`.
  - Run the same immutability check.
  - Make the Hidden fields `dehydrated(false)` and set them on the server.
- **Effort / when:** S, now.

### AUTH-9: Every service deletion path skips the server and the money checks
- **Severity:** Medium. **Type:** bug / fragile design. Confirmed by reading.
- **Where:**
  - `ServiceResource.php:272` (bulk)
  - `CouponResource/RelationManagers/ServicesRelationManager.php:37`
  - `OrderResource/RelationManagers/ServiceRelationManager.php:39`
  - `ServiceController::destroy`
- **Failure scenario:**
  - The account stays on the server.
  - Invoice lines are left pointing at a deleted service.
  - On the coupon page, "Delete" deletes the services themselves instead of detaching the coupon.
- **Fix:** one deletion service: terminate optionally, refuse when there are unpaid or paid lines, detach instead of delete on the coupon page.
- **Effort / when:** M, now.

### AUTH-10: No admin-only custom client fields
- **Severity:** Medium. **Type:** WHMCS-parity gap. Confirmed by reading.
- **Where:**
  - `app/Livewire/ComponentWithProperties.php:66-71`
  - `themes/default/views/components/form/properties.blade.php`
- **Failure scenario:**
  - When imported, myip's "σήμανση «δύσκολος πελάτης»" and its notes will be shown to the client, and the client can edit them.
  - `non_editable` blocks only after a value exists, so the client can set it once (ΑΦΜ too).
- **Fix:** add `admin_only`/`client_visible` flags, enforce them on the server, and lock ΑΦΜ after issuing.
- **Effort / when:** M, with the roadmap item "Custom fields πελάτη".

### AUTH-11: No client status (Active/Inactive/Closed)
- **Severity:** Medium. **Type:** WHMCS-parity gap. Confirmed by reading.
- **Where:** `users` table, `Livewire/Auth/Login.php`, `ResolveUserSession`.
- **Failure scenario:** WHMCS customers in Closed status log in and place orders after the import. WHMCS refuses login for Closed.
- **Fix:** add a status column, check it at login, in remember sessions and in the API, and import it.
- **Effort / when:** S–M, before the importer.
- **Overlap:** GDPR item ("απενεργοποίηση").

### AUTH-12: `settings.view` exposes secrets
- **Severity:** Low-Medium. **Type:** security. Confirmed by reading.
- **Where:** `app/Admin/Pages/Settings.php:38-47` fills every value, including `encrypted` ones, into the Livewire state.
- **Failure scenario:** staff with view-only access read `mail_password`, `ticket_mail_password`, captcha and OAuth secrets from the page.
- **Fix:** do not fill encrypted values; use an empty field that means "unchanged".
- **Effort / when:** S, now.

### AUTH-13: Ownership is hard-coded as `user_id === auth id` everywhere
- **Severity:** Low-Medium. **Type:** fragile design. Confirmed by reading.
- **Where:**
  - `BasePolicy::adminPermission` with `orCondition`
  - Every client Livewire component (`Auth::user()->invoices()` and similar)
  - `TicketMessageCreatedListener.php:15`: staff and customer are told apart only by "user ≠ ticket owner"
  - API `ticket-messages` accepts any `user_id`
- **Failure scenario:** with multi-user accounts, a sub-user's reply will count as a staff reply ("replied" plus a notification). Every policy and query will need rewriting.
- **Fix:** add a central `ClientAccess::owns()` / `forAccount()` now, and use `role` for the staff check.
- **Effort / when:** M, with the Φάση 3 multi-user item, but put the helper in place before new features.

### AUTH-14: Staff password changes and impersonation
- **Severity:** Low. **Type:** security / WHMCS-parity gap. Confirmed by reading.
- **Where:**
  - `UserResource.php:62-65`: password or email changed from the admin leaves the user's sessions alive, including 30-day remember tokens.
  - `Password/Request.php:37`: staff cannot reset their password by email.
  - Impersonation (`EditUser.php:22`) is not logged. Actions taken while impersonating are attributed to the customer. WHMCS logs "admin logged in as client".
- **Fix:**
  - `sessions()->delete()` on admin password change.
  - A staff reset flow, or document the CLI.
  - An audit entry for impersonate start and stop.
- **Effort / when:** S, later.

### AUTH-15: 2FA brute-force budget
- **Severity:** Low. **Type:** security. Confirmed by reading.
- **Where:** `Livewire/Auth/Tfa.php:39-46`.
- **Failure scenario:** someone who knows the password gets 5 tries per minute indefinitely (about 7,200 per day, roughly 2% success per day). There is no lockout and no email to the user.
- **Fix:** an increasing back-off per user and an alert email.
- **Effort / when:** S, with the ROADMAP 2FA item.
- **Not repeated here:** the known L9 items (disabling 2FA and changing email without the password).

### AUTH-16: Admin API hygiene
- **Severity:** Low. **Type:** fragile design. Confirmed by reading.
- **Where:** `routes/api.php`, every controller's `index`.
- **Problems:**
  - `per_page` has no limit, so `per_page=1000000` is a memory DoS.
  - No throttle on `/api/v1/admin`.
  - `UpdateServiceRequest`: `expires_at after_or_equal:today`, so staff cannot set an earlier due date (WHMCS allows it).
- **Fix:** `max:100`, `throttle:api`, review the date rule.
- **Effort / when:** S, later.

### AUTH-17: The "Assigned To" ticket filter is wrong
- **Severity:** Low. **Type:** bug. Confirmed by reading.
- **Where:** `TicketResource.php:181`, `->relationship('user', …)`.
- **Failure scenario:** it filters by the ticket's **owner**, so it always returns nothing for staff.
- **Fix:** use `assignedTo`.
- **Effort / when:** S, now.

### AUTH-18: The "Email Verified" user filter does nothing
- **Severity:** Low. **Type:** bug. Suspected: it has no `->query()`.
- **Where:** `UserResource.php:121`.
- **Effort / when:** S.

### AUTH-19: Invoice route binding by number or id is ambiguous
- **Severity:** Low. **Type:** fragile design. Confirmed by reading.
- **Where:** `app/Models/Invoice.php:347-367`.
- **Failure scenario:** the link for a draft (no number) is `/invoices/{id}`. If another invoice has `number == that id`, the link opens that one, so the owner sees the wrong invoice or a 403. The policy still prevents IDOR.
- **Fix:** separate keys, or prefixes.
- **Effort / when:** S, before the numbering work.

### AUTH-20: Null dereferences give 500s in the client area
- **Severity:** Low. **Type:** bug. Confirmed by reading.
- **Where:**
  - `Services/Show::updateBillingAgreement` (`$agreement->id` when there is no match)
  - `Invoices/Show::processPayment` with `setAsDefault` and an invalid ulid
- **Effort / when:** S.

### AUTH-21: Refunds and direct credit edits are gated too loosely
- **Severity:** Low. **Type:** WHMCS-parity gap. Confirmed by reading.
- **Where:**
  - Refunds depend on `invoice_transactions.update` (`TransactionsRelationManager.php:162`); WHMCS has a separate "Refund" permission.
  - `ShowCredits` `TextInputColumn` edits the balance directly, with no reason and no lock.
- **Effort / when:** S, with MT-1.
- **Overlap:** MT decisions on credit adjustments with a reason.

**What holds up:** client IDOR is well covered.
- `#[Locked]` plus typed model properties: Livewire refuses model replacement.
- Route `can:` middleware, which persists on Livewire updates.
- Saved cards always go through `Auth::user()->billingAgreements()`.
- `logoutSession` checks ownership.
- The impersonation middleware re-checks permissions and outranks on every request.
- Panel access requires a role.
- No IDOR was found in tickets, invoices or services.

## Overall health
The client area is in good shape for IDOR. The weak points are on the staff side.
- The admin API was not covered by the PermissionGuard work: AUTH-1 is a real escalation path and should be fixed first.
- The permission model is half broken: names that do not exist (AUTH-2), and Filament treats a missing policy method as "allow" (AUTH-3). In practice this pushes every staff member to `*`.
- Several destructive or money paths inherited from Paymenter run with no guards: immediate cancellation (AUTH-4), cascade deletes (AUTH-7), service deletion (AUTH-9), unvalidated upgrade options (AUTH-5).
- My suggestion is to fix AUTH-1 to AUTH-6 and AUTH-8 before building features. Each is S–M.
