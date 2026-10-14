<?php

namespace App\Livewire\Services;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Support\Actor;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    public Service $service;

    #[Locked]
    public $buttons = [];

    #[Locked]
    public $views = [];

    #[Locked]
    public $fields = [];

    #[Url('tab', except: false), Locked]
    public $currentView;

    #[Url('cancel', except: false)]
    public bool $showCancel = false;

    public bool $showBillingAgreement = false;

    #[Url('label', except: false)]
    public bool $editLabel = false;

    public ?string $label = null;

    public $selectedMethod;

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

    public function updatedShowBillingAgreement()
    {
        if (Gate::denies('update', $this->service)) {
            $this->showBillingAgreement = false;

            return;
        }
        $this->selectedMethod = $this->service->client->billingAgreements()->where('id', $this->service->billing_agreement_id)->first()?->ulid;
    }

    public function updateBillingAgreement()
    {
        $this->authorize('update', $this->service); // `manageproducts` (docs/accounts.md, PR 2b)
        // The service's account's payment methods (docs/accounts.md, PR 2a), whichever account the client area is on.
        $agreement = $this->service->client->billingAgreements()->where('ulid', $this->selectedMethod)->first();
        if (!$agreement) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }
        $this->service->billing_agreement_id = $agreement->id;
        $this->service->save();

        $this->showBillingAgreement = false;
    }

    public function clearBillingAgreement()
    {
        $this->authorize('update', $this->service);
        $this->service->billing_agreement_id = null;
        $this->service->save();
        $this->selectedMethod = null;
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
        // If its a response, return it
        if (!is_string($result)) {
            return $result;
        }
        $this->redirect($result);
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
        ])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }
}
