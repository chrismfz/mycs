<?php

namespace App\Admin\Resources\ServiceResource;

use App\Admin\Resources\InvoiceResource;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use App\Services\ServiceUpgrade\UpgradeQuote;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use MyCS\Pricing\DifferenceDiscount;
use MyCS\Pricing\DifferenceDiscountRefused;

/**
 * WHMCS's admin «Upgrade/Downgrade» (owner 2026-11-04): another product for the service, its cycle, and before anything is
 * made the days left of the cycle and what is due (UpgradeQuote). An upgrade makes the invoice the customer's page would
 * (applied once paid); a downgrade is applied now at 0.00, its new price from the next renewal (credit only where the
 * settings give it). Both are kept on the service's «Package changes» and mailed to the customer once applied.
 *
 * On an upgrade staff may give a discount on the difference (owner 2026-11-06, docs/decisions.md, SVC-14): a percentage,
 * the amount to charge (with tax, as the summary shows it), or waive it. The summary shows the result before anything is
 * made; the invoice shows the discount as a line of its own. The renewal price is not touched.
 */
final class ChangePackage
{
    public static function action(): Action
    {
        return Action::make('changePackage')
            ->label('Upgrade/Downgrade')
            ->icon('ri-arrow-up-down-line')
            ->authorize('update')
            // Always in the menu (owner 2026-11-10, docs/staff-freedom.md): when it does not apply it is disabled, with why
            // and staff's way through as its tooltip, rather than gone without a word. A disabled action is not mounted.
            // Not the catalog on every load of the page: the products are listed once the modal opens.
            ->disabled(fn (Service $record) => self::refusal($record) !== null)
            ->tooltip(fn (Service $record) => self::refusal($record))
            ->modalHeading('Upgrade/Downgrade')
            ->modalDescription(fn (Service $record) => "Related service: {$record->product?->name}"
                . ($record->domain ? " ({$record->domain})" : ''))
            ->schema([
                Select::make('product_id')
                    ->label('New product/service')
                    ->options(fn (Service $record) => self::targets($record)->mapWithKeys(fn (Product $product) => [$product->id => $product->name]))
                    ->searchable()
                    ->required()
                    ->live(),
                Placeholder::make('cycle')
                    ->label('Billing cycle')
                    ->content(fn (Get $get, Service $record) => self::cycle($record, $get('product_id'))),
                ToggleButtons::make('discount_mode')
                    ->label('Discount on the difference')
                    ->options([
                        'none' => 'None',
                        DifferenceDiscount::PERCENT => 'Percentage',
                        DifferenceDiscount::AMOUNT => 'Amount to charge',
                        DifferenceDiscount::WAIVE => 'Waive it',
                    ])
                    ->default('none')
                    ->inline()
                    ->live()
                    // Only an upgrade that charges something: a downgrade or nothing due stays as it is.
                    ->visible(fn (Get $get, Service $record) => self::upgradeDue($record, $get('product_id'))),
                TextInput::make('discount_value')
                    ->label(fn (Get $get) => $get('discount_mode') === DifferenceDiscount::PERCENT ? 'Percentage (%)' : 'Amount to charge (with tax)')
                    ->helperText(fn (Get $get) => $get('discount_mode') === DifferenceDiscount::PERCENT
                        ? 'Of the difference, up to 100.' : 'What the customer pays now, tax included; 0 waives it.')
                    ->required()
                    ->live(debounce: 500)
                    ->visible(fn (Get $get, Service $record) => in_array($get('discount_mode'), [DifferenceDiscount::PERCENT, DifferenceDiscount::AMOUNT], true)
                        && self::upgradeDue($record, $get('product_id'))),
                Placeholder::make('summary')
                    ->label('Upgrade summary')
                    ->content(fn (Get $get, Service $record) => self::summary($record, $get('product_id'), $get('discount_mode'), $get('discount_value'))),
            ])
            ->modalSubmitActionLabel('Create order')
            ->action(function (array $data, Service $record, Action $action, $livewire): void {
                // Read again: it may have changed since the page was loaded (a request, a change of cycle).
                if (($refused = self::refusal($record->refresh())) !== null) {
                    Notification::make()->danger()->title('Not changed')->body($refused)->send();
                    $action->halt();

                    return;
                }
                $product = self::targets($record)->firstWhere('id', (int) $data['product_id']);
                if ($product === null) {
                    Notification::make()->danger()->title('Not changed')->body('This product is not available for this service.')->send();
                    $action->halt();

                    return;
                }
                [$mode, $value] = self::discountOf($data['discount_mode'] ?? null, $data['discount_value'] ?? null);
                $placed = PlaceUpgrade::handle($record, $product, PlaceUpgrade::defaultChoices($record, $product), ServiceUpgrade::SOURCE_STAFF,
                    Auth::guard('staff')->id(), $mode, $value);
                $why = match ($placed->outcome) {
                    PlaceUpgrade::NOT_UPGRADABLE => 'Another change of this service is waiting for payment: pay its invoice, or cancel the change under «Package changes» first.',
                    PlaceUpgrade::CYCLE_CHANGE_PENDING, PlaceUpgrade::PARENT_CYCLE_CHANGE_PENDING => PlaceUpgrade::cycleChangePending($record),
                    PlaceUpgrade::CYCLE_NOT_STARTED => self::cycleNotStarted($record),
                    PlaceUpgrade::UPGRADE_INVOICE_OPEN => 'An upgrade invoice of this service is open (#' . ($placed->invoice->number ?? $placed->invoice?->id)
                        . "): record its payment, or cancel it first («Cancel Invoice» on invoice #{$placed->invoice?->id}).",
                    PlaceUpgrade::INVALID => 'The new product has no plan for this billing cycle in this currency.',
                    PlaceUpgrade::UNCHANGED => 'It already has this product.',
                    PlaceUpgrade::NEEDS_STAFF => 'The catalog has no price for it in the service\'s currency: change it by hand.',
                    PlaceUpgrade::NOT_NOW => 'The service no longer runs.',
                    PlaceUpgrade::OUT_OF_STOCK => 'The new product is out of stock (pending upgrades to it included).',
                    PlaceUpgrade::DISCOUNT_REFUSED => self::refusalText($placed->refusal),
                    default => null,
                };
                if ($why !== null) {
                    Notification::make()->danger()->title('Not changed')->body($why)->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['product_id', 'plan_id', 'price', 'price_locked']);
                }
                if (property_exists($livewire, 'filledAttributes')) {
                    $livewire->filledAttributes = $record->getAttributes();
                }
                if ($placed->outcome === PlaceUpgrade::INVOICED) {
                    $invoice = $placed->invoice;
                    Notification::make()->success()->title("Invoice #{$invoice->id} created")
                        ->body("{$invoice->formattedTotal}: the change is applied once it is paid.")
                        ->actions([Action::make('open')->label('Open invoice')->url(InvoiceResource::getUrl('edit', ['record' => $invoice]))])
                        ->send();

                    return;
                }
                $credit = $placed->creditCents > 0 ? ' ' . number_format($placed->creditCents / 100, 2, '.', '') . " {$record->currency_code} credited." : '';
                Notification::make()->success()->title('Package changed')
                    ->body(($placed->downgrade ? 'Nothing charged now; the new price applies from the next renewal.'
                        : ($placed->discount !== null ? 'The difference was waived: nothing charged, applied now.' : 'Nothing to pay now.')) . $credit)
                    ->send();
            });
    }

    /** The customer's refusal with its date, and staff's way through meanwhile. */
    private static function cycleNotStarted(Service $service): string
    {
        return PlaceUpgrade::cycleNotStarted($service) . ' ' . __('services.change_package.refused.cycle_not_started_staff');
    }

    /**
     * Why staff cannot change its package now, with their way through, or null. The checks stay (without them the change
     * is priced on a period that is unpaid or not there); the service's form stays open for product, plan and price.
     */
    public static function refusal(Service $service): ?string
    {
        if ($service->plan === null || $service->product === null) {
            return __('services.change_package.refused.no_plan');
        }
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            return __('services.change_package.refused.not_running', ['status' => $service->status]);
        }
        if ($service->upgrade()->where('status', ServiceUpgrade::STATUS_PENDING)->exists()) {
            return __('services.change_package.refused.pending_change');
        }
        // A cancellation asked for: an upgrade of a service about to end would only be refunded (CR-10, as the customer's
        // page; CR-2 of #638). Staff take the cancellation back first.
        if ($service->cancellation()->exists()) {
            return __('services.change_package.refused.cancelling');
        }
        // Set up before its order was paid: an upgrade would reprice the order's line (adversarial A5).
        if ($service->orderUnpaid()) {
            return __('services.change_package.refused.order_unpaid');
        }
        // A recurring one with no due date is never billed: an upgrade would keep the dearer product for good (ADV-594-2).
        if ($service->plan->type === 'recurring' && $service->expires_at === null) {
            return __('services.change_package.refused.no_due_date');
        }
        // What PlaceUpgrade refuses under its lock, said before the form is filled in: a change of cycle set for its
        // next renewal, or made for a period that has not started (S7-PLAN-CYCLE-ADDONS-2).
        if ($service->next_plan_id !== null) {
            return PlaceUpgrade::cycleChangePending($service);
        }
        if ($service->planStartsOn() !== null) {
            return self::cycleNotStarted($service);
        }

        return null;
    }

    /**
     * The products staff may move it to: any of the same module (another module's would leave the account on the old
     * server, sweep §4), not retired, in stock, with a plan of its cycle in its currency. Not only its product's upgrade
     * paths: WHMCS's admin lists every product.
     *
     * @return Collection<int, Product>
     */
    public static function targets(Service $service): Collection
    {
        if ($service->product === null || $service->plan === null) {
            return collect();
        }
        $module = $service->server_id !== null ? $service->server?->extension : $service->product->server?->extension;

        // Once per request: the options, the cycle and the summary each ask for it on every change of the form.
        return once(fn () => Product::query()->with('server')->whereKeyNot($service->product_id)->orderBy('name')->get()
            ->filter(fn (Product $product) => $product->server?->extension === $module && !$product->retired
                && ($product->stock === null || $product->stock - $service->quantity >= 0)
                && PlaceUpgrade::targetPlan($service, $product) !== null)
            ->values());
    }

    private static function cycle(Service $service, mixed $productId): string
    {
        $product = $productId ? self::targets($service)->firstWhere('id', (int) $productId) : null;
        $plan = $product === null ? $service->plan : PlaceUpgrade::targetPlan($service, $product);
        if ($plan === null) {
            return '—';
        }
        $price = $product === null ? null : $plan->price($service->currency_code);

        return $plan->name . ($price ? " ({$price})" : '') . ' — the cycle stays; «Change billing cycle» changes it.';
    }

    /**
     * The upgrade as it would be placed (not saved), with the options it sets, or null without a product.
     *
     * @return array{ServiceUpgrade, array<int, int>, Product}|null
     */
    private static function draft(Service $service, mixed $productId): ?array
    {
        $product = $productId ? self::targets($service)->firstWhere('id', (int) $productId) : null;
        if ($product === null) {
            return null;
        }
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $product->id,
            'plan_id' => PlaceUpgrade::targetPlan($service, $product)?->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $product);
        $choices = PlaceUpgrade::defaultChoices($service, $product);
        $upgrade->setRelation('configs', collect($choices)->map(fn ($value, $option) => new ServiceConfig([
            'config_option_id' => $option, 'config_value_id' => $value,
        ]))->values());

        return [$upgrade, $choices, $product];
    }

    /** The chosen product is an upgrade that charges something now: the discount is offered. */
    private static function upgradeDue(Service $service, mixed $productId): bool
    {
        $draft = self::draft($service, $productId);
        if ($draft === null || $draft[0]->needsStaff()) {
            return false;
        }

        return (float) $draft[0]->calculatePrice()->price > 0;
    }

    /**
     * The discount as the form gives it: [mode, value], or [null, ''] for none. A comma is a decimal point (Greek
     * keyboards); anything else not a number DifferenceDiscount refuses.
     *
     * @return array{?string, string}
     */
    private static function discountOf(mixed $mode, mixed $value): array
    {
        if (!in_array($mode, [DifferenceDiscount::PERCENT, DifferenceDiscount::AMOUNT, DifferenceDiscount::WAIVE], true)) {
            return [null, ''];
        }

        return [$mode, str_replace(',', '.', trim((string) $value))];
    }

    private static function refusalText(?string $reason): string
    {
        return match ($reason) {
            DifferenceDiscount::REFUSED_TOO_LARGE => 'The discount is more than the difference: at most 100%, or an amount to charge from 0 up to the difference.',
            DifferenceDiscount::REFUSED_NOTHING_DUE => 'Nothing is charged for this change: there is no difference to discount.',
            default => 'The discount must be a number of at most two decimals, 0 or more.',
        };
    }

    /** As WHMCS's «Days Left on Current Cycle: 16 / 31, Upgrade Total Due: €40.21 EUR», with staff's discount. */
    private static function summary(Service $service, mixed $productId, mixed $discountMode = null, mixed $discountValue = null): HtmlString|string
    {
        $draft = self::draft($service, $productId);
        if ($draft === null) {
            return 'Choose a product.';
        }
        [$upgrade, $choices, $product] = $draft;
        // No price to show: it would be the new cycle's over the old period's days (S7-PLAN-CYCLE-ADDONS-2).
        if ($service->planStartsOn() !== null) {
            return self::cycleNotStarted($service);
        }
        if ($upgrade->needsStaff()) {
            return 'The catalog has no price for it in the service\'s currency: change it by hand.';
        }
        $quote = UpgradeQuote::for($upgrade);
        $currency = $service->currency_code;
        $lines = [];
        if ($quote->daysLeft !== null) {
            $lines[] = "Days left on current cycle: {$quote->daysLeft} / {$quote->daysInPeriod}";
        }
        // The options it sets, as the customer's page would (the value the service has, else the first).
        $values = ConfigOption::query()->whereIn('id', array_values($choices))->pluck('name', 'id');
        foreach ($product->upgradableConfigOptions->whereIn('id', array_keys($choices)) as $option) {
            $lines[] = "{$option->name}: " . ($values[$choices[$option->id]] ?? '—');
        }
        if ($quote->downgrade) {
            $lines[] = "Downgrade total due: 0.00 {$currency}";
            $lines[] = (float) $quote->credit > 0
                ? "Credit to the account: {$quote->credit} {$currency} (Credit on downgrade is on)"
                : 'No credit; the new price applies from the next renewal'
                    . ($service->expires_at ? ' on ' . $service->expires_at->format('d/m/Y') : '') . '.';
        } elseif ((float) $quote->dueNow > 0) {
            array_push($lines, ...self::discountLines($upgrade, $quote, $currency, $discountMode, $discountValue));
        } else {
            // Nothing to charge (its period ended, or the same price): applied at once, as PlaceUpgrade does (CR-3 of #638).
            $lines[] = "Total due: 0.00 {$currency}; applied at once, the new price from the next renewal";
        }
        $lines[] = "Renews at: {$quote->renewsAfter} {$currency} per period with tax (now {$quote->renewsNow})";

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }

    /**
     * What an upgrade charges now, with staff's discount when one is chosen: the full difference, the discount, and what
     * is due (as PlaceUpgrade prices it: UpgradeQuote::discount).
     *
     * @return list<string>
     */
    private static function discountLines(ServiceUpgrade $upgrade, UpgradeQuote $quote, string $currency, mixed $mode, mixed $value): array
    {
        [$mode, $value] = self::discountOf($mode, $value);
        $full = "Upgrade total due: {$quote->dueNow} {$currency} (with tax; an invoice, applied once paid)";
        if ($mode === null || ($mode !== DifferenceDiscount::WAIVE && $value === '')) {
            return [$full];
        }
        try {
            $discount = UpgradeQuote::discount($upgrade, $mode, $value);
        } catch (DifferenceDiscountRefused $e) {
            return [$full, 'Discount: ' . self::refusalText($e->reason)];
        }
        if ($discount->none()) {
            return [$full, 'Discount: none'];
        }
        $lines = ["Upgrade difference: {$discount->fullTotal} {$currency} (with tax)",
            "Discount: −{$discount->discountTotal} {$currency}" . ($discount->percent !== null ? " ({$discount->percent}%)" : '')];
        if ($discount->waived()) {
            $lines[] = "Due now: 0.00 {$currency}; no invoice, applied at once, the new price from the next renewal";

            return $lines;
        }
        $lines[] = "Due now: {$discount->dueTotal} {$currency} (with tax; an invoice, applied once paid)";
        if ($mode === DifferenceDiscount::AMOUNT && !BigDecimal::of($value)->isEqualTo($discount->dueTotal)) {
            $lines[] = "{$value} {$currency} cannot be reached exactly (each invoice line rounds its own tax): the cent under it.";
        }

        return $lines;
    }
}
