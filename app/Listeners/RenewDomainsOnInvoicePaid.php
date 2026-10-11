<?php

namespace App\Listeners;

use App\Events\Invoice\Paid;
use App\Services\Domains\DomainRenewalService;

/**
 * Renew at the registrar the domains an invoice paid for (on-paid policy, ROADMAP Phase 2).
 * A registrar failure must never make the payment look failed: it is reported (and stays
 * in domain_registrar_logs as 'failed') for the operator to retry.
 */
class RenewDomainsOnInvoicePaid
{
    public function __construct(private readonly DomainRenewalService $renewals) {}

    public function handle(Paid $event): void
    {
        try {
            $this->renewals->renewForInvoice($event->invoice);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
