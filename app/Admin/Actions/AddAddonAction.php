<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\AddAddon;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * «Add addon» on a service's page (docs/addons.md, step 3, as WHMCS's «New Addon»): an addon product that applies to it,
 * its quantity and price, and how it is billed (AddAddon), with what it comes to before it is made (as WHMCS's upgrade
 * summary: the days left on the cycle and what is due now). No cycle to choose: an addon is on its service's (2026-11-09).
 */
class AddAddonAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addAddon';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('services.addons.add.action'))
            ->icon('ri-puzzle-line')
            ->color('gray')
            // Not on an addon or an ended service. On one not started yet it is shown, disabled, with why and staff's way
            // through (owner 2026-11-10: a reason, not a button that is gone).
            ->visible(fn (Service $record) => AddAddon::refusal($record) === null || $record->status === Service::STATUS_PENDING)
            ->disabled(fn (Service $record) => AddAddon::refusal($record) !== null)
            ->tooltip(fn (Service $record) => AddAddon::refusal($record))
            // To be cancelled: the addon ends with it, said before it is added.
            ->modalDescription(fn (Service $record) => AddAddon::cancellationNote($record))
            ->authorize(fn () => Gate::allows('has-permission', 'admin.services.create'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('services.addons.add.action'))
            ->schema(fn (Service $record) => [
                Select::make('product_id')->label(__('services.addons.add.product'))->required()->live()
                    ->options(self::productOptions($record))
                    ->placeholder(__('services.addons.add.none')),
                TextInput::make('quantity')->label(__('services.addons.add.quantity_label'))->integer()->minValue(1)->maxValue(1000)->default(1)->required()->live(onBlur: true)
                    ->visible(fn (Get $get) => (Product::query()->whereKey($get('product_id'))->value('allow_quantity') ?? 'disabled') !== 'disabled'),
                TextInput::make('price')->label(__('services.addons.add.price'))->numeric()->minValue(0)->maxValue(999999)->step(0.01)->live(onBlur: true)
                    ->prefix($record->currency?->prefix)->suffix($record->currency?->suffix)
                    ->placeholder(fn (Get $get) => self::catalog($record, $get('product_id')))
                    ->helperText(fn (Get $get) => ($price = self::catalog($record, $get('product_id'))) !== null
                        ? __('services.addons.add.price_help', ['price' => $price]) : null),
                Toggle::make('keep_price')->label(__('services.addons.add.keep_price'))->default(false),
                Radio::make('start')->label(__('services.addons.add.start'))->required()->live()
                    ->default(AddAddon::PRORATED)
                    ->options(fn (Get $get) => self::startOptions($record, $get('product_id'))),
                TextEntry::make('summary')->label(__('services.addons.add.summary'))
                    ->state(fn (Get $get) => self::summary($record, $get('product_id'), $get('quantity'), $get('price'), $get('start'))),
            ])
            ->action(function (array $data, Service $record, Action $action) {
                $invoiced = ($data['start'] ?? null) !== AddAddon::NO_INVOICE && self::plan($record, $data['product_id'] ?? null)?->type === 'recurring';
                if ($invoiced && !Gate::allows('has-permission', 'admin.invoices.create')) {
                    Notification::make()->danger()->title(__('services.addons.add.no_rights'))->send();
                    $action->halt();
                }
                try {
                    $made = (new AddAddon)->handle($record, Product::query()->findOrFail($data['product_id']),
                        (int) ($data['quantity'] ?? 1), isset($data['price']) ? (string) $data['price'] : null, (bool) ($data['keep_price'] ?? false),
                        (string) $data['start']);
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('services.addons.add.action'))->body(e($e->getMessage()))->send();
                    $action->halt();

                    return;
                }
                $invoice = $made['invoice'];
                Notification::make()->success()->title(__('services.addons.add.added'))
                    ->body($invoice instanceof Invoice
                        ? new HtmlString('<a class="underline" href="' . e(InvoiceResource::getUrl('edit', ['record' => $invoice])) . '">'
                            . e(__('services.addons.add.added_invoice', ['invoice' => $invoice->number ?: '#' . $invoice->id])) . '</a>')
                        : __('services.addons.add.added_started'))
                    ->send();
                $action->redirect(ServiceResource::getUrl('edit', ['record' => $record]));
            });
    }

    /** @return array<int, string> the addons with their price for the service's cycle, in its currency */
    private static function productOptions(Service $parent): array
    {
        return AddAddon::products($parent)->mapWithKeys(fn (Product $product) => [$product->id => $product->name . ' · '
            . (($plan = AddAddon::plan($product, $parent)) !== null ? AddAddon::catalogPrice($parent, $product, $plan) . ' ' . $parent->currency_code . ' / ' . $plan->name
                : __('services.addons.add.no_cycle_plan_short'))])->all();
    }

    /** The plan the addon would be on: its product's of the service's cycle (AddAddon::plan). */
    private static function plan(Service $parent, mixed $productId): ?Plan
    {
        $product = blank($productId) ? null : Product::query()->with('plans.prices')->find($productId);

        return $product === null ? null : AddAddon::plan($product, $parent);
    }

    /** @return array<string, string> */
    private static function startOptions(Service $parent, mixed $productId): array
    {
        $plan = self::plan($parent, $productId);
        $end = $plan === null ? null : AddAddon::alignedEnd($parent, $plan);
        $options = [AddAddon::PRORATED => $end !== null ? __('services.addons.add.start_prorated', ['date' => $end->format('d/m/Y')])
            : __('services.addons.add.start_prorated_none'),
            AddAddon::NO_INVOICE => __('services.addons.add.start_no_invoice')];
        if ($plan !== null && $plan->type !== 'recurring') {
            return [AddAddon::NO_INVOICE => $options[AddAddon::NO_INVOICE]]; // free: nothing to invoice
        }

        return $options;
    }

    private static function catalog(Service $parent, mixed $productId): ?string
    {
        $product = blank($productId) ? null : Product::query()->with('plans.prices')->find($productId);
        $plan = $product === null ? null : AddAddon::plan($product, $parent);

        return $plan === null ? null : AddAddon::catalogPrice($parent, $product, $plan);
    }

    /** What it comes to, as WHMCS's summary: the days left on the cycle and what is due now (for the quantity). */
    private static function summary(Service $parent, mixed $productId, mixed $quantity, mixed $price, mixed $start): string
    {
        $plan = self::plan($parent, $productId);
        if ($plan === null && filled($productId) && ($product = Product::query()->find($productId)) !== null) {
            return AddAddon::noPlan($product, $parent->plan);
        }
        $unit = filled($price) ? (string) $price : self::catalog($parent, $productId);
        if ($unit === null || $plan === null || blank($start)) {
            return '—';
        }
        $quantity = max(1, (int) ($quantity ?: 1));
        try {
            $quote = AddAddon::quote($parent, $plan, $unit, (string) $start);
        } catch (Throwable $e) {
            return $e instanceof DisplayException ? $e->getMessage() : '—';
        }
        if ($quote === null) {
            // No invoice: due on its parent's day, where a prorated one would end (REV-KISS-5).
            return __('services.addons.add.summary_none', ['date' => AddAddon::alignedEnd($parent, $plan)?->format('d/m/Y') ?? '—']);
        }
        [$from, $until, $amount] = $quote;
        $due = (string) BigDecimal::of($amount)->multipliedBy($quantity)->toScale(2) . ' ' . $parent->currency_code;
        $full = AddAddon::fullStart($parent, $plan, $until);

        return __('services.addons.add.summary_line', ['days' => (int) $from->diffInDays($until), 'of' => (int) $full->diffInDays($until), 'amount' => $due]);
    }
}
