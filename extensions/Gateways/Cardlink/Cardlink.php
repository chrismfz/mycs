<?php

namespace MyCS\Extensions\Gateways\Cardlink;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGroup;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayLog;
use App\Services\Money\PaymentsLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Nixpal\CardlinkEurobank\Amount;
use Nixpal\CardlinkEurobank\OrderId;
use Nixpal\CardlinkEurobank\PaymentRequest;
use Nixpal\CardlinkEurobank\ReturnVerifier;
use Nixpal\CardlinkEurobank\VposReturn;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cardlink/Eurobank vPOS (PR H2a, docs/payments.md «Cardlink»): the bank's hosted page takes a card, Apple/Google Pay or
 * IRIS. pay() keeps an order (CardlinkOrder: what its order id pays) and posts the signed form to the vPOS. The vPOS posts
 * the signed result back twice, from the customer's browser and from its own server; either one records the payment, once
 * (its transaction id is the order id). The return pays its order's invoice or Mass Pay group, for the amount the vPOS
 * says it captured, and nothing else: the fields come only from a return whose digest holds for the terminal set here
 * (packages/cardlink-eurobank, ReturnVerifier), the invoice only from our order.
 *
 * No refunds from here: they are made in the Cardlink portal.
 */
#[ExtensionMeta(
    name: 'Cardlink (Eurobank)',
    description: 'Accept cards, Apple/Google Pay and IRIS through the Cardlink/Eurobank vPOS.',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgcng9IjY0IiBmaWxsPSIjMGQzYjY2Ii8+PHJlY3QgeD0iOTYiIHk9IjE2MCIgd2lkdGg9IjMyMCIgaGVpZ2h0PSIyMDAiIHJ4PSIyNCIgZmlsbD0iI2ZmZiIvPjxyZWN0IHg9Ijk2IiB5PSIyMDAiIHdpZHRoPSIzMjAiIGhlaWdodD0iNDAiIGZpbGw9IiMwZDNiNjYiLz48L3N2Zz4=',
)]
class Cardlink extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.cardlink', __DIR__ . '/resources/views');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'merchant_id',
                'label' => 'Merchant ID (mid)',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'shared_secret',
                'label' => 'Shared secret',
                'type' => 'text',
                'encrypted' => true,
                'required' => true,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test mode (the Cardlink test vPOS)',
                'type' => 'checkbox',
                'required' => false,
            ],
        ];
    }

    /** The terminal takes euros only. */
    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return strtoupper((string) $currency) === 'EUR';
    }

    public function supportsPaymentGroups(): bool
    {
        return true;
    }

    /**
     * The form that takes the customer to the vPOS, with a new order for this invoice (or the Mass Pay group it is paying:
     * $invoice->payingGroup), for $total.
     *
     * @param  float  $total
     */
    public function pay(Invoice $invoice, $total)
    {
        $cents = RecordPaymentService::toCents($total);
        if ($invoice->client_id === null || $cents <= 0) {
            throw new DisplayException(__('Nothing is left to pay on this invoice.'));
        }
        $order = $this->newOrder($invoice, $cents);
        $client = $invoice->client;
        $contact = $client?->primaryContact;
        $url = route('extensions.gateways.cardlink.return', ['o' => $order->order_id]);
        try {
            $fields = PaymentRequest::fields([
                'mid' => (string) $this->config('merchant_id'),
                'lang' => str_starts_with(app()->getLocale(), 'el') ? 'el' : 'en',
                'orderid' => $order->order_id,
                'orderDesc' => $this->description($invoice),
                'amount' => $order->amount,
                'currency' => $order->currency_code,
                'payerEmail' => (string) $client?->email,
                'billCountry' => (string) $contact?->country,
                'billZip' => (string) $contact?->postcode,
                'billCity' => (string) $contact?->city,
                'billAddress' => (string) $contact?->address1,
                'confirmUrl' => $url,
                'cancelUrl' => $url,
            ], (string) $this->config('shared_secret'));
        } catch (InvalidArgumentException $e) {
            report($e); // the merchant id or the secret is not set
            throw new DisplayException(__('Could not process payment. Please try again or use a different payment method.'));
        }

        /** @var view-string $form registered in boot() */
        $form = 'gateways.cardlink::pay';

        return view($form, ['endpoint' => PaymentRequest::endpoint((bool) $this->config('test_mode')), 'fields' => $fields]);
    }

    /**
     * The vPOS's return (the browser's and the server's), or the customer coming back from a cancel. Answers a page that
     * sends the browser to the invoice; the vPOS's own leg ignores it.
     */
    public function callback(Request $request): Response
    {
        $hint = is_string($request->query('o')) ? CardlinkOrder::query()->where('order_id', $request->query('o'))->first() : null;
        $posted = $request->isMethod('post') ? $request->request->all() : [];
        if (!array_key_exists('digest', $posted)) {
            return $this->page($hint, null); // back from the vPOS with no result (a cancel): nothing to record
        }

        $result = ReturnVerifier::verify($posted, (string) $this->config('shared_secret'), (string) $this->config('merchant_id'));
        GatewayLog::current()->signature($result->verified);
        if (!$result->verified) {
            // Forged, or another terminal's, or a field the vPOS added (it fails closed: see the log's field names).
            PaymentsLog::warning('Cardlink return refused: ' . $result->reason, $result->summary());

            return $this->page(null, null, 400);
        }

        $order = CardlinkOrder::query()->where('order_id', $result->orderId())->first();
        if ($order === null) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'unknown_order', $this->details($result));
            if ($result->status() === CardlinkOrder::STATUS_CAPTURED) {
                self::tellStaff('unknown:' . $result->orderId(), 'Cardlink payment not recorded', sprintf(
                    'The Cardlink vPOS captured %s %s for order %s, which is not one of ours: the money is NOT recorded. Check it in the Cardlink portal.',
                    $result->amount(), $result->currency(), $result->orderId()));
            }

            return $this->page(null, null);
        }

        $status = $result->status();
        // What the vPOS said, forward only: a captured order stays captured whatever comes after it.
        CardlinkOrder::query()->whereKey($order->id)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', CardlinkOrder::STATUS_CAPTURED))
            ->update([
                'status' => substr($status, 0, 20) ?: null,
                'tx_id' => substr($result->txId(), 0, 64) ?: null,
                'pay_method' => substr($result->payMethod(), 0, 30) ?: null,
                'returned_at' => now(),
            ]);

        if ($status === CardlinkOrder::STATUS_CAPTURED) {
            $this->record($order, $result);
        } elseif ($status === 'AUTHORIZED') {
            // Never asked for (no pre-authorisation here): not money until it is captured in the portal.
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'authorized', $this->details($result, $order));
            self::tellStaff('authorized:' . $order->order_id, 'Cardlink payment authorised, not captured', sprintf(
                'The Cardlink vPOS authorised %s %s for order %s (invoice #%d) without capturing it: capture or void it in the Cardlink portal, and record the payment if captured.',
                $result->amount(), $result->currency(), $order->order_id, $order->invoice_id));
        } else {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'status_' . strtolower(substr($status, 0, 20)), $this->details($result, $order));
        }

        return $this->page($order, $status);
    }

    /** A captured return: the amount the vPOS took, on the order's invoice (or group), once. */
    private function record(CardlinkOrder $order, VposReturn $result): void
    {
        $cents = Amount::cents($result->amount());
        if ($cents === null || $cents <= 0) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'bad_amount', $this->details($result, $order));
            self::tellStaff('amount:' . $order->order_id, 'Cardlink payment not recorded', sprintf(
                'The Cardlink vPOS captured order %s (invoice #%d) with an amount that cannot be read: the money is NOT recorded. Check it in the Cardlink portal.',
                $order->order_id, $order->invoice_id));

            return;
        }
        if ($cents !== RecordPaymentService::toCents($order->amount)) {
            // The vPOS signed what it took: that is recorded (what the invoice cannot take is credit), and staff look.
            self::tellStaff('differs:' . $order->order_id, 'Cardlink payment of another amount', sprintf(
                'The Cardlink vPOS captured %s %s for order %s (invoice #%d), which was sent for %s %s. The amount captured is recorded.',
                Amount::format($cents / 100), $result->currency(), $order->order_id, $order->invoice_id, $order->amount, $order->currency_code));
        }
        // Another currency is refused by RecordPaymentService (staff told), as for every gateway.
        ExtensionHelper::addPayment($order->invoice_id, 'Cardlink', Amount::format($cents / 100), transactionId: $order->order_id,
            currency: $result->currency(), paymentGroup: $order->payment_group);
    }

    private function newOrder(Invoice $invoice, int $cents): CardlinkOrder
    {
        // Two random digits keep two orders of one invoice in one second apart; a clash is tried again.
        for ($try = 1; ; $try++) {
            try {
                return CardlinkOrder::query()->create([
                    'order_id' => OrderId::generate($invoice->id, $invoice->client_id, now()->getTimestamp(), random_int(0, 99)),
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'payment_group' => $invoice->payingGroup,
                    'amount' => Amount::format($cents / 100),
                    'currency_code' => strtoupper((string) $invoice->currency_code),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** What the bank's page and portal show: the invoice, or the invoices of the Mass Pay group. */
    private function description(Invoice $invoice): string
    {
        $group = $invoice->payingGroup === null ? null : PaymentGroup::query()->where('ulid', $invoice->payingGroup)->with('invoices')->first();
        if ($group === null) {
            return 'Invoice ' . ($invoice->number ?? $invoice->id);
        }

        return 'Invoices ' . $group->invoices->map(fn (Invoice $item) => $item->number ?? $item->id)->implode(', ');
    }

    /** @return array<string, mixed> */
    private function details(VposReturn $result, ?CardlinkOrder $order = null): array
    {
        return [
            'amount' => Amount::cents($result->amount()) === null ? null : $result->amount(),
            'currency_code' => $result->currency(), 'external_id' => $result->orderId(), 'invoice_id' => $order?->invoice_id,
        ];
    }

    /** The page the browser lands on: to the invoice (or the invoices), with what came of the payment. */
    private function page(?CardlinkOrder $order, ?string $status, int $code = 200): Response
    {
        $paid = $status === CardlinkOrder::STATUS_CAPTURED;
        $url = $order === null ? route('invoices') : route('invoices.show', $order->invoice_id) . ($paid ? '?checkPayment' : '');

        return response()->view('gateways.cardlink::return', ['url' => $url, 'paid' => $paid, 'known' => $order !== null], $code);
    }

    /** Staff hear of it once (PaymentsLog keeps every one). */
    private static function tellStaff(string $key, string $subject, string $message): void
    {
        PaymentsLog::warning($message);
        if (Cache::add('cardlink-notice:' . $key, true, now()->addDays(7))) {
            NotificationHelper::sendSystemEmailNotification($subject, e($message));
        }
    }
}
