<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## DATA audit: schema, data integrity, WHMCS importer (static reading only, nothing written)

I left out what the docs already record: the importer runs `migrate:fresh`, admins are never inserted, `unpaid` is used as a status, the `isset` bug, the invoice counter off by one, MT-1 §8 (customer delete cascades), the ledger and `unique transaction_id`. Money columns are already `decimal(17,2)` everywhere. The money problems are in the PHP casts and in what the stored amounts mean (DATA-1).

**DATA-1: Stored prices include tax; WHMCS stores net amounts**
- High · fragile design / WHMCS-parity gap · confirmed by reading
- Where: `app/Classes/Price.php:88-97`, `app/Models/Service.php:574-600` (`calculatePrice(... apply_exclusive_tax: true, tax: Settings::tax($this->user))`), `CronJob.php:~290` (the line price is `$service->price`), `Invoice::total` (items plus notes, no tax column).
- Scenario: with `tax_type=exclusive` (myip: 24% exclusive), `services.price` and `invoice_items.price` are saved gross, so the tax rate at order time is frozen into the service.
  - A customer who later becomes VAT-exempt or reverse-charge (VIES) keeps being billed +24%.
  - A rate change does not reach existing services.
  - The importer copies WHMCS net `tblhosting.amount` and `tblinvoiceitems.amount` as-is, so imported services bill 24% less and imported invoices sum to the WHMCS subtotal, not the total.
- WHMCS: line amounts are net, with a `taxed` flag per line. Tax, tax2 and the total are stored on `tblinvoices`, with a `TaxPerLineItem` rounding mode.
- Fix: decide the model now. Either store net plus a tax rate/amount per line (and a stored subtotal, tax and total per invoice), or make the importer gross up only lines with `taxed=1` and accept cent differences, which `mycs:parity` will flag. Effort L. **Now (before features)**; it blocks the EU VAT and importer work.
- Overlap: ROADMAP EU VAT, Importer.

**DATA-2: Deleting an order or service silently cascades or leaves dangling references; pages and emails crash**
- High · bug · confirmed
- Where:
  - `2024_07_05_143851_create_services_table.php:22`: `services.order_id` is `cascadeOnDelete`.
  - `OrderResource/Pages/EditOrder.php:16` and the bulk action in `OrderResource.php:185` delete with no guard; so does API `OrderController::destroy`.
  - Polymorphic `invoice_items.reference` has no FK.
- Scenario:
  - Staff delete an order. The database wipes its services, with no Eloquent events and no server terminate. `service_cancellations` and `service_upgrades` go too, by cascade. The `service_configs` and `properties` (morph) rows are left orphaned.
  - Paid invoices keep lines whose `reference_id` no longer exists. `NotificationHelper.php:153` (`$item->reference->subscription_id`) then throws on every invoice email, and `themes/default/views/invoices/show.blade.php:105` (`$item->reference->service_id` for upgrade lines) returns a 500 on the client invoice page.
  - Deleting a single service (admin, bulk, API) leaves the same dangling lines.
- Fix:
  - Make `services.order_id` `nullOnDelete`/`restrict` and block deleting an order that has services.
  - Null-safe access to `reference` everywhere.
  - Clean up morph children in `deleting` hooks, or switch to a "terminated" status rather than delete.
- Effort S–M. **Now.** Overlap: MT-1 §8 (only covers customer deletes).

**DATA-3: Deleting a staff user deletes all their ticket replies; customer deletes leave invoices with no owner**
- High · bug · confirmed
- Where:
  - `2024_08_09_120938_create_ticket_messages_table.php:55`: `ticket_messages.user_id` is `cascadeOnDelete`. Staff and customers share `users`.
  - `2026_05_15_..._draft.php:20-30`: `invoices.user_id` is `nullOnDelete`, while services, orders, credits and `billing_agreements` cascade.
- Scenario:
  - Removing a former employee in `EditUser` erases every reply they wrote on every customer's ticket.
  - Deleting a customer leaves invoices and transactions with `user_id NULL`. `RecordPaymentService::addCredit` and `deleteTransaction` then hit `$invoice->user->credits()` and fatal, and `Settings::tax(null)` falls back to `Auth::user()`.
  - Latent: `ticket_messages.ticket_mail_log_id` also cascades, so any future pruning of mail logs would delete messages.
