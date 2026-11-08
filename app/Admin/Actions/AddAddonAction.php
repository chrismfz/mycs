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
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * its cycle, quantity and price, and how it is billed (AddAddon), with what it comes to before it is made (as WHMCS's
 * upgrade summary: the days left on the cycle and what is due now).
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
            ->visible(fn (Service $record) => AddAddon::refusal($record) === null)
            ->authorize(fn () => Gate::allows('has-permission', 'admin.services.create'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('services.addons.add.action'))
            ->schema(fn (Service $record) => [
                Select::make('product_id')->label(__('services.addons.add.product'))->required()->live()
                    ->options(AddAddon::products($record)->pluck('name', 'id')->all())
                    ->placeholder(__('services.addons.add.none'))
                    ->afterStateUpdated(fn ($set) => $set('plan_id', null)),
                Select::make('plan_id')->label(__('services.addons.add.plan'))->required()->live()
                    ->options(fn (Get $get) => self::planOptions($record, $get('product_id')))
                    ->disabled(fn (Get $get) => blank($get('product_id'))),
                TextInput::make('quantity')->label(__('services.addons.add.quantity_label'))->integer()->minValue(1)->maxValue(1000)->default(1)->required()->live(onBlur: true)
                    ->visible(fn (Get $get) => (Product::query()->whereKey($get('product_id'))->value('allow_quantity') ?? 'disabled') !== 'disabled'),
                TextInput::make('price')->label(__('services.addons.add.price'))->numeric()->minValue(0)->maxValue(999999)->step(0.01)->live(onBlur: true)
                    ->prefix($record->currency?->prefix)->suffix($record->currency?->suffix)
                    ->placeholder(fn (Get $get) => self::catalog($record, $get('product_id'), $get('plan_id')))
                    ->helperText(fn (Get $get) => ($price = self::catalog($record, $get('product_id'), $get('plan_id'))) !== null
                        ? __('services.addons.add.price_help', ['price' => $price]) : null),
                Toggle::make('keep_price')->label(__('services.addons.add.keep_price'))->default(false),
                Radio::make('start')->label(__('services.addons.add.start'))->required()->live()
                    ->default(AddAddon::PRORATED)
                    ->options(fn (Get $get) => self::startOptions($record, $get('plan_id'))),
                // No invoice: its parent's due date by default, as an addon shares it (owner 2026-11-08).
                DatePicker::make('date')->live()->label(__('services.addons.add.due_date'))
                    ->visible(fn (Get $get) => $get('start') === AddAddon::NO_INVOICE)
                    ->default(fn () => $record->expires_at !== null && CarbonImmutable::parse($record->expires_at)->isFuture() ? CarbonImmutable::parse($record->expires_at)->toDateString() : now()->toDateString())->minDate(now()->startOfDay())->required(),
                TextEntry::make('summary')->label(__('services.addons.add.summary'))
                    ->state(fn (Get $get) => self::summary($record, $get('product_id'), $get('plan_id'), $get('quantity'), $get('price'), $get('start'), $get('date'))),
            ])
            ->action(function (array $data, Service $record, Action $action) {
                $invoiced = ($data['start'] ?? null) !== AddAddon::NO_INVOICE && Plan::query()->whereKey($data['plan_id'] ?? 0)->value('type') === 'recurring';
                if ($invoiced && !Gate::allows('has-permission', 'admin.invoices.create')) {
                    Notification::make()->danger()->title(__('services.addons.add.no_rights'))->send();
                    $action->halt();
                }
                try {
                    $made = (new AddAddon)->handle($record, Product::query()->findOrFail($data['product_id']), Plan::query()->findOrFail($data['plan_id']),
                        (int) ($data['quantity'] ?? 1), isset($data['price']) ? (string) $data['price'] : null, (bool) ($data['keep_price'] ?? false),
                        (string) $data['start'], filled($data['date'] ?? null) ? CarbonImmutable::parse($data['date']) : null);
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

    /** @return array<int, string> its cycles with their price in the service's currency */
    private static function planOptions(Service $parent, mixed $productId): array
    {
        $product = blank($productId) ? null : Product::query()->with('plans.prices')->find($productId);
        if ($product === null) {
            return [];
        }

        return AddAddon::plans($product, $parent)->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->name . ' · '
            . AddAddon::catalogPrice($parent, $product, $plan) . ' ' . $parent->currency_code])->all();
    }

    /** @return array<string, string> */
    private static function startOptions(Service $parent, mixed $planId): array
    {
        $plan = blank($planId) ? null : Plan::query()->find($planId);
        $end = $plan === null ? null : AddAddon::alignedEnd($parent, $plan);
        $options = [AddAddon::PRORATED => $end !== null ? __('services.addons.add.start_prorated', ['date' => $end->format('d/m/Y')])
            : __('services.addons.add.start_prorated_none'),
            AddAddon::NO_INVOICE => __('services.addons.add.start_no_invoice')];
        if ($plan !== null && $plan->type !== 'recurring') {
            return [AddAddon::NO_INVOICE => $options[AddAddon::NO_INVOICE]]; // free: nothing to invoice
        }

        return $options;
    }

    private static function catalog(Service $parent, mixed $productId, mixed $planId): ?string
    {
        $product = blank($productId) ? null : Product::query()->with('plans.prices')->find($productId);
        $plan = $product === null || blank($planId) ? null : AddAddon::plans($product, $parent)->firstWhere('id', (int) $planId);

        return $plan === null ? null : AddAddon::catalogPrice($parent, $product, $plan);
    }

    /** What it comes to, as WHMCS's summary: the days left on the cycle and what is due now (for the quantity). */
    private static function summary(Service $parent, mixed $productId, mixed $planId, mixed $quantity, mixed $price, mixed $start, mixed $date): string
    {
        $unit = filled($price) ? (string) $price : self::catalog($parent, $productId, $planId);
        $plan = blank($planId) ? null : Plan::query()->find($planId);
        if ($unit === null || $plan === null || blank($start)) {
            return '—';
        }
        $quantity = max(1, (int) ($quantity ?: 1));
        try {
            $day = filled($date) ? CarbonImmutable::parse($date) : null;
            $quote = AddAddon::quote($parent, $plan, $unit, (string) $start);
        } catch (Throwable $e) {
            return $e instanceof DisplayException ? $e->getMessage() : '—';
        }
        if ($quote === null) {
            return __('services.addons.add.summary_none', ['date' => ($day ?? $parent->expires_at)?->format('d/m/Y') ?? '—']);
        }
        [$from, $until, $amount] = $quote;
        $due = (string) BigDecimal::of($amount)->multipliedBy($quantity)->toScale(2) . ' ' . $parent->currency_code;
        $full = AddAddon::fullStart($parent, $plan, $until);

        return __('services.addons.add.summary_line', ['days' => (int) $from->diffInDays($until), 'of' => (int) $full->diffInDays($until), 'amount' => $due]);
    }
}
