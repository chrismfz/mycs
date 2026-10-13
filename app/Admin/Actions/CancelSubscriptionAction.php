<?php

namespace App\Admin\Actions;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Throwable;

/**
 * Staff "Cancel Subscription" on a service's subscription id: a hint action of the subscription_id field on the
 * service page and in the order page's services repeater (getRecord() is the item's Service for a saved item; null,
 * so hidden, for one not saved yet).
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
                if ($error === null) {
                    // The form still holds the id: a later Save (the order page's repeater) would write it back.
                    $component->state(null);
                }
                $error === null
                    ? Notification::make('Subscription Cancelled')->title('The subscription has been successfully cancelled')->success()->send()
                    : Notification::make('Subscription Not Cancelled')->title('The subscription could not be cancelled')->body($error ?: 'No gateway recognised this subscription id.')->danger()->send();
            })
            // Its own permission: the order page's form must not let staff without services.update cancel one.
            ->authorize(fn () => (bool) auth()->user()?->hasPermission('admin.services.update'))
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
