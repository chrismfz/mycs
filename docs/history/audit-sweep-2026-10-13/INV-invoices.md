<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## Invoices: documents and arithmetic. Audit findings (static reading only; nothing modified)

Context read first: CLAUDE.md, ROADMAP, audit-billing, money-trail, payments, decisions, settings-map/whmcs-usage (grep), billing-cursor (grep), git log through b67f57e. Findings already covered by PR 2a/2b/2c, MT-1..3 or the importer rewrite are left out unless I found a new angle, and each overlap is noted.

**INV-1: Saving the admin Settings page rewinds the invoice and credit-note counters.** High · bug · confirmed by reading
- Where: `app/Admin/Pages/Settings.php:37-46` (form filled at mount), `:87-120` (writes every value that differs from the DB). The counters are ordinary fields (`app/Classes/Settings.php:571-576`, `:628-634`).
- Scenario: staff open Settings. Cron or checkout then creates invoices (DB counter 32480 → 32485). Staff change any setting and press Save. The stale 32480 is written back. The next number hits the `invoices.number` unique index and throws.
- Why it is High: in proforma mode (nixpal) the number is assigned on the paid transition inside the payment transaction. The payment then rolls back: the gateway charged but nothing is recorded. The same applies to `credit_note_number`.
- Fix: take the counters out of the generic form, or skip fields unchanged since mount. Add a "set next number" action, validated ≥ current, under lock. Effort S. When: now.

**INV-2: `Settings::tax()` is memoised per user object id and never flushed, so the wrong VAT can go to the wrong customer.** High · bug · mechanism confirmed, impact suspected
- Where: `app/Classes/Settings.php:704-723`. It uses `once()`, keyed by `spl_object_id($user)` (vendor `Onceable.php:64-90`), stored on the static Once instance. Nothing outside tests flushes it.
- Scenario: the cron renewal loop or a queue worker frees one `User` and loads the next. PHP reuses the object id, so the second customer gets the first customer's rate. This feeds `CreateInvoiceSnapshotService` (snapshot tax) and `Service::calculatePrice`. Rate or country changes are also never seen until the worker restarts.
- Impact: harmless for myip (one 24% rate). Wrong for nixpal (27 EU rates).
- Fix: drop `once`, or key it by user id plus a per-request or per-job flush. Effort S. When: now (before EU VAT).

**INV-3: The tax model is gross-only with one tax line per invoice, which differs fundamentally from WHMCS.** High · parity gap / fragile design · confirmed by reading
- How it works today:
  - Amounts are stored tax-inclusive: `services.price` (`Service.php:596-599`), cart lines (`CartItem.php:103`, `Cart.php:226-233`).
  - The invoice's VAT is back-calculated once from its grand total (`Invoice.php:122-143`, `:242-257`, `Price.php:84-88`).
  - Lines carry no net amount, no rate and no taxed flag. There is no per-customer exemption, and only one rate per invoice.
- Consequences:
  - (a) Staff (admin repeater `InvoiceResource.php:102-114`) and the API enter the price as gross. A WHMCS-trained myip admin typing the net 10.00 undercharges by 24%.
  - (b) A VAT-rate change or a customer becoming exempt or reverse-charge does not reprice renewals; the stored gross stays.
  - (c) Credit deposit invoices (`Livewire/Client/Credits.php:91-102`) show VAT on the deposit, and VAT shows again when the credit is spent.
  - (d) Rounding from the total differs from rounding per line. Example: three lines of net 0.99 → MyCS 3.69 vs WHMCS 3.68. Ekdosi/myDATA work per line, so the fiscal VAT will not match the proforma.
  - (e) The importer must turn WHMCS net amounts into gross.
- WHMCS: line amount is net, with a per-line "taxed" flag; subtotal, tax and total are stored; tax is per line or on the total (setting); exempt clients are supported.
- Fix: store net, rate and tax per line, compute at issue, persist the totals. Effort L. When: decide the data model now, before EU VAT, the bridge and the importer. Overlaps the EU VAT ROADMAP item, which does not cover this storage decision.

