<?php

namespace MyCS\Extensions\Gateways\BankTransfer;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use App\Services\Money\BankTransferDetails;
use Illuminate\Support\Facades\View;

/**
 * Bank transfer (myip, 43% of its payments; owner 2026-10-21): an offline method. It shows the company's bank accounts and
 * asks the customer to write the invoice's (proforma's) number in the transfer's reference; staff record the payment when
 * it reaches the bank (Add payment), as they do now. The same details go on an unpaid invoice's PDF and mail
 * (BankTransferDetails). Nothing is sent anywhere and nothing is paid from here.
 */
#[ExtensionMeta(
    name: 'Bank transfer',
    description: 'Show your bank accounts for a payment by bank transfer; staff record the payment when it arrives.',
    version: 'builtin',
    author: 'MyCS',
)]
class BankTransfer extends Gateway
{
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'accounts',
                'label' => 'Bank accounts',
                'type' => 'textarea',
                'required' => true,
                'description' => 'As the customer should read them: bank, beneficiary, IBAN, BIC; one account after the other, a blank line between them.',
            ],
            [
                'name' => 'currencies',
                'label' => 'Currencies',
                'type' => 'text',
                'required' => false,
                'description' => 'The currencies these accounts take, comma separated (EUR). Empty: any.',
            ],
            [
                'name' => 'show_on_invoices',
                'label' => 'Also on unpaid invoices (PDF and mail)',
                'type' => 'checkbox',
                'required' => false,
                'default' => true,
            ],
        ];
    }

    /** Offered only in a currency the accounts take (reviews of #287: never a USD invoice told to wire to EUR accounts). */
    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return BankTransferDetails::takes($this->config('currencies'), $currency);
    }

    /**
     * The accounts, what is left to pay and the reference to write: shown in the payment window.
     *
     * @param  float  $total
     * @return \Illuminate\Contracts\View\View
     */
    public function pay(Invoice $invoice, $total)
    {
        // A saved method pays it on its due date: a transfer too would be paid twice (adversarial BT-ADV-3).
        if (PaymentReminders::chargedOnItsOwn($invoice)) {
            return View::file(__DIR__ . '/resources/views/charged.blade.php', ['due' => $invoice->due_at?->format('d/m/Y')]);
        }

        return View::file(__DIR__ . '/resources/views/pay.blade.php', [
            'accounts' => (string) $this->config('accounts'),
            'reference' => BankTransferDetails::reference($invoice),
            'total' => (string) $invoice->formattedRemaining,
        ]);
    }
}
