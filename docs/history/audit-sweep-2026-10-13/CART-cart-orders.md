<!-- Αναφορά reviewer του audit sweep 2026-10-13 (main @ b67f57e, static reading). Σύνοψη: docs/audit-sweep.md -->

## Cart, checkout, orders and pricing audit (static reading only, nothing modified)

Files read: `app/Classes/Cart.php`, `app/Classes/Price.php`, `app/Classes/Synths/PriceSynth.php`, `app/Livewire/Cart.php`, `app/Livewire/Products/Checkout.php`, the models `CartItem`, `Cart`, `Coupon`, `Order`, `Plan`, `ConfigOption` and the `HasPlans` trait, `Service::calculatePrice`, `ServiceUpgradeService`, `CronJob` (orders_cancelled), `CancellationCreatedListener`, the Order and Invoice observers, the admin `OrderResource`/`CreateOrder` and `CouponResource`, and `Settings::tax`. Things already in the docs (billing cursor, payment guards, EU VAT, mass `->update()`) are only cross-referenced below.

### CART-1: A coupon with an empty `recurring` field gives a lifetime discount on upgrade or recalculation
- **Severity:** High. **Type:** bug (money). Confirmed by reading.
- **Where:** `app/Models/Service.php:590`
  - `if ($this->coupon->recurring == 0 || $invoices <= ...)`
  - In PHP `null == 0` is true.
- **Failure scenario:**
  - The admin help text says "1 (or leave empty) = first cycle only", and checkout (`app/Livewire/Cart.php:175`) treats null as first-only.
  - But `calculatePrice()` treats null as "all cycles".
  - `ServiceUpgradeService.php:57` sets `$service->price = calculatePrice()`, and so does the admin "recalculate" (`ServiceResource.php:153`). Either one puts the discount back on every future renewal.
  - The CronJob expiry (`recurring > 0`) never fires for null, so nothing removes it again.
