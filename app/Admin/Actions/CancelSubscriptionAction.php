<?php

namespace App\Admin\Actions;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Throwable;

/**
 * Staff "Cancel Subscription" on a service's subscription id (the service page and the order page's services).
 * The id is cleared only when a gateway confirmed the cancellation: otherwise the gateway may still be billing it,
 * and the id is what a retry needs.
 */
class CancelSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('Cancel Subscription ID')
            ->action(function (Component $component) {
                $record = $component->getRecord();
                if (!$record instanceof Service) {
                    return;
                }

                $error = self::cancel($record);
                $error === null
                    ? Notification::make('Subscription Cancelled')->title('The subscription has been successfully cancelled')->success()->send()
                    : Notification::make('Subscription Not Cancelled')->title('The subscription could not be cancelled')->body($error ?: null)->danger()->send();
            })
            ->requiresConfirmation()
            ->label('Cancel Subscription')
            ->hidden(fn (Component $component) => !(($record = $component->getRecord()) instanceof Service && $record->subscription_id));
    }

    /**
     * @return string|null null when cancelled (the id is cleared); otherwise why not ('' when no gateway took it)
     */
    public static function cancel(Service $service): ?string
    {
        try {
            if (!ExtensionHelper::cancelSubscription($service)) {
                return '';
            }
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }

        $service->update(['subscription_id' => null]);

        return null;
    }
}
