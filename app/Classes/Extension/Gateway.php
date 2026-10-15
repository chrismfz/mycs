<?php

namespace App\Classes\Extension;

use App\Models\BillingAgreement;
use App\Models\Card;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\View;

/**
 * Class Gateway
 */
abstract class Gateway extends Extension
{
    /**
     * Pay the given invoice with the given total amount.
     *
     * @param  mixed  $total
     * @return View|string
     */
    abstract public function pay(Invoice $invoice, $total);

    /**
     * Whether pay() and charge() carry the customer's Mass Pay group ($invoice->payingGroup, PR F2) to the provider and
     * its payment comes back with it (ExtensionHelper::addPayment(..., paymentGroup:)). Only such a gateway is offered
     * for a group: its payment is the one spread over the group's invoices, never a guess.
     */
    public function supportsPaymentGroups(): bool
    {
        return false;
    }

    /**
     * Check if gateway supports billing agreements.
     */
    public function supportsBillingAgreements(): bool
    {
        return false;
    }

    /**
     * Create a billing agreement (a saved payment method) for the given customer account.
     *
     * @param  string  $currencyCode
     * @return View|string
     */
    public function createBillingAgreement(Client $client)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Cancel the billing agreement associated with the given card.
     *
     * @return void
     */
    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Charge the given billing agreement for the given invoice and amount.
     *
     * @param  mixed  $total
     * @return bool
     */
    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Check if gateway supports refunds.
     */
    public function supportsRefunds(): bool
    {
        return false;
    }

    /**
     * Refund the given transaction for the given amount: true, or better the gateway's id of the refund (PR 2c-2), so its
     * webhook telling the same refund is recognised; false (or an exception) when it was not refunded.
     *
     * @param  float  $amount
     * @return string|bool
     */
    public function refund(InvoiceTransaction $transaction, $amount)
    {
        throw new \Exception('Not implemented');
    }
}
