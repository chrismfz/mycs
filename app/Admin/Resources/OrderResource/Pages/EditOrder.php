<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Exceptions\DisplayException;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Order\AcceptOrder;
use App\Services\Order\CancelOrder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

/** WHMCS's order page buttons (Σ1-b2): Accept, Cancel, Cancel & Refund, Set as Fraud, Set back to Pending, Delete. */
class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept')->label('Accept Order')->icon('ri-check-double-line')->color('success')
                ->visible(fn (Order $record) => $record->status === Order::STATUS_PENDING)
                ->authorize('update')
                ->modalDescription('The order turns active and its pending services are set up, paid or not.')
                ->schema([
                    Checkbox::make('run_create')->label('Run module create')->default(true)
                        ->helperText('Unticked: the services turn active as they are (built by hand).'),
                    Checkbox::make('send_email')->label('Send welcome email')->default(true),
                ])
                ->action(function (Order $record, array $data) {
                    try {
                        AcceptOrder::handle($record, self::staff(), (bool) $data['run_create'], (bool) $data['send_email']);
                    } catch (Throwable $e) {
                        if (!$e instanceof DisplayException) {
                            report($e);
                        }
                        Notification::make()->danger()->title('The order was not accepted')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Order accepted')->send();
                    $this->refreshFormData(['status']);
                }),
            ActionGroup::make([
                $this->cancelAction('cancel', 'Cancel Order', refund: false, fraud: false),
                $this->cancelAction('cancelRefund', 'Cancel & Refund', refund: true, fraud: false),
                $this->cancelAction('fraud', 'Set as Fraud', refund: false, fraud: true),
                // Not on an order with nothing alive (it would wait in Pending Orders for ever: ADV-6).
                Action::make('pending')->label('Set back to Pending')->icon('ri-arrow-go-back-line')
                    ->visible(fn (Order $record) => $record->status !== Order::STATUS_PENDING
                        && $record->services()->whereNotIn('status', Service::ENDED_STATUSES)->exists())
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Only the order\'s status: its services and invoices stay as they are.')
                    ->action(function (Order $record) {
                        $record->forceFill(['status' => Order::STATUS_PENDING])->save();
                        $this->refreshFormData(['status']);
                    }),
                DeleteAction::make()->before(function (DeleteAction $action, Order $record) {
                    if ($record->services()->exists()) {
                        Notification::make()->danger()->title('Whoops!')->body(__('This order has services: delete them first.'))->send();
                        $action->cancel();
                    }
                }),
            ])->label('More')->button(),
        ];
    }

    private function cancelAction(string $name, string $label, bool $refund, bool $fraud): Action
    {
        return Action::make($name)->label($label)->color('danger')
            ->icon($fraud ? 'ri-spy-line' : 'ri-close-circle-line')
            ->visible(fn (Order $record) => !in_array($record->status, [Order::STATUS_CANCELLED, Order::STATUS_FRAUD], true))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription('Its services are cancelled and their open invoices with them. ' . ($refund
                ? 'The payments of the order\'s own invoice go back (renewals and upgrades paid later stay: refund them from their invoice). What was refunded already, or taken back by a chargeback, is not given twice. A gateway that cannot refund is left to you, with a notice.'
                : 'Paid invoices stay: refund them from the invoice if needed.'))
            ->schema(array_filter([
                Checkbox::make('terminate')->label('Terminate accounts already created on the servers')->default(false),
                $refund ? Radio::make('method')->label('Refund')->required()->default('gateway')->options([
                    'gateway' => 'Through the payment gateway',
                    'manual' => 'By hand (record it only)',
                    'credit' => 'To the client\'s credit balance',
                ]) : null,
            ]))
            ->action(function (Order $record, array $data) use ($refund, $fraud) {
                if ($refund && !self::staff()?->hasPermission('admin.invoice_transactions.update')) {
                    Notification::make()->danger()->title('Refunds need the permission to change transactions')->send();

                    return;
                }
                try {
                    $left = CancelOrder::handle($record, (bool) ($data['terminate'] ?? false), $refund ? $data['method'] : null, $fraud);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('The order was not cancelled')->body($e->getMessage())->send();

                    return;
                }
                foreach ($left as $notice) {
                    Notification::make()->warning()->title('Left for staff')->body($notice->getMessage())->persistent()->send();
                }
                Notification::make()->success()->title($fraud ? 'Order set as fraud' : 'Order cancelled')->send();
                $this->refreshFormData(['status']);
            });
    }

    private static function staff(): ?Staff
    {
        $user = auth('staff')->user();

        return $user instanceof Staff ? $user : null;
    }
}
