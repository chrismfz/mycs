<?php

namespace App\Admin\Actions;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * «Invoice selected items» (D7a, as WHMCS's): on the client page (services and domains together), on its Services and
 * Domains lists (bulk), and «Invoice next period» on one service or domain. InvoiceSelectedItems does the billing.
 */
class InvoiceSelectedItemsAction
{
    /** The client page: tick services and domains, one invoice per currency. */
    public static function forClient(): Action
    {
        return Action::make('invoiceSelected')
            ->label(__('invoices.selected.action'))
            ->icon('heroicon-o-document-duplicate')
            ->authorize(fn (): bool => self::allowed())
            ->modalDescription(__('invoices.selected.help'))
            ->schema(fn (Client $record): array => [
                CheckboxList::make('services')->label(__('invoices.selected.services'))
                    ->options($record->services()->whereIn('status', ['active', 'suspended'])->with('product')->orderBy('expires_at')->get()
                        ->mapWithKeys(fn (Service $s) => [$s->id => self::serviceLabel($s)])->all()),
                CheckboxList::make('domains')->label(__('invoices.selected.domains'))
                    ->options($record->domains()->whereIn('status', array_map(fn ($s) => $s->value, RenewalBilling::BILLED_DOMAINS))->orderBy('expires_at')->get()
                        ->mapWithKeys(fn (Domain $d) => [$d->id => self::domainLabel($d)])->all()),
            ])
            ->action(function (Client $record, array $data): void {
                // Only this client's: ids from the form are checked against it.
                self::run(
                    $record->services()->whereKey(array_map('intval', $data['services'] ?? []))->get(),
                    $record->domains()->whereKey(array_map('intval', $data['domains'] ?? []))->get(),
                );
            });
    }

    /** The client's Services or Domains list: the ticked rows. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('invoiceSelected')
            ->label(__('invoices.selected.action'))
            ->icon('heroicon-o-document-duplicate')
            ->authorize(fn (): bool => self::allowed())
            ->requiresConfirmation()
            ->modalDescription(__('invoices.selected.help'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::run(
                $records->filter(fn ($r) => $r instanceof Service),
                $records->filter(fn ($r) => $r instanceof Domain),
            ));
    }

    /** One service's or domain's page. */
    public static function nextPeriod(): Action
    {
        return Action::make('invoiceNextPeriod')
            ->label(__('invoices.selected.next_period'))
            ->icon('heroicon-o-document-plus')
            ->authorize(fn (): bool => self::allowed())
            ->requiresConfirmation()
            ->modalDescription(__('invoices.selected.help'))
            ->action(fn (Service|Domain $record) => self::run(
                $record instanceof Service ? [$record] : [],
                $record instanceof Domain ? [$record] : [],
            ));
    }

    private static function allowed(): bool
    {
        return (bool) auth()->user()?->can('create', Invoice::class);
    }

    /**
     * @param  iterable<Service>  $services
     * @param  iterable<Domain>  $domains
     */
    private static function run(iterable $services, iterable $domains): void
    {
        if (collect($services)->isEmpty() && collect($domains)->isEmpty()) {
            Notification::make()->warning()->title(__('invoices.selected.none'))->send();

            return;
        }
        $result = (new InvoiceSelectedItems)->handle($services, $domains);
        $skipped = collect($result['skipped'])->map(fn (string $why, string $item) => e($item) . ': ' . e($why))->implode('<br>');
        $notification = $result['invoices'] === []
            ? Notification::make()->warning()->title(__('invoices.selected.nothing_billed'))
            : Notification::make()->success()->title(trans_choice('invoices.selected.done', count($result['invoices']), ['invoices' => implode(', #', $result['invoices'])]));
        if ($skipped !== '') {
            $notification->body('<strong>' . e(__('invoices.selected.skipped')) . '</strong><br>' . $skipped)->persistent();
        }
        $notification->send();
    }

    private static function serviceLabel(Service $service): string
    {
        return '#' . $service->id . ' ' . $service->product?->name . ' · ' . $service->formattedPrice
            . ($service->expires_at !== null ? ' · ' . $service->expires_at->toDateString() : '');
    }

    private static function domainLabel(Domain $domain): string
    {
        return $domain->fqdn . ($domain->expires_at !== null ? ' · ' . $domain->expires_at->toDateString() : '')
            . ($domain->auto_renew ? '' : ' · ' . __('invoices.selected.auto_renew_off'));
    }
}
