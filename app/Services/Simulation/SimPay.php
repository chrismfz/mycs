<?php

namespace App\Services\Simulation;

use App\Exceptions\DisplayException;
use App\Models\Gateway;
use App\Services\Invoice\RecordPaymentService;

/**
 * A card gateway that takes no money (`mycs:simulate`): it accepts a saved-method charge, or declines it for the clients it
 * is told to, and records the payment against the real gateway row of the agreement, so the money trail reads as it would.
 */
class SimPay
{
    public $explicitConfig;

    /** @var array<int, true> client ids whose card is declined */
    public static array $declined = [];

    /** @var list<array{invoice: int, client: int, amount: float, ok: bool}> */
    public static array $charges = [];

    public function supportsBillingAgreements()
    {
        return true;
    }

    public function cancelBillingAgreement($agreement): bool
    {
        return true;
    }

    public function charge($invoice, $amount, $agreement)
    {
        $ok = !isset(self::$declined[(int) $invoice->client_id]);
        self::$charges[] = ['invoice' => (int) $invoice->id, 'client' => (int) $invoice->client_id, 'amount' => (float) $amount, 'ok' => $ok];
        if (!$ok) {
            throw new DisplayException('Card declined: insufficient_funds');
        }
        (new RecordPaymentService)->handle($invoice, Gateway::query()->find($agreement->gateway_id), $amount, null, 'sim_ch_' . count(self::$charges), currency: $invoice->currency_code ?? null);

        return true;
    }
}
