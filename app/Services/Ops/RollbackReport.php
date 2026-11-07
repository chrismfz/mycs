<?php

namespace App\Services\Ops;

use App\Enums\MoneyEventKind;
use App\Models\Audit;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\License;
use App\Models\ModuleAction;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the MyCS did since the cut-over that has to go back to WHMCS before its cron runs again (cutover-runbook §3,
 * «Τι ξαναπερνά στο WHMCS»): payments and refunds, the invoices it issued and changed, new and changed clients,
 * services and domains, what it did at the registrars and on the servers, credit, tickets, saved cards, and the
 * customers it mailed (to be told). Read only.
 *
 * It rests on two facts of the cut-over: the importer keeps WHMCS's ids and writes no audits (audit.console is off for
 * it), so a row created after --since, or an audit after it, is the MyCS's own; and the cron, the queued jobs and the
 * web are audited.
 */
final class RollbackReport
{
    /** Fields of an audit not worth carrying back (bookkeeping of the MyCS itself). */
    private const NOISE = ['updated_at', 'created_at', 'last_synced_at', 'sync_error', 'remember_token', 'last_login_at'];

    /**
     * By model: an invoice's created_at is its date in WHMCS (ADV-690b-S-3), a service's billing_day is the MyCS's own.
     *
     * @var array<string, array{keep?: list<string>, drop?: list<string>}>
     */
    private const NOISE_BY_MODEL = [Invoice::class => ['keep' => ['created_at']], Service::class => ['drop' => ['billing_day']]];

    /** Audit events of a change, beyond updated / deleted / restored: an IP given or taken back (written without the model). */
    private const EVENTS_BY_MODEL = [Service::class => ['ip_assigned', 'ip_released'], User::class => ['password_changed']];

    private readonly Carbon $since;

    private readonly ?Carbon $until;

    /** @var array<string, list<int>> the ids the MyCS created, by model */
    private array $mine = [];

    public function __construct(Carbon $since, ?Carbon $until = null)
    {
        // The rows are kept in the app's zone: an --since given in another («00:00 +02:00») is the same moment (ADV-690-7).
        $zone = (string) config('app.timezone');
        $this->since = $since->copy()->setTimezone($zone);
        $this->until = $until?->copy()->setTimezone($zone);
    }

    /**
     * The rows of a model the MyCS created, between --since and --until: its «created» audit (the importer writes none). A
     * row without one only by its created_at (made by a path not audited), and never one whose date was changed here
     * (ADV-690b-S-2: a WHMCS invoice dated today is still WHMCS's). Not created_at alone (adversarial #690): an imported
     * row may have none (0000-00-00), a merge dates the invoice it keeps back, a payment is dated the day the money came
     * (ADV-690b-S-1), and a deleted row has no date left to read.
     *
     * @param  class-string<Model>  $class
     * @return list<int>
     */
    private function mine(string $class): array
    {
        if (isset($this->mine[$class])) {
            return $this->mine[$class];
        }
        $model = new $class;
        $type = $model->getMorphClass();
        $table = $model->getTable();
        $audited = $this->between(Audit::query()->where('auditable_type', $type)->where('event', 'created'))->pluck('auditable_id')->map(fn ($id) => (int) $id)->all();
        $unaudited = $this->between($class::query()->withoutGlobalScopes())
            ->whereNotExists(fn (Builder $q) => $q->from('audits')->where('audits.auditable_type', $type)->whereColumn('audits.auditable_id', "{$table}.id")
                ->where(fn (Builder $e) => $e->where('audits.event', 'created')
                    ->orWhere(fn (Builder $u) => $u->where('audits.event', 'updated')->where('audits.new_values', 'like', '%"created_at"%'))))
            ->pluck("{$table}.id")->map(fn ($id) => (int) $id)->all();

        return $this->mine[$class] = array_values(array_unique([...$audited, ...$unaudited]));
    }

