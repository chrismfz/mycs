<?php

namespace App\Livewire\Services;

use App\Exceptions\DisplayException;
use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\SslVersion;
use App\Models\User;
use App\Services\Licensing\Licenses;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\Ssl\SslVault;
use App\Support\Actor;
use App\Support\Redact;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use MyCS\SslTools\KeyType;
use Symfony\Component\HttpFoundation\RedirectResponse;

use function Livewire\store;

class Show extends Component
{
    public Service $service;

    #[Locked]
    public $buttons = [];

    #[Locked]
    public $views = [];

    #[Locked]
    public $fields = [];

    /** @var list<array{app: string, icon: string, label: string}> the panel's shortcuts (WHMCS's «Quick Shortcuts») */
    #[Locked]
    public array $shortcuts = [];

    /** The server changes the account's password (cPanel: WHMCS's «Change Password»). */
    #[Locked]
    public bool $canChangePassword = false;

    /** Its password change may change the MySQL user's too (cPanel's «db_pass_update»; not mailcow). */
    #[Locked]
    public bool $passwordHasMysql = false;

    /** The domain a new mailbox is made on (cPanel: WHMCS's «Quick Create Email Account»); null: none. */
    #[Locked]
    public ?string $mailboxDomain = null;

    /** An SSL certificate of Openprovider's (its order, CSR and validation on this page). */
    #[Locked]
    public bool $hasSsl = false;

    public string $sslCsr = '';

    /** The CSR's name, once checked; the approver addresses Openprovider allows for it. Set here only. */
    #[Locked]
    public ?string $sslName = null;

    /** @var list<string> */
    #[Locked]
    public array $sslApprovers = [];

    public string $sslMethod = 'dns';

    /** «Generate it for me» or «I have a CSR» (docs/ssl-vault.md). */
    public string $sslMode = 'generate';

    /** The domain a key's CSR is made for: «example.gr» (and its www) or «*.example.gr» (and example.gr). */
    public string $sslDomain = '';

    public string $sslKeyType = 'rsa2048';

    /** A reissue's key: a new one (the default, owner 2026-10-24), or the current one kept. */
    public string $sslReissueKey = 'new';

    /** @var list<array{code: string, status: string, values: array<string, string>}> the version in use, checked (SslVault::check) */
    #[Locked]
    public array $sslChecks = [];

    /** A CSR or certificate pasted to be read: never a key (SslVault::decode). */
    public string $sslDecodeText = '';

    /** @var list<array{code: string, status: string, values: array<string, string>}> */
    #[Locked]
    public array $sslDecoded = [];

    public ?string $sslApprover = null;

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /** WHM's «db_pass_update»: the account's MySQL user gets the new password too (unchecked, as WHM). */
    public bool $changeMysqlToo = false;

    public string $mailboxName = '';

    public string $mailboxPassword = '';

    #[Url('tab', except: false), Locked]
    public $currentView;

    #[Url('cancel', except: false)]
    public bool $showCancel = false;

    #[Url('label', except: false)]
    public bool $editLabel = false;

    public ?string $label = null;

    public function mount()
    {
        // A link (`?cancel=1`, `?label=1`) opens no form for a login that may not manage the service (PR 2b).
        if (Gate::denies('update', $this->service)) {
            $this->showCancel = false;
            $this->editLabel = false;
        }
        // Only fetch the actions if the service is active
        if ($this->service->status == Service::STATUS_ACTIVE) {
            $actions = [];
            try {
                $actions = ExtensionHelper::getActions($this->service);
            } catch (Exception $e) {
            }
            // separate the actions into buttons and views
            $canManage = Gate::allows('update', $this->service);
            foreach ($actions as $action) {
                // Buttons manage the service (`manageproducts`), or sign into its panel (`sso`: `productsso`); a
                // login without the permission does not get the button, nor a link computed for it.
                if ($action['type'] == 'button' && !(!empty($action['sso']) ? $this->ownsWith('productsso') : $canManage)) {
                    continue;
                }
                if ($action['type'] == 'button') {
                    $this->buttons[] = $action;
                } elseif ($action['type'] == 'view') {
                    $this->views[] = $action;
                } elseif ($action['type'] == 'text') {
                    $this->fields[] = $action;
                } elseif ($action['type'] === 'shortcuts' && $this->ownsWith('productsso')) {
                    $this->shortcuts = array_values((array) ($action['items'] ?? []));
                } elseif ($action['type'] === 'password' && $canManage) {
                    $this->canChangePassword = true;
                    $this->passwordHasMysql = !empty($action['mysql']);
                } elseif ($action['type'] === 'ssl') {
                    $this->hasSsl = true;
                } elseif ($action['type'] === 'email' && $canManage && filled($action['domain'] ?? null)) {
                    $this->mailboxDomain = (string) $action['domain'];
                }
            }
            $this->currentView = $this->currentView ?? ($this->views[0]['name'] ?? null);
            if ($this->hasSsl) {
                $this->sslDomain = (string) SslOrder::query()->where('service_id', $this->service->id)->value('common_name');
            }
        }
        $this->label = $this->service->label;
        $this->contactId = $this->service->contact_id;
    }

