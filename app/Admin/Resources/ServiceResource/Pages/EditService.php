<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Actions\InvoiceSelectedItemsAction;
use App\Admin\Components\ClientComponent;
use App\Admin\Concerns\RemembersRecent;
use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\OfflineMachine;
use App\Admin\Resources\ServiceResource\PoolAddresses;
use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Exceptions\DisplayException;
use App\Exceptions\NothingToDoOnServer;
use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Livewire\Services\Show;
use App\Models\Client;
use App\Models\License;
use App\Models\Plan;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\Staff;
use App\Services\Clients\MoveToClient;
use App\Services\Licensing\Licenses;
use App\Services\Money\MoneyTrail;
use App\Services\Service\DeleteService;
use App\Services\Service\WelcomeMail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Support\LicenseKeyMask;
use App\Support\Redact;
use App\Support\ServicePassword;
use App\Support\StaffLink;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use MyCS\Extensions\Servers\CPanel\CPanel;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use OwenIt\Auditing\Events\AuditCustom;

class EditService extends EditRecord
{
    use RemembersRecent;
    use TwoColumnForm;

    protected static string $resource = ServiceResource::class;

    /** The record as the form was filled from it: a save writes only what staff changed since. */
    #[Locked]
    public array $filledAttributes = [];

    protected function afterFill(): void
    {
        $this->filledAttributes = $this->getRecord()->getAttributes();
    }

    /** The change of cycle before it is made: what the customer pays from then, and now what is credited and billed. */
    private static function cyclePreview(Service $service, mixed $planId, string $mode): string
    {
        $plan = ChangeCycle::plans($service)->firstWhere('id', (int) $planId);
        if ($plan === null) {
            return 'Choose a plan.';
        }
        if (($why = ChangeCycle::refusal($service, $plan, $mode)) !== null) {
            return $why;
        }
        $quote = ChangeCycle::quote($service, $plan, $mode);
        $currency = $service->currency_code;
        if ((int) $plan->id === (int) $service->plan_id) {
            return 'Takes back the change set for the next renewal' . ($service->nextPlan ? " (to {$service->nextPlan->name})." : '.');
        }
        $text = "Recurring amount: {$quote['price']} {$currency} net per unit, {$quote['gross']} {$currency} with tax (now {$service->price} net).";
        if ($mode === ChangeCycle::NEXT_RENEWAL) {
            $text .= ' It applies when the next period is billed; an open renewal of the old cycle is cancelled and billed again.';
        }
        if ($mode === ChangeCycle::NOW) {
            $text .= " Credit for the unused days: {$quote['credit']} {$currency}. New period {$quote['start']?->toDateString()} – "
                . "{$quote['end']?->toDateString()}, billed {$quote['charge']} {$currency} with tax.";
        }

        return $text;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Service $record */
        // Livewire reloads the record on every request: diff against the form as it was filled, so a
        // payment or the cron that ran while the page was open is not written back.
        try {
            $record->applyStaffChanges(Arr::except($data, ['ends_billing']), $this->filledAttributes ?: null, (bool) ($data['ends_billing'] ?? false));
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Not saved'))->body($e->getMessage())->send();

            throw new Halt;
        }

        return $record;
    }

    /** At most this many in the list (a reseller's hundreds: the rest on the client's tab). */
    private const CLIENT_SERVICES = 200;

    /** This client's services to move between (owner 2026-10-23, as WHMCS's select above a product), with ‹ › to the next. */
    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();
        if (!$record instanceof Service) {
            return null;
        }
        // A window around this one (review of #405: past the first 200, it lost its own place and its ‹ ›).
        $own = fn () => Service::query()->where('client_id', $record->client_id);
        $half = intdiv(self::CLIENT_SERVICES, 2);
        $services = $own()->where('id', '<', $record->id)->orderByDesc('id')->limit($half)->pluck('id')
            ->reverse()->push($record->id)->merge($own()->where('id', '>', $record->id)->orderBy('id')->limit($half)->pluck('id'));
        $services = Service::query()->whereIn('id', $services->all())->with(['product:id,name', 'license'])->orderBy('id')
            ->get(['id', 'client_id', 'product_id', 'domain', 'status']);
        $at = $services->search(fn (Service $service) => $service->is($record));
        $url = fn (?Service $service) => $service === null ? null : ServiceResource::getUrl('edit', ['record' => $service]);

