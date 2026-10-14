<?php

namespace App\Livewire\Services;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Service;
use App\Models\User;
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
    }

    public function updatedShowBillingAgreement()
    {
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

        return view('services.show', ['extensionView' => $view])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }
}