    /**
     * Every part, in the order they go back (money first).
     *
     * @return list<array{key: string, title: string, todo: string, columns: list<string>, rows: list<list<string>>}>
     */
    public function sections(): array
    {
        return [
            $this->payments(),
            $this->changed('payments_changed', 'WHMCS payments changed or deleted', InvoiceTransaction::class,
                'The same on the WHMCS payment: a deleted one is deleted there (DeleteTransaction / the invoice back to unpaid), a refunded amount as below.'
                . ' One noted «Payment Pending» has no payment in WHMCS (its invoice is still Payment Pending there): record the payment in WHMCS'
                . ' (AddInvoicePayment) when it was completed here, or mark the invoice\'s debit failed when it was deleted.',
                extra: ['note'], extraOf: fn (array $ids) => $this->importedPending($ids)),
            $this->refunds(),
            $this->newInvoices(),
            $this->changed('invoices_changed', 'Invoices changed', Invoice::class,
                'The same change on the WHMCS invoice (status, due date, lines): UpdateInvoice. Before the payments on it.'),
            $this->linesOnWhmcsInvoices(),
            $this->changed('invoice_lines_changed', 'Invoice lines changed', InvoiceItem::class, 'The same line on the WHMCS invoice (UpdateInvoice: amount, description, removed).'),
            $this->credit(),
            $this->newClients(),
            $this->changed('clients_changed', 'Clients changed', Client::class, 'UpdateClient with the new values.'),
            $this->newContacts(),
            $this->contactsChanged(),
            $this->newLogins(),
            $this->changed('logins_changed', 'Logins changed', User::class,
                'UpdateUser (email, name); «password or secret changed»: they reset their password in WHMCS (it does not go back).',
                empty: 'password or secret changed'),
            $this->orders(),
            $this->newServices(),
            $this->changed('services_changed', 'Services changed', Service::class,
                'UpdateClientProduct: next due date, status, plan, price. Status as on the server (module actions below).'),
            $this->cancellations(),
            $this->moduleActions(),
            $this->changed('licenses_changed', 'Licenses changed', License::class,
                'The license in WHMCS\'s licensing addon as here (status, valid IP and domain; a reissue: Reissue there too), else its next check fails.',
                extra: ['key', 'valid ip', 'valid domain', 'status now'],
                extraOf: fn (array $ids) => License::query()->whereKey($ids)->get()->mapWithKeys(fn (License $l) => [$l->id => [$l->license_key, $l->valid_ip, $l->valid_domain, $l->status]])->all()),
            $this->newDomains(),
            $this->changed('domains_changed', 'Domains changed', Domain::class,
                'UpdateClientDomain: expiry and next due date, status, registrar. Else WHMCS renews it a second time.'),
            $this->registrar(),
            $this->tickets(),
            $this->changed('tickets_changed', 'WHMCS tickets changed', Ticket::class, 'UpdateTicket on the WHMCS ticket (status, department, priority).',
                extra: ['whmcs ticket'], extraOf: fn (array $ids) => Ticket::query()->whereKey($ids)->pluck('whmcs_id', 'id')->map(fn ($w) => [$w])->all()),
            $this->ticketReplies(),
            $this->quarantine(),
            $this->notes(),
            $this->cards(),
            $this->mails(),
        ];
    }

    private function between($query, string $column = 'created_at')
    {
        $query->where($column, '>=', $this->since);
        if ($this->until !== null) {
            $query->where($column, '<=', $this->until);
        }

        return $query;
    }

    private function section(string $key, string $title, string $todo, array $columns, iterable $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = array_map(fn ($cell) => self::cell($cell), $row);
        }

