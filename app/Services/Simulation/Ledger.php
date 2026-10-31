<?php

namespace App\Services\Simulation;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * What the cron did on a simulated day, read from what it left: the services that changed status or were renewed, the
 * invoices it made and what became of them, the mails it sent (their template and the client, never the address), the
 * module actions, and what the stand-ins were asked (servers, cards, registrars). One line each: [day, event, ref, detail].
 */
final class Ledger
{
    /** @var array<int, array{status: string, expires: ?string}> */
    private array $services = [];

    /** @var array<int, string> invoice id => status */
    private array $invoices = [];

    private int $lastMail = 0;

    private int $lastAction = 0;

    private int $lastInvoice = 0;

    private int $lastTransaction = 0;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $lines = [];

    public function __construct()
    {
        $this->read();
        $this->lastMail = (int) DB::table('email_logs')->max('id');
        $this->lastAction = (int) DB::table('module_actions')->max('id');
        $this->lastInvoice = (int) DB::table('invoices')->max('id');
        $this->lastTransaction = (int) DB::table('invoice_transactions')->max('id');
    }

    /** Reset what the stand-ins remember, run before a day. */
    public static function forget(): void
    {
        SimServer::$calls = [];
        SimPay::$charges = [];
        SimRegistrar::$calls = [];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> this day's lines */
    public function day(string $day): array
    {
        $out = [];
        $add = function (string $event, string $ref, string $detail = '') use (&$out, $day) {
            $out[] = [$day, $event, $ref, $detail];
        };
        foreach (DB::table('services')->get(['id', 'status', 'expires_at']) as $s) {
            $before = $this->services[$s->id] ?? null;
            if ($before === null) {
                continue;
            }
            if ($before['status'] !== $s->status) {
                $add('service status', "service #{$s->id}", "{$before['status']} → {$s->status}");
            }
            if (substr((string) $before['expires'], 0, 10) !== substr((string) $s->expires_at, 0, 10)) {
                $add('service renewed', "service #{$s->id}", substr((string) $before['expires'], 0, 10) . ' → ' . substr((string) $s->expires_at, 0, 10));
            }
        }
        foreach (Invoice::query()->where('id', '>', $this->lastInvoice)->orderBy('id')->get() as $i) {
            $add('invoice created', "invoice #{$i->id}", "client #{$i->client_id}, total " . number_format((float) $i->total, 2, '.', '') . ', due ' . substr((string) $i->due_at, 0, 10));
        }
        foreach (DB::table('invoices')->where('id', '<=', $this->lastInvoice)->get(['id', 'status']) as $i) {
            if (isset($this->invoices[$i->id]) && $this->invoices[$i->id] !== $i->status) {
                $add('invoice status', "invoice #{$i->id}", "{$this->invoices[$i->id]} → {$i->status}");
            }
        }
        // Every payment, whatever paid it (a card, credit): an invoice made and paid in one step is in no status change.
        foreach (DB::table('invoice_transactions')->where('id', '>', $this->lastTransaction)->orderBy('id')->get(['invoice_id', 'amount', 'status', 'is_credit_transaction', 'gateway_id']) as $t) {
            $add('payment', "invoice #{$t->invoice_id}", 'paid ' . number_format((float) $t->amount, 2, '.', '') . " ({$t->status})" . ($t->is_credit_transaction ? ' from credit' : ''));
        }
        foreach (DB::table('email_logs')->where('id', '>', $this->lastMail)->orderBy('id')->get(['template_key', 'client_id', 'status']) as $m) {
            $add('mail', 'client #' . ($m->client_id ?? '-'), ($m->template_key ?? 'no template') . " ({$m->status})");
        }
        foreach (DB::table('module_actions')->where('id', '>', $this->lastAction)->orderBy('id')->get() as $a) {
            $add('module action', 'service #' . ($a->service_id ?? '-'), ($a->action ?? '?') . ' ' . ($a->status ?? ''));
        }
        foreach (SimServer::$calls as $call) {
            $add('server asked', $call);
        }
        foreach (SimPay::$charges as $c) {
            $add($c['ok'] ? 'card charged' : 'card declined', "invoice #{$c['invoice']}", "client #{$c['client']}, " . number_format($c['amount'], 2, '.', ''));
        }
        foreach (SimRegistrar::$calls as $call) {
            $add('registrar asked', $call);
        }
        $this->read();
        $this->lastMail = (int) DB::table('email_logs')->max('id');
        $this->lastAction = (int) DB::table('module_actions')->max('id');
        $this->lastInvoice = (int) DB::table('invoices')->max('id');
        $this->lastTransaction = (int) DB::table('invoice_transactions')->max('id');
        $this->lines = [...$this->lines, ...$out];

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    public function lines(): array
    {
        return $this->lines;
    }

    private function read(): void
    {
        $this->services = DB::table('services')->get(['id', 'status', 'expires_at'])->mapWithKeys(fn ($s) => [$s->id => ['status' => $s->status, 'expires' => $s->expires_at]])->all();
        $this->invoices = DB::table('invoices')->pluck('status', 'id')->all();
    }
}
