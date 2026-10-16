<?php

namespace App\Admin\Resources\CurrencyResource\Pages;

use App\Admin\Resources\CurrencyResource;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\Price;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        if (config('settings.default_currency') == $this->record->code) {
            return [];
        }

        return [
            DeleteAction::make()->before(function (DeleteAction $action, Currency $record) {
                // Prevent deletion if its being used by services/orders/credits
                $serviceCount = $record->services()->count();
                $orderCount = $record->orders()->count();
                $creditCount = $record->credits()->where('amount', '>', 0)->count();
                // Invoices too (DATA-16): their money, credit notes and transactions are in this currency.
                $invoiceCount = Invoice::query()->where('currency_code', $record->code)->count();
                // Domains are billed in it (docs/domains.md D-4); TLD prices in it go with it, as product prices.
                $domainCount = Domain::query()->where('currency_code', $record->code)->count();
                if ($serviceCount > 0 || $orderCount > 0 || $creditCount > 0 || $invoiceCount > 0 || $domainCount > 0) {
                    $message = 'Cannot delete currency because it is being used by ';
                    $parts = [];
                    if ($serviceCount > 0) {
                        $parts[] = "{$serviceCount} service(s)";
                    }
                    if ($orderCount > 0) {
                        $parts[] = "{$orderCount} order(s)";
                    }
                    if ($creditCount > 0) {
                        $parts[] = "{$creditCount} credit(s)";
                    }
                    if ($invoiceCount > 0) {
                        $parts[] = "{$invoiceCount} invoice(s)";
                    }
                    if ($domainCount > 0) {
                        $parts[] = "{$domainCount} domain(s)";
                    }
                    $message .= implode(', ', $parts) . '.';
                    Notification::make()
                        ->title('Whoops!')
                        ->body($message)
                        ->danger()
                        ->send();
                    $action->cancel();
                }
            })
                ->after(function (DeleteAction $action, Currency $record) {
                    // Remove carts with this currency
                    Cart::where('currency_code', $record->code)->delete();
                    Price::where('currency_code', $record->code)->delete();
                    DomainTldPrice::query()->where('currency_code', $record->code)->delete();
                }),
        ];
    }
}
