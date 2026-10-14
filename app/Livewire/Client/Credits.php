<?php

namespace App\Livewire\Client;

use App\Support\CurrentClient;
use App\Attributes\DisabledIf;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Client;
use App\Services\Money\MoneyHistory;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\WithPagination;

#[DisabledIf('credits_enabled', reverse: true)]
class Credits extends Component
{
    use WithPagination;

    #[Validate('required|exists:currencies,code')]
    public $currency;

    public $amount;

    #[Locked]
    public $gateways = [];

    public $gateway;

    /** The customer lets credit pay open invoices on its own (MT-1e), or keeps it for something else. */
    public bool $autoApply = true;

    public function mount()
    {
        $this->autoApply = (bool) (CurrentClient::model()?->credit_auto_apply ?? true);
        $this->amount = config('settings.credits_minimum_deposit');
        $this->currency = session('currency', config('settings.default_currency'));
        $this->gateways = ExtensionHelper::getCheckoutGateways($this->amount, $this->currency, 'credits');
        if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
            $this->gateway = $this->gateways[0]->id;
        }
    }

    public function updated($variable)
    {
        if ($variable === 'amount' || $variable === 'currency') {
            $this->gateways = ExtensionHelper::getCheckoutGateways($this->amount, $this->currency, 'credits');
            if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
                $this->gateway = $this->gateways[0]->id;
            }
        }
    }

    public function updatedAutoApply(bool $value): void
    {
        CurrentClient::model()?->forceFill(['credit_auto_apply' => $value])->save();
        $this->notify($value ? __('account.credit_auto_apply_on') : __('account.credit_auto_apply_off'), 'success');
    }

    public function addCredit()
    {
        $this->validate([
            'currency' => 'required|exists:currencies,code',
            'amount' => 'required|numeric|min:' . config('settings.credits_minimum_deposit') . '|max:' . config('settings.credits_maximum_deposit'),
            'gateway' => 'required|in:' . implode(',', array_column($this->gateways, 'id')),
        ]);

        // Create invoice
        DB::beginTransaction();

        try {
            // The customer first, as every payment path does (RecordPaymentService): no lock-order deadlock.
            $client = CurrentClient::model();
            Client::lock($client->id);
            // Lock the account's invoices and credits
            $client->invoices()->lockForUpdate()->get();
            $credits = $client->credits()->where('currency_code', $this->currency)->lockForUpdate()->get();

            // Check if user has credits in this currency
            if ($credits->isNotEmpty()) {
                // Check if the current credits + the new credits exceed the maximum credits allowed
                if ($credits->sum('amount') + $this->amount > config('settings.credits_maximum_credit')) {
                    DB::rollBack();
                    $this->notify(__('You cannot exceed the maximum credits allowed.'), 'error');

                    return;
                }
            }

            // Check if the user has any unpaid invoice items referencing credits
            $unpaidInvoiceItems = $client->invoices()->where('status', Invoice::STATUS_UNPAID)->whereHas('items', function ($query) {
                $query->where('reference_type', Credit::class);
            })->exists();

            if ($unpaidInvoiceItems) {
                throw new DisplayException('You have an unpaid invoice for credits. Please pay the invoice before adding more credits.');
            }

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'currency_code' => $this->currency,
                'due_at' => now(),
                'status' => Invoice::STATUS_UNPAID,
            ]);

            $invoice->items()->create([
                'description' => __('account.credit_deposit', ['currency' => $this->currency]),
                'quantity' => 1,
                'price' => $this->amount,
                'reference_type' => Credit::class,
            ]);

            DB::commit();

            Session::put(['gateway' => $this->gateway]);

            // Redirect to the invoices page and pay the invoice
            if ($this->gateway) {
                $pay = ExtensionHelper::pay(Gateway::where('id', $this->gateway)->first(), $invoice->fresh());
                if (is_string($pay)) {
                    return $this->redirect($pay);
                }
            }

            return $this->redirect(route('invoices.show', $invoice) . '?gateway=' . $this->gateway . '&pay', true);
        } catch (Exception $e) {
            // Rollback the transaction
            DB::rollBack();
            // Return error message
            throw $e;
        }
    }

    public function render()
    {
        // The customer's credit history (decision 3, MT-1d): read only, without who, source or the staff reason.
        $history = MoneyHistory::creditEvents(CurrentClient::id())->with('invoice:id,client_id')
            ->paginate(config('settings.pagination', 15), pageName: 'history');

        return view('client.account.credits', ['history' => $history])->layoutData([
            'sidebar' => true,
            'title' => 'Add Credits',
        ]);
    }
}