    /**
     * Who sees the related contact: a login with `contacts` on the service's account (review 1-2: not the session's
     * current account); who sets it, one that also manages the service.
     */
    private function canSeeContact(): bool
    {
        return $this->ownsWith('contacts');
    }

    private function canEditContact(): bool
    {
        return Gate::allows('update', $this->service) && $this->canSeeContact();
    }

    /** The related contact chosen: one of the account's active contacts, or none. */
    public function updatedContactId($value)
    {
        abort_unless($this->canEditContact(), 403);
        $id = $value === '' || $value === null ? null : (int) $value;
        if ($id !== null && !$this->service->client->contacts()->active()->whereKey($id)->exists()) {
            $this->contactId = $this->service->contact_id;
            $this->notify(__('This contact is not available.'), 'error');

            return;
        }
        $this->service->update(['contact_id' => $id]);
        $this->notify(__('services.related_contact_saved'), 'success');
    }

    /** The related contact (information only, docs/accounts.md 3b). */
    public $contactId = null;

    /** The plan of another cycle the customer moves to, from the next renewal (SVC-14b). */
    public $cyclePlan = null;

    public bool $showCycle = false;

    /** Change the billing cycle from the next renewal: nothing charged now, the next renewal bills the new one. */
    public function changeCycle()
    {
        $this->authorize('update', $this->service); // `manageproducts`
        $plan = ChangeCycle::plans($this->service)->firstWhere('id', (int) $this->cyclePlan);
        if ($plan === null) {
            $this->notify(__('This billing cycle is not available for this service.'), 'error');

            return;
        }
        try {
            ChangeCycle::apply($this->service, $plan, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->service->refresh();
        $this->showCycle = false;
        $this->notify(__('The billing cycle changes from your next renewal.'), 'success');
    }

    public function updateLabel()
    {
        $this->authorize('update', $this->service);
        $this->validate([
            'label' => 'nullable|string|max:255',
        ]);

        $this->service->label = $this->label;
        $this->service->save();

        $this->editLabel = false;
        $this->notify(__('Service label updated successfully'), 'success');
    }

    /**
     * The service's password, for «Show» (sweep §6 #7): never in the page, sent only when asked, to who may manage the
     * service, at most 10 times a minute per login. Renderless: kept out of the component's snapshot.
     */
    #[Renderless]
    public function revealPassword(): ?string
    {
        $this->authorize('update', $this->service);
        if (RateLimiter::tooManyAttempts($key = 'reveal-password:' . Auth::id(), 10)) {
            $this->notify(__('services.reveal_too_many'), 'error');

            return null;
        }
        RateLimiter::hit($key, 60);

        return $this->service->password;
    }

    /**
     * The customer's Reissue (WHMCS «Reissue License»): who may manage the service, when the product allows it, under the
     * limit (Licenses::canReissue, checked again under the lock). The next check binds the license to its new server.
     */
    public function reissueLicense(): void
    {
        $this->authorize('update', $this->service);
        $license = $this->service->license;
        if ($license === null) {
            return;
        }
        try {
            Licenses::reissue($license, (string) request()->ip());
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->service->unsetRelation('license');
        $this->notify(__('services.license.reissued'));
    }

    /**
     * The custom fields the license reports (the product's «Custom Fields Reported», as zlic.php sends them).
     *
     * @return array<string, string>
     */
    private function licenseFields(): array
    {
        return Licenses::reportedFields($this->service);
    }

    public function changeView($view)
    {
        if (!$view) {
            return;
        }
        if ($this->currentView === $view || !in_array($view, array_column($this->views, 'name'))) {
            return $this->skipRender();
        }
        $this->currentView = $view;
    }

    public function updatedShowCancel($value)
    {
        if ($value && Gate::denies('update', $this->service)) {
            $this->showCancel = false;

            return $this->notify(__('You do not have access to this area of the account.'), 'error');
        }
        if (!$this->service->cancellable) {
            $this->notify(__('This service cannot be cancelled'), 'error');
            $this->showCancel = false;

            return;
        }
    }

    public function goto($function)
    {
        // Active now, not only when the page opened (ADV-2 of #318): no panel opened on a service suspended since.
        if ($this->service->fresh()?->status !== Service::STATUS_ACTIVE) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        // Check if function is allowed
        if (!in_array($function, array_column($this->buttons, 'function'))) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        // A server's buttons manage the service (`manageproducts`); one marked as a single sign-on into its panel
        // needs `productsso` (WHMCS names, docs/accounts.md, PR 2b).
        $button = collect($this->buttons)->firstWhere('function', $function);
        $allowed = !empty($button['sso']) ? $this->ownsWith('productsso') : Gate::allows('update', $this->service);
        if (!$allowed) {
            $this->notify(__('You do not have access to this area of the account.'), 'error');

            return;
        }
        $result = ExtensionHelper::callService($this->service, $function);
        // A redirect, as an address, as a response, or one the extension already asked Livewire for (redirect()->away()
        // inside a Livewire call stores it at once: ADV-1 of #323): only to a web page (panelUrl).
        if ($this->refusedPendingRedirect()) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        if ($result instanceof RedirectResponse) {
            $result = $result->getTargetUrl();
        }
        if (!is_string($result)) {
            return $result;
        }
        if (($url = self::panelUrl($result)) === null) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        $this->redirect($url);
    }

    /** A redirect the extension already asked Livewire for, dropped when it is not a web page (panelUrl). */
    private function refusedPendingRedirect(): bool
    {
        $pending = store($this)->get('redirect');
        if (is_string($pending) && self::panelUrl($pending) === null) {
            store($this)->set('redirect', null);

            return true;
        }

        return false;
    }

    /**
     * An address a server gave to open its panel, if it is a web page: http(s) with a host, nothing else (a server
     * that answers `javascript:` would run it on this site, where the customer and staff are signed in).
     */
    public static function panelUrl(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1 || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * A shortcut into the panel (WHMCS's «Quick Shortcuts»): `productsso`, one of those offered, signed in on that page.
     */
    public function openShortcut(string $app)
    {
        // Active now, not only when the page opened (ADV-1 of #318: suspended since).
        if ($this->service->fresh()?->status !== Service::STATUS_ACTIVE) {
            $this->notify(__('services.cpanel.failed'), 'error');

            return;
        }
        if (!in_array($app, array_column($this->shortcuts, 'app'), true) || !$this->ownsWith('productsso')) {
            $this->notify(__('You do not have access to this area of the account.'), 'error');

            return;
        }
        try {
            $url = ExtensionHelper::callService($this->service, 'getLoginUrl', [$app]);
        } catch (Exception $e) {
            // A redirect asked for before the failure goes nowhere (ADV-2 of #323).
            store($this)->set('redirect', null);
            report($e);
            $this->notify(__('services.cpanel.failed'), 'error');

            return;
        }
        if (!$this->refusedPendingRedirect() && is_string($url) && ($url = self::panelUrl($url)) !== null) {
            $this->redirect($url);
        } else {
            $this->notify(__('services.cpanel.failed'), 'error');
        }
    }

    /**
     * A new password for the account at its server (WHMCS's «Change Password»): `manageproducts`, a few a quarter-hour,
     * the server's strength rules on top of ours; its answer never repeats the password.
     */
    public function changeServicePassword(): void
    {
        $this->authorize('update', $this->service);
        if (!$this->canChangePassword || $this->service->status !== Service::STATUS_ACTIVE) {
            return;
        }
        $this->validate([
            'newPassword' => ['required', 'string', 'max:128', Password::min(10)->letters()->mixedCase()->numbers()],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ]);
        $password = $this->newPassword;
        $this->reset('newPassword', 'newPasswordConfirmation'); // never kept, whatever follows (ADV-6)
        if (RateLimiter::tooManyAttempts($key = 'service-password:' . $this->service->id, 5)) {
            $this->notify(__('services.cpanel.too_many'), 'error');

            return;
        }
        RateLimiter::hit($key, 900);
        try {
            // Never «may fail»: that path reports the error unredacted and returns null (a refusal told as success).
            if (ExtensionHelper::callService($this->service, 'changePassword', [$password, $this->passwordHasMysql && $this->changeMysqlToo]) !== true) {
                throw new Exception('the server did not confirm the change');
            }
        } catch (Exception $e) {
            $this->serverFailed($e, $password, 'password_refused', 'password_unknown');

            return;
        }
        $this->notify(__('services.cpanel.password_changed'), 'success');
    }

    /** The CSR checked (its name, against the product) before anything is ordered; the approver addresses for it. */
    public function sslCheck(): void
    {
        if (!$this->sslMayOrder() && !$this->sslMayReissue()) {
            return;
        }
        $this->validate(['sslCsr' => ['required', 'string', 'max:16384', 'regex:/-----BEGIN (NEW )?CERTIFICATE REQUEST-----/']]);
        $this->reset('sslName', 'sslApprovers', 'sslApprover');
        if ($this->sslLimited('check', 20)) {
            return;
        }
        try {
            $info = ExtensionHelper::callService($this->service, 'decodeCsr', [$this->sslCsr]);
            $this->sslApprovers = (array) ExtensionHelper::callService($this->service, 'approverEmails', [(string) ($info['common_name'] ?? '')]);
            $this->sslName = (string) $info['common_name'];
        } catch (Exception $e) {
            $this->sslFailed($e);
        }
    }

    /**
     * A key and its CSR made here for the domain (kept encrypted in the vault), then checked as a pasted CSR would be:
     * the order or the reissue goes on as before. A few an hour per service, and a day per account.
     */
    public function sslGenerate(): void
    {
        $reissue = !$this->sslMayOrder() && $this->sslMayReissue();
        if (!$reissue && !$this->sslMayOrder()) {
            return;
        }
        $this->validate([
            'sslDomain' => ['required', 'string', 'max:253'],
            'sslKeyType' => ['required', Rule::enum(KeyType::class)],
            'sslReissueKey' => ['required', 'in:new,keep'],
        ]);
        $this->reset('sslName', 'sslApprovers', 'sslApprover');
        if ($this->sslLimited('generate', 5) || $this->sslLimitedAccount('generate', 20)) {
            return;
        }
        $order = SslOrder::query()->where('service_id', $this->service->id)->sole();
        $vault = app(SslVault::class);
        try {
            $names = SslVault::namesFor($this->sslDomain);
            $keep = $reissue && $this->sslReissueKey === 'keep' ? $vault->current($order) : null;
            if ($reissue && $this->sslReissueKey === 'keep' && $keep === null) {
                throw new ServerRefused(__('services.ssl.vault.no_key_to_keep'));
            }
            $version = $vault->generate($order, KeyType::from($this->sslKeyType), $names, $keep);
        } catch (ServerRefused $e) {
            $this->notify(__('services.ssl.refused', ['reason' => $e->getMessage()]), 'error');

            return;
        }
        $this->sslCsr = (string) $version->csr;
        $this->sslCheck();
    }

    /**
     * The version in use checked (docs/ssl-vault.md, PR 2): whoever sees the service. Its key is read for the match
     * only, never shown. A few an hour.
     */
    public function sslRunChecks(): void
    {
        $this->authorize('view', $this->service);
        $order = $this->hasSsl ? SslOrder::query()->where('service_id', $this->service->id)->first() : null;
        $current = $order !== null ? app(SslVault::class)->current($order) : null;
        if ($current === null || $this->sslLimited('verify', 30)) {
            return;
        }
        $this->sslChecks = app(SslVault::class)->check($current);
    }

    /** A pasted CSR or certificate read (nothing kept); a pasted key refused and cleared at once. */
    public function sslDecode(): void
    {
        $this->authorize('view', $this->service);
        $this->sslDecoded = [];
        // A key pasted here is cleared first, before any other answer could leave it in the page (review 1 of #438).
        if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----|PRIVATE KEY/', $this->sslDecodeText) === 1) {
            $this->sslDecodeText = '';
            $this->notify(__('services.ssl.checks.no_keys_here'), 'error');

            return;
        }
        if (!$this->hasSsl) {
            return;
        }
        $this->validate(['sslDecodeText' => ['required', 'string', 'max:65536']]);
        if ($this->sslLimited('verify', 30)) {
            return;
        }
        try {
            $this->sslDecoded = SslVault::decode($this->sslDecodeText);
        } catch (ServerRefused $e) {
            $this->sslDecodeText = '';
            $this->notify($e->getMessage(), 'error');
        }
    }

    /** Another CSR is checked again. */
    public function updatedSslCsr(): void
    {
        $this->reset('sslName', 'sslApprovers', 'sslApprover');
    }

    /** The certificate ordered (charged at Openprovider): once, for the CSR checked, with the validation chosen. */
    public function sslOrder(): void
    {
        if (!$this->sslMayOrder() || $this->sslName === null) {
            return;
        }
        $this->validate([
            'sslMethod' => ['required', 'in:' . implode(',', SslOrder::METHODS)],
            'sslApprover' => ['nullable', 'required_if:sslMethod,email', 'in:' . implode(',', $this->sslApprovers ?: [''])],
        ]);
        if ($this->sslLimited('order', 5)) {
            return;
        }
        try {
            if (ExtensionHelper::callService($this->service, 'placeOrder', [$this->sslCsr, $this->sslMethod, $this->sslMethod === 'email' ? $this->sslApprover : null]) !== true) {
                throw new Exception('the order was not confirmed');
            }
        } catch (Exception $e) {
            $this->sslFailed($e);

            return;
        }
        $this->reset('sslCsr', 'sslName', 'sslApprovers', 'sslApprover');
        $this->notify(__('services.ssl.ordered'), 'success');
    }

    /** A new certificate for the same name (not charged): for the CSR checked, with the validation chosen. */
    public function sslReissue(): void
    {
        if (!$this->sslMayReissue() || $this->sslName === null) {
            return;
        }
        $this->validate([
            'sslMethod' => ['required', 'in:' . implode(',', SslOrder::METHODS)],
            'sslApprover' => ['nullable', 'required_if:sslMethod,email', 'in:' . implode(',', $this->sslApprovers ?: [''])],
        ]);
        if ($this->sslLimited('reissue', 5)) {
            return;
        }
        try {
            if (ExtensionHelper::callService($this->service, 'reissue', [$this->sslCsr, $this->sslMethod, $this->sslMethod === 'email' ? $this->sslApprover : null]) !== true) {
                throw new Exception('the reissue was not confirmed');
            }
        } catch (Exception $e) {
            $this->sslFailed($e);

            return;
        }
        $this->reset('sslCsr', 'sslName', 'sslApprovers', 'sslApprover');
        $this->notify(__('services.ssl.reissued'), 'success');
    }

    /** The approver addresses Openprovider allows for the order's name, to choose another (email validation). */
    public function sslLoadApprovers(): void
    {
        $order = $this->sslAwaitingEmail();
        if ($order === null || $this->sslLimited('check', 20)) {
            return;
        }
        try {
            $this->sslApprovers = (array) ExtensionHelper::callService($this->service, 'approverEmails', [(string) $order->common_name]);
        } catch (Exception $e) {
            $this->sslFailed($e);
        }
    }

    /** Another approver address, one Openprovider allows. */
    public function sslChangeApprover(): void
    {
        if ($this->sslAwaitingEmail() === null) {
            return;
        }
        $this->validate(['sslApprover' => ['required', 'in:' . implode(',', $this->sslApprovers ?: [''])]]);
        if ($this->sslLimited('approver', 5)) {
            return;
        }
        try {
            ExtensionHelper::callService($this->service, 'changeApproverEmail', [(string) $this->sslApprover]);
        } catch (Exception $e) {
            $this->sslFailed($e);

            return;
        }
        $this->reset('sslApprovers', 'sslApprover');
        $this->notify(__('services.ssl.approver_changed'), 'success');
    }

    /** `manageproducts`, an active service, a certificate issued whose order has not ended. */
    private function sslMayReissue(): bool
    {
        $this->authorize('update', $this->service);
        $order = SslOrder::query()->where('service_id', $this->service->id)->first();

        // Never during a renewal (OpenproviderSsl::reissue refuses it): no key made for nothing (review 8).
        return $this->hasSsl && $this->service->status === Service::STATUS_ACTIVE && $order?->order_id !== null && $order->issued() && $order->status === 'ACT'
            && ($order->order_ends_at === null || $order->order_ends_at->isFuture()) && $order->renew_requested_at === null;
    }

    /** `manageproducts`, an active service, an order waiting for its approver's email. */
    private function sslAwaitingEmail(): ?SslOrder
    {
        $this->authorize('update', $this->service);
        $order = SslOrder::query()->where('service_id', $this->service->id)->first();

        return $this->hasSsl && $this->service->status === Service::STATUS_ACTIVE && $order !== null && $order->waiting()
            && $order->validation_method === 'email' ? $order : null;
    }

    /** Openprovider's approver email sent again. */
    public function sslResend(): void
    {
        $this->authorize('update', $this->service);
        if (!$this->hasSsl || $this->service->status !== Service::STATUS_ACTIVE || $this->sslLimited('resend', 3)) {
            return;
        }
        try {
            ExtensionHelper::callService($this->service, 'resendApproverEmail');
        } catch (Exception $e) {
            $this->sslFailed($e);

            return;
        }
        $this->notify(__('services.ssl.resent'), 'success');
    }

    /** `manageproducts`, an active service, a certificate not ordered yet. */
    private function sslMayOrder(): bool
    {
        $this->authorize('update', $this->service);

        return $this->hasSsl && $this->service->status === Service::STATUS_ACTIVE
            && (SslOrder::query()->where('service_id', $this->service->id)->first()?->awaitingConfiguration() ?? false);
    }

    private function sslLimited(string $action, int $max): bool
    {
        if (RateLimiter::tooManyAttempts($key = "service-ssl-{$action}:" . $this->service->id, $max)) {
            $this->notify(__('services.ssl.too_many'), 'error');

            return true;
        }
        RateLimiter::hit($key, 3600);

        return false;
    }

    /** A per-account limit, over a day (a key a few times a day is plenty; many services do not multiply it). */
    private function sslLimitedAccount(string $action, int $max): bool
    {
        if (RateLimiter::tooManyAttempts($key = "client-ssl-{$action}:" . $this->service->client_id, $max)) {
            $this->notify(__('services.ssl.too_many'), 'error');

            return true;
        }
        RateLimiter::hit($key, 86400);

        return false;
    }

    /**
     * What the page shows of the vault: the versions ordered (newest first) and the last key made since, which one is
     * in use, and whether this login may have its keys.
     *
     * @return array{sslVersions: Collection<int, SslVersion>, sslCurrent: ?SslVersion, sslMayKey: bool}
     */
    private function sslVaultView(?SslOrder $order): array
    {
        if ($order === null) {
            return ['sslVersions' => collect(), 'sslCurrent' => null, 'sslMayKey' => false];
        }
        $versions = $order->versions()->orderByDesc('number')->get();
        $lastOrdered = $versions->first(fn (SslVersion $v) => $v->ordered_at !== null);
        $shown = $versions->filter(fn (SslVersion $v) => $v->ordered_at !== null || $v->number > (int) $lastOrdered?->number)
            ->unique(fn (SslVersion $v) => $v->ordered_at !== null ? "o{$v->id}" : 'draft')->values();

        return ['sslVersions' => $shown, 'sslCurrent' => app(SslVault::class)->current($order), 'sslMayKey' => SslVault::customerMayReadKey($this->service)];
    }

    /** Openprovider's refusal told with its reason; anything else (not known) told as such, its detail reported. */
    private function sslFailed(Exception $e): void
    {
        report($e);
        $this->notify($e instanceof ServerRefused ? __('services.ssl.refused', ['reason' => Redact::text($e->getMessage())]) : __('services.ssl.failed'), 'error');
    }

    /**
     * A mailbox on the service's domain (WHMCS's «Quick Create Email Account»): `manageproducts`, a few an hour; its
     * password is not kept here.
     */
    public function createMailbox(): void
    {
        $this->authorize('update', $this->service);
        if ($this->mailboxDomain === null || $this->service->status !== Service::STATUS_ACTIVE) {
            return;
        }
        $this->validate([
            'mailboxName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9])?$/', 'not_regex:/\.\./'],
            'mailboxPassword' => ['required', 'string', 'max:128', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);
        $password = $this->mailboxPassword;
        $this->reset('mailboxPassword'); // never kept, whatever follows (ADV-6)
        if (RateLimiter::tooManyAttempts($key = 'service-mailbox:' . $this->service->id, 10)) {
            $this->notify(__('services.cpanel.too_many'), 'error');

            return;
        }
        RateLimiter::hit($key, 3600);
        try {
            $address = ExtensionHelper::callService($this->service, 'createEmailAccount', [$this->mailboxName, $password]);
            if (!is_string($address) || $address === '') {
                throw new Exception('the server did not confirm the mailbox');
            }
        } catch (Exception $e) {
            $this->serverFailed($e, $password, 'mailbox_refused', 'mailbox_unknown');

            return;
        }
        $this->reset('mailboxName');
        $this->notify(__('services.cpanel.mailbox_created', ['address' => (string) $address]), 'success');
    }

    /**
     * The server's failure told: its own refusal with its reason (the password taken out); no answer (a timeout, an HTTP
     * error) as «may have been done», never as refused, nor with the server's address or body (ADV-3, 4 of #318). Reported
     * without the password either way.
     */
    private function serverFailed(Exception $e, string $password, string $refused, string $unknown): void
    {
        $reason = Redact::values($e->getMessage(), [$password]);
        report(new Exception($reason));
        if ($e instanceof ServerRefused) {
            $this->notify(__('services.cpanel.' . $refused, ['reason' => $reason]), 'error');

            return;
        }
        $this->notify(__('services.cpanel.' . $unknown), 'error');
    }

    /** The login may do this on the service's account (staff in «Login as client» may do all). */
    private function ownsWith(string $permission): bool
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof User) {
            return false;
        }
        if (Actor::impersonating()) {
            return Actor::staffMayAs($permission); // only what they may do in the admin (SA-5)
        }

        return (bool) ClientUser::query()->where('client_id', $this->service->client_id)->where('user_id', $user->id)
            ->whereNull('revoked_at')->first()?->allows($permission);
    }

    public function render()
    {
        $view = null;
        $previousView = $this->currentView;

        if ($this->currentView) {
            try {
                // Search array for the current view
                $currentViewObj = $this->views[array_search($this->currentView, array_column($this->views, 'name'))] ?? null;
                if (!$currentViewObj) {
                    throw new Exception('View not found');
                }
                $view = ExtensionHelper::getView($this->service, $currentViewObj);
            } catch (Exception $e) {
                if ($previousView !== $this->views[0]['name'] ?? null) {
                    $this->notify(__('Got an error while trying to load the view'), 'error');
                }
                $this->currentView = $this->views[0]['name'] ?? null;
            }
        }

        $canEditContact = $this->canEditContact();
        $options = [];
        if ($canEditContact) {
            $options = $this->service->client->contacts()->active()->orderBy('id')->get()
                ->mapWithKeys(fn ($contact) => [$contact->id => $contact->displayName()])->all();
            // The one it has, archived since: shown as it is (else the select would read «None»).
            $current = $this->service->contact;
            if ($current !== null && !isset($options[$current->id])) {
                $options[$current->id] = $current->displayName() . ' (' . __('services.related_contact_archived') . ')';
            }
        }

        return view('services.show', [
            'extensionView' => $view,
            'canEditContact' => $canEditContact,
            'canSeeContact' => $canEditContact || $this->canSeeContact(),
            'contactOptions' => $options,
            'licenseFields' => $this->licenseFields(),
            'sslOrder' => $sslOrder = $this->hasSsl ? SslOrder::query()->where('service_id', $this->service->id)->first() : null,
            ...$this->sslVaultView($sslOrder),
        ])->layoutData([
            'title' => $this->service->label,
            'sidebar' => true,
        ]);
    }
}