- WHMCS: staff replies keep the admin name as a string; admins are a separate table.
- Fix: `ticket_messages.user_id` → `nullOnDelete` plus a stored author name; staff get deactivated, not deleted. Effort S. **Now**, with MT-1.

**DATA-4: Hosting account credentials stored in plain text, and copied into audits**
- High · security · confirmed
- Where: `extensions/Servers/DirectAdmin/DirectAdmin.php:204-209` writes `directadmin_password` to `properties.value`. `app/Models/Property.php` has no cast and is `Auditable`, so the old and new values also land in `audits` in plain text. Convoy uses `properties['password']` the same way.
- The importer plan says it will bring "server and username (credentials encrypted)", but the schema has nowhere encrypted to put them; the ROADMAP's encrypted-credentials item only covered extension settings.
- Fix: encrypted credentials columns on `services` (or an `encrypted` flag on properties) and an audit exclude. Effort M. **Now, before the cPanel work and the importer.**

**DATA-5: Invoice number is a separate counter, not the id; proforma numbers get assigned while unpaid**
- Medium-High · fragile design / contract · confirmed
- Where: `app/Listeners/InvoiceNumberListener.php:20-50`.
- Scenario:
  - The CLAUDE.md contract for myip is "number = id". Here the number comes from `settings.invoice_number`. Any rolled-back invoice creation (cron per-service transaction, checkout) burns an InnoDB auto-increment id but rolls the counter back, so from then on number ≠ id, and the bank-reference matching by number drifts.
  - There is no `{id}` placeholder in `formatInvoiceNumber`.
  - In proforma mode (nixpal), the `Updating` branch numbers on draft→**pending** as well as paid. A staff-created invoice that is published and then cancelled leaves a gap in the sequential numbering.
  - The counter never resets per year. To check: whether nixpal's WHMCS resets `{YEAR}-{NUMBER}`.
  - Lookup is `where('key')` without `whereNull('settingable_type')`.
- Fix: a numbering mode "number = id" set after insert; number only on paid when proforma is on; optional yearly reset. Effort M. With ROADMAP "Αρίθμηση".

**DATA-6: Soft-deleting a server silently drops provisioning for all its services**
- Medium · bug · confirmed
- Where: `Server extends Extension` (SoftDeletes); `products.server_id` has no FK; `Product::server()` excludes trashed rows; `EditServer.php:19` has no guard.
- Scenario: delete a server that still has products. `$service->product->server` becomes null, so `RenewServiceService::activate`, suspend and terminate skip the module calls. Statuses still change in the database, and nothing warns anyone.
- Fix: refuse to delete a server while products or services use it; use `withTrashed` on the relation. Effort S. Now.

**DATA-7: Historical invoice tax and totals are not frozen**
- Medium · fragile design / parity · confirmed
- Where: `CreateInvoiceSnapshotService.php:22-26` only stores the tax when a rate matched. `Invoice::tax()` (`Invoice.php:242-257`) falls back to the *live* `Settings::tax($user)` when `tax_name` is null. `InvoiceObserver.php:28-31, 80-81` takes a snapshot only on create (non-draft) or on draft→pending.
- Scenario:
  - A 0% (exempt or no-rate) invoice gains VAT on its PDF after a `TaxRate` is added or the customer changes country.
  - An invoice that goes draft→paid never gets a snapshot.
  - Imported invoices have no snapshot at all.
  - There is no `paid_at`, stored subtotal/tax/total or payment method on `invoices`, so "amounts per month" in the parity check cannot be reproduced.
- WHMCS stores `subtotal, tax, tax2, credit, total, taxrate, datepaid, paymentmethod`.
- Fix: always snapshot the rate, including 0; snapshot on any exit from draft; the importer writes snapshots; add `paid_at`. Effort M. With EU VAT and the importer.