- **WHMCS:** a first-payment-only promo is never re-applied.
- **Fix:** normalise `recurring` (null means 1) in a cast or mutator plus a migration, and compare strictly (`=== 0`). Effort S, now.
- **Overlap:** billing-cursor coupon work (PR #32) covered the cron path only, not this one.

### CART-2: The cart coupon is attached to every service in the order, including products it does not apply to
- **Severity:** High. **Type:** bug (money). Confirmed by reading.
- **Where:** `app/Livewire/Cart.php:190` (`'coupon_id' => $cart->coupon_id` for every item). `Service::calculatePrice` (`Service.php:587-592`) never checks `coupon->products`.
- **Failure scenario:**
  1. A coupon restricted to product A is used on a cart with A and B.
  2. B's first price is correct, because `CartItem` skips the discount for B.
  3. But B carries `coupon_id`, so an upgrade or recalculation of B applies the discount.
  4. B also counts toward `max_uses` and `max_uses_per_user`, and shows "Promotion" on the service.
- **Fix:** set `coupon_id` only when the item's discount is above 0. Also check eligibility in `calculatePrice` (including the new product on upgrade). Effort S, now.

### CART-3: In exclusive-tax mode the stored service price and invoice lines already include VAT
- **Severity:** High. **Type:** fragile design. Confirmed by reading.
- **Where:**
  - `Price.php:89-98`: price becomes net + tax.
  - `CartItem.php:266-270` and `Service::calculatePrice` (`Service.php:597-602`) both return that gross price.
  - `Invoice::formattedTotal` (`Invoice.php:153`) then pulls tax back out of the total using the current or snapshot rate.
- **Failure scenarios:**
  - **Rate change:** a customer whose rate changes (country set after ordering, 24% to reverse-charge 0%, or the rate itself changing) keeps renewing at the old gross amount. With a 0% rate the invoice shows "tax 0" and the old Greek VAT silently becomes revenue.
  - **Gross vs net mixed:** the admin order form (CART-4) stores the net plan price in the same `price` column, so gross and net amounts are mixed in one field.
- **Context:** nixpal runs "24% exclusive" (`settings-map.md:100`).
- **Fix:** store net recurring amounts and line prices, and compute tax per invoice from the snapshot. Effort M–L, before or with the EU VAT item (roadmap "EU VAT", Phase 1). It needs a data migration for existing services.

### CART-4: Admin "Create Order" skips the checkout logic entirely
- **Severity:** High. **Type:** bug / fragile design. Confirmed by reading.
- **Where:** `app/Admin/Resources/OrderResource.php:99-121`, `OrderResource/Pages/CreateOrder.php`.
- **Failure scenarios:**
  - **Price:** it uses the raw net plan price, with no tax added (exclusive mode undercharges by 24%), no setup fee and no config options.
  - **Ignored:** stock, per-user limit and coupons.
  - **Quantity:** not validated as numeric.
  - **Invoices:** it creates a 0 € invoice for free items, and an empty invoice if no services were entered.
  - **Status:** the services stay `pending`, so the cron auto-cancels them 7 days later (CART-11).
  - **Due date:** fixed at +7 days.
- **WHMCS:** "Add New Order" uses the same pricing engine as the cart, with options for "Generate invoice", "Send email" and "Order status".
- **Fix:** move the order-creation core out of `Livewire\Cart::checkout` into a service (for example `PlaceOrderService`) and use it from the client cart, the admin form and the API. Effort M, now (before building more on orders).

### CART-5: Coupon `max_uses` and `max_uses_per_user` are checked once per checkout, not per service, and can race
- **Severity:** Medium. **Type:** bug. The first part is confirmed by reading; the race is suspected.
- **Where:** `app/Classes/Cart.php:189-194`, `Coupon.php:402-411`.
- **Failure scenarios:**
  - With `max_uses=1` (or a per-user limit of 1), a cart with 5 eligible items passes the check at count 0 and creates 5 discounted services.
  - **Race:** inside the checkout transaction, `$cart->coupon` is lazy-loaded at `Cart.php:120` before the coupon `lockForUpdate`. That read fixes the REPEATABLE READ snapshot, so after waiting for the lock, the `services()->count()` from a concurrent user's checkout is not visible and `max_uses` can be exceeded by one. This is the same snapshot pitfall documented in `docs/payments.md`.
- **Fix:** check `count + eligible items in cart` against the limits. Lock the coupon first, then count with a locking read (or keep a `uses` counter on the coupon row). Effort S, now.

### CART-6: Two concurrent checkouts of the same cart create duplicate orders
- **Severity:** Medium. **Type:** bug. Confirmed by reading.
- **Where:** `app/Livewire/Cart.php:100-118`. Cart items are loaded (`once()`) before `DB::beginTransaction()`; the user-row lock serialises the two requests, but the cart is never re-read or checked for existence afterwards.
- **Failure scenario:** checkout from two tabs creates two orders, two invoices and duplicate services. Stock and coupon uses are consumed twice, and free items are activated twice.
- **Fix:** after the user lock, reload the cart with `lockForUpdate` and abort if it is gone or empty, or delete the cart inside the transaction. Effort S, now.

### CART-7: Checkout decides whether to create an invoice from a stale stored total
- **Severity:** Medium. **Type:** bug. Confirmed by reading.
- **Where:** `app/Livewire/Cart.php:162` and `:252`. `#[Locked] $total` is computed at mount, while the lines are recomputed fresh at `:227`.
- **Failure scenarios:**
  - **Free cart, paid item added in another tab:** `$invoice` is never set, and `$invoice->items()` on null throws an `Error`. It is not caught, because the code catches `Exception` only, so the transaction is left open (500 error).
  - **The reverse:** an empty pending invoice is created.
- **Fix:** recompute the total inside the transaction, create the invoice lazily, and catch `Throwable`. Effort S, now.

### CART-8: Fixed-amount coupons are taken off the gross price in the cart but off the net price on recalculation, and "all" applies them twice
- **Severity:** Medium. **Type:** bug. Confirmed by reading.
- **Where:** `CartItem.php:281-285` (discount on gross, after tax) vs `Service.php:591` (discount on net, then tax). `Coupon::calculateDiscount` applies `value` separately to price and to setup fee when `applies_to = all`.
- **Failure scenarios:**
  - Net 20, 24% tax, fixed 5, all cycles: the first invoice and stored price are 19.80, while an upgrade or recalculation gives 18.60.
  - A fixed €10 coupon on "price + setup fee" takes €20 off.
  - Displayed `tax` is not recomputed after the discount.
  - Fixed coupons have no currency, so €5 becomes 5 units of any currency.
- **Fix:** one discount function on net amounts. A fixed amount should be a single amount per item, split across price and setup fee (or make that choice explicit). Effort S–M, with CART-3.

### CART-9: WHMCS "quantity" config options lose their price, and number/text values are not validated
- **Severity:** Medium. **Type:** WHMCS-parity gap plus security. Confirmed by reading.
- **Where:**
  - `ImportFromWhmcs.php:397` maps optiontype 4 to `number`.
  - `CartItem.php:243` and `Checkout.php:114` treat number as having no price.
  - `Checkout.php:162-163` only requires a value.
- **Failure scenarios:**
  - Products priced per unit (extra IPs, accounts) are sold without that charge.
  - A client can submit `-5` or `999999` or any string; it is stored as a service property and passed to the server extension for provisioning.
- **WHMCS:** price × quantity, with min/max per option.
- **Fix:** add a priced quantity type (min, max, step, price per unit, used in `CartItem`, `calculatePrice` and upgrades), plus validation rules for number options. Effort M, with the importer and config-options parity work (whmcs-usage: 230 options on 282 services).

### CART-10: Adding a new checkbox config option to a product breaks existing carts
- **Severity:** Medium-Low. **Type:** bug. Confirmed by reading, assuming Laravel's default warning-to-exception handler.
- **Where:** `CartItem.php:228-231`. `(object) null` becomes an empty stdClass, and `$selected?->value` raises an "Undefined property" warning, which Laravel turns into an `ErrorException`.
- **Failure scenario:** the cart page crashes, and checkout fails with "An error occurred" until the item is removed.
- **More:** stale `config_options` JSON is also written into `service_configs` at `Cart.php:220` without re-checking that the option and value still belong to the product, so a service can get a config it did not pay for, and renewals then charge it.
- **Fix:** use `isset($selected->value)`, and re-validate the options against the current product at checkout. Effort S.

### CART-11: The pending-order auto-cancel uses `created_at`, not the invoice due date
- **Severity:** Medium. **Type:** bug / parity. Confirmed by reading.
- **Where:** `app/Console/Commands/CronJob.php:79-93`.
- **Failure scenarios:**
  - A staff-extended due date is ignored.
  - Partially paid first invoices are cancelled, stranding the partial payment.
  - Admin-created pending services with no invoice are cancelled too.
  - Bulk `->update()` skips the observers, so no cancellation credit note is created.
- **Fix:** base it on invoice `due_at` + grace, skip invoices with any payment, and go through a service with observers. Effort S–M, now.
- **Overlap:** ROADMAP "Ακυρώσεις τιμολογίων μέσω observers" (invoice cancellations via observers).

### CART-12: The order model has no status, so there is no Pending/Active/Fraud/Cancelled flow or Accept Order
- **Severity:** Medium. **Type:** WHMCS-parity gap. Confirmed by reading.
- **Where:** `app/Models/Order.php`.
- **Details:**
  - No status, invoice link, promo code, amount, IP or notes on the order.
  - `Order::total` (`:57-60`) is the sum of the services' recurring prices, not what was charged. It leaves out the setup fee, and with first-cycle-only coupons it shows the undiscounted price.
  - Free items are provisioned immediately at checkout with no gate.
- **Fix:** add status, amount, promo code and invoice id to orders, plus an "Accept Order" action. Effort M.
- **Overlap:** ROADMAP "Products «σαν WHMCS»" (Products "like WHMCS") / Auto Setup / Accept Order item.

### CART-13: Order and invoice "created" emails are sent even when checkout rolls back
- **Severity:** Low-Medium. **Type:** bug. Confirmed by reading.
- **Where:** `OrderObserver.php:27` and `InvoiceObserver.php:41` (`dispatch(...)->afterResponse()` inside the checkout transaction).
- **Failure scenario:** a failure after the order or invoice insert still emails the customer about an order or invoice that does not exist.
- **Fix:** use `DB::afterCommit` (or afterCommit listeners). Effort S. Relevant to the money-trail work.

### CART-14: Exclusive tax is rounded per unit, then multiplied by quantity
- **Severity:** Low-Medium. **Type:** bug. Confirmed by reading.
- **Where:** `Price.php:91-93` with the invoice line `price × quantity`; tax is then re-extracted from the invoice total.
- **Failure scenario:** net 0.99 × 100 at 24% gives 123.00 instead of 122.76, and the line tax shown does not match the invoice tax.
- **Fix:** compute tax on the line (or invoice) total in decimals/cents. Effort S, with CART-3 and EU VAT.

### CART-15: Coupon rules are thinner than WHMCS promotions
- **Severity:** Low-Medium. **Type:** parity. Confirmed by reading.
- **Missing:**
  - Billing-cycle restriction ("applies to cycles").
  - "Requires" products.
  - New signups only / existing clients only.
  - Once per order / once per client.
  - A currency on fixed values.
- **Expiry:** `expires_at` comes from a DatePicker, so it is midnight and `isPast()` excludes the whole last day (`Cart.php:183`).
- **Usage:** myip has 4 codes with 295 uses, 3 of them recurring.
- **Fix:** effort M, with the ROADMAP item "recurring promotions/ομάδες" (recurring promotions/client groups). Fix the expiry to end-of-day now (S).

### CART-16: There is no "retired" product; hidden products can still be ordered by slug
- **Severity:** Low-Medium. **Type:** parity. Confirmed by reading.
- **Where:** `Checkout.php:44`. No hidden or retired check at mount, add or checkout.
- **Detail:** this is fine for direct-link products, but there is no way to stop sales of a legacy product that existing services still use.
- **Fix:** add a `retired` flag checked in Checkout and the cart. Effort S, with the ROADMAP "Products «σαν WHMCS»" item.

### CART-17: Stock restore is inconsistent
- **Severity:** Low. **Type:** bug. Confirmed by reading.
- **Where:** `CancellationCreatedListener.php:28` uses `if (stock)`, so a sold-out product (stock 0) never gets its stock back on cancellation.
- **Also:** a cron-cancelled order that staff reopen and get paid is reactivated without taking stock again.
- **Fix:** use `!== null`, and centralise stock changes. Effort S.

### CART-18: The per-user product limit counts cancelled and pending services
- **Severity:** Low. **Type:** bug. Confirmed by reading.
- **Where:** `Cart.php:138`.
- **Failure scenario:** a customer whose unpaid order was auto-cancelled can never re-order a limit-1 product.
- **Fix:** exclude cancelled services. Effort S.

### CART-19: An invalid coupon cannot be removed from the cart page
- **Severity:** Low. **Type:** bug. Confirmed by reading.
- **Where:** `Cart.php:120-121`. `validateAndRefreshCoupon()` removes the coupon inside the transaction, then `DB::rollBack()` undoes the removal.
- **Failure scenario:** the UI sets `$this->coupon = null`, so the "remove coupon" action answers "No coupon code applied". The customer is stuck until they reload or add an item.
- **Fix:** remove the coupon after the rollback. Effort S.

### CART-20: Config options on one-time or free plans are priced from the cheapest cycle
- **Severity:** Low-Medium. **Type:** bug. **Suspected:** it depends on how one-time plans are stored.
- **Where:** `HasPlans::price` with a null billing period/unit (the admin form hides those fields for one-time plans).
- **Failure scenario:** the option takes the cheapest price across any of its plans and cycles, for example monthly €1 instead of the one-time price.
- **Fix:** match by plan type when there is no period. Effort S.

### CART-21: Smaller tampering and robustness issues
- **Severity:** Low. **Type:** security/robustness. Confirmed by reading.
- **Quantity:** `updateQuantity` (`Classes/Cart.php:144-161`) has no integer or maximum check; non-numeric or fractional input hits the database. Stock and the per-user limit are the only caps.
- **Auto-add on page view:** `Checkout::mount` adds products with a single plan and no options to the cart on a plain GET (crawlers, link prefetch).
- **Foreign plan id:** `updatedPlanId` loads any plan id, so a hidden product's pricing is visible. Checkout validation still blocks the purchase.
- **Fix:** effort S, later.

### CART-22: Smaller differences from WHMCS on the order invoice
- **Severity:** Low. **Type:** parity. Confirmed by reading.
- **Due date:** the order invoice is due at +7 days, hard-coded in both the client and admin paths; WHMCS makes it due on the order date.
- **Setup fee:** it is merged into the first-period line instead of a separate line (this matters for line classification across the Ekdosi bridge).
- **Credits:** `$use_credits` is dead code, so credit is never auto-applied to the order invoice.
- **Currency:** a client can order in any currency, with no per-client currency as in WHMCS.
- **Fix:** effort S each, with the settings-map items.

### Overall health
- **Good:** the core client checkout has the right ideas: a transaction, user and product row locks, stock taken under lock, a coupon lock, and the fork's existing "price unavailable in this currency" guards.
- **Weak:**
  - Pricing logic is duplicated across `Checkout`, `CartItem`, `Service::calculatePrice`, the admin order form and upgrades, and the copies disagree on tax (gross vs net), fixed coupons and coupon semantics.
  - The tax model (gross stored in exclusive mode) blocks EU VAT.
  - Orders are a thin shell with no lifecycle.
- **Recommendation:** before building features, extract a single tested `PlaceOrderService` and pricing service working in decimals on net amounts, and fix CART-1, 2, 5, 6 and 7. All of them are small but affect money.
