<?php

namespace App\Listeners;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Events\Invoice\Paid;
use App\Events\Order\Finalized;
use App\Events\Service\Updated as ServiceUpdated;
use App\Events\ServiceCancellation\Created as CancellationCreated;
use App\Events\TicketMessage\Created as MessageCreated;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Chat\ChatNotifier;
use Throwable;

/**
 * What staff are told in their chat (Setup → Chat notifications; sweep of myip, 2026-10-25): a new ticket or a customer's
 * reply, an order, an invoice paid, a service suspended, unsuspended or terminated, a cancellation asked for. Each with a
 * link to its admin page. A chat that fails never fails what it tells of, and the event's models are never touched: the
 * text is made, only when a rule asks, from fresh copies (a relation loaded on the event's order emptied its staff mail's
 * domains: StaffOrderNoticeReviewTest).
 */
class ChatNotifications
{
    public function handleMessage(MessageCreated $event): void
    {
        $message = $event->ticketMessage ?? null;
        if ($message === null || $message->isFromStaff() || ($message->is_internal ?? false)) {
            return;
        }
        $this->tell(function () use ($message) {
            $ticket = Ticket::query()->with('department', 'client')->find($message->ticket_id);
            if ($ticket === null) {
                return;
            }
            $new = $ticket->messages()->count() === 1;
            ChatNotifier::notify($new ? 'ticket_opened' : 'ticket_reply', fn () => ($new ? '🎫 New ticket ' : '💬 Customer reply on ')
                . ChatNotifier::link('#' . $ticket->id . ' ' . $ticket->subject, TicketResource::getUrl('edit', ['record' => $ticket], panel: 'admin'))
                . ' · ' . ChatNotifier::escape($ticket->requesterName()) . ($ticket->department ? ' · ' . ChatNotifier::escape($ticket->department->name) : ''));
        });
    }

    public function handleOrder(Finalized $event): void
    {
        $id = $event->order->id;
        $this->tell(fn () => ChatNotifier::notify('order_placed', function () use ($id) {
            $order = Order::query()->findOrFail($id);

            return '🛒 New order ' . ChatNotifier::link('#' . $order->id, OrderResource::getUrl('edit', ['record' => $order], panel: 'admin'))
                . ' · ' . ChatNotifier::escape($order->client->name ?? '') . ' · ' . $order->formattedTotal;
        }));
    }

    public function handlePaid(Paid $event): void
    {
        $id = $event->invoice->id;
        $this->tell(fn () => ChatNotifier::notify('invoice_paid', function () use ($id) {
            $invoice = Invoice::query()->findOrFail($id);

            return '✅ Invoice ' . ChatNotifier::link('#' . ($invoice->number ?: $invoice->id), InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin'))
                . ' paid · ' . ChatNotifier::escape($invoice->client->name ?? '') . ' · ' . $invoice->formattedTotal;
        }));
    }

    public function handleService(ServiceUpdated $event): void
    {
        $service = $event->service;
        if (!array_key_exists('status', $event->changes)) {
            return;
        }
        // The change this event is for, not the model as it is now (saved twice in one transaction: review of #456).
        $from = (string) ($event->original['status'] ?? '');
        $to = (string) $event->changes['status'];
        $kind = match (true) {
            $to === Service::STATUS_SUSPENDED => ['service_suspended', '⏸️ Suspended'],
            $to === Service::STATUS_ACTIVE && $from === Service::STATUS_SUSPENDED => ['service_unsuspended', '▶️ Unsuspended'],
            $to === Service::STATUS_TERMINATED => ['service_terminated', '⛔ Terminated'],
            default => null,
        };
        if ($kind === null) {
            return;
        }
        $id = $service->id;
        $this->tell(fn () => ChatNotifier::notify($kind[0], function () use ($id, $kind) {
            $service = Service::query()->with('product', 'client')->findOrFail($id);

            return $kind[1] . ' ' . ChatNotifier::link('#' . $service->id . ' ' . ($service->product->name ?? '') . ($service->domain ? ' (' . $service->domain . ')' : ''),
                ServiceResource::getUrl('edit', ['record' => $service], panel: 'admin')) . ' · ' . ChatNotifier::escape($service->client->name ?? '');
        }));
    }

    public function handleCancellation(CancellationCreated $event): void
    {
        [$type, $reason, $serviceId] = [$event->cancellation->type, $event->cancellation->reason, $event->cancellation->service_id];
        $this->tell(fn () => ChatNotifier::notify('cancellation_requested', function () use ($type, $reason, $serviceId) {
            $service = Service::query()->with('product', 'client')->findOrFail($serviceId);

            return '🗑️ Cancellation requested (' . $type . ') '
                . ChatNotifier::link('#' . $service->id . ' ' . ($service->product->name ?? ''), ServiceResource::getUrl('edit', ['record' => $service], panel: 'admin'))
                . ' · ' . ChatNotifier::escape($service->client->name ?? '') . (filled($reason) ? ' · «' . ChatNotifier::escape(mb_substr((string) $reason, 0, 200)) . '»' : '');
        }));
    }

    private function tell(callable $tell): void
    {
        try {
            $tell();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