**INV-4: "Mark Paid" is a status dropdown that creates no transaction.** High · parity gap · confirmed by reading
- Where: `InvoiceResource.php:74-86`; API `UpdateInvoiceRequest` (`status in:pending,paid,cancelled`), `CreateInvoiceRequest` (`paid` allowed).
- Scenario: staff mark a bank transfer paid. The status becomes paid and services renew, but there is no transaction: no date, method or transaction id, and `remaining` stays > 0. Reports and the future Ekdosi bridge see no payment. Because `hasPayments()` is false (`Invoice.php:100-105`), the "paid" invoice can also be deleted.
- WHMCS: Mark Paid writes a `tblaccounts` row.
- Fix: remove `paid` from the select and the API; add a Mark Paid action through `RecordPaymentService`. Effort S–M. When: now / MT-1, which already plans "Mark Paid".

**INV-5: "myip number = id" is not implemented; a separate counter drifts from the id.** High (myip) · bug · confirmed by reading
- Where: `InvoiceNumberListener.php:34-52`.
- Why it drifts: the counter row is updated inside the caller's transaction, but MySQL auto-increment is not transactional. Every rollback after an invoice INSERT therefore burns an id and keeps the counter. Examples: a Cart checkout exception after `Invoice::save`, or a failed per-service cron transaction.
- Importer off-by-one: it writes `invoice_number = max(id)+1` (`ImportFromWhmcs.php:939-944`), and the listener increments before use, so the first new number is max+2. The Setting's help text also says "next number" while the code stores the last one (`Settings.php:576`).
- Fix: a "number = id" mode that sets `number = id` in `created` within the same transaction. Fix the importer's +1. Effort S–M. When: now (before Phase 5 work builds on it).

**INV-6: Route binding resolves "number OR id", preferring the number.** Medium · security/bug · confirmed by reading
- Where: `Invoice.php:347-368`, used by the API resource (`routes/api.php:32`), the client route (`routes/web.php:39`) and `extensions/Gateways/PayPal/routes.php:7`.
- Scenario: once numbers and ids diverge (INV-5), or with a `{number}` format, `PUT/DELETE /api/.../invoices/123` acts on the invoice whose number is "123", not id 123. Unnumbered (draft/proforma) client and PayPal URLs use the id and can collide the same way.
- Fix: the API binds by id only; client and gateway URLs bind by one explicit key. Effort S. When: now.

**INV-7: Proforma mode numbers invoices at draft → pending, not only at paid.** Medium · bug · confirmed by reading
- Where: `InvoiceNumberListener.php:20-26`.
- Scenario: with immutable invoices on, staff create a draft and press Publish (`EditInvoice.php:85-89`). The unpaid proforma consumes the next sequential paid number. Gaps appear in nixpal's `{YEAR}-{NUMBER}` sequence, and cancelled unpaid invoices carry "final" numbers.
- WHMCS: the sequential number is assigned only on payment.
- Fix: in proforma mode, assign the number only on → paid. Effort S. When: with the ROADMAP "Αρίθμηση {YEAR}-{NUMBER} + proforma" item.

**INV-8: The PDF and client totals do not add up, and balance and due date are missing.** Medium · bug/parity gap · confirmed by reading
- Where: `resources/views/pdf/invoice.blade.php` (~179-220), `themes/default/views/invoices/show.blade.php:126-160`.
- Scenario: subtotal and tax are derived from the total, which already includes the adjustment notes, and then the adjustments are listed again. Example: items 124 with a −24 note show Subtotal 80.65 + VAT 19.35 + Adjustments −24 = "Total 100". A cancelled invoice shows full lines, 0 VAT, total 0.
- Missing: no "paid / balance due" row on either page (the pay modal charges `remaining`); no due date on the PDF.
- WHMCS PDF shows Sub Total, Tax, Credit, Total, Balance and Due Date.
- Fix: compute subtotal and tax from the lines, show adjustments, payments and balance separately, add the due date. Effort S–M. When: before the nixpal go-live branding item.

