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
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * WHMCS's admin «Upgrade/Downgrade» (owner 2026-11-04): another product for the service, its cycle, and before anything is
 * made the days left of the cycle and what is due (UpgradeQuote). An upgrade makes the invoice the customer's page would
 * (applied once paid); a downgrade is applied now at 0.00, its new price from the next renewal (credit only where the
 * settings give it). Both are kept on the service's «Package changes» and mailed to the customer once applied.
 */
final class ChangePackage
{
    public static function action(): Action
    {
        return Action::make('changePackage')
            ->label('Upgrade/Downgrade')
            ->icon('ri-arrow-up-down-line')
            ->authorize('update')
            // Not the catalog on every load of the page: the products are listed once the modal opens.
            ->visible(fn (Service $record) => self::refusal($record) === null)
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
                Placeholder::make('summary')
                    ->label('Upgrade summary')
                    ->content(fn (Get $get, Service $record) => self::summary($record, $get('product_id'))),
            ])
            ->modalSubmitActionLabel('Create order')
            ->action(function (array $data, Service $record, Action $action, $livewire): void {
                $product = self::targets($record)->firstWhere('id', (int) $data['product_id']);
                if ($product === null) {
                    Notification::make()->danger()->title('Not changed')->body('This product is not available for this service.')->send();
                    $action->halt();

                    return;
                }
                $placed = PlaceUpgrade::handle($record, $product, PlaceUpgrade::defaultChoices($record, $product), ServiceUpgrade::SOURCE_STAFF,
                    Auth::guard('staff')->id());
                $why = match ($placed->outcome) {
                    PlaceUpgrade::NOT_UPGRADABLE => 'Another change of this service is waiting for payment.',
                    PlaceUpgrade::INVALID => 'The new product has no plan for this billing cycle in this currency.',
                    PlaceUpgrade::UNCHANGED => 'It already has this product.',
                    PlaceUpgrade::NEEDS_STAFF => 'The catalog has no price for it in the service\'s currency: change it by hand.',
                    PlaceUpgrade::NOT_NOW => 'The service no longer runs.',
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
                    ->body(($placed->downgrade ? 'Nothing charged now; the new price applies from the next renewal.' : 'Nothing to pay now.') . $credit)
                    ->send();
            });
    }

    /** Why staff cannot change its package now, or null. */
    public static function refusal(Service $service): ?string
    {
        if ($service->plan === null || $service->product === null) {
            return 'It has no product or plan.';
        }
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            return 'It does not run.';
        }
        if ($service->upgrade()->where('status', ServiceUpgrade::STATUS_PENDING)->exists()) {
            return 'A change is waiting for payment.';
        }
        // A cancellation asked for: an upgrade of a service about to end would only be refunded (CR-10, as the customer's
        // page; CR-2 of #638). Staff take the cancellation back first.
        if ($service->cancellation !== null) {
            return 'A cancellation is requested.';
        }
        // Set up before its order was paid: an upgrade would reprice the order's line (adversarial A5).
        if ($service->orderUnpaid()) {
            return 'Its order is unpaid.';
        }
        // A recurring one with no due date is never billed: an upgrade would keep the dearer product for good (ADV-594-2).
        if ($service->plan->type === 'recurring' && $service->expires_at === null) {
            return 'It has no due date.';
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

    /** As WHMCS's «Days Left on Current Cycle: 16 / 31, Upgrade Total Due: €40.21 EUR». */
    private static function summary(Service $service, mixed $productId): HtmlString|string
    {
        $product = $productId ? self::targets($service)->firstWhere('id', (int) $productId) : null;
        if ($product === null) {
            return 'Choose a product.';
        }
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $product->id,
            'plan_id' => PlaceUpgrade::targetPlan($service, $product)?->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $product);
        $choices = PlaceUpgrade::defaultChoices($service, $product);
        $upgrade->setRelation('configs', collect($choices)->map(fn ($value, $option) => new ServiceConfig([
            'config_option_id' => $option, 'config_value_id' => $value,
        ]))->values());
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
            $lines[] = "Upgrade total due: {$quote->dueNow} {$currency} (with tax; an invoice, applied once paid)";
        } else {
            // Nothing to charge (its period ended, or the same price): applied at once, as PlaceUpgrade does (CR-3 of #638).
            $lines[] = "Total due: 0.00 {$currency}; applied at once, the new price from the next renewal";
        }
        $lines[] = "Renews at: {$quote->renewsAfter} {$currency} per period with tax (now {$quote->renewsNow})";

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }
}
