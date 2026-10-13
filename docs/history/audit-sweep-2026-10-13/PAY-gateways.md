<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## PAY audit: payment gateways, webhooks, auto-charge, refunds (static review only, nothing changed)

Most severe first. I left out what docs/payments.md, money-trail.md and audit-security.md already list, unless I found something new about it.

**PAY-1: PayPal REST calls never fail, so refund, charge and cancel always report success**
- Severity: Critical. Type: bug. Confirmed by reading.
- Where:
  - `extensions/Gateways/PayPal/PayPal.php:232-239`: `request()` has no `->throw()` and no status check.
  - `refund()` at :467-485 ignores the response.
  - `charge()` at :109-131 ignores `$result`.
  - `cancelSubscription()` :449 and `cancelBillingAgreement()` :70 always return true.
- Failure scenarios:
  - **Refund that never happened.** Staff refund a PayPal payment "via gateway". The cases where PayPal returns an error:
    - a subscription sale id or an IPN txn_id sent to `/v2/payments/captures/{id}/refund` (docs already suspect a 404 here);
    - an amount above what PayPal can still refund;
    - an expired token.
  - In all of these `refund()` returns true. `ExtensionHelper::refund` then records the refund: `refunded_amount`, a credit note, and bought credit taken back. The customer never gets the money, and nothing is logged because `refundedAtGateway` looks true.
  - **Declined vault charge.** It returns true. The customer UI says "Successfully charged" (`Livewire/Invoices/Show.php:177`), the cron counts it as a success, and nobody gets a failure notice.
  - **Admin "Cancel Subscription"** reports success even when PayPal refused.
- Fix: in `request()`, throw on non-2xx. Then check `refund.status ∈ {COMPLETED, PENDING}`, and in `charge()` check the order status (COMPLETED) and the capture status. Effort S. When: now.

**PAY-2: Auto-charge runs once, when the invoice is created, with no retry**
- Severity: High. Type: WHMCS-parity gap. Confirmed by reading.
- Where: `app/Console/Commands/CronJob.php:305-321`, called from `billRenewal`.
- What happens:
  - The card is charged at invoice generation, which is `cronjob_invoice` days before due (default 7), not on the due date.
  - There is exactly one attempt and no retry schedule.
  - Only cron renewal invoices are charged; manual, upgrade and order invoices never are.
  - A failure only emails the customer. Staff get no report.
  - Example: a card declined on day −7 is never tried again. The service gets suspended at +2 days even if the customer topped up the card.
- WHMCS: captures on the due date ("Process Days Before Due"), retries every N days for up to X attempts, and emails both the client ("Credit Card Payment Failed") and admin.
- Fix: a separate "capture due invoices" cron step with attempt tracking (`attempts`, `last_attempt_at`) and settings for capture day, retry interval and max attempts. Charge any open invoice whose service or customer has a default agreement. Effort M.
- When / overlap: ROADMAP "Stripe με αποθηκευμένες κάρτες και auto-charge". This matters for nixpal, where 62.5% of payments are Stripe auto-charged at expiry.

**PAY-3: No idempotency on money-moving gateway calls (double charge / double refund)**
- Severity: High. Type: fragile design. Confirmed by reading.
- Where:
  - Stripe `charge()` :762 and `refund()` :840: no `Idempotency-Key`.
  - PayPal: `PayPal-Request-Id` is `uniqid()` per call (:237).
  - Livewire `payWithSavedMethod` has no per-invoice lock.
- Failure scenarios:
  - **Double refund.** A refund HTTP call times out after Stripe processed it. `->throw()` raises before `$refundedAtGateway = true`, so the DB rolls back and no `Log::critical` is written. Staff retry, and Stripe refunds a second time while refundable amount remains.
  - **Double charge.** A double-click on "pay with saved card", or two tabs, creates two off-session PaymentIntents. The second payment silently becomes account credit.
- Fix:
  - Refunds: key on `refund:{tx_id}:{refunded_cents_before}:{amount_cents}`.
  - Charges: key on `charge:{invoice_id}:{attempt}`, plus a cache lock per invoice for UI charges.
  - PayPal: use the same keys as the Request-Id.
- Effort S. When: now.

**PAY-4: Refund is allowed on failed or processing transactions**
- Severity: Medium (High impact if staff use it). Type: bug. Confirmed by reading.
- Where:
  - `ExtensionHelper::prepareRefund` (`app/Helpers/ExtensionHelper.php:608`) never checks `status`.
  - The action's `visible()` (`TransactionsRelationManager.php:166-170`) only checks `transaction_id` and `refundable_amount > 0`.
- Failure scenario:
  - Stripe `payment_intent.payment_failed` stores a Failed row with the PI id and the full amount, so the "Refund" button appears with the full amount refundable.
  - `refundLocally` then writes a −X credit note. That lowers the invoice total like a phantom discount, and can bring `remaining` to ≤0.
  - Processing (SEPA) rows behave the same way.
- Fix: require `Succeeded` in both places. Effort S. When: now.