**INV-9: Adjustment ("credit/debit") notes are mutable, numbered and customer-facing.** Medium · fragile design · confirmed by reading
- Where: `AdjustmentNoteResource.php:40-140` and `EditAdjustmentNote.php` void/unvoid/delete, with no status or immutability check; `AdjustmentNotesRelationManager.php:28-31,108-122`; `Invoice.php:107-115`; `InvoiceObserver.php:66-68`.
- Scenario:
  - Staff can edit the amount, delete (bulk too), or unvoid a "CN-n" note on paid or cancelled invoices. Numbering gaps appear and the invoice status is never re-checked: e.g. unvoiding the cancellation note of a reopened invoice leaves it pending with balance ≤ 0, or a cancelled invoice gets a balance.
  - Every cancelled unpaid invoice burns a credit-note number and shows customers a "Credit note", easy to confuse with a fiscal πιστωτικό (Ekdosi's job).
- WHMCS: cancelling an unpaid invoice creates no document; credits are in the credit log.
- Fix: treat these as internal, append-only adjustments (void, never delete), no "credit note" label for clients, re-check status on change. Consider no note for unpaid cancellations. Effort M. When: MT-1 / 2c.

**INV-10: Staff can change an invoice's customer or currency after money is on it.** Medium · bug · confirmed by reading
- Where: `InvoiceResource.php:59,87-91`; API `UpdateInvoiceRequest` (`user_id`, `currency_code`).
- Scenario:
  - Change the customer: the snapshot keeps the old name and tax (`CreateInvoiceSnapshotService.php:12` returns early), and the lines still reference the old customer's services. Bought credit goes to the new customer (#39 made that explicit).
  - Change the currency: existing EUR transactions count as USD.
- Fix: lock `user_id` and `currency_code` once the invoice has transactions (or is no longer a draft); rebuild the snapshot on a customer change. Effort S. When: now.

**INV-11: Editing lines on paid or partially paid invoices; the invoice-items API ignores immutability.** Medium · bug · confirmed by reading
- Where: `InvoiceResource.php:96-134` (repeater); `InvoiceItemController.php:45-88` and `Requests/Api/Admin/InvoiceItems/*` (no invoice-status or `immutable_invoices_enabled` check).
- Scenario: lines can be added, changed or removed on a paid invoice. The status stays as it is and any overpayment is not credited. Removing a renewal line drops the period from the billing cursor (rebilling suspected).
- Also: the admin form allows negative or fractional quantities (integer column), while the API requires `min:1`. The API's `reference_type` accepts any existing class and instantiates it via `app($class)` (Low security).
- Fix: route edits through the PR 2b re-check, enforce immutability in the API, allow-list `reference_type`. Effort S–M. When: PR 2b (overlap).

**INV-12: VAT on old invoices can change after issue.** Medium · bug · confirmed by reading
- Where: `Invoice.php:244-256`, `CreateInvoiceSnapshotService.php:22-26`, `Price.php:80`.
- Scenario: when no rate applied at issue, the snapshot has no `tax_name`, so `tax` falls back to the customer's live country rate. A later "all" rate, a country change, `invoice_snapshot` turned off, or `tax_enabled` toggled changes the VAT shown on old PDFs.
- Fix: always snapshot the rate (0 explicitly) and the tax mode; never fall back for finalized invoices. Effort S. When: with INV-3.

**INV-13: Customers see their draft invoices.** Medium · parity gap · confirmed by reading
- Where: `app/Livewire/Invoices/Index.php:16` (no status filter), `InvoicePolicy.php:21-24` (owner only).
- WHMCS hides drafts from clients.
- Fix: filter drafts in the list and deny them in the policy for non-staff. Effort S. When: now.

**INV-14: Terminating one service cancels whole multi-service invoices.** Medium · bug · confirmed by reading
- Where: `CronJob.php:82`, `:149`, via the `Service::invoices()` hasManyThrough (`Service.php:524-527`).
- Scenario: a mass `update(['status' => 'cancelled'])` on every pending invoice containing the service also cancels other services' lines (order invoices now, grouped renewals later). No lock and no observer run.
- Fix: per-invoice handling, or void/remove only the service's line. Effort S–M. When: MT-2 (mass-update item); the multi-service angle is new.

**INV-15: No grouping of renewals into one invoice per customer per due date.** Medium · parity gap · confirmed by reading
- Where: `CronJob.php:218-295` creates one invoice per service per cycle.
- WHMCS bundles everything due for a client on the same date (except the 3 "separate invoices" clients).
- Impact: more emails, more Ekdosi fiscal documents, more Mass Pay. Not on the ROADMAP (only "Group Similar Line Items").
- Fix: generate per customer per due date. Effort M–L. When: with the per-cycle "days before" item, before cut-over.

**INV-16: Paid → Cancelled is allowed and books a full-amount credit note.** Medium · bug · confirmed by reading
- Where: `ViewInvoice.php:199` (the cancel action is visible for paid invoices), the form select, the API.
- Scenario: the note equals −total while the money stays. Remaining becomes −paid and is not converted to credit.
- Overlap: payments.md already lists the partial-payment case; the fully paid case is the same root cause.
- Fix: block it or require a refund/credit decision. Effort S. When: 2c / MT-1.

**INV-17: "Issued At" is silently discarded.** Low–Medium · bug · confirmed by reading
- Where: `InvoiceResource.php:64-68` shows `created_at` as an editable field, but it is not in `$fillable` (`Invoice.php:56`), so `fill`/`update` drop it on both create and edit.
- WHMCS allows editing the invoice date.
- Fix: make it fillable for staff, or remove the field. Effort S.

**INV-18: New-invoice email can go out for an invoice that was rolled back.** Low · bug · confirmed by reading
- Where: `InvoiceObserver.php:42-44`, `:86-88` use `dispatch(...)->afterResponse()`, which is not tied to the DB commit.
- Scenario: checkout or the cron saves the invoice, then rolls back; the customer still gets "new invoice #N" with a PDF. In queue workers, afterResponse callbacks only run at worker exit (suspected).
- Fix: `DB::afterCommit`. Effort S.

**INV-19: The emailed PDF file is shared and never cleaned up.** Low · fragile design · confirmed by reading
- Where: `NotificationHelper.php:157-173` writes `storage/app/invoices/{number ?? id}.pdf`.
- Problems: proforma id and number names can collide; a later save overwrites a queued attachment; files with personal data are never deleted. A number format containing `/` breaks the path and the client route (the format is validated only for `{number}`).
- Fix: temp file per send, deleted after; restrict the format's characters. Effort S.

**INV-20: Missing WHMCS statuses.** Low · parity gap
- No Refunded or Collections status (myip has 35 and 15); the admin filter has no Draft. The old importer maps Refunded → cancelled and writes `unpaid`, both already known.
- When: 2c and the importer rewrite.

**INV-21: Leftover float and pricing nits.** Low · confirmed by reading
- `Invoice::currentBalance` is float (`Invoice.php:162-170`; the admin "Remaining" column) while `remaining` is in cents.
- `Price` does float arithmetic with `number_format` rounding.
- Coupons discount the gross price and `tax` is not recomputed after the discount (`CartItem.php:117-123`).
- The setup fee is merged into the recurring line (`Cart.php:226`); WHMCS shows a separate line.
- New-order `due_at` is now+7 (WHMCS: the order date).
- Effort S each. When: later, or with INV-3.

**INV-22: The very first invoice-number assignment is not locked.** Low · bug · suspected
- `lockForUpdate` on a missing Setting row locks nothing, so the first two concurrent invoices can draw the same number and one fails. Only possible before the row exists (fresh install or import).

### Summary
- **Arithmetic:** totals are computed live in integer cents and payment locking is now solid (PR 2a and follow-ups).
- **Documents:** the invoice as a document is weak. The VAT model is gross-only and invoice-level (INV-3, 12), the counters are fragile (INV-1, 5, 7, 22), staff can freely change money-bearing fields with no re-check (INV-4, 9, 10, 11, 16), and the PDF totals do not add up (INV-8).
- **Most urgent:** INV-1 and INV-2 are cheap fixes with real money and VAT impact.
- **Decide now:** INV-3 (net amounts and tax per line), INV-5 (number = id) and INV-15 (grouping) should be decided before the EU VAT work, the Ekdosi bridge and the importer build on the current shape.
- **Already planned:** the rest fits into PR 2b/2c and MT-1/MT-2.
