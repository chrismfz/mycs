<?php

namespace App\Listeners;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Events\Invoice\Paid;
use App\Events\Order\Finalized;
use App\Events\Service\Updated as ServiceUpdated;
use App\Events\ServiceCancellation\Created as CancellationCreated;
use App\Events\TicketMessage\Created as MessageCreated;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Chat\ChatCard;
use App\Services\Chat\ChatNotifier;
use App\Services\Reports\IncomeReport;
use Throwable;

/**
 * What staff are told in their chat (Setup → Chat notifications; sweep of myip, 2026-10-25): a new ticket, a customer's
 * or staff's reply, an order, an invoice paid, a service suspended, unsuspended or terminated, a cancellation asked for.
 * Each a card as WHMCS's (ChatCard): its title links to the admin page, its fields name the client (linked) and what
 * staff look at first. A chat that fails never fails what it tells of, and the event's models are never touched: the
 * text is made, only when a rule asks, from fresh copies (a relation loaded on the event's order emptied its staff mail's
 * domains: StaffOrderNoticeReviewTest).
 */
class ChatNotifications
{
    public function handleMessage(MessageCreated $event): void
    {
        $message = $event->ticketMessage ?? null;
        if ($message === null || ($message->is_internal ?? false)) {
            return;
        }
        $fromStaff = $message->isFromStaff();
        $staffId = $message->staff_id;
        $this->tell(function () use ($message, $fromStaff, $staffId) {
            $ticket = Ticket::query()->with('department', 'client')->find($message->ticket_id);
            if ($ticket === null) {
                return;
            }
            $first = $ticket->messages()->count() === 1;
            $event = $fromStaff ? 'ticket_staff_reply' : ($first ? 'ticket_opened' : 'ticket_reply');
            ChatNotifier::notify($event, function () use ($ticket, $fromStaff, $first, $staffId) {
                $who = $fromStaff ? ChatNotifier::escape(Staff::query()->find($staffId)->name ?? 'a staff member') : '';
                $what = match (true) {
                    // Opened by staff (or while impersonating): told with staff's replies, as what it is.
                    $fromStaff && $first => 'A new ticket has been opened by ' . $who . '.',
                    $fromStaff => 'A new reply has been posted by ' . $who . '.',
                    $first => 'A new ticket has been opened.',
                    default => 'A new reply has been posted by the customer.',
                };

                return (new ChatCard('#' . ($ticket->reference ?: $ticket->id) . ' - ' . ChatNotifier::plain((string) $ticket->subject),
                    TicketResource::getUrl('edit', ['record' => $ticket], panel: 'admin'), $what, $fromStaff ? ChatCard::BLUE : ChatCard::ORANGE))
                    ->field('Department', $ticket->department ? ChatNotifier::escape($ticket->department->name) : null)
                    ->field($ticket->isGuest() ? 'Guest' : 'Client', $ticket->isGuest()
                        ? ChatNotifier::escape(trim($ticket->guest_name . ' ' . ($ticket->guest_email ? '(' . $ticket->guest_email . ')' : '')))
                        : $this->client($ticket->client))
                    ->field('Priority', ucfirst((string) $ticket->priority))
                    // The status the reply gives it: the listener that sets it may run after this one.
                    ->field('Status', Ticket::statusLabel($ticket->statusAfterReply($fromStaff)));
            });
        });
    }

    public function handleOrder(Finalized $event): void
    {
        $id = $event->order->id;
        $this->tell(fn () => ChatNotifier::notify('order_placed', function () use ($id) {
            $order = Order::query()->with('client', 'services.product')->findOrFail($id);

            return (new ChatCard('Order #' . $order->id, OrderResource::getUrl('edit', ['record' => $order], panel: 'admin'), 'A new order has been placed.', ChatCard::PURPLE))
                ->field('Client', $this->client($order->client))
                ->field('Total', (string) $order->formattedTotal)
                ->field('Items', ChatNotifier::escape($order->services->map(fn (Service $service) => ($service->product->name ?? '')
                    . ($service->domain ? ' (' . $service->domain . ')' : ''))->filter()->implode(', ')), short: false);
        }));
    }

    public function handlePaid(Paid $event): void
    {
        $id = $event->invoice->id;
        $this->tell(fn () => ChatNotifier::notify('invoice_paid', function () use ($id) {
            $invoice = Invoice::query()->with('client', 'transactions.gateway')->findOrFail($id);

            return (new ChatCard('Invoice #' . ($invoice->number ?: $invoice->id), InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin'),
                'An invoice has been paid.', ChatCard::GREEN))
                ->field('Client', $this->client($invoice->client))
                ->field('Due Date', $invoice->due_at?->format('d/m/Y'))
                ->field('Total', (string) $invoice->formattedTotal)
                ->field('Payment Method', ChatNotifier::escape(IncomeReport::paidWith($invoice)))
                ->field('Status', 'Paid');
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
            $to === Service::STATUS_SUSPENDED => ['service_suspended', 'Suspended', 'A service has been suspended.', ChatCard::ORANGE],
            $to === Service::STATUS_ACTIVE && $from === Service::STATUS_SUSPENDED => ['service_unsuspended', 'Unsuspended', 'A service has been unsuspended.', ChatCard::GREEN],
            $to === Service::STATUS_TERMINATED => ['service_terminated', 'Terminated', 'A service has been terminated.', ChatCard::RED],
            default => null,
        };
        if ($kind === null) {
            return;
        }
        $id = $service->id;
        $this->tell(fn () => ChatNotifier::notify($kind[0], function () use ($id, $kind) {
            $service = Service::query()->with('product', 'client')->findOrFail($id);

            return (new ChatCard('Service ' . $kind[1] . ': #' . $service->id . ' ' . ChatNotifier::plain((string) ($service->product->name ?? '')),
                ServiceResource::getUrl('edit', ['record' => $service], panel: 'admin'), $kind[2], $kind[3]))
                ->field('Client', $this->client($service->client))
                ->field('Domain', $service->domain ? ChatNotifier::escape((string) $service->domain) : null)
                ->field('Status', $kind[1]);
        }));
    }

    public function handleCancellation(CancellationCreated $event): void
    {
        [$type, $reason, $serviceId] = [$event->cancellation->type, $event->cancellation->reason, $event->cancellation->service_id];
        $this->tell(fn () => ChatNotifier::notify('cancellation_requested', function () use ($type, $reason, $serviceId) {
            $service = Service::query()->with('product', 'client')->findOrFail($serviceId);

            return (new ChatCard('Cancellation request: #' . $service->id . ' ' . ChatNotifier::plain((string) ($service->product->name ?? '')),
                ServiceResource::getUrl('edit', ['record' => $service], panel: 'admin'), 'A cancellation has been requested.', ChatCard::RED))
                ->field('Client', $this->client($service->client))
                ->field('Domain', $service->domain ? ChatNotifier::escape((string) $service->domain) : null)
                ->field('Type', $type === 'immediate' ? 'Immediate' : 'End of billing period')
                ->field('Reason', filled($reason) ? ChatNotifier::escape(mb_substr((string) $reason, 0, 500)) : null, short: false);
        }));
    }

    /** The client's name, linked to their summary in the admin. */
    private function client(?Client $client): ?string
    {
        return $client === null ? null : ChatNotifier::link((string) $client->name, ClientResource::getUrl('view', ['record' => $client], panel: 'admin'));
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