**PAY-5: Refunds and chargebacks started at the provider are never reflected**
- Severity: Medium-High. Type: WHMCS-parity gap. Confirmed by reading.
- Where:
  - Stripe only subscribes to the events at `Stripe.php:150-159`: no `charge.refunded` and no `charge.dispute.*`.
  - PayPal: no handling of `PAYMENT.CAPTURE.REFUNDED/REVERSED/DENIED/PENDING` or `PAYMENT.SALE.REFUNDED/REVERSED`.
  - IPN: anything that isn't Completed is dropped (`PayPal_IPN.php:97`).
  - Mollie only acts on `paid`.
- Failure scenario: after a chargeback or a dashboard refund, the invoice stays paid, the service stays renewed, and staff get no alert.
- WHMCS: the PayPal callback records Refunded/Reversed as refund transactions (and can flag the invoice); a dispute is visible in the gateway log.
- Fix: subscribe to these events and record them through the 2c refund path without calling the gateway. Disputes notify staff and flag the invoice. Effort M.
- When: with PR 2c / MT-1.

**PAY-6: Money that matches no invoice or service is lost silently, or retried forever**
- Severity: Medium. Type: fragile design. Confirmed by reading.
- Where:
  - `RecordPaymentService::lockInvoice` `firstOrFail` (:253) throws a 500 on every retry when the invoice no longer exists. Example: a PI or order was opened, then the invoice was deleted; deletion is only blocked once a transaction exists.
  - `PayPal::recordSubscriptionSale` :330 `return`s silently when no service has that `billing_agreement_id`. That happens after admin "Cancel Subscription" nulls `subscription_id` (and PAY-1 means PayPal may still be billing), and for legacy subscriptions.
  - PayPal CAPTURE.COMPLETED with a currency mismatch or missing invoice (:412-415) returns a silent 200.
  - The IPN early `return`s are silent.
- Ekdosi rule: "the truth of the money wins".
- Fix: an "orphan payment" path that records the money as unallocated credit (or at least logs it) and notifies staff. Effort S-M.
- When: the notify part now; the rest with MT-4 (gateway log).

**PAY-7: PayPal capture() records PENDING captures as paid, and can fail after the money is taken**
- Severity: Medium. Type: bug. Field-level behaviour suspected.
- Where: `PayPal.php:299-303`.
- What happens:
  - Only the order status is checked. `captures[0]->status` can be PENDING (PENDING_REVIEW, currency not held for USD, eCheck); it is recorded as Succeeded and the service renews.
  - A later DENIED is not handled (PAY-5).
  - `seller_receivable_breakdown->paypal_fee->value` is dereferenced without null-safety. If it is missing, an exception fires after PayPal has captured. The customer sees an error and may pay again; the webhook records the first payment and the second becomes credit.
  - PayPal error details are swallowed by `abort(400)`, so the JS `INSTRUMENT_DECLINED` restart never fires.
- Fix: map capture COMPLETED → Succeeded and PENDING → Processing; read fields with `?->`; return the PayPal error body. Effort S. When: now.

**PAY-8: Auto-charge doesn't check that the saved payment method belongs to the invoice's customer**
- Severity: Medium. Type: bug / security. Confirmed for the API path; the Filament form is suspected.
- Where:
  - `ExtensionHelper::charge` (:439) and the cron (:306) never compare `billingAgreement->user_id` with `invoice->user_id`.
  - API `UpdateServiceRequest` allows changing `user_id` and leaves `billing_agreement_id` untouched.
- Failure scenario: a service is transferred to another customer. The next renewal charges the previous owner's PayPal vault token, because `charge()` sends only `vault_id`. On Stripe it fails, since the PaymentMethod is attached to another customer.
- Fix: assert ownership in `ExtensionHelper::charge`, and null `billing_agreement_id` when the owner changes. Effort S. When: now.

**PAY-9: Suspension and termination ignore payments still in progress**
- Severity: Medium. Type: bug. Confirmed by reading.
- Where: `CronJob.php:125-158`.
- Failure scenario:
  - The suspend and terminate jobs, and the mass cancel of pending invoices, don't look at Processing transactions.
  - A SEPA debit takes days, and Stripe can already produce SEPA (iDEAL/Bancontact conversion at `Stripe.php:693`, and dashboard payment methods via `automatic_payment_methods`).
  - So the service is suspended, then terminated, and the invoice cancelled. When the debit succeeds, all of it becomes credit, and the service data is already gone.
- Fix: skip or hold services whose open invoice has a processing transaction. Effort S.
- When: now, or at the latest with the "Stripe SEPA" roadmap item.