        return ['key' => $key, 'title' => $title, 'todo' => $todo, 'columns' => $columns, 'rows' => $list];
    }

    private static function cell(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i');
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''); // whole: the CSV is read by a script (ADV-690-8)
    }

    /** Whether the invoice was issued by the MyCS (after --since): then it is made in WHMCS first. */
    private function isNew(?Invoice $invoice): string
    {
        return $invoice !== null && in_array((int) $invoice->id, $this->mine(Invoice::class), true) ? 'new (MyCS)' : 'WHMCS';
    }

    private function payments(): array
    {
        // By its created audit, not created_at: staff date a bank transfer the day the money came (ADV-690b-S-1/E-1).
        $renewedHere = $this->renewedHere();
        $rows = InvoiceTransaction::query()->with(['invoice.client.primaryContact', 'invoice.client.ownerGrant.user', 'gateway'])->whereKey($this->mine(InvoiceTransaction::class))->orderBy('id')->get()
            ->map(fn (InvoiceTransaction $t) => [$t->id, $t->created_at, $t->invoice_id, $this->isNew($t->invoice), $t->invoice?->client_id,
                $t->invoice?->client?->name, $t->amount, $t->invoice?->currency_code, $t->is_credit_transaction ? 'credit applied' : ($t->gateway->name ?? '—'),
                $t->transaction_id, $t->status, $t->refunded_amount, $t->fee, $renewedHere[(int) $t->invoice_id] ?? '']);

        return $this->section('payments', 'Payments', 'Record it on the invoice WITHOUT WHMCS\'s automation (ADV-690b-E-7): AddInvoicePayment runs «Auto Renew on Payment», auto-setup and auto-unsuspend, so a domain renewed at the registrar here would be renewed again and a new order set up twice. Turn those off while carrying back (or AddTransaction and the invoice marked paid), mostly where «done here» says so. A «credit applied» row is ApplyCredit. An invoice «new (MyCS)» is created in WHMCS first.',
            ['id', 'at', 'invoice', 'invoice from', 'client', 'name', 'amount', 'currency', 'gateway', 'transaction', 'status', 'refunded', 'fee', 'done here'], $rows);
    }

    /**
     * Per invoice, what the MyCS already did for it: a renewal at the registrar, a server action on its lines.
     *
     * @return array<int, string>
     */
    private function renewedHere(): array
    {
        $done = [];
        foreach ($this->between(DomainRegistrarLog::query()->whereNotNull('invoice_id')->where('status', DomainRegistrarLog::STATUS_OK))->get(['invoice_id', 'action']) as $log) {
            $done[(int) $log->invoice_id][] = 'registrar ' . $log->action;
        }
        $actions = $this->between(ModuleAction::query()->whereNotNull('invoice_item_id'))->get(['invoice_item_id', 'action']);
        $invoiceOf = InvoiceItem::query()->whereKey($actions->pluck('invoice_item_id')->unique()->all())->pluck('invoice_id', 'id');
        foreach ($actions as $action) {
            if (($invoice = $invoiceOf[$action->invoice_item_id] ?? null) !== null) {
                $done[(int) $invoice][] = 'server ' . $action->action;
            }
        }

        return array_map(fn (array $list) => implode(', ', array_unique($list)), $done);
    }

    private function refunds(): array
    {
        // The money trail, not gateway_refunds (adversarial #690): a refund by hand or to credit has no gateway row, and a
        // dispute merely opened has one that moved nothing. A reversal is a refund undone (a chargeback won).
        $rows = $this->between(MoneyEvent::query()->whereIn('kind', [MoneyEventKind::Refund, MoneyEventKind::Reversal]), 'occurred_at')->orderBy('id')->get()
            ->map(fn (MoneyEvent $e) => [$e->id, $e->occurred_at, $e->kind, $e->invoice_id, $e->invoice_transaction_id,
                in_array((int) $e->invoice_transaction_id, $this->mine(InvoiceTransaction::class), true) ? 'new (MyCS)' : 'WHMCS',
                $e->client_id, $e->amount, $e->currency_code, $e->source, $e->cause, $e->external_reference, $e->reason]);

        return $this->section('refunds', 'Refunds and reversals', 'On a WHMCS payment: RefundInvoicePayment without the gateway (it is refunded there already), to credit if the cause says so; a reversal puts it back. On a payment «new (MyCS)»: record the payment first.',
            ['id', 'at', 'kind', 'invoice', 'payment', 'payment from', 'client', 'amount', 'currency', 'source', 'cause', 'reference', 'reason'], $rows);
    }

    private function newInvoices(): array
    {
        $rows = Invoice::query()->with(['client.primaryContact', 'client.ownerGrant.user', 'items'])->whereKey($this->mine(Invoice::class))->orderBy('id')->get()
            ->map(fn (Invoice $i) => [$i->id, $i->created_at, $i->number, $i->client_id, $i->client?->name, $i->status, $i->total, $i->currency_code, $i->due_at,
                $i->items->map(fn (InvoiceItem $item) => ($item->reference_type ? class_basename($item->reference_type) . ' #' . $item->reference_id . ' ' : '')
                    . $item->description . ' ' . $item->price . ($item->period_end ? ' → ' . Carbon::parse($item->period_end)->toDateString() : ''))->implode(' | ')]);

        return $this->section('invoices_new', 'Invoices issued by the MyCS', 'CreateInvoice in WHMCS with the same lines (its own id there: note it beside this one), and its next due dates moved as below. Else WHMCS issues its own for the same periods. An upgrade\'s invoice still unpaid: the upgrade order itself in WHMCS (UpgradeProduct), not only its lines.',
            ['id', 'at', 'number', 'client', 'name', 'status', 'total', 'currency', 'due', 'lines'], $rows);
    }

    /** Lines the MyCS added to invoices that came from WHMCS (an upgrade, a late fee, a merge). */
    private function linesOnWhmcsInvoices(): array
    {
        $rows = InvoiceItem::query()->with('invoice')->whereKey($this->mine(InvoiceItem::class))->whereIntegerNotInRaw('invoice_id', collect($this->mine(Invoice::class)))
            ->orderBy('id')->get()
            ->map(fn (InvoiceItem $item) => [$item->id, $item->created_at, $item->invoice_id, $item->invoice?->client_id, $item->description, $item->price, $item->quantity,
                $item->reference_type ? class_basename($item->reference_type) . ' #' . $item->reference_id : '']);

        return $this->section('invoice_lines_new', 'Lines added to WHMCS invoices', 'UpdateInvoice newitem… on the WHMCS invoice.',
            ['id', 'at', 'invoice', 'client', 'description', 'price', 'qty', 'for'], $rows);
    }

    private function credit(): array
    {
        $audits = $this->between(Audit::query()->where('auditable_type', (new Credit)->getMorphClass()))->orderBy('id')->get();
        $credits = Credit::query()->whereKey($audits->pluck('auditable_id')->unique()->all())->get()->keyBy('id');
        $rows = $audits->map(function (Audit $a) use ($credits) {
            $credit = $credits[$a->auditable_id] ?? null;

            return [$a->created_at, $credit?->client_id, $credit?->currency_code, $a->old_values['amount'] ?? '0', $a->new_values['amount'] ?? '0', self::who($a)];
        });

        return $this->section('credit', 'Credit balance', 'AddCredit / the balance in WHMCS by the difference (new − old), unless a payment above already moved it.',
            ['at', 'client', 'currency', 'old', 'new', 'by'], $rows);
    }

    private function newClients(): array
    {
        $rows = Client::query()->with(['primaryContact', 'ownerGrant.user'])->whereKey($this->mine(Client::class))->orderBy('id')->get()
            ->map(fn (Client $c) => [$c->id, $c->created_at, $c->name, $c->email, $c->primaryContact?->company_name, $c->primaryContact?->vat_number ?: $c->primaryContact?->tax_id,
                trim(implode(', ', array_filter([$c->primaryContact?->address1, $c->primaryContact?->address2, $c->primaryContact?->postcode, $c->primaryContact?->city, $c->primaryContact?->state]))),
                $c->country, $c->primaryContact?->phone]);

        return $this->section('clients_new', 'New clients', 'AddClient in WHMCS with these details (a new id there: note it, every row below of this client follows it); WHMCS makes its owner login with it.',
            ['id', 'at', 'name', 'email', 'company', 'vat / tax id', 'address', 'country', 'phone'], $rows);
    }

    /** Contacts the MyCS added to accounts (not a new client's own: that one is in New clients). */
    private function newContacts(): array
    {
        $rows = Contact::query()->whereKey($this->mine(Contact::class))->whereNull('primary_of_client_id')->orderBy('id')->get()
            ->map(fn (Contact $c) => [$c->id, $c->created_at, $c->client_id, trim($c->first_name . ' ' . $c->last_name), $c->email, $c->company_name, $c->phone]);

        return $this->section('contacts_new', 'New contacts', 'AddContact on the WHMCS client.', ['id', 'at', 'client', 'name', 'email', 'company', 'phone'], $rows);
    }

    /**
     * Contact changes, by the WHMCS client they go to (ADV-690b-E-3): an account's own details are its primary contact
     * here (an id of the MyCS's own), a field of the client itself in WHMCS.
     */
    private function contactsChanged(): array
    {
        return $this->changed('contacts_changed', 'Contacts changed', Contact::class,
            'A «client details» row: UpdateClient on that client (its address, phone, company, VAT are the client\'s in WHMCS). A «contact» row: UpdateContact.',
            extra: ['client', 'in WHMCS'],
            extraOf: fn (array $ids) => Contact::query()->whereKey($ids)->get(['id', 'client_id', 'primary_of_client_id'])
                ->mapWithKeys(fn (Contact $c) => [$c->id => [$c->primary_of_client_id ?? $c->client_id, $c->primary_of_client_id ? 'client details' : 'contact']])->all());
    }

    private function newLogins(): array
    {
        $rows = $this->between(User::query())->orderBy('id')->get()->map(fn (User $u) => [$u->id, $u->created_at, $u->email, $u->name]);

        return $this->section('logins_new', 'New logins', 'A new client\'s own is made with it (AddClient); any other: AddUser in WHMCS. They set their password again (it does not go back).',
            ['id', 'at', 'email', 'name'], $rows);
    }

    private function orders(): array
    {
        $rows = $this->between(Order::query()->with(['client.primaryContact', 'client.ownerGrant.user']))->orderBy('id')->get()
            ->map(fn (Order $o) => [$o->id, $o->created_at, $o->client_id, $o->client?->name, $o->status, $o->invoice_id, $o->currency_code]);

        return $this->section('orders', 'Orders', 'AddOrder in WHMCS with its services and domains (below), or the services and domains by hand.',
            ['id', 'at', 'client', 'name', 'status', 'invoice', 'currency'], $rows);
    }

    private function newServices(): array
    {
        $rows = Service::query()->with(['client.primaryContact', 'client.ownerGrant.user', 'product'])->whereKey($this->mine(Service::class))->orderBy('id')->get()
            ->map(fn (Service $s) => [$s->id, $s->created_at, $s->client_id, $s->client?->name, $s->product?->name, $s->label, $s->status, $s->price, $s->expires_at]);

        return $this->section('services_new', 'New services', 'In WHMCS with its order (AddOrder), the same product, price and next due date; on the server it already is.',
            ['id', 'at', 'client', 'name', 'product', 'label', 'status', 'price', 'next due'], $rows);
    }

    /** Cancellation requests (ADV-690b-E-2): one «at the end of the period» on a service with nothing open writes nothing else. */
    private function cancellations(): array
    {
        $rows = ServiceCancellation::query()->with(['service.client.primaryContact', 'service.client.ownerGrant.user'])->whereKey($this->mine(ServiceCancellation::class))->orderBy('id')->get()
            ->map(fn (ServiceCancellation $c) => [$c->id, $c->created_at, $c->service_id, $c->service?->client_id, $c->service?->client?->name, $c->type, $c->reason]);

        return $this->section('cancellations', 'Cancellation requests', 'AddCancelRequest on the WHMCS service (type, reason); else WHMCS bills the next period and never ends it.',
            ['id', 'at', 'service', 'client', 'name', 'type', 'reason'], $rows);
    }

    private function moduleActions(): array
    {
        $rows = $this->between(ModuleAction::query()->with(['service.client.primaryContact', 'service.client.ownerGrant.user', 'domain']))->orderBy('id')->get()
            ->map(fn (ModuleAction $m) => [$m->id, $m->created_at, $m->action, $m->service_id ?? '', $m->domain->fqdn ?? '', $m->service->client->name ?? '', $m->status, $m->last_error ?? '']);

        return $this->section('module_actions', 'Server and module actions', 'The service\'s status in WHMCS as on the server now (create, suspend, unsuspend, terminate done here). An open one did not happen there.',
            ['id', 'at', 'action', 'service', 'domain', 'client', 'status', 'error'], $rows);
    }

    private function newDomains(): array
    {
        $rows = Domain::query()->with(['client.primaryContact', 'client.ownerGrant.user'])->whereKey($this->mine(Domain::class))->orderBy('id')->get()
            ->map(fn (Domain $d) => [$d->id, $d->created_at, $d->fqdn, $d->client_id, $d->client->name, $d->status, $d->expires_at]);

        return $this->section('domains_new', 'New domains', 'In WHMCS with its order, the registrar and expiry as here.',
            ['id', 'at', 'domain', 'client', 'name', 'status', 'expires'], $rows);
    }

    private function registrar(): array
    {
        // A sync only reads (its line is a change seen at the registrar, which WHMCS's own sync sees too).
        $rows = $this->between(DomainRegistrarLog::query()->with('domain')->where('action', '!=', 'sync'))->orderBy('id')->get()
            ->map(fn (DomainRegistrarLog $l) => [$l->id, $l->created_at, $l->domain->fqdn ?? '', $l->action, $l->status, $l->invoice_id ?? '', $l->error ?? '']);

        return $this->section('registrar', 'At the registrars', 'What was done at the registrar from here (register, renew, transfer, contacts, nameservers): the domain in WHMCS as it is now, so its cron does not do it again.',
            ['id', 'at', 'domain', 'action', 'status', 'invoice', 'error'], $rows);
    }

    private function tickets(): array
    {
        $rows = Ticket::query()->with(['client.primaryContact', 'client.ownerGrant.user'])->whereKey($this->mine(Ticket::class))->orderBy('id')->get()
            ->map(fn (Ticket $t) => [$t->id, $t->created_at, $t->reference, $t->client_id ?? '', $t->client->name ?? $t->guest_email, $t->subject, $t->status]);

        return $this->section('tickets_new', 'New tickets', 'OpenTicket in WHMCS (its messages below).',
            ['id', 'at', 'reference', 'client', 'from', 'subject', 'status'], $rows);
    }

    private function ticketReplies(): array
    {
        $rows = $this->between(TicketMessage::query()->with('ticket'))->orderBy('id')->get()
            ->map(fn (TicketMessage $m) => [$m->id, $m->created_at, $m->ticket_id, $m->ticket->whmcs_id ?? '', $m->staff_id ? 'staff #' . $m->staff_id : ($m->sender_email ?? 'customer'),
                $m->is_internal ? 'note' : 'reply', Str::limit((string) $m->message, 80)]);

        return $this->section('ticket_messages', 'Ticket messages', 'AddTicketReply (or AddTicketNote for a note) on the WHMCS ticket, in order.',
            ['id', 'at', 'ticket', 'whmcs ticket', 'from', 'kind', 'start'], $rows);
    }

    /** Mail the ticket mailboxes held back (ADV-690b, low): once piping is WHMCS's again, it is in neither. */
    private function quarantine(): array
    {
        $rows = $this->between(TicketQuarantine::query())->orderBy('id')->get()
            ->map(fn (TicketQuarantine $q) => [$q->id, $q->created_at, $q->from_email, $q->subject, $q->reason, $q->status, $q->ticket_id ?? '']);

        return $this->section('quarantine', 'Mail held in quarantine', 'Handle it before piping goes back to WHMCS (open the ticket there, or answer the sender).',
            ['id', 'at', 'from', 'subject', 'reason', 'status', 'ticket'], $rows);
    }

    /** Staff's notes on accounts, and those a move to another account writes (MoveToClient: its domain row is written without the model). */
    private function notes(): array
    {
        $rows = $this->between(ClientNote::query())->orderBy('id')->get()
            ->map(fn (ClientNote $n) => [$n->id, $n->created_at, $n->client_id, $n->staff_id ? 'staff #' . $n->staff_id : '', $n->body]);

        return $this->section('notes', 'Client notes', 'AddClientNote; a note of a move to another account: the service or domain moved in WHMCS too (Move to client).',
            ['id', 'at', 'client', 'by', 'note'], $rows);
    }

    private function cards(): array
    {
        $rows = $this->between(BillingAgreement::query()->withTrashed()->with(['client.primaryContact', 'client.ownerGrant.user', 'gateway']))->orderBy('id')->get()
            ->map(fn (BillingAgreement $b) => [$b->id, $b->created_at, $b->client_id, $b->client?->name, $b->gateway->name ?? '', $b->name, $b->deleted_at ? 'removed' : 'kept']);

        return $this->section('cards', 'Saved payment methods', 'Do not go back: the customer saves the card again in WHMCS (tell them).',
            ['id', 'at', 'client', 'name', 'gateway', 'method', 'now'], $rows);
    }

    private function mails(): array
    {
        // One read, grouped here: not a query per customer (ADV-690b-S-8).
        $mails = $this->between(EmailLog::query()->whereNotNull('client_id')->where('status', '!=', EmailLog::STATUS_SKIPPED))
            ->orderBy('id')->toBase()->get(['client_id', 'subject', 'created_at'])->groupBy('client_id');
        $clients = Client::query()->with(['primaryContact', 'ownerGrant.user'])->whereKey($mails->keys()->map(fn ($id) => (int) $id)->all())->get()->keyBy('id');
        $rows = $mails->sortKeys()->map(fn ($list, $clientId) => [$clientId, $clients[$clientId]->name ?? '', $list->count(), $list->max('created_at'),
            $list->take(5)->pluck('subject')->implode(' | ')])->values();

        return $this->section('mails', 'Customers mailed', 'Tell them what happened (runbook §3, step 7): an invoice or a notice from the MyCS may not stand.',
            ['client', 'name', 'mails', 'last', 'subjects (first 5)'], $rows);
    }

    /**
     * Changes of rows that came from WHMCS, deletions too (the audit keeps the row: adversarial #690); the MyCS's own rows
     * are listed in their own part, as they are now.
     *
     * @param  class-string<Model>  $class
     */
    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $extra  columns of $extraOf's cells
     * @param  (\Closure(list<int>): array<int, list<mixed>>)|null  $extraOf  cells per row id, read once
     * @param  string|null  $empty  shown for an update with no field listed (a login's password: excluded from its audit)
     */
    private function changed(string $key, string $title, string $class, string $todo, array $extra = [], ?\Closure $extraOf = null, ?string $empty = null): array
    {
        $model = new $class;
        $noise = array_values(array_diff([...self::NOISE, ...(self::NOISE_BY_MODEL[$class]['drop'] ?? [])], self::NOISE_BY_MODEL[$class]['keep'] ?? []));
        // Raw integers, not one placeholder per id: a big --since window passes MariaDB's 65,535 (ADV-690b-S-7).
        $audits = $this->between(Audit::query()->where('auditable_type', $model->getMorphClass())
            ->whereIn('event', ['updated', 'deleted', 'restored', ...(self::EVENTS_BY_MODEL[$class] ?? [])]))
            ->whereIntegerNotInRaw('auditable_id', $this->mine($class))
            ->orderBy('id')->get();
        $cells = $extraOf !== null ? $extraOf($audits->pluck('auditable_id')->map(fn ($id) => (int) $id)->unique()->values()->all()) : [];
        $rows = $audits->map(function (Audit $a) use ($noise, $empty, $extra, $cells) {
            $old = (array) $a->old_values;
            $new = (array) $a->new_values;
            $fields = array_diff(array_unique([...array_keys($old), ...array_keys($new)]), $noise);
            if ($fields === [] && $a->event === 'updated' && $empty === null) {
                return null;
            }
            $change = $fields === [] && $empty !== null ? $empty
                : collect($fields)->map(fn ($f) => $f . ': ' . self::flat($old[$f] ?? null) . ' → ' . self::flat($new[$f] ?? null))->implode('; ');

            return [$a->auditable_id, ...($cells[$a->auditable_id] ?? array_fill(0, count($extra), '')), $a->created_at, $a->event, $change, self::who($a)];
        })->filter()->values();

        return $this->section($key, $title, $todo, ['id', ...$extra, 'at', 'event', 'change', 'by'], $rows);
    }

    /**
     * The payments among these that the importer made of a WHMCS «Payment Pending» (whmcs-pending-*: WHMCS has no payment
     * for them, R1b-2), by the row or, deleted, by its audit's old values.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function importedPending(array $ids): array
    {
        $pending = InvoiceTransaction::query()->whereIntegerInRaw('id', $ids)->where('transaction_id', 'like', 'whmcs-pending-%')->pluck('id')
            ->merge(Audit::query()->where('auditable_type', (new InvoiceTransaction)->getMorphClass())->whereIntegerInRaw('auditable_id', $ids)
                ->where('old_values', 'like', '%whmcs-pending-%')->pluck('auditable_id'))
            ->map(fn ($id) => (int) $id)->unique();

        return $pending->mapWithKeys(fn (int $id) => [$id => ['Payment Pending in WHMCS: no payment there; record it in WHMCS, or mark the debit failed']])->all();
    }

    private static function flat(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function who(Audit $audit): string
    {
        return $audit->user_type === null ? 'system (cron, jobs)' : class_basename((string) $audit->user_type) . ' #' . $audit->user_id;
    }
}
