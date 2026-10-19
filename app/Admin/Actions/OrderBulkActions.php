<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
use App\Models\Order;
use App\Models\Staff;
use App\Services\Order\AcceptOrder;
use App\Services\Order\CancelOrder;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * WHMCS's «With Selected: Accept Order / Cancel Order» on Pending Orders: staff look at what came in and accept it, or
 * cancel what does not look right, many at once. Each order on its own, as from its page; one refused is reported.
 */
final class OrderBulkActions
{
    public static function accept(): BulkAction
    {
        return BulkAction::make('acceptOrders')
            ->label('Accept Order')
            ->icon('ri-check-double-line')
            ->color('success')
            ->authorize(fn (): bool => (bool) auth('staff')->user()?->hasPermission('admin.orders.update'))
            ->requiresConfirmation()
            ->modalDescription('Each pending order turns active. What waits for staff is set up (a product set up on Accept, a paid service not set up yet), with its welcome email; a service still waiting for its payment keeps waiting. To start one unpaid, accept it from its page.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                self::each($records, 'accepted', fn (Order $order) => AcceptOrder::handle($order, self::staff()));
            });
    }

    public static function cancel(): BulkAction
    {
        return BulkAction::make('cancelOrders')
            ->label('Cancel Order')
            ->icon('ri-close-circle-line')
            ->color('danger')
            ->authorize(fn (): bool => (bool) auth('staff')->user()?->hasPermission('admin.orders.update'))
            ->requiresConfirmation()
            ->modalDescription('Their services are cancelled and their open invoices with them; accounts already created on the servers are not terminated and paid invoices stay. To refund or terminate, cancel from the order\'s page.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                self::each($records, 'cancelled', function (Order $order) {
                    // As the page offers it: not on one cancelled or set as fraud already.
                    if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_FRAUD], true)) {
                        throw new DisplayException('It is ' . $order->status . ' already.');
                    }
                    foreach (CancelOrder::handle($order, false, null, false) as $notice) {
                        Notification::make()->warning()->title("Order #{$order->id}: left for staff")->body($notice->getMessage())->persistent()->send();
                    }
                });
            });
    }

    /** @param  Collection<int, Order>  $records */
    private static function each(Collection $records, string $done, callable $handle): void
    {
        $ok = 0;
        foreach ($records as $order) {
            if (!auth()->user()?->can('update', $order)) {
                Notification::make()->danger()->title("Order #{$order->id} not {$done}")->body('You may not change this order.')->send();

                continue;
            }
            try {
                $handle($order);
                $ok++;
            } catch (Throwable $e) {
                if (!$e instanceof DisplayException) {
                    report($e);
                }
                Notification::make()->danger()->title("Order #{$order->id} not {$done}")->body($e->getMessage())->send();
            }
        }
        if ($ok > 0) {
            Notification::make()->success()->title("{$ok} order(s) {$done}")->send();
        }
    }

    private static function staff(): ?Staff
    {
        $user = auth('staff')->user();

        return $user instanceof Staff ? $user : null;
    }
}