**DATA-8: Removing a config-option value silently strips it from services**
- Medium · bug · confirmed
- Where: `2025_06_30_..._service_configs.php:16-19` (`config_value_id` cascade); the children repeater in `ConfigOptionResource.php:~90-118` has no guard. Only deleting the whole option shows a warning, in `EditConfigOption`.
- Scenario: staff remove the value "Extra 10GB". Every service's `service_configs` row for it disappears. The next `calculatePrice()` (upgrade, coupon expiry, admin "recalculate") under-bills, and provisioning loses the option.
- WHMCS keeps the orphaned `tblhostingconfigoptions` rows.
- Fix: `restrict` on the FK, plus a block or a hidden flag for values in use. Effort S. Now.

**DATA-9: Soft-deleted billing agreement or gateway vs `nullOnDelete` FKs**
- Medium · bug · confirmed
- Where: `PaymentMethods.php:78` soft-deletes the agreement, but `services.billing_agreement_id` stays set (the FK only fires on hard delete). `CronJob.php:306-320` calls `$service->billingAgreement->gateway`, so you get a `TypeError`, which is not caught by `catch (Exception)`. The customer gets the invoice but no "payment failed" notice. The same happens for a soft-deleted gateway (`Livewire/Invoices/Show.php:171`).
- Fix: null the service FK in the agreement's `deleting` hook; null-safe calls; catch `Throwable`. Effort S. Now.

