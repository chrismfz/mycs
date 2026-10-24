<?php

namespace App\Admin\Actions;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\ServiceResource;
use App\Exceptions\DisplayException;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            // Only what staff may see (review F4): services and domains each with their own permission.
            ->schema(fn (Client $record): array => [
                CheckboxList::make('services')->label(__('invoices.selected.services'))->visible(self::may('admin.services.viewAny'))
                    ->options(self::billableServices($record)->with('product')->orderBy('expires_at')->get()
                        ->mapWithKeys(fn (Service $s) => [$s->id => self::serviceLabel($s)])->all()),
                CheckboxList::make('domains')->label(__('invoices.selected.domains'))->visible(self::may('admin.domains.viewAny'))
                    ->options(self::billableDomains($record)->orderBy('expires_at')->get()
                        ->mapWithKeys(fn (Domain $d) => [$d->id => self::domainLabel($d)])->all()),
            ])
            ->action(function (Client $record, array $data): void {
                // Only this client's: ids from the form are checked against it.
                self::run(
                    self::may('admin.services.viewAny') ? $record->services()->whereKey(array_map('intval', $data['services'] ?? []))->get() : [],
                    self::may('admin.domains.viewAny') ? $record->domains()->whereKey(array_map('intval', $data['domains'] ?? []))->get() : [],
                );
            });
    }

    /**
     * The Summary's «With selected» (the owner, 2026-10-24, as WHMCS's): the rows ticked in its Products/Services and
     * Domains tables, kept on the page as `selectedServices` and `selectedDomains`. Only this client's billable ones,
     * each kind only for staff who may see it, whatever ids the browser sends.
     */
    public static function forSummary(): Action
    {
        return Action::make('invoiceSelectedRows')
            ->label(__('invoices.selected.action'))
            ->icon('heroicon-o-document-duplicate')
            ->authorize(fn (): bool => self::allowed())
            ->requiresConfirmation()
            ->modalDescription(__('invoices.selected.help'))
            ->action(function (Client $record, $livewire): void {
                $ids = fn (mixed $ticked): array => array_values(array_filter(array_map('intval', (array) $ticked)));
                self::run(
                    self::may('admin.services.viewAny') ? self::billableServices($record)->whereKey($ids($livewire->selectedServices))->get() : [],
                    self::may('admin.domains.viewAny') ? self::billableDomains($record)->whereKey($ids($livewire->selectedDomains))->get() : [],
                );
                $livewire->selectedServices = [];
                $livewire->selectedDomains = [];
            });
    }

    /**
     * What may be invoiced ahead: its live services that renew, and its domains the renewals bill.
     *
     * @return HasMany<Service, Client>
     */
    public static function billableServices(Client $client): HasMany
    {
        return $client->services()->whereIn('status', ['active', 'suspended'])
            ->whereDoesntHave('plan', fn ($plan) => $plan->whereIn('type', ['free', 'one-time']));
    }

    /** @return HasMany<Domain, Client> */
    public static function billableDomains(Client $client): HasMany
    {
        return $client->domains()->whereIn('status', array_map(fn ($s) => $s->value, RenewalBilling::BILLED_DOMAINS));
    }

    /** May staff invoice ahead, and see this kind (services or domains)? */
    public static function mayInvoice(string $kind): bool
    {
        return self::allowed() && self::may('admin.' . $kind . '.viewAny');
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
            ->action(function (Service|Domain $record, $livewire): void {
                self::run($record instanceof Service ? [$record] : [], $record instanceof Domain ? [$record] : []);
                // Billing may change the service (its next plan, a coupon's end, its billing day): the page shows it now,
                // and a later Save does not put the old values back (review F1).
                $livewire->redirect($record instanceof Service ? ServiceResource::getUrl('edit', ['record' => $record])
                    : DomainResource::getUrl('view', ['record' => $record]));
            });
    }

    private static function allowed(): bool
    {
        return (bool) auth()->user()?->can('create', Invoice::class);
    }

    private static function may(string $permission): bool
    {
        return (bool) auth()->user()?->hasPermission($permission);
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
        try {
            $result = (new InvoiceSelectedItems)->handle($services, $domains);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('invoices.selected.nothing_billed'))->body(e($e->getMessage()))->send();

            return;
        }
        $notification = $result['invoices'] === []
            ? Notification::make()->warning()->title(__('invoices.selected.nothing_billed'))
            : Notification::make()->success()->title(trans_choice('invoices.selected.done', count($result['invoices']), ['invoices' => implode(', #', $result['invoices'])]));
        $body = collect(['skipped' => $result['skipped'], 'problems' => $result['problems']])->filter()
            ->map(fn (array $rows, string $section) => '<strong>' . e(__('invoices.selected.' . $section)) . '</strong><br>'
                . collect($rows)->map(fn (string $why, string $item) => e($item) . ': ' . e($why))->implode('<br>'))
            ->implode('<br>');
        if ($body !== '') {
            $notification->body($body)->persistent();
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
