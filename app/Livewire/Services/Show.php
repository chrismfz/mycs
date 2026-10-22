<?php

namespace App\Livewire\Services;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Service;
use App\Models\User;
use App\Services\Licensing\Licenses;
use App\Services\Licensing\MycsLicenseRepository;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Support\Actor;
use App\Support\Redact;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use MyCS\Extensions\Servers\CPanel\WhmRefused;
use Symfony\Component\HttpFoundation\RedirectResponse;

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

    /** The domain a new mailbox is made on (cPanel: WHMCS's «Quick Create Email Account»); null: none. */
    #[Locked]
    public ?string $mailboxDomain = null;

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
                } elseif ($action['type'] === 'email' && $canManage && filled($action['domain'] ?? null)) {
                    $this->mailboxDomain = (string) $action['domain'];
                }
            }
            $this->currentView = $this->currentView ?? ($this->views[0]['name'] ?? null);
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
        if ($this->service->license === null) {
            return [];
        }
        $reported = MycsLicenseRepository::fieldNames((string) (ExtensionHelper::settingsToArray($this->service->product->settings)['custom_fields'] ?? ''));

        return $this->service->properties()->whereIn('key', $reported)->where('value', '!=', '')->pluck('value', 'name')->all();
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
        // A redirect, as an address or as a response: only to a web page (panelUrl).
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
            report($e);
            $this->notify(__('services.cpanel.failed'), 'error');

            return;
        }
        if (is_string($url) && ($url = self::panelUrl($url)) !== null) {
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
            if (ExtensionHelper::callService($this->service, 'changePassword', [$password, $this->changeMysqlToo]) !== true) {
                throw new Exception('the server did not confirm the change');
            }
        } catch (Exception $e) {
            $this->serverFailed($e, $password, 'password_refused', 'password_unknown');

            return;
        }
        $this->notify(__('services.cpanel.password_changed'), 'success');
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
        if ($e instanceof WhmRefused) {
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
            return true;
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
        ])->layoutData([
            'title' => $this->service->label,
            'sidebar' => true,
        ]);
    }
}
