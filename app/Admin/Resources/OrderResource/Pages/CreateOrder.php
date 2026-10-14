<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff's «Create Order» goes through the PlaceOrderService (Σ7, CART-4): the catalog price with the setup fee, the
 * options and the client's tax, stock and limits, one invoice only when something is due; a free service starts at
 * once. Staff may give a line its own recurring price.
 */
class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->findOrFail($data['client_id']);
        $lines = [];
        foreach ($data['lines'] ?? [] as $line) {
            $product = Product::query()->with('configOptions')->findOrFail($line['product_id']);
            $plan = Plan::query()->with('prices')->whereKey($line['plan_id'])
                ->where('priceable_type', Product::class)->where('priceable_id', $product->id)->firstOrFail();
            $price = $line['price'] ?? null;
            $lines[] = new OrderLine($product, $plan, (int) $line['quantity'],
                price: $price === null || $price === '' ? null : number_format((float) $price, 2, '.', ''));
        }

        try {
            return (new PlaceOrderService)->place($client, $data['currency_code'], $lines,
                invoiceStatus: config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_UNPAID,
                notes: $data['notes'] ?? null,
            )->order;
        } catch (DisplayException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
