<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Actions\InvoiceSelectedItemsAction;
use App\Admin\Components\ClientComponent;
use App\Admin\Concerns\RemembersRecent;
use App\Admin\Pages\WhoisLookup;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\DomainRegistrarConnection;
use App\Models\Staff;
use App\Services\Billing\RenewalBilling;
use App\Services\Clients\MoveToClient;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainOwnerChanges;
use App\Services\Domains\DomainRecordCorrections;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Support\Domains\DnssecOptions;
use App\Support\Domains\DnssecRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
    use RemembersRecent;

    protected static string $resource = DomainResource::class;

    private static function can(string $permission): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission($permission);
    }

    private static function contactLabel(Contact $contact): string
    {
        return trim(($contact->company_name ? $contact->company_name . ' · ' : '') . $contact->first_name . ' ' . $contact->last_name)
            . ' <' . $contact->email . '> #' . $contact->client_id;
    }

    private static function lastDeclaration(Domain $record): ?DomainOwnerChange
    {
        return DomainOwnerChange::query()->where('domain_id', $record->id)->whereNotNull('declaration_path')->latest('id')->first();
    }

    /** The open change of holder's next steps (G8-5b), each for the state it is in. @return list<Action> */
    private static function ownerChangeActions(): array
    {
        $open = fn (Domain $record) => DomainOwnerChanges::openFor($record);
        $in = fn (Domain $record, string ...$statuses) => self::can('admin.domains.owner_change') && in_array($open($record)?->status, $statuses, true);
        $run = function (Closure $do, string $done) {
            try {
                $do();
                Notification::make()->success()->title($done)->send();
            } catch (Throwable $e) {
                Notification::make()->danger()->title(__('domains.owner_change.title'))->body($e->getMessage())->send();
            }
        };

        return [
            Action::make('ownerChangeApproved')->label(__('domains.owner_change.approved'))->icon('heroicon-o-check')
                ->visible(fn (Domain $record) => $in($record, DomainOwnerChange::STATUS_AWAITING_EETT))->requiresConfirmation()
                ->action(fn (Domain $record) => $run(fn () => app(DomainOwnerChanges::class)->approved($open($record)), __('domains.owner_change.statuses.done'))),
            Action::make('ownerChangeRetry')->label(__('domains.owner_change.retry'))->icon('heroicon-o-arrow-path')
                ->visible(fn (Domain $record) => $in($record, DomainOwnerChange::STATUS_REFUSED))->requiresConfirmation()
                ->action(fn (Domain $record) => $run(fn () => app(DomainOwnerChanges::class)->retry($open($record)), __('domains.owner_change.retry'))),
            Action::make('ownerChangeDoneThere')->label(__('domains.owner_change.done_there'))->icon('heroicon-o-check-badge')
                ->visible(fn (Domain $record) => $in($record, DomainOwnerChange::STATUS_UNKNOWN))->requiresConfirmation()
                ->action(fn (Domain $record) => $run(fn () => app(DomainOwnerChanges::class)->doneThere($open($record)), __('domains.owner_change.statuses.done'))),
            Action::make('ownerChangeCancel')->label(__('domains.owner_change.cancel'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Domain $record) => $in($record, DomainOwnerChange::STATUS_AWAITING_PAYMENT, DomainOwnerChange::STATUS_DUE,
                    DomainOwnerChange::STATUS_REFUSED, DomainOwnerChange::STATUS_UNKNOWN))
                ->schema([Textarea::make('reason')->label(__('domains.owner_change.cancel_reason'))->required()->maxLength(500)])
                ->action(fn (Domain $record, array $data) => $run(fn () => app(DomainOwnerChanges::class)->cancel($open($record), (string) $data['reason']),
                    __('domains.owner_change.statuses.cancelled'))),
            // The last declaration kept, open or ended (it is the record of the transfer).
            Action::make('ownerChangeDeclaration')->label(__('domains.owner_change.download'))->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn (Domain $record) => self::can('admin.domains.owner_change') && self::lastDeclaration($record) !== null)
                ->action(fn (Domain $record) => Storage::disk(DomainOwnerChanges::DISK)->download(self::lastDeclaration($record)->declaration_path,
                    self::lastDeclaration($record)->declaration_name ?: basename(self::lastDeclaration($record)->declaration_path))),
        ];
    }

    private const CLIENT_DOMAINS = 200;

    /** @var list<Action>|null the row, built once a request */
    private ?array $registrarCommands = null;

    /** WHMCS's «Registrar Commands», in its order: [the page's action, its button's label, its colour]. */
    private const REGISTRAR_COMMANDS = [
        ['renewNow', 'Renew now', 'warning'],
        ['nameservers', 'Nameservers', 'gray'],
        ['contacts', 'Contacts', 'gray'],
        ['registrarContacts', 'Contacts at the registrar', 'gray'],
        ['emailAuthCode', 'Email the EPP code', 'gray'],
        ['eppCode', 'Get EPP code', 'gray'],
        ['editDnssec', 'DNSSEC', 'gray'],
        ['readDnssec', 'DNSSEC', 'gray'],
        ['readChildHosts', 'Child nameservers', 'gray'],
        ['lock', 'Transfer lock', 'gray'],
        ['privacy', 'WHOIS privacy', 'gray'],
        ['ownerChange', 'Change holder', 'warning'],
        ['recall', 'Recall', 'danger'],
    ];

    /**
     * The registrar's commands as one row of buttons (owner 2026-10-23, the mockup): each opens the action of «More» by
     * its name, with its own checks and confirmation; one not offered for this domain is not shown.
     *
     * @return list<Action>
     */
    public function registrarCommands(): array
    {
        // Once a request (ADV-1 of #401: the row's section and its actions asked five times); asked before the page's
        // actions are built (an empty row then), asked again.
        if ($this->registrarCommands !== null && $this->registrarCommands !== []) {
            return $this->registrarCommands;
        }

        return $this->registrarCommands = collect(self::REGISTRAR_COMMANDS)
            ->filter(fn (array $command) => (bool) $this->getAction($command[0], false)?->isVisible())
            // One «DNSSEC»: its change once it was read, else its read (ADV-3 of #401).
            ->unique(fn (array $command) => $command[1])
            ->map(fn (array $command) => Action::make('command_' . $command[0])->label(__($command[1]))->size('sm')->outlined()->color($command[2])
                ->alpineClickHandler("\$wire.mountAction('{$command[0]}')"))
            ->values()->all();
    }

    /** This client's domains to move between (owner 2026-10-23, as WHMCS's «Domains:» select), with ‹ › to the next. */
    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();
        if (!$record instanceof Domain) {
            return null;
        }
        // The first 200 (a reseller with hundreds: the rest on the client's tab, ADV-4 of #401).
        $domains = Domain::query()->where('client_id', $record->client_id)->orderBy('fqdn')->limit(self::CLIENT_DOMAINS)->get(['id', 'fqdn', 'status']);
        if ($domains->count() < 2) {
            return null;
        }
        $at = $domains->search(fn (Domain $domain) => $domain->is($record));
        $url = fn (?Domain $domain) => $domain === null ? null : DomainResource::getUrl('view', ['record' => $domain]);

        return new HtmlString(view('admin.resources.domain-resource.client-domains', [
            'domains' => $domains->map(fn (Domain $domain) => ['fqdn' => $domain->fqdn, 'url' => $url($domain), 'current' => $domain->is($record),
                'status' => $domain->status->getLabel(), 'ended' => in_array($domain->status->value, DomainStatus::terminalValues(), true)])->all(),
            'previous' => $url($at > 0 ? $domains[$at - 1] : null),
            'next' => $url($domains[$at + 1] ?? null),
            // Only for staff who may open the client (ADV-2 of #401), as the Client field.
            'tab' => ClientResource::canView($record->client) ? ClientResource::getUrl('domains', ['record' => $record->client_id]) : null,
            'total' => Domain::query()->where('client_id', $record->client_id)->count(),
        ])->render());
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

    /** @return list<array<string, mixed>> as last read (meta.child_hosts: stored data, each key checked) */
    private static function childHosts(Domain $record): array
    {
        $hosts = $record->meta['child_hosts']['hosts'] ?? [];

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_array')) : [];
    }

    /** @return list<string> */
    private static function lines(mixed $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $text) ?: []), fn (string $l) => $l !== ''));
    }

    /** A registrar call: done and synced, or why not, never an exception on the page. */
    private function attempt(Closure $call, string $done): void
    {
        try {
            $call();
            Notification::make()->success()->title($done)->send();
        } catch (RegistrarOutcomeUnknown $e) {
            // Its answer lost or unclear: maybe done, never «not accepted» (review 4 of #210).
            Notification::make()->warning()->title(__('Maybe done: the registrar\'s answer was lost or unclear.'))->body(e($e->getMessage()))->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('The registrar did not accept it.'))->body(e($e->getMessage()))->send();
        }
        $this->record->refresh();
        $this->refreshFormData([]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // D7a: its next period invoiced now, before its time (renewed when paid, D5b-2): first, as on the service's page.
            InvoiceSelectedItemsAction::nextPeriod()->visible(fn (Domain $record) => in_array($record->status, RenewalBilling::BILLED_DOMAINS, true)),
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

            // Everything at the registrar and the rest (owner 2026-10-23): its commands are also one row above the details
            // (registrarCommands), each opening the action here.
            ActionGroup::make([
                // To another client (owner 2026-10-23, as WHMCS's «Move»): with its invoices not paid yet; staff with its own
                // permission, confirmed.
                Action::make('moveToClient')
                    ->label('Move to another client')->icon('ri-arrow-left-right-line')->color('gray')
                    ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.move'))
                    ->schema([
                        ClientComponent::picker('to_client_id')->label('Move to client'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('The domain moves to the other client. Nothing changes at the registrar: its contacts are copied to the new client. Its unpaid renewal invoices go with it; paid ones stay. A note is left on both accounts.')
                    ->action(function (array $data, Domain $record, Action $action): void {
                        $staff = Auth::guard('staff')->user();
                        abort_unless((bool) $staff?->hasPermission('admin.domains.move'), 403);
                        $to = Client::query()->findOrFail((int) $data['to_client_id']);
                        try {
                            $result = app(MoveToClient::class)->domain($record, $to, $staff);
                        } catch (DisplayException $e) {
                            Notification::make()->title('Not moved')->body($e->getMessage())->danger()->send();
                            $action->halt();

                            return;
                        }
                        $told = trim(($result['moved'] !== [] ? count($result['moved']) . ' unpaid invoice(s) moved with it. ' : '')
                            . ($result['split'] !== [] ? count($result['split']) . ' new invoice(s) issued to the new client for its lines.' : ''));
                        Notification::make()->title('Moved to ' . $to->name)->body($told !== '' ? $told : null)->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    }),
                // Our record corrected when something changed outside MyCS (owner 2026-10-23): nothing asked of a registrar,
                // each change audited before → after (DomainRecordCorrections).
                Action::make('correctRecord')->label(__('Correct the record'))->icon('heroicon-o-wrench-screwdriver')->color('gray')
                    ->visible(fn () => self::can('admin.domains.update'))
                    ->modalDescription(__('Our record only: nothing is sent to any registrar. Each change is in the domain\'s activity, before → after.'))
                    ->fillForm(fn (Domain $record) => ['fqdn' => $record->fqdn, 'registrar_connection_id' => $record->registrar_connection_id,
                        'nameservers' => $record->nameservers()->orderBy('sort')->pluck('host')->implode("\n")])
                    ->schema(fn (Domain $record) => [
                        TextInput::make('fqdn')->label(__('Name'))->required()->maxLength(255)
                            ->helperText(__('As the registry writes it. A registrar never renames a domain: if it answers with another name, the sync says so.')),
                        Select::make('registrar_connection_id')->label(__('Registrar'))
                            ->placeholder(__('Its TLD\'s (:registrar)', ['registrar' => $record->domainTld->registrarConnection->label ?? __('none')]))
                            ->options(DomainRegistrarConnection::query()->orderBy('label')->get()->mapWithKeys(fn (DomainRegistrarConnection $c) => [$c->id => $c->label
                                . ($c->registrar === DomainRegistrarConnection::MANUAL ? ' (' . __('by hand') . ')' : '') . ($c->is_active ? '' : ' (' . __('inactive') . ')')])->all())
                            ->helperText(__('Where it is (moved by hand). Then it is read from there, if it has an API.')),
                        Textarea::make('nameservers')->label(__('Nameservers'))->rows(4)
                            ->visible(fn () => !app(DomainManagementService::class)->isManageable($record))
                            ->helperText(__('One a line. Only for a domain kept by hand: one at a registrar changes them there (Nameservers).')),
                    ])
                    ->action(function (Domain $record, array $data) {
                        $staff = self::staff() ?? throw new \RuntimeException('No staff.');
                        abort_unless(self::can('admin.domains.update'), 403);
                        $corrections = app(DomainRecordCorrections::class);
                        try {
                            $corrections->rename($record, (string) $data['fqdn'], $staff);
                            $said = $corrections->moveToRegistrar($record->refresh(), filled($data['registrar_connection_id'] ?? null) ? (int) $data['registrar_connection_id'] : null, $staff);
                            // Only while no registrar API keeps them: moved to one just now, they are read from there (ADV-5 of #403).
                            if (array_key_exists('nameservers', $data) && !app(DomainManagementService::class)->isManageable($record->refresh())) {
                                $corrections->setNameserversByHand($record, preg_split('/\R/', (string) $data['nameservers']) ?: [], $staff);
                            }
                            Notification::make()->success()->title(__('Corrected.'))->body($said)->send();
                        } catch (DisplayException $e) {
                            Notification::make()->danger()->title(__('Not corrected'))->body($e->getMessage())->send();
                        }
                        $this->redirect(DomainResource::getUrl('view', ['record' => $record->refresh()]));
                    }),
                // All its history: staff's changes, the customer's, the sync's (owner 2026-10-23, as the service's).
                AuditAction::make()->label(__('Activity')),
                // D-1: a trusted customer's domain renewed now, paid later (the payment adopts it, never a second charge).
                Action::make('renewNow')->label(__('Renew now'))->icon('heroicon-o-arrow-path-rounded-square')->color('warning')
                    ->visible(fn (Domain $record) => self::can('admin.domains.renew') && self::manageable($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Domain $record) => __('Renews :domain at the registrar now for :years year(s), charged to our account there. Its renewal invoice is made if it is not, and its payment later renews nothing again.',
                        ['domain' => $record->fqdn, 'years' => max(1, (int) $record->years)]))
                    ->action(function (Domain $record) {
                        $staff = self::staff();
                        if ($staff === null) {
                            return;
                        }
                        try {
                            $line = app(DomainRenewalService::class)->renewNow($record, $staff);
                            Notification::make()->success()->title(__('Renewed at the registrar.'))
                                ->body(__('Its renewal is on invoice #:invoice, to be paid.', ['invoice' => $line->invoice_id]))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('The registrar did not accept it.'))->body(e($e->getMessage()))->send();
                        }
                        $this->record->refresh();
                        $this->refreshFormData([]);
                    }),

                Action::make('nameservers')->label(__('Nameservers'))->icon('heroicon-o-server-stack')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record))
                    ->fillForm(fn (Domain $record) => ['nameservers' => $record->nameservers->pluck('host')->all()])
                    ->schema([
                        Repeater::make('nameservers')->hiddenLabel()->simple(TextInput::make('host')->required()->maxLength(253))
                            ->minItems(2)->maxItems(13)->reorderable()->defaultItems(2)
                            ->helperText(fn (Domain $record) => app(DomainManagementService::class)->managesChildHosts($record)
                                ? __('2 to 13. A nameserver inside this domain must exist as a child nameserver first (with its IPs).')
                                : __('2 to 13. A nameserver inside this domain gets its glue IP from its current DNS.')),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setNameservers($record, array_values(array_map(
                            fn ($row) => is_array($row) ? (string) ($row['host'] ?? '') : (string) $row, $data['nameservers'] ?? [])), self::staff()),
                        __('Nameservers changed at the registrar.'),
                    )),

                // Child nameservers (glue): the .gr registry keeps them as objects of their own; read on request.
                Action::make('readChildHosts')->label(__('Read child nameservers'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->managesChildHosts($record))
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->readChildHosts($record, self::staff()),
                        __('Child nameservers read from the registrar.'),
                    )),

                Action::make('createChildHost')->label(__('Add child nameserver'))->icon('heroicon-o-plus')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->managesChildHosts($record))
                    ->schema([
                        TextInput::make('host')->label(__('Name'))->required()->maxLength(253)
                            ->helperText(fn (Domain $record) => __('ns1, or ns1.:domain', ['domain' => $record->fqdn])),
                        Textarea::make('addresses')->label(__('IP addresses'))->required()->rows(3)->helperText(__('One per line, IPv4 or IPv6.')),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->createChildHost($record, (string) $data['host'], self::lines($data['addresses'] ?? ''), self::staff()),
                        __('Child nameserver created at the registrar.'),
                    )),

                Action::make('updateChildHost')->label(__('Change child nameserver IPs'))->icon('heroicon-o-pencil')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->managesChildHosts($record)
                        && self::childHosts($record) !== [])
                    ->schema([
                        Select::make('host')->label(__('Name'))->required()->live()
                            ->options(fn (Domain $record) => collect(self::childHosts($record))->reject(fn (array $h) => ($h['read'] ?? true) === false)
                                ->mapWithKeys(fn (array $h) => [$h['name'] => $h['name']])->all())
                            ->afterStateUpdated(fn ($state, Set $set, Domain $record) => $set('addresses',
                                implode("\n", collect(self::childHosts($record))->firstWhere('name', $state)['addresses'] ?? []))),
                        Textarea::make('addresses')->label(__('IP addresses'))->required()->rows(3)
                            ->helperText(__('The full set, one per line: what is missing is added, what is not here is removed.')),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->updateChildHost($record, (string) $data['host'], self::lines($data['addresses'] ?? ''), self::staff()),
                        __('Child nameserver changed at the registrar.'),
                    )),

                Action::make('deleteChildHost')->label(__('Delete child nameserver'))->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->managesChildHosts($record)
                        && collect(self::childHosts($record))->contains(fn (array $h) => !($h['linked'] ?? true)))
                    ->requiresConfirmation()
                    ->modalDescription(__('Only one no domain uses as a nameserver. Logged.'))
                    ->schema([
                        Select::make('host')->label(__('Name'))->required()
                            ->options(fn (Domain $record) => collect(self::childHosts($record))->reject(fn (array $h) => $h['linked'] ?? true)
                                ->mapWithKeys(fn (array $h) => [$h['name'] => $h['name']])->all()),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(
                        fn () => app(DomainManagementService::class)->deleteChildHost($record, (string) $data['host'], self::staff()),
                        __('Child nameserver deleted at the registrar.'),
                    )),

                // DNSSEC (G8-4b): DS records (the .gr registry) or DNSKEYs (Openprovider); read on request.
                Action::make('readDnssec')->label(__('Read DNSSEC'))->icon('heroicon-o-shield-check')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->managesDnssec($record))
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->readDnssec($record, self::staff()),
                        __('DNSSEC read from the registrar.'),
                    )),

                Action::make('editDnssec')->label(__('Change DNSSEC'))->icon('heroicon-o-key')->color('warning')
                    // From this registrar's read only, not out of date (ADV-3 of #210, review 2).
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->dnssecRead($record) !== null)
                    ->modalDescription(fn (Domain $record) => app(DomainManagementService::class)->dnssecKind($record) === DnssecRecord::DS
                        ? __('The full set of DS records (none: unsigned). Sign the zone at its nameservers first: the .gr registry checks them there within 20 minutes and refuses them all if one does not match.')
                        : __('The full set of DNSKEYs (none: unsigned); the registrar makes the DS. Sign the zone at its nameservers first: a key that does not match breaks the domain for validating resolvers.'))
                    ->fillForm(function (Domain $record) {
                        $records = array_map(fn (DnssecRecord $r) => $r->toArray(), app(DomainManagementService::class)->dnssecRead($record) ?? []);

                        // What staff see is what the registrar must still have when they send it (ADV-1 of #210).
                        return ['records' => $records, 'seen' => json_encode($records)];
                    })
                    ->schema(fn (Domain $record) => [
                        Hidden::make('seen'),
                        Repeater::make('records')->hiddenLabel()->defaultItems(0)->maxItems(16)->columns(4)
                            ->schema(app(DomainManagementService::class)->dnssecKind($record) === DnssecRecord::DS ? [
                                TextInput::make('key_tag')->label(__('Key tag'))->required()->integer()->minValue(0)->maxValue(65535),
                                // The registry's own lists, and whatever it has already (ADV-2 of #210: one kept, never dropped).
                                Select::make('algorithm')->label(__('Algorithm'))->required()
                                    ->options(fn ($state) => DnssecOptions::algorithms(DnssecRecord::DS, $state)),
                                Select::make('digest_type')->label(__('Digest type'))->required()
                                    ->options(fn ($state) => DnssecOptions::digestTypes($state)),
                                TextInput::make('digest')->label(__('Digest'))->required()->maxLength(200)->columnSpanFull()
                                    ->regex('/\A[0-9A-Fa-f\s]+\z/')->validationMessages(['regex' => __('Hex digits only.')]),
                            ] : [
                                Select::make('flags')->label(__('Flags'))->required()->options(fn ($state) => DnssecOptions::flags($state)),
                                Select::make('algorithm')->label(__('Algorithm'))->required()
                                    ->options(fn ($state) => DnssecOptions::algorithms(DnssecRecord::DNSKEY, $state)),
                                Textarea::make('public_key')->label(__('Public key'))->required()->rows(3)->columnSpanFull()
                                    ->regex('/\A[A-Za-z0-9+\/=\s]+\z/')->validationMessages(['regex' => __('Base64 only.')]),
                            ]),
                    ])
                    ->action(fn (Domain $record, array $data) => $this->attempt(function () use ($record, $data) {
                        $service = app(DomainManagementService::class);
                        $kind = $service->dnssecKind($record);
                        $rows = fn (array $list) => array_map(fn (array $row) => DnssecRecord::fromArray(['kind' => $kind] + $row),
                            array_values(array_filter($list, 'is_array')));
                        $seen = json_decode((string) ($data['seen'] ?? ''), true);
                        $service->setDnssec($record, $rows($data['records'] ?? []), self::staff(), is_array($seen) ? $rows($seen) : $service->dnssecRead($record));
                    }, __('DNSSEC sent to the registrar.'))),

                Action::make('lock')->label(fn (Domain $record) => $record->transfer_lock ? __('Unlock (allow transfer)') : __('Lock (block transfer)'))
                    ->icon(fn (Domain $record) => $record->transfer_lock ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record)
                        && ($record->meta['registrar']['is_lockable'] ?? true) !== false
                        && app(DomainManagementService::class)->registrarSupports($record, 'supportsTransferLock'))
                    ->requiresConfirmation()
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->setTransferLock($record, !$record->transfer_lock, self::staff()),
                        __('Transfer lock changed at the registrar.'),
                    )),

                Action::make('privacy')->label(fn (Domain $record) => $record->id_protection ? __('Turn WHOIS privacy off') : __('Turn WHOIS privacy on'))
                    ->icon('heroicon-o-eye-slash')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && self::manageable($record)
                        && ($record->meta['registrar']['is_private_whois_allowed'] ?? true) !== false
                        && app(DomainManagementService::class)->registrarSupports($record, 'supportsPrivacy'))
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
                // Its registrar says it is not ours (the sync's note): staff confirm it moved, after a look at its whois.
                Action::make('markTransferredAway')->label(__('Transferred away'))->icon('heroicon-o-arrow-right-on-rectangle')->color('danger')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && is_array($record->meta['not_ours'] ?? null)
                        && !$record->status->isTerminal())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Domain $record) => __('Its registrar says it is not ours since :since (:said). Check its WHOIS first: if a setting of its connection is wrong, fix that instead. Ended here: auto-renew off, no more sync or billing; its open renewal invoices are cancelled a day later. Logged.',
                        ['since' => (string) ($record->meta['not_ours']['since'] ?? '—'), 'said' => (string) ($record->meta['not_ours']['said'] ?? '—')]))
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->markTransferredAway($record, self::staff() ?? throw new \RuntimeException('No staff.')),
                        __('Marked transferred away.'),
                    )),

                Action::make('resumeNotAway')->label(__('Not transferred away: resume'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && $record->status === DomainStatus::TransferredAway
                        && is_array($record->meta['transferred_away'] ?? null))
                    ->requiresConfirmation()
                    ->modalDescription(__('Its status and auto-renew as they were, then synced. Logged.'))
                    ->action(fn (Domain $record) => $this->attempt(
                        fn () => app(DomainManagementService::class)->resumeNotTransferredAway($record, self::staff() ?? throw new \RuntimeException('No staff.')),
                        __('Resumed.'),
                    )),

                // A .gr change of holder (G8-5b): a name change or a transfer, charged, sent once paid, never recalled.
                Action::make('ownerChange')->label(__('domains.owner_change.title'))->icon('heroicon-o-user-group')->color('warning')
                    ->visible(fn (Domain $record) => self::can('admin.domains.owner_change') && DomainOwnerChanges::openFor($record) === null
                        && app(DomainManagementService::class)->changesOwner($record))
                    ->modalWidth('2xl')
                    ->schema(fn (Domain $record) => [
                        Radio::make('kind')->label(__('domains.owner_change.kind'))->required()->live()
                            ->options([ChangesOwner::NAME => __('domains.owner_change.kind_name'), ChangesOwner::OWNER => __('domains.owner_change.kind_owner')]),
                        Select::make('contact_id')->label(__('domains.owner_change.holder'))->helperText(__('domains.owner_change.holder_help'))->required()
                            ->searchable()
                            ->options(fn (Get $get) => $get('kind') === ChangesOwner::NAME
                                ? Contact::query()->where('client_id', $record->client_id)->whereKeyNot((int) $record->registrant_contact_id)->get()
                                    ->mapWithKeys(fn (Contact $contact) => [$contact->id => self::contactLabel($contact)])->all()
                                : [])
                            ->getSearchResultsUsing(fn (Get $get, string $search) => $get('kind') === ChangesOwner::NAME ? [] : Contact::query()
                                ->whereKeyNot((int) $record->registrant_contact_id)
                                ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('company_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->limit(25)->get()->mapWithKeys(fn (Contact $contact) => [$contact->id => self::contactLabel($contact)])->all())
                            ->getOptionLabelUsing(fn ($value) => ($contact = Contact::query()->find($value)) ? self::contactLabel($contact) : null),
                        FileUpload::make('declaration')->label(__('domains.owner_change.declaration'))->helperText(__('domains.owner_change.declaration_help'))
                            ->disk(DomainOwnerChanges::DISK)->directory(DomainOwnerChanges::DIRECTORY)->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])->maxSize(10240)
                            ->storeFileNamesIn('declaration_name')
                            ->required(fn (Get $get) => $get('kind') === ChangesOwner::OWNER),
                        Hidden::make('declaration_name'),
                        Toggle::make('move')->label(__('domains.owner_change.move'))->default(true)
                            ->visible(fn (Get $get) => $get('kind') === ChangesOwner::OWNER && filled($get('contact_id'))
                                && (int) Contact::query()->whereKey($get('contact_id'))->value('client_id') !== (int) $record->client_id),
                        Toggle::make('send_now')->label(__('domains.owner_change.send_now'))
                            ->helperText(__('domains.owner_change.send_now_help', ['price' => DomainOwnerChanges::price() ?? '—']))
                            ->default(DomainOwnerChanges::price() === null),
                        TextInput::make('confirm')->label(__('domains.owner_change.confirm'))->helperText(__('domains.owner_change.confirm_help'))
                            ->required()->rule(fn () => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                                if (mb_strtolower(trim((string) $value)) !== mb_strtolower($record->fqdn)) {
                                    $fail(__('domains.owner_change.confirm_mismatch', ['domain' => $record->fqdn]));
                                }
                            }),
                    ])
                    ->action(function (Domain $record, array $data) {
                        try {
                            $change = app(DomainOwnerChanges::class)->request($record, Contact::query()->findOrFail($data['contact_id']), (string) $data['kind'],
                                self::staff() ?? throw new \RuntimeException('No staff.'), $data['declaration'] ?? null, $data['declaration_name'] ?? null,
                                (bool) ($data['move'] ?? false), (bool) ($data['send_now'] ?? false));
                            Notification::make()->success()->title(__('domains.owner_change.requested'))
                                ->body(__($change->free ? 'domains.owner_change.requested_now' : 'domains.owner_change.requested_invoice'))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('domains.owner_change.title'))->body($e->getMessage())->send();
                        }
                    }),
                ...self::ownerChangeActions(),

                // A registration recalled within 5 days (the .gr registry, G5a of grepp): its payment refunded separately.
                Action::make('recall')->label(__('Recall the registration'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Domain $record) => self::can('admin.domains.manage') && app(DomainManagementService::class)->recallable($record))
                    ->requiresConfirmation()
                    ->modalDescription(__('The registry cancels the registration (only within 5 days of it); the domain is cancelled here. Refund its invoice line by hand; the Cancel & Refund of its order only if the domain is the whole order (it cancels all of it). Logged.'))
                    ->action(function (Domain $record) {
                        try {
                            $said = app(DomainManagementService::class)->recall($record, self::staff());
                            Notification::make()->success()->title(__('Recalled'))->body($said)->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Not recalled'))->body($e->getMessage())->send();
                        }
                    }),
                // The .gr registry never gives the code: it emails the registrant a reset link (DACoR, G4b of grepp).
                Action::make('emailAuthCode')->label(__('Email the EPP code to the registrant'))->icon('heroicon-o-envelope')
                    ->visible(fn (Domain $record) => self::can('admin.domains.epp') && self::manageable($record)
                        && app(DomainManagementService::class)->emailsAuthCode($record))
                    ->requiresConfirmation()
                    ->modalDescription(__('The registry emails the registrant a link to set a new EPP code; it never shows the code. Free; logged.'))
                    ->action(function (Domain $record) {
                        try {
                            $said = app(DomainManagementService::class)->emailAuthCode($record, self::staff());
                            Notification::make()->success()->title(__('Sent'))->body($said)->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Not sent'))->body($e->getMessage())->send();
                        }
                    }),
                Action::make('eppCode')->label(__('Get EPP code'))->icon('heroicon-o-key')
                    ->visible(fn (Domain $record) => self::can('admin.domains.epp') && self::manageable($record)
                        && !app(DomainManagementService::class)->emailsAuthCode($record))
                    ->modalHeading(fn (Domain $record) => __('EPP code of :domain', ['domain' => $record->fqdn]))
                    ->modalDescription(__('The code transfers the domain to whoever has it. Read from the registrar now, never stored; the read is logged.'))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Domain $record) => $this->eppCode($record)),
            ])->label(__('More'))->icon('heroicon-o-chevron-down')->iconPosition('after')->button()->color('gray'),

            // Here only, not at the registrar.
            // What the world sees at its registry: staff's own WHOIS Lookup, asked at once (no captcha, staff's limit;
            // for .gr the registry's WHOIS API, G6 of grepp).
            Action::make('whois')->label(__('WHOIS'))->icon('heroicon-o-globe-alt')->color('gray')
                ->url(fn (Domain $record) => WhoisLookup::getUrl(['domain' => $record->fqdn]))->openUrlInNewTab(),
            Action::make('edit')->label(__('Edit'))->icon('heroicon-o-pencil-square')
                ->visible(fn () => self::can('admin.domains.update'))
                ->fillForm(fn (Domain $record) => ['auto_renew' => $record->auto_renew, 'notes' => $record->notes, 'years' => max(1, (int) $record->years),
                    'recurring_amount' => $record->recurring_amount])
                ->schema([
                    Toggle::make('auto_renew')->label(__('Auto renew'))
                        ->helperText(__('Off: no renewal invoice is made for it (do not renew).')),
                    // Its renewal period (owner 2026-10-17): among the years its TLD prices; its open, unpaid renewal
                    // invoice changes with it (DomainRenewalYears).
                    Select::make('years')->label(__('Renewal period (years)'))
                        ->options(fn (Domain $record) => DomainRenewalYears::choices($record))
                        ->visible(fn (Domain $record) => DomainRenewalYears::changeable($record))
                        ->selectablePlaceholder(false)
                        ->helperText(fn (Domain $record) => $record->recurring_amount !== null
                            ? __('It renews at a recurring amount set by hand (:amount): change that too if the period changes.', ['amount' => $record->recurring_amount])
                            : __('From its next renewal; its open, unpaid renewal invoice changes with it.')),
                    // A price of staff's own (a friend's; owner 2026-10-23), kept until emptied («Recalculate»): the TLD's again.
                    // Decimal as typed, never through a float; what the column holds (ADV-6 of #403).
                    TextInput::make('recurring_amount')->label(__('Renewal price'))->numeric()->minValue(0)->maxValue(99999999.99)->nullable()
                        ->rule('regex:/^\d{1,8}(\.\d{1,2})?$/')
                        ->prefix(fn (Domain $record) => $record->currency_code)
                        ->placeholder(fn (Domain $record) => $record->domainTld?->priceFor('renew', max(1, (int) $record->years), (string) $record->currency_code) ?? '—')
                        ->helperText(__('Empty: the TLD\'s renew price (shown). From its next renewal invoice; one already made keeps its price.'))
                        ->hintAction(Action::make('recalculate')->label(__('Recalculate'))->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('recurring_amount', null))),
                    Textarea::make('notes')->label(__('Admin notes'))->helperText(__('Staff only.'))->rows(4)->maxLength(5000),
                ])
                ->action(function (Domain $record, array $data) {
                    // The period first: refused, nothing of the form is saved (code review 6 of #182).
                    $outcome = DomainRenewalYears::UNCHANGED;
                    if (isset($data['years']) && (int) $data['years'] !== max(1, (int) $record->years)) {
                        try {
                            $outcome = app(DomainRenewalYears::class)->change($record, (int) $data['years'], byStaff: true);
                        } catch (DisplayException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                    }
                    $price = $data['recurring_amount'] ?? null;
                    $record->forceFill(['auto_renew' => (bool) $data['auto_renew'], 'notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                        'recurring_amount' => $price === null || $price === '' ? null : (string) BigDecimal::of((string) $price)->toScale(2, RoundingMode::HalfUp)])->save(); // audited
                    Notification::make()->success()->title(__('Saved.'))->body(match ($outcome) {
                        DomainRenewalYears::INVOICE => __('Its open renewal invoice changed with the period.'),
                        DomainRenewalYears::NEXT => __('The period counts from its next renewal invoice: the open one, if any, keeps its period (paid or with money on it, a price set by hand, in grace or redemption, or a later period billed already).'),
                        default => null,
                    })->send();
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
