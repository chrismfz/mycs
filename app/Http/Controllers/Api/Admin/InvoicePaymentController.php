<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Payments\CreatePaymentRequest;
use App\Http\Requests\Api\Admin\Payments\DeletePaymentRequest;
use App\Http\Requests\Api\Admin\Payments\GetPaymentsRequest;
use App\Http\Requests\Api\Admin\Payments\RefundPaymentRequest;
use App\Http\Requests\Api\Admin\Payments\UndoRefundRequest;
use App\Http\Requests\Api\Admin\Payments\UpdatePaymentRequest;
use App\Http\Resources\InvoiceTransactionResource;
use App\Http\Resources\PaymentRefundResource;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The payments of an invoice, as staff correct them in the panel's «Transactions» table (owner 2026-11-10, sweep 7
 * TIGHT-INVOICES-PAYMENTS-32, docs/staff-freedom.md). Each action calls the service the panel's action calls, with its
 * permission on the key: no money rule lives here, and a refusal is the service's own message (422). A payment is
 * always addressed under its invoice (404 otherwise).
 */
#[Group(name: 'Invoice payments', weight: 5)]
class InvoicePaymentController extends ApiController
{
    /**
     * List the payments of an invoice
     */
    public function index(GetPaymentsRequest $request, Invoice $invoice)
    {
        return InvoiceTransactionResource::collection($invoice->transactions()->orderBy('id')->get());
    }

    /**
     * Show a payment of an invoice
     */
    public function show(GetPaymentsRequest $request, Invoice $invoice, InvoiceTransaction $transaction)
    {
        return new InvoiceTransactionResource($transaction);
    }

    /**
     * Record a payment by hand
     *
     * The panel's «Add Payment» (and «Mark Paid» with `up_to_remaining`). The invoice becomes paid when its payments
     * cover it; what it cannot take becomes account credit (`credited_amount`).
     *
     * @response 422 {"message": "This gateway transaction is already recorded (invoice #12): nothing was added."}
     */
    public function store(CreatePaymentRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $gateway = filled($data['gateway_id'] ?? null) ? Gateway::query()->find($data['gateway_id']) : null;
        // «Reopen» is Mark Paid's tick, and Mark Paid never records more than what is left.
        $reopen = (bool) ($data['reopen'] ?? false);
        $payment = $this->guarded(fn () => (new RecordPaymentService)->recordByStaff(
            $invoice, $gateway, $data['amount'], $data['transaction_id'] ?? null, $data['fee'] ?? null,
            paidAt: filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at'])->setTimeFrom(now()) : null,
            reopen: $reopen,
            upToRemaining: $reopen || ($data['up_to_remaining'] ?? false),
        ));

        return new InvoiceTransactionResource($payment->refresh());
    }

    /**
     * Edit a payment
     *
     * The panel's «Edit». Date, gateway, transaction id and fee change in place. Another amount or invoice is «delete and
     * record again» in one transaction (the answer then has a new id) and also needs `invoice_transactions.create`.
     *
     * @response 422 {"message": "The gateway recorded this payment: its amount is what the gateway took. Refund it at the gateway, or record a correction by hand (another payment, or an adjustment note)."}
     */
    public function update(UpdatePaymentRequest $request, Invoice $invoice, InvoiceTransaction $transaction)
    {
        $data = $request->validated();
        $changes = array_intersect_key($data, array_flip(['invoice_id', 'amount', 'gateway_id', 'transaction_id', 'fee']));
        // Another day only: the time of day it had stays (today, never later than now), as the panel's form.
        if (filled($data['paid_at'] ?? null) && Carbon::parse($data['paid_at'])->toDateString() !== $transaction->created_at?->toDateString()) {
            $changes['paid_at'] = Carbon::parse($data['paid_at'])->setTimeFrom($transaction->created_at ?? now())->min(now());
        }
        $payment = $this->guarded(fn () => (new EditPayment)->handle($transaction, $changes, (bool) ($data['allow_negative_credit'] ?? false)));

        // Read again: recorded again, it is still an edit (200), with the id it has now.
        return new InvoiceTransactionResource(InvoiceTransaction::query()->findOrFail($payment->getKey()));
    }

    /**
     * Delete a payment
     *
     * @response 422 {"message": "This payment has refunds: it cannot be deleted (its refund history and gateway transaction id must stay)."}
     */
    public function destroy(DeletePaymentRequest $request, Invoice $invoice, InvoiceTransaction $transaction)
    {
        $this->guarded(fn () => (new RecordPaymentService)->deleteTransaction($transaction, (bool) $request->validated('allow_negative_credit', false)));

        return $this->returnNoContent();
    }

    /**
     * List the refunds standing on a payment
     *
     * What «Undo refund» offers: each with the id it is undone by. Never a chargeback.
     */
    public function refunds(GetPaymentsRequest $request, Invoice $invoice, InvoiceTransaction $transaction)
    {
        return PaymentRefundResource::collection(UndoRefund::refunds($transaction));
    }

    /**
     * Refund a payment
     *
     * The panel's «Refund»: through the gateway (when the payment's gateway refunds), by hand, or to account credit.
     *
     * @response 422 {"message": "Only a succeeded payment can be refunded."}
     */
    public function refund(RefundPaymentRequest $request, Invoice $invoice, InvoiceTransaction $transaction)
    {
        $data = $request->validated();
        $amount = (float) $data['amount'];
        $notify = (bool) ($data['notify'] ?? true);
        try {
            match ($data['method']) {
                'gateway' => ExtensionHelper::refund($transaction, $amount, $notify),
                'manual' => ExtensionHelper::refundLocally($transaction, $amount, $notify),
                'credit' => ExtensionHelper::refundToCredit($transaction, $amount, $notify),
                default => throw new \InvalidArgumentException('Choose how the money goes back.'),
            };
        } catch (HttpExceptionInterface|QueryException $e) {
            throw $e;
        } catch (\Exception $e) {
            // As the panel's notice: the refusal, or the gateway's failure, in its own words.
            abort(422, $e->getMessage());
        }

        return new InvoiceTransactionResource($transaction->refresh());
    }

    /**
     * Undo a refund of a payment
     *
     * The panel's «Undo refund»: MyCS's books only, the gateway is never called.
     *
     * @response 422 {"message": "The gateway made (or reported) this refund: tick that the money has left at the gateway and that only MyCS's books are corrected."}
     */
    public function undoRefund(UndoRefundRequest $request, Invoice $invoice, InvoiceTransaction $transaction, int $refund)
    {
        abort_unless(UndoRefund::refunds($transaction)->contains('id', $refund), 404);
        $this->guarded(fn () => (new UndoRefund)->handle($transaction, $refund,
            (bool) $request->validated('allow_negative_credit', false), (bool) $request->validated('money_left_at_gateway', false)));

        return new InvoiceTransactionResource($transaction->refresh());
    }

    /** The services' refusals answer 422 with their own message. */
    private function guarded(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (DisplayException $e) {
            abort(422, $e->getMessage());
        }
    }
}
