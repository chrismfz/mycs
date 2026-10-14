<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** WHMCS's «Pending Orders» and the other order lists (Σ1-b2): ?activeTab=pending. */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];
        foreach (Order::STATUSES as $status) {
            $tabs[$status] = Tab::make(ucfirst($status))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status))
                ->badge($status === Order::STATUS_PENDING ? (Order::query()->where('status', $status)->count() ?: null) : null);
        }

        return $tabs;
    }
}