**DATA-10: Status columns are free strings with incomplete value sets**
- Medium · parity / fragile · confirmed
- `services.status`, `invoices.status`, `tickets.status`/`priority` and `coupons.type` are `string` with no enum or CHECK. A foreign value (as the importer's `unpaid` already showed) silently drops rows out of the cron.
- Services have no terminated, fraud or completed (WHMCS Terminated is a distinct state; it is mapped to cancelled).
- Invoices have no refunded, collections or payment-pending.
- `orders` has no status, number, amount, promo, IP or invoice, yet "Accept Order" is done 449 times a year.
- Fix: PHP enums with casts plus a CHECK constraint; decide the status sets before the importer. Effort M. Now. Overlap: ROADMAP Products / "Accept Order".

**DATA-11: Missing unique constraints at the database level**
- Medium · fragile · confirmed
- `credits(user_id, currency_code)`: enforced only by the form and API rule. Affiliates and `Upgrade.php:216` create rows with no DB guarantee, and `lockCredit()->first()` would hide a second row.
- `invoice_transactions(gateway_id, transaction_id)`: index only; overlaps the ROADMAP item.
- `coupons.code`: form-level only.
- `service_cancellations.service_id` and `invoice_snapshots.invoice_id`: models use `hasOne`.
- `settings(key, settingable_id, settingable_type)`: MySQL treats NULLs as distinct, so global settings, including the invoice counter row, are not unique.
- Fix: add the indexes (dedupe migration first). Effort S. Now.

**DATA-12: `invoice_items.description` is varchar(255)**
- Medium · parity · confirmed
- Where: `2024_07_05_144024_create_invoice_items_table.php:20`.
- WHMCS uses TEXT, with multi-line hosting, config-option and domain-period descriptions. The importer truncates with `mb_substr`. Native writes longer than 255 fail under strict mode, and the API caps at 255.
- Fix: `text`. Effort S. Now.

**DATA-13: Importer losses beyond the documented list** (requirements for the new importer; `ImportFromWhmcs.php`)
- Medium · parity · confirmed unless noted
- Tickets:
  - The opening message (`tbltickets.message`) is never imported, only `tblticketreplies` (:707-776).
  - `$departmentName` leaks from the previous ticket when a department is missing.
  - Guest tickets (about half of myip's) create real customer accounts. Unknown admins become *customer* users with `admin+…@paymenter.org` emails (:661-705).
- Customers:
  - `zip`/`phone` are mapped to non-existent WHMCS columns (:276-282), so postcode and phone are lost.
  - Clients without an owner are skipped, but their services, invoices and orders are still inserted with FK checks off, leaving orphan rows.
  - Negative credit balances are dropped.
- Payments:
  - `tblaccounts.amountout` (refunds) becomes a 0-amount *succeeded* payment.
  - Rows with `invoiceid=0` are inserted against invoice 0.
  - Fees, gateway and rate are dropped (:975-1001).
- Invoices:
  - Invoices paid with credit (2,622 on myip) have no `tblaccounts` rows and get no `is_credit_transaction`, so they are `paid` with remaining > 0.
  - myip invoices with an empty `invoicenum` get `number=NULL`; they should be `number = id`.
  - Refunded is mapped to cancelled.
- Services:
  - Per-service config options (`tblhostingconfigoptions`, 282 myip services) are not imported.
  - Terminated is mapped to cancelled.
  - Suspected: `price=amount` together with `quantity=qty` double-counts if WHMCS `amount` is the total for qty>1.
- General:
  - LIMIT/OFFSET with no ORDER BY.
  - Suspected: PDO DSN without `charset=utf8mb4`.
- Effort L. With ROADMAP Importer.

**DATA-14: Staff and customers share `users` (one id space, unique email)**
- Medium · parity · confirmed
- Where: `importAdmins` (:331) uses `tbladmins.id` as `users.id`, which collides with client ids, and skips any admin whose email equals a client's email.
- Fix: map admin ids (offset or map table) and allow staff and customer accounts with the same email, or split staff from customers. Decide before Phase 3 multi-user. Effort M. Overlap: Phase 3 "Ένας χρήστης, πολλοί λογαριασμοί".

**DATA-15: WHMCS data with no home in the schema** (items not already on the ROADMAP)
- Medium · parity
- Customer: language per customer (needed for el/en templates; nixpal uses 7 languages), customer status (Active/Inactive/Closed; MT-1 covers deactivation), default gateway per customer, separate-invoices flag (3), admin/sticky notes.
- Service: domain, username, dedicated/assigned IPs as searchable columns (today they live in TEXT properties: staff search them about 479 times a year and they are not indexed), `nextinvoicedate`, termination date, never-suspend (ROADMAP has it), product custom fields per service (`tblcustomfieldsvalues`, possibly nixpal licence data), hosting addons (12 active).
- Invoice lines: line `type` and `taxed` flag (PromoHosting, GroupDiscount, AddFunds, mass-pay "Invoice" lines are lost when the reference is set to null).
- Fix: schema decisions per item, M–L. Before the importer.

**DATA-16: Deleting a currency ignores invoices and transactions** (Low, confirmed)
- `EditCurrency.php:24-54` checks services, orders and credits only, then deletes all `Price` rows. `currency_code` has no FK anywhere. Fix effort S.

**DATA-17: Missing indexes for cron scans** (Low, confirmed)
- No index on `services(status, expires_at)`, `invoices(status, due_at)`, `services.subscription_id` (PayPal webhook lookup) or `tickets.status`. Fine at the current size (~2k services, ~20k invoices). Fix effort S, later.

**DATA-18: Timezones** (Low, partly suspected)
- Datetimes are stored naive in the settings timezone. `config/database.php` sets no DB session timezone, and `timestamp` and `dateTime` columns are mixed (DST, and `useCurrent` uses the server's clock).
- Changing the timezone after go-live shifts all history. The importer must read WHMCS local time explicitly.
- Fix: pin `Europe/Athens` (or UTC) at install and lock the setting. Effort S.

**DATA-19: Admin API invoice items accept any class name** (Low, security, confirmed)
- `CreateInvoiceItemRequest` resolves an arbitrary `reference_type` with `app($class)`, which lets the API instantiate any class. Price is `min:0`, while WHMCS has negative discount lines.
- Fix: allow-list the reference types. Effort S.

**DATA-20: Float casts on money** (Low, confirmed)
- `Coupon::value` is cast `float`; `Credit::amount` and `Service::price` have no cast. Overlaps ROADMAP "Ποσά σε decimal/minor units".

### Summary
The column types themselves are sound (`decimal(17,2)`, dates). The real risks are elsewhere:
- **Delete and cascade behaviour** on FKs and polymorphic references, plus soft-delete vs FK mismatches (DATA-2, -3, -6, -8, -9).
- **What stored amounts mean**: gross prices with tax baked in, and invoice tax and totals not frozen (DATA-1, -7). This will dominate importer parity.
- **Invoice number vs id** (DATA-5) and plain-text service credentials (DATA-4).

The existing importer is unusable, as already decided. The schema still lacks a home for many WHMCS fields: customer language and status, service domain, username and IPs, line types, `paid_at`, order status. Those schema decisions should be made before the new importer and the money-trail work are built on top.