**PAY-10: Unused subscription code still reacts to webhooks from a shared provider account**
- Severity: Medium. Type: fragile design / security. Confirmed by reading; the WHMCS interaction is suspected.
- Where:
  - Stripe `setup_intent.succeeded` without `is_billing_agreement` goes to `setupSubscription` (:256-257, :317). Any SetupIntent made by WHMCS or the Stripe dashboard on the same account hits an undefined `metadata->invoice_id`, returns 500, and Stripe retries for days. Line :322 also does `new Exception($msg, $user)`, which is a TypeError.
  - The `stripe_use_subscriptions` setting doesn't exist.
  - PayPal `BILLING.SUBSCRIPTION.CREATED` (:385-397) binds any subscription whose `custom_id` matches one of our invoice ids. Invoice ids are kept from WHMCS, so a WHMCS-created subscription could attach to MyCS services, and its sales would then be recorded in MyCS.
  - `ExtensionHelper::cancelSubscription` loops over every gateway. PayPal always says yes (PAY-1); Stripe throws on a foreign id.
- No code path creates new subscriptions any more.
- Fix: remove these paths, or gate them behind explicit config. Answer foreign events with a logged 200. Effort S. When: now, before running in parallel with WHMCS.

**PAY-11: References sent to providers use our bare invoice id**
- Severity: Medium. Type: fragile design. Suspected; it depends on the PayPal account setting.
- Where: PayPal `invoice_id => $invoice->id` (:116, :248), IPN `item_number`, and Stripe/Mollie `metadata.invoice_id`.
- Failure scenario: PayPal's default "block multiple payments per invoice ID" rejects:
  - a second PayPal payment on the same invoice (after a partial payment, after a refund, or a retry after auto-charge);
  - any id WHMCS already used on the same account. Ids are preserved, and the nixpal pilot runs in parallel.
- New angle on audit-security #4: prefix the reference (`{instance}-{id}-{attempt}`) and keep our own reference in `custom_id`. Effort S. When: now.

**PAY-12: Credit isn't applied the way WHMCS applies it**
- Severity: Medium. Type: WHMCS-parity gap. Confirmed by reading.
- Where: the cron applies credit only when it covers the whole invoice (`CronJob.php:324-332`). At checkout, `Cart::$use_credits` is declared and never used.
- Failure scenario: with auto-charge, the card is charged the full amount while the customer's credit sits unused.
- WHMCS: automatically applies available credit, even partially, to new invoices, and offers "apply credit" at checkout.
- Fix: a setting for partial auto-apply, defaulting to WHMCS behaviour; apply credit before the charge. Effort S.
- When: with MT-1b (CreditLedger, PR #42). This rule is documented as intentional, so it needs a decision.

**PAY-13: Admin Add Payment and Refund differ from WHMCS in ways that cause mistakes**
- Severity: Low-Medium. Type: WHMCS-parity gap.
- Where: `TransactionsRelationManager.php:40-67, 144-148`.
- What's missing:
  - Add Payment has no payment date. This matters for bank transfers, which are 42.7% of myip's payments, and for receipts/Ekdosi.
  - There is no "send confirmation email".
  - Refund defaults to *local*: the `refund_via_gateway` toggle starts off, so a refund records but no money goes back.
  - Refund only appears when there is a `transaction_id`, so manual payments without an id can't be refunded.
- When: overlaps the money-trail "Mark Paid / Add Payment" list.

**PAY-14: Stripe webhook signature has no timestamp tolerance**
- Severity: Low. Type: security. Confirmed by reading.
- Where: `Stripe.php:804-820`. Old signed events can be replayed forever, and only the last `v1=` value is checked (secret rotation breaks).
- Payments are protected by idempotency, but a replayed or late-retried `setup_intent.succeeded` re-creates a billing agreement the customer had removed.
- Fix: 300-second tolerance and check every v1. Effort S.

**PAY-15: A new provider payment object on every pay attempt, none reused**
- Severity: Low. Type: fragile design.
- Where: Stripe `pay()` :200, PayPal :244, Mollie :71.
- Every "pay" creates a new PaymentIntent, order or Mollie payment, and none are cancelled. Two open ones can both be completed; the duplicate becomes credit.
- When: with MT §7 (payment intents).

**PAY-16: Small correctness items**
- Severity: Low. Confirmed by reading.
- The Stripe fee (`balance_transaction.fee/100`, :248) is in the settlement currency but stored as if it were the invoice currency.
- `refundable_amount` and `currentBalance` accessors use float arithmetic (`InvoiceTransaction.php:93`, `Invoice.php:166`), so `visible(refundable_amount > 0)` can be true for a ~1e-15 leftover (the server side is safe because it works in cents).
- Stripe `amount * 100` only works because `http_build_query` uses precision 14 (already ROADMAP #1258).

### Overall health
The core money path is solid after five review rounds: `RecordPaymentService`, its locks, idempotency and credit handling. The weak spot is the **gateway adapters** around it. The PayPal adapter treats every HTTP error as success (PAY-1). No call that moves money has an idempotency key. Nothing handles reversals, chargebacks or pending captures, and some webhook paths still drop money silently or retry forever. Auto-charge is far from WHMCS: one attempt, 7 days early, no retries, no ownership check. Before building Cardlink, the PayPal vault or SEPA on top of this, I'd fix PAY-1, 3, 4, 7, 8 and 10 (all small) and design PAY-2 and PAY-5 into the gateway contract, as capabilities plus a status mapping, together with MT-4.
