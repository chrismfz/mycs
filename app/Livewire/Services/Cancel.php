<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Models\ServiceCancellation;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Validate;

class Cancel extends Component
{
    public Service $service;

    #[Validate('required|in:end_of_period,immediate')]
    public $type = 'end_of_period';

    #[Validate('required|max:255')]
    public $reason = '';

    public function cancelService()
    {
        $this->authorize('update', $this->service); // `manageproducts` (docs/accounts.md, PR 2b)

        // The form shows only for a cancellable service without a request: checked here too, a direct call skips the
        // form (audit sweep SVC-12): a one-time or free service, or a second request.
        if (!$this->service->cancellable) {
            return $this->notify(__('services.cancellation_not_possible'), 'error');
        }

        $this->validate();

        // Event hook will handle the cancellation (if its immediate or end of period). One request per service, held by
        // the database too (DATA-11): a double submit gets the message, not an error page.
        try {
            ServiceCancellation::create([
                'service_id' => $this->service->id,
                'type' => $this->type,
                'reason' => $this->reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->notify(__('services.cancellation_not_possible'), 'error');
        }

        $this->notify(__('services.cancellation_requested'), 'success', true);

        $this->redirect(route('services.show', $this->service), true);
    }

    public function render()
    {
        return view('services.cancel')->layoutData([
            'title' => __('services.cancellation', ['service' => $this->service->product->name]),
            'sidebar' => true,
        ]);
    }
}
