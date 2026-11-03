<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Carbon\CarbonImmutable;

/**
 * What an invoice's page tells the customer before any click (docs/pay-flow-ux.md, PR 2): whether they need to do
 * anything, and why they cannot pay now. Read from the same rules the payment follows (SavedMethodCharge::holding and
 * onItsWay, AutoCharge::nextAttempt), so the page never offers a payment the server refuses, nor tells of a charge the
 * cron does not make. The server's guards stay where they are: this only decides what is shown.
 */
final class PaymentStatus
{
    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public const CANCELLED = 'cancelled';

    /** A saved method's charge sent with no answer, or of unknown outcome. */
    public const HELD = 'held';

    /** A saved method's charge taken, its payment not recorded yet. */
    public const TAKEN = 'taken';

    /** A payment of it the gateway is processing (SEPA, a PayPal review). */
    public const PROCESSING = 'processing';

    /** A payment of a Mass Pay group it is in, on its way. */
    public const PROCESSING_GROUP = 'processing_group';

    /** Back from a gateway (?checkPayment), its payment not seen yet. */
    public const CHECKING = 'checking';

    /** The bank asked the customer to confirm (3-D Secure) the last charge: the cron does not charge that method again. */
    public const ACTION = 'action';

    public const SCHEDULED = 'scheduled';

    /** The cron's charge was declined and it tries again. */
    public const RETRY = 'retry';

    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    public const UNPAID = 'unpaid';

    /** @param array<string, string> $params */
    private function __construct(public readonly string $state, public readonly array $params = [], private readonly bool $today = false) {}

    public static function for(Invoice $invoice, bool $checking = false): self
    {
        return match ($invoice->status) {
            Invoice::STATUS_PAID => self::paid($invoice),
            Invoice::STATUS_REFUNDED => new self(self::REFUNDED),
            Invoice::STATUS_UNPAID => self::unpaid($invoice, $checking),
            default => new self(self::CANCELLED),
        };
    }

    /** Whether a payment may be started on its page: unpaid, and nothing on its way that could pay it. */
    public function payable(): bool
    {
        return in_array($this->state, [self::ACTION, self::SCHEDULED, self::RETRY, self::DUE, self::OVERDUE, self::UNPAID], true);
    }

    /** A payment on its way: the page looks again by itself, and the customer is asked not to pay twice. */
    public function waiting(): bool
    {
        return in_array($this->state, [self::HELD, self::TAKEN, self::PROCESSING, self::PROCESSING_GROUP, self::CHECKING], true);
    }

    /** ok, info, warn, error or plain: the card's colour. */
    public function tone(): string
    {
        return match ($this->state) {
            self::PAID => 'ok',
            self::SCHEDULED, self::RETRY => 'info',
            self::ACTION, self::OVERDUE => 'error',
            self::REFUNDED, self::CANCELLED, self::DUE, self::UNPAID => 'plain',
            default => 'warn',
        };
    }

    public function title(): string
    {
        return __('invoices.payment_status.' . $this->key() . '.title', $this->params);
    }

    public function body(): string
    {
        return __('invoices.payment_status.' . $this->key() . '.body', $this->params);
    }

    /** Its texts' key: the state, or a variant of it (a charge today, a payment with no method known). */
    private function key(): string
    {
        return match (true) {
            $this->today && in_array($this->state, [self::SCHEDULED, self::RETRY], true) => $this->state . '_today',
            $this->state === self::PAID && !isset($this->params['method']) => 'paid_on',
            default => $this->state,
        };
    }

    private static function paid(Invoice $invoice): self
    {
        $payment = $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->with('gateway')->latest('id')->first();
        $date = ($invoice->paid_at ?? $payment?->created_at)?->format('d/m/Y');
        if ($date === null) {
            return new self(self::PAID, ['date' => '—']);
        }
        $method = $payment === null ? null : ($payment->is_credit_transaction ? __('invoices.paid_with_credits') : $payment->gateway?->name);

        return new self(self::PAID, array_filter(['date' => $date, 'method' => $method], fn ($value) => $value !== null && $value !== ''));
    }

    private static function unpaid(Invoice $invoice, bool $checking): self
    {
        // A saved method's charge not settled holds it for every way of paying (predeploy S1, S2): the server refuses them.
        $held = SavedMethodCharge::holding($invoice)->first();
        if ($held !== null) {
            return new self($held->status === ChargeAttempt::STATUS_SUCCEEDED ? self::TAKEN : self::HELD, [
                'amount' => $invoice->formattedTotal->format((float) $held->amount),
                'method' => $held->billingAgreement->name ?? __('invoices.payment_status.saved_method'),
                'date' => $held->sent_at->format('d/m/Y H:i'),
            ]);
        }
        // A payment the gateway is processing, of it or of a Mass Pay group it is in (onItsWay's other half).
        if (SavedMethodCharge::onItsWay($invoice)) {
            $payment = $invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->with('gateway')->latest('id')->first();

            return $payment === null ? new self(self::PROCESSING_GROUP) : new self(self::PROCESSING, [
                'amount' => $invoice->formattedTotal->format((float) $payment->amount),
                'gateway' => $payment->gateway->name ?? '—',
                'date' => $payment->created_at?->format('d/m/Y H:i') ?? '—',
            ]);
        }
        if ($checking) {
            return new self(self::CHECKING);
        }
        // The cron charges it: when, and with which method (agreementFor, as the run).
        $next = AutoCharge::nextAttempt($invoice);
        if ($next !== null) {
            $declined = ChargeAttempt::query()->where('invoice_id', $invoice->id)->where('source', ChargeAttempt::SOURCE_CRON)
                ->where('status', ChargeAttempt::STATUS_FAILED)->exists();

            return new self($declined ? self::RETRY : self::SCHEDULED,
                ['date' => $next->format('d/m/Y'), 'method' => (string) AutoCharge::agreementFor($invoice)?->name], $next->isSameDay(CarbonImmutable::today()));
        }
        $last = ChargeAttempt::query()->where('invoice_id', $invoice->id)->with('billingAgreement')->latest('id')->first();
        if ($last?->status === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            return new self(self::ACTION, ['method' => $last->billingAgreement->name ?? __('invoices.payment_status.saved_method')]);
        }
        if ($invoice->due_at === null) {
            return new self(self::UNPAID);
        }

        return new self($invoice->due_at->copy()->startOfDay()->lt(CarbonImmutable::today()) ? self::OVERDUE : self::DUE, ['date' => $invoice->due_at->format('d/m/Y')]);
    }
}