        $client = $record->client;
        // Nothing to show (no list, a client staff may not open): no subheading at all (ADV-1 of #407).
        $back = $client !== null && ClientResource::canView($client);
        if (!$back && $services->count() < 2) {
            return null;
        }

        return new HtmlString(view('admin.components.client-switcher', [
            'client' => $back
                ? ['label' => '#' . $client->id . ' ' . $client->name, 'url' => ClientResource::getUrl('view', ['record' => $client])] : null,
            'title' => __("This client's services"), 'tabLabel' => __("On the client's Products/Services tab"),
            'previousLabel' => __('Previous service'), 'nextLabel' => __('Next service'),
            'items' => $services->map(fn (Service $service) => [
                // A license's «domain» is its key: masked as the search shows it.
                'label' => '#' . $service->id . ' ' . ($service->product->name ?? '') . ($service->domain ? ' · ' . LicenseKeyMask::domain($service) : ''),
                'url' => $url($service), 'current' => $service->is($record),
                'status' => Service::statusLabel($service->status), 'ended' => in_array($service->status, Service::ENDED_STATUSES, true),
            ])->all(),
            'previous' => $url($at !== false && $at > 0 ? $services[$at - 1] : null),
            'next' => $url($at !== false ? ($services[$at + 1] ?? null) : null),
            // Only for staff who may open the client, as the Client field.
            'tab' => $record->client !== null && ClientResource::canView($record->client) ? ClientResource::getUrl('services', ['record' => $record->client_id]) : null,
            'total' => $own()->count(), // a window shows part of them
        ])->render());
    }

    /** Staff may sign into its panel: their permission, a server whose module offers it, an account made there. */
    public static function panelLoginOffered(Service $record): bool
    {
        $server = $record->provisioningServer();

        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.services.sso') && $server !== null
            && in_array($record->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            && rescue(fn () => ExtensionHelper::hasFunction($server, 'getLoginUrl'), false, false);
    }

    /** Its server's module changes passwords (cPanel, mailcow, Centova). */
    private static function changesPasswordOnServer(Service $record): bool
    {
        return rescue(fn () => ($server = $record->provisioningServer()) !== null
            && ExtensionHelper::hasFunction($server, 'changePassword'), false, false);
    }

    private static function passwordInput(): TextInput
    {
        return TextInput::make('password')->label(__('New password'))->password()->revealable()->required()->maxLength(128);
    }

    private static function sendWelcomeCheckbox(): Checkbox
    {
        return Checkbox::make('sendWelcome')->label(__('Then send the welcome email with the new password'))->default(false)
            ->visible(fn (Service $record) => in_array($record->status, WelcomeMail::STATUSES, true))
            // The reason it would not go, before staff count on it (a stored password lifts «no password» once saved).
            ->helperText(fn (Service $record) => self::welcomeBlocker($record)
                ?? __('To the account\'s owner (:email).', ['email' => WelcomeMail::recipient($record) ?? '—']));
    }

    /** A refusal that a new password does not lift (not «no password»: the action stores one). */
    private static function welcomeBlocker(Service $record): ?string
    {
        $probe = (clone $record)->forceFill(['password' => 'x']);

        return WelcomeMail::refusal($probe);
    }

    /** Who stored a password and how (the attribute's own audit shows only that it changed, never its value). */
    private static function auditPassword(Service $record, string $event): void
    {
        $record->auditEvent = $event;
        $record->isCustomEvent = true;
        $record->auditCustomOld = [];
        $record->auditCustomNew = ['staff_id' => Auth::guard('staff')->id()];
        event(new AuditCustom($record));
        $record->isCustomEvent = false; // a later save of this instance audits as usual (password redacted)
        $record->auditCustomNew = [];
    }

    /** The form shows the password stored now, and a save diffs against it (not the old one). */
    private function passwordStored(): void
    {
        $attributes = $this->getRecord()->refresh()->getAttributes();
        $this->refreshFormData(['password']);
        if (array_key_exists('password', $attributes)) {
            $this->filledAttributes['password'] = $attributes['password'];
        }
    }

    private function resendWelcome(Service $record): void
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof Staff && $staff->can('update', $record), 403);
        try {
            $log = WelcomeMail::resend($record, $staff);
        } catch (DisplayException $e) {
            Notification::make()->title(__('Welcome email not sent'))->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title(__('Welcome email sent'))->body(__('Queued for :email.', ['email' => $log->to]))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        /** @var Service $record */
        $record = $this->getRecord();

        return [
            InvoiceSelectedItemsAction::nextPeriod()->visible(in_array($record->status, ['active', 'suspended'], true)),
            // A machine with no API: its details (the form's «Machine» opens it too).
            OfflineMachine::editAction()->visible(fn (Service $record) => OfflineMachine::fields($record) !== []),
            // Its Proxmox VM's power (one mapped in Proxmox Guests).
            ProxmoxPower::group($record),
            // Its addresses from the IP pools (docs/proxmox.md, B3).
            ActionGroup::make([PoolAddresses::assign(), PoolAddresses::release()])->label('IPs')->icon('ri-git-merge-line')->button()->color('gray'),
            // Into its panel, signed in, as WHMCS's «Log in to panel» (owner 2026-10-23): its own permission, audited.
            // A link in a new tab (owner 2026-10-26): the admin page stays; its signed route signs in (ServicePanelLoginController).
            Action::make('loginToPanel')->label('Log in to panel')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->visible(fn (Service $record) => self::panelLoginOffered($record))
                ->url(fn (Service $record) => StaffLink::to('filament.admin.sso.service', ['service' => $record->id]))
                ->openUrlInNewTab()->extraAttributes(['rel' => 'noopener noreferrer']),
            // The rest behind «More» (owner 2026-10-23: eight buttons on one line); the server's commands are also in the
            // form's Account, one button each.
            ActionGroup::make([
                DeleteAction::make()
                    ->form(function (DeleteAction $action) use ($record) {
                        // A record of an account already gone (terminated straight on the server): untick to delete only it.
                        if ($record->isEnded() || $record->status === Service::STATUS_PENDING || !$record->hasServerActions()) {
                            return [];
                        }

                        return [
                            Checkbox::make('deleteExtensionServer')
                                ->label('Also trigger deletion of server')
                                ->default(true),
                        ];
                    })
                    ->action(function (array $data, Service $record): void {
                        try {
                            (new DeleteService)->handle($record, (bool) ($data['deleteExtensionServer'] ?? false));
                        } catch (DisplayException $e) {
                            Notification::make('Error')->title(__('Service not deleted'))->body($e->getMessage())->danger()->send();

                            return;
                        } catch (Exception $e) {
                            report($e);

                            Notification::make('Error')
                                ->title('Error occured while deleting the related server:')
                                ->body($e->getMessage() . ' The service was not deleted (untick the server deletion to delete it anyway).')
                                ->danger()
                                ->send();

                            // Kept: deleting it would orphan the account on the server and lose its username.
                            return;
                        }
                        $this->redirect(ServiceResource::getUrl());
                    }),
                // SVC-14b: another plan of its product (another cycle), now or from the next renewal, with what it does.
                Action::make('changeCycle')
                    ->label('Change billing cycle')
                    ->visible(fn (Service $record) => ChangeCycle::plans($record)->isNotEmpty())
                    ->schema([
                        Select::make('plan_id')
                            ->label('New plan')
                            ->options(fn (Service $record) => ChangeCycle::plans($record)->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->name]))
                            ->required()
                            ->live(),
                        Radio::make('mode')
                            ->label('When')
                            ->options([
                                ChangeCycle::NEXT_RENEWAL => 'From the next renewal (nothing charged or credited now)',
                                ChangeCycle::NOW => 'Now (the unused days become credit; a new period starts today and is billed)',
                            ])
                            ->default(ChangeCycle::NEXT_RENEWAL)
                            ->required()
                            ->live(),
                        Placeholder::make('preview')
                            ->label('What it does')
                            ->content(fn (Get $get, Service $record) => self::cyclePreview($record, $get('plan_id'), (string) $get('mode'))),
                    ])
                    ->action(function (array $data, Service $record, Action $action): void {
                        $plan = ChangeCycle::plans($record)->firstWhere('id', (int) $data['plan_id']);
                        try {
                            if ($plan === null) {
                                throw new DisplayException('This billing cycle is not available for this service.');
                            }
                            $invoice = ChangeCycle::apply($record, $plan, $data['mode'], MoneyTrail::SOURCE_STAFF);
                        } catch (DisplayException $e) {
                            Notification::make()->title('Not changed')->body($e->getMessage())->danger()->send();
                            $action->halt();

                            return;
                        }
                        $record->refresh();
                        $this->refreshFormData(['plan_id', 'price', 'expires_at']);
                        $this->filledAttributes = $record->getAttributes();
                        Notification::make()->title('Billing cycle changed')
                            ->body($invoice ? "Invoice #{$invoice->id} bills the new period." : 'It applies when the next period is billed.')
                            ->success()->send();
                    }),

                // WHMCS's «Change Password» for staff: a new password at the server (cPanel's passwd), kept on the service once the
                // server took it. Its answer never repeats the password. «Generate» fills one that meets the rule; the welcome
                // mail may go with it right after (owner 2026-10-29).
                Action::make('serverPassword')
                    ->label(__('Change password on server'))
                    ->authorize('update')
                    ->visible(fn (Service $record) => $record->status === Service::STATUS_ACTIVE && self::changesPasswordOnServer($record))
                    ->modalDescription(__('The password is changed on the server, then stored on the service.'))
                    ->schema([
                        self::passwordInput()->rule(ServicePassword::rule())
                            ->suffixAction(Action::make('generatePassword')->label(__('Generate'))->icon('heroicon-m-sparkles')
                                ->action(fn (Set $set) => $set('password', ServicePassword::generate())))
                            ->helperText(__('At least 10 characters, with upper and lower case letters and numbers.')),
                        Checkbox::make('mysqlToo')->label('Change the MySQL password too')->default(false)
                            ->visible(fn (Service $record) => $record->provisioningServer()?->extension === 'CPanel') // cPanel's db_pass_update only
                            ->helperText('WHM\'s «db_pass_update»: the account\'s main MySQL user gets the new password too. Scripts that use it must then be updated.'),
                        self::sendWelcomeCheckbox(),
                    ])
                    ->action(function (array $data, Service $record, Action $action): void {
                        $password = (string) $data['password'];
                        try {
                            if (ExtensionHelper::callService($record, 'changePassword', [$password, (bool) ($data['mysqlToo'] ?? false)]) !== true) {
                                throw new Exception('the server did not confirm the change');
                            }
                        } catch (Exception $e) {
                            $reason = Redact::values($e->getMessage(), [$password]);
                            report(new Exception($reason));
                            // The server's own refusal: not changed. No answer (a timeout, an HTTP error): it may have been (ADV-3 of #318).
                            $refused = $e instanceof ServerRefused;
                            Notification::make()->title($refused ? 'Password not changed' : 'No clear answer from the server: the password may have changed')
                                ->body($refused ? $reason : 'Check by logging in with the new password before trying again. ' . $reason)->danger()->send();
                            $action->halt();

                            return;
                        }
                        // Stored? A module that changed it at the server but could not store it (cPanel reports it) leaves the old
                        // one here: never mailed as the new one.
                        $stored = WelcomeMail::password($record->refresh()) === $password;
                        self::auditPassword($record, 'password_changed_on_server');
                        $this->passwordStored();
                        Notification::make()->title(__('Password changed on the server'))->success()->send();
                        if (!$stored) {
                            Notification::make()->title(__('The new password could not be stored on the service'))
                                ->body(__('Save it with «Save a password set elsewhere»; no welcome email was sent.'))->warning()->persistent()->send();
                        } elseif ($data['sendWelcome'] ?? false) {
                            $this->resendWelcome($record);
                        }
                    }),

                // A password set elsewhere (by the customer on the server, by staff on WHM), stored here only, so the welcome
                // email sends the one that works (owner 2026-10-29). Nothing is asked of the server.
                Action::make('storePassword')
                    ->label(__('Save a password set elsewhere'))->icon('ri-key-2-line')->color('gray')
                    ->authorize('update')
                    ->visible(fn (Service $record) => !$record->isEnded())
                    ->modalDescription(__('Only stores the password on the service: nothing is changed on the server. For a password already set there (by the customer, or by staff on the server\'s own panel).'))
                    ->schema([
                        self::passwordInput()->label(__('Password as set on the server')),
                        self::sendWelcomeCheckbox(),
                    ])
                    ->action(function (array $data, Service $record): void {
                        $record->forceFill(['password' => (string) $data['password']])->save();
                        self::auditPassword($record, 'password_stored');
                        $this->passwordStored();
                        Notification::make()->title(__('Password stored on the service'))->body(__('Nothing was changed on the server.'))->success()->send();
                        if ($data['sendWelcome'] ?? false) {
                            $this->resendWelcome($record);
                        }
                    }),

                // WHMCS's «Resend Welcome Email» (owner 2026-10-29): the same mail, with the password stored now, to the owner.
                Action::make('resendWelcome')
                    ->label(__('Resend welcome email'))->icon('ri-mail-send-line')->color('gray')
                    ->authorize('update')
                    ->visible(fn (Service $record) => in_array($record->status, WelcomeMail::STATUSES, true))
                    ->requiresConfirmation()
                    ->modalHeading(__('Resend welcome email'))
                    ->modalDescription(fn (Service $record) => WelcomeMail::refusal($record)
                        ?? __('The welcome email goes again to the account\'s owner (:email), with the login details and the password stored on the service now.', ['email' => WelcomeMail::recipient($record)]))
                    ->modalSubmitActionLabel(__('Send'))
                    ->modalSubmitAction(fn (Action $action, Service $record) => $action->disabled(WelcomeMail::refusal($record) !== null))
                    ->action(fn (Service $record) => $this->resendWelcome($record)),

                // An Openprovider SSL order request whose answer was lost and that is not found there (ssl:sync tells it): cleared
                // by staff after checking Openprovider, so the customer may order again (code review 2 of #345).
                Action::make('sslClearRequest')
                    ->label(fn (Service $record) => SslOrder::query()->where('service_id', $record->id)->whereNotNull('renew_requested_at')->exists()
                        ? 'Clear the unseen SSL renewal' : 'Clear the unanswered SSL order request')->icon('ri-lock-unlock-line')->color('warning')
                    ->authorize('update')
                    ->visible(fn (Service $record) => ($order = SslOrder::query()->where('service_id', $record->id)->first()) !== null
                        && OpenproviderSsl::stuck($order))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Service $record) => SslOrder::query()->where('service_id', $record->id)->whereNotNull('renew_requested_at')->exists()
                        ? 'Only after checking at Openprovider that this order was NOT renewed (it is looked for once more first): the next sync may then renew it, and that renewal is charged.'
                        : 'Only after checking Openprovider has no order with this CSR (it is looked for once more first): the customer may then order again, and a second order is charged.')
                    ->action(function (Service $record): void {
                        $order = SslOrder::query()->where('service_id', $record->id)->sole();
                        $server = $record->provisioningServer() ?? throw new Exception('The service has no server');
                        ExtensionHelper::getExtension('server', $server->extension, $server->settings)->clearRequest($order);
                        Notification::make()->title('The SSL request was cleared')->success()->send();
                    }),

                // To another client (owner 2026-10-23, as WHMCS's «Move»): with its invoices not paid yet; staff with its own
                // permission, confirmed.
                Action::make('moveToClient')
                    ->label('Move to another client')->icon('ri-arrow-left-right-line')->color('gray')
                    ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.services.move'))
                    ->schema([
                        ClientComponent::picker('to_client_id')->label('Move to client'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('The service moves to the other client. Its unpaid invoices go with it (one that also bills other items is split: its lines for this service go to a new invoice of the new client); paid ones stay. Its related contact is cleared. A note is left on both accounts.')
                    ->action(function (array $data, Service $record, Action $action): void {
                        $staff = Auth::guard('staff')->user();
                        abort_unless((bool) $staff?->hasPermission('admin.services.move'), 403);
                        $to = Client::query()->findOrFail((int) $data['to_client_id']);
                        try {
                            $result = app(MoveToClient::class)->service($record, $to, $staff);
                        } catch (DisplayException $e) {
                            Notification::make()->title('Not moved')->body($e->getMessage())->danger()->send();
                            $action->halt();

                            return;
                        }
                        $told = trim(($result['moved'] !== [] ? count($result['moved']) . ' unpaid invoice(s) moved with it. ' : '')
                            . ($result['split'] !== [] ? count($result['split']) . ' new invoice(s) issued to the new client for its lines.' : ''));
                        Notification::make()->title('Moved to ' . $to->name)->body($told !== '' ? $told : null)->success()->send();
                        $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
                    }),

                // An ended certificate (its paid order over, or cancelled): the service waits for a new CSR, and the customer's next
                // order is a new one, charged (R2-1 of #350).
                Action::make('sslStartOver')
                    ->label('Start a new SSL order')->icon('ri-restart-line')->color('warning')
                    ->authorize('update')
                    ->visible(fn (Service $record) => ($order = SslOrder::query()->where('service_id', $record->id)->first()) !== null && $order->order_id !== null
                        && $order->renew_requested_at === null && ($order->order_ends_at?->isPast() || in_array($order->status, ['CAN', 'EXP', 'REJ', 'FAI'], true)))
                    ->requiresConfirmation()
                    ->modalDescription('The certificate\'s order has ended. The service will wait for a new CSR, and the customer\'s next order is charged at Openprovider: only when the customer has paid for it.')
                    ->action(function (Service $record): void {
                        $order = SslOrder::query()->where('service_id', $record->id)->sole();
                        $server = $record->provisioningServer() ?? throw new Exception('The service has no server');
                        ExtensionHelper::getExtension('server', $server->extension, $server->settings)->startOver($order);
                        Notification::make()->title('The service waits for a new CSR')->success()->send();
                    }),

                Action::make('changeStatus')
                    ->label('Module command…')
                    // Opened at a command by the form's buttons (ServiceResource::MODULE_COMMANDS).
                    ->fillForm(fn (array $arguments) => ['action' => $arguments['action'] ?? null, 'sendNotification' => false])
                    ->schema([
                        Select::make('action')
                            ->label('Action')->live()
                            ->options([
                                'create' => 'Create server',
                                'suspend' => 'Suspend server',
                                'unsuspend' => 'Unsuspend server',
                                'terminate' => 'Terminate server',
                                'upgrade' => 'Change package (upgrade/downgrade)',
                            ])->required(),
                        Checkbox::make('sendNotification')
                            ->label('Send Notification')
                            ->default(false),
                        // A cPanel reseller that owns accounts: they are deleted with it only when its username is typed here
                        // (owner 2026-10-22); never by a cron or a cancellation.
                        TextInput::make('confirmUsername')->label('Reseller: type its username to delete its accounts too')
                            ->visible(fn ($get) => $get('action') === 'terminate')
                            ->helperText('Only for a cPanel reseller that owns other accounts: they are deleted with it. Leave empty otherwise.'),
                    ])
                    ->action(function (array $data, Service $record, Action $action): void {
                        try {
                            switch ($data['action']) {
                                case 'create':
                                    $sdata = ExtensionHelper::createServer($record);
                                    if ($data['sendNotification']) {
                                        // The service's user (order is null for services made by staff or the API); adapters
                                        // return true or the data for the email.
                                        NotificationHelper::serverCreatedNotification($record->client, $record, is_array($sdata) ? $sdata : []);
                                    }
                                    break;
                                case 'suspend':
                                    // A manual hold, saved before the adapter runs (pending/cancelled keep their status).
                                    $record->placeOnHold();
                                    $sdata = self::onServer(fn () => ExtensionHelper::suspendServer($record));
                                    if ($data['sendNotification'] && $record->status === Service::STATUS_SUSPENDED) {
                                        // WHMCS's «Service Suspension Notification» on a manual suspend too (sweep modules §7).
                                        NotificationHelper::serverSuspendedNotification($record->client, $record, is_array($sdata) ? $sdata : []);
                                    }
                                    break;
                                case 'unsuspend':
                                    $sdata = self::onServer(fn () => ExtensionHelper::unsuspendServer($record));
                                    // Only suspended -> active: a pending or cancelled service is not revived.
                                    if ($record->status === Service::STATUS_SUSPENDED) {
                                        $told = $record->toldOfSuspension(); // before: unsuspended, its suspension's date goes
                                        $record->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
                                        if ($data['sendNotification'] && $told) {
                                            // WHMCS's «Service Unsuspension Notification» on a manual unsuspend too; only of a
                                            // suspension the customer was told of (the cron's job may not have run yet).
                                            NotificationHelper::serverUnsuspendedNotification($record->client, $record, is_array($sdata) ? $sdata : []);
                                        } elseif ($data['sendNotification']) {
                                            Notification::make()->info()->title(__('No email sent'))
                                                ->body(__('The customer was never told of this suspension: no unsuspension email.'))->send();
                                        }
                                    }
                                    break;
                                case 'terminate':
                                    $typed = trim((string) ($data['confirmUsername'] ?? ''));
                                    // In this request only, never stored (A4 of #315): a cron or a Retry never sees it.
                                    if ($typed !== '') {
                                        CPanel::confirmTermination($record, $typed);
                                    }
                                    try {
                                        $sdata = ExtensionHelper::terminateServer($record);
                                    } finally {
                                        CPanel::clearConfirmation();
                                    }
                                    // Only once the server is gone (it threw otherwise).
                                    foreach ($record->markTerminated() as $left) {
                                        Notification::make()->title('Left open for staff')->body($left->getMessage())->warning()->persistent()->send();
                                    }
                                    break;
                                case 'upgrade':
                                    $sdata = ExtensionHelper::upgradeServer($record);
                                    break;
                            }
                        } catch (Exception $e) {
                            if (config('app.debug')) {
                                throw $e;
                            }
                            report($e);
                            Notification::make('Error')
                                ->title('Error occured while triggering the action:')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            $action->halt();
                        }
                        Notification::make('Success')
                            ->title('Action triggered successfully')
                            ->body('The action has been triggered successfully')
                            ->success()
                            ->send();
                    })
                    ->color('primary')
                    ->modalSubmitActionLabel('Trigger'),

                // Its nixpal license (L2): staff reissue whatever the limit, and set bindings or status by hand (WHMCS's
                // licensing admin page).
                // Only an Active one (a suspended or expired license is not moved: review R1).
                Action::make('reissueLicense')->label('Reissue license')->icon('ri-refresh-line')->color('gray')
                    ->visible(fn (Service $record) => $record->license?->status === License::STATUS_ACTIVE)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Its bindings are cleared and the next server that checks it takes it (staff: whatever the limit).')
                    ->action(function (Service $record) {
                        try {
                            Licenses::reissue($record->license, (string) request()->ip(), byStaff: true);
                        } catch (DisplayException $e) {
                            Notification::make()->danger()->title('Not reissued')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('License reissued')->send();
                        $this->refreshFormData(['license']);
                    }),
                Action::make('editLicense')->label('Edit license')->icon('ri-key-2-line')->color('gray')
                    ->visible(fn (Service $record) => $record->license !== null)
                    ->authorize('update')
                    ->fillForm(fn (Service $record) => $record->license->only(['status', 'valid_ip', 'valid_domain', 'valid_directory']))
                    ->schema([
                        Select::make('status')->required()->options(array_combine($s = [License::STATUS_ACTIVE, License::STATUS_REISSUED, License::STATUS_SUSPENDED, License::STATUS_EXPIRED], $s)),
                        // Text columns, as WHMCS's (a list can be long: review R4).
                        Textarea::make('valid_ip')->label('Valid IPs')->rows(2)->helperText('Comma separated. Empty: the next check binds it.'),
                        Textarea::make('valid_domain')->label('Valid domains')->rows(2)->helperText('Information only (bound by IP).'),
                        Textarea::make('valid_directory')->label('Valid directory')->rows(1),
                    ])
                    ->action(function (Service $record, array $data) {
                        $record->license->update(array_map(fn ($value) => $value === '' ? null : $value, $data)); // audited
                        Notification::make()->success()->title('License saved')->send();
                        $this->refreshFormData(['license']);
                    }),
            ])->label('More')->icon('heroicon-o-chevron-down')->iconPosition('after')->button()->color('gray'),
            AuditAction::make()->auditChildren([
                'order',
                'invoices',
                'properties',
                'configs',
                'invoiceItems',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        /** @var Service $record */
        $record = $this->getRecord();
        if (!$record->cancellation()->exists()) {
            return [];
        }

        return [
            ServiceResource\Widgets\CancellationOverview::class,
        ];
    }

    /** A service without a module (or a module without the action) is suspended or unsuspended here only, as the jobs do. */
    private static function onServer(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (NothingToDoOnServer) {
            return [];
        }
    }
}
