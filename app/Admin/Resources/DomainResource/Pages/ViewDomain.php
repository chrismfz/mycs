<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\DomainResource;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Staff;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainSyncService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A domain, and what staff do with it from here rather than the registrar's panel (docs/domains.md D4b): sync, its
 * auto renew and admin notes (here only), its nameservers, lock, privacy and contacts (at the registrar,
 * `admin.domains.manage`), its EPP code (`admin.domains.epp`), what the registrar holds for its contacts. Each
 * registrar call is logged (Registrar log) and its answer applied.
 */
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    private static function can(string $permission): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission($permission);
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    private static function manageable(Domain $record): bool
    {
        return app(DomainManagementService::class)->isManageable($record);
    }

    /** A registrar call: done and synced, or why not, never an exception on the page. */
    private function attempt(Closure $call, string $done): void
    {
        try {
            $call();
            Notification::make()->success()->title($done)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('The registrar did not accept it.'))->body(e($e->getMessage()))->send();
        }
        $this->record->refresh();
        $this->refreshFormData([]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The registrar's truth now (docs/domains.md D-9): reads the registrar, changes nothing there.
            Action::make('sync')->label(__('Sync now'))->icon('heroicon-o-arrow-path')
                ->visible(fn (Domain $record) => self::can('admin.domains.update') && app(DomainSyncService::class)->isSyncable($record))
                ->action(function (Domain $record) {
                    try {
                        $changes = app(DomainSyncService::class)->sync($record);
                        app(DomainLifecycle::class)->advance($record->refresh());
                        Notification::make()->success()->title(__('Synced.'))
                            ->body($changes === [] ? __('Nothing changed.') : implode(', ', array_keys($changes)))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('The registrar could not be read.'))->body(e($e->getMessage()))->send();
                    }
                    $this->refreshFormData([]);
                }),

            ActionGroup::make([
                Action::make('nameservers')->label(__('Nameservers'))->icon('heroicon-o-server-stack')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record))
                    ->fillForm(fn (Domain $record) => ['nameservers' => $record->nameservers->pluck('host')->all()])
                    ->schema([
                        Repeater::make('nameservers')->hiddenLabel()->simple(TextInput::make('host')->required()->maxLength(253))
                            ->minItems(2)->maxItems(13)->reorderable()->defaultItems(2)
                            ->helperText(__('2 to 13. A nameserver inside this domain gets its glue IP from its current DNS.')),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setNameservers($record, array_values(array_map(
                            fn ($row) => is_array($row) ? (string) ($row['host'] ?? '') : (string) $row, $data['nameservers'] ?? [])), self::staff()),
                        __('Nameservers changed at the registrar.'),
                    )),

                Action::make('lock')->label(fn (Domain $record) => $record->transfer_lock ? __('Unlock (allow transfer)') : __('Lock (block transfer)'))
                    ->icon(fn (Domain $record) => $record->transfer_lock ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record)
                        && ($record->meta['registrar']['is_lockable'] ?? true) !== false)
                    ->requiresConfirmation()
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setTransferLock($record, !$record->transfer_lock, self::staff()),
                        __('Transfer lock changed at the registrar.'),
                    )),

                Action::make('privacy')->label(fn (Domain $record) => $record->id_protection ? __('Turn WHOIS privacy off') : __('Turn WHOIS privacy on'))
                    ->icon('heroicon-o-eye-slash')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record)
                        && ($record->meta['registrar']['is_private_whois_allowed'] ?? true) !== false)
                    ->requiresConfirmation()
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setWhoisPrivacy($record, !$record->id_protection, self::staff()),
                        __('WHOIS privacy changed at the registrar.'),
                    )),

                Action::make('contacts')->label(__('Contacts'))->icon('heroicon-o-identification')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record))
                    ->fillForm(fn (Domain $record) => collect(Domain::ROLES)->mapWithKeys(fn ($role) => [$role => $record->{"{$role}_contact_id"}])->all())
                    ->schema(fn (Domain $record) => array_map(
                        fn (string $role) => Select::make($role)->label(__('domains.role.' . $role))->required($role === 'registrant')
                            ->options(Contact::query()->where('client_id', $record->client_id)->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName()])->all()),
                        Domain::ROLES,
                    ))
                    ->modalDescription(__('A contact changed gets a new handle at the registrar, with the account contact\'s details.'))
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setContacts($record, array_map(fn ($id) => $id !== null && $id !== '' ? (int) $id : null, $data), self::staff()),
                        __('Contacts changed at the registrar.'),
                    )),

                // What the registrar holds for each role's handle, read when opened (each read is logged).
                Action::make('registrarContacts')->label(__('Contacts at the registrar'))->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Domain $record) => $this->registrarContacts($record)),

                // Read when the modal opens and only rendered in it (review 4): not a notification, which Filament keeps in
                // the cache and the page's state until shown. Each read is logged, the code never.
                Action::make('eppCode')->label(__('Get EPP code'))->icon('heroicon-o-key')
                    ->visible(fn (Domain $record) => self::can('admin.domains.epp') && self::manageable($record))
                    ->modalHeading(fn (Domain $record) => __('EPP code of :domain', ['domain' => $record->fqdn]))
                    ->modalDescription(__('The code transfers the domain to whoever has it. Read from the registrar now, never stored; the read is logged.'))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Domain $record) => $this->eppCode($record)),
            ])->label(__('At the registrar'))->icon('heroicon-o-globe-alt')->button(),

            // Here only, not at the registrar.
            Action::make('edit')->label(__('Edit'))->icon('heroicon-o-pencil-square')
                ->visible(fn () => self::can('admin.domains.update'))
                ->fillForm(fn (Domain $record) => ['auto_renew' => $record->auto_renew, 'notes' => $record->notes])
                ->schema([
                    Toggle::make('auto_renew')->label(__('Auto renew'))
                        ->helperText(__('Off: no renewal invoice is made for it (do not renew).')),
                    Textarea::make('notes')->label(__('Admin notes'))->helperText(__('Staff only.'))->rows(4)->maxLength(5000),
                ])
                ->action(function (Domain $record, array $data) {
                    $record->forceFill(['auto_renew' => (bool) $data['auto_renew'], 'notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null])->save(); // audited
                    Notification::make()->success()->title(__('Saved.'))->send();
                    $this->refreshFormData([]);
                }),
        ];
    }

    /** Read once per request however often the modal renders (review 10). */
    private ?HtmlString $eppCode = null;

    private ?HtmlString $registrarContacts = null;

    private function eppCode(Domain $record): HtmlString
    {
        if ($this->eppCode !== null) {
            return $this->eppCode;
        }
        try {
            $code = app(DomainManagementService::class)->eppCode($record, self::staff());
            $html = '<code style="user-select:all;font-size:1.1em">' . e($code) . '</code>';
        } catch (Throwable $e) {
            $html = e(__('The registrar did not give it.') . ' ' . $e->getMessage());
        }

        return $this->eppCode = new HtmlString($html);
    }

    /** Each role's handle and what the registrar holds for it (escaped: it is the registrar's text). */
    private function registrarContacts(Domain $record): HtmlString
    {
        if ($this->registrarContacts !== null) {
            return $this->registrarContacts;
        }
        $rows = '';
        foreach (Domain::ROLES as $role) {
            $handle = (string) app(DomainManagementService::class)->handleFor($record, $role); // linked or only at the registrar (D4c)
            try {
                $contact = $handle !== '' ? app(DomainManagementService::class)->registrarContact($record, $role, self::staff()) : null;
                $text = $contact === null ? e($handle === '' ? __('No handle') : __('Not found at the registrar'))
                    : implode('<br>', array_map('e', array_filter([$contact->name, $contact->org, $contact->email, $contact->phone,
                        trim(implode(' ', array_filter([$contact->address1, $contact->address2]))),
                        trim(implode(' ', array_filter([$contact->postcode, $contact->city, $contact->country])))])));
            } catch (Throwable $e) {
                $text = e($e->getMessage());
            }
            $rows .= '<tr><th style="text-align:left;vertical-align:top;padding:6px 12px 6px 0">' . e(__('domains.role.' . $role))
                . '<br><small>' . e($handle) . '</small></th><td style="padding:6px 0">' . $text . '</td></tr>';
        }

        return $this->registrarContacts = new HtmlString('<table>' . $rows . '</table>');
    }
}
