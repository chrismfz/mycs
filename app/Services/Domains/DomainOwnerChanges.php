<?php

namespace App\Services\Domains;

use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A .gr domain's change of holder (G8-5b, owner 2026-10-23, docs/domains/grepp.md): a name change (the same holder) or a
 * transfer to another holder, both charged at one price (Settings → Domains), on an invoice of their own; sent to the
 * registry once that is paid, or at once when staff send it without one. Never recalled there: claimed before it is
 * asked (due → sending, once), and an answer lost is «unknown» until staff look at the registry, never asked again.
 */
class DomainOwnerChanges
{
    /** Where the signed declarations are kept (the private disk). */
    public const DISK = 'local';

    public const DIRECTORY = 'domain-declarations';

    public function __construct(private readonly DomainManagementService $manage) {}

    /** The one price, as entered (with tax, as the TLD prices), in the default currency; null: none set. */
    public static function price(): ?string
    {
        $price = trim((string) config('settings.domain_gr_owner_change_price', '0'));

        return is_numeric($price) && BigDecimal::of($price)->isPositive() ? (string) BigDecimal::of($price)->toScale(2) : null;
    }

    /** Its open change, if any: one at a time per domain. */
    public static function openFor(Domain $domain): ?DomainOwnerChange
    {
        return DomainOwnerChange::query()->where('domain_id', $domain->id)->whereIn('status', DomainOwnerChange::OPEN)->latest('id')->first();
    }

    /**
     * Staff ask it: checked here as far as we can (the registry checks the rest when it is sent); its invoice made, or,
     * with $sendNow, sent once this commits (no charge to the customer).
     *
     * @param  string|null  $declaration  the signed declaration's path on DISK (a transfer needs one)
     */
    public function request(Domain $domain, Contact $holder, string $kind, Staff $staff, ?string $declaration, ?string $declarationName,
        bool $moveToHolder, bool $sendNow): DomainOwnerChange
    {
        if (!in_array($kind, [ChangesOwner::NAME, ChangesOwner::OWNER], true)) {
            throw new DisplayException(__('domains.owner_change.not_a_kind'));
        }
        if (!$this->manage->changesOwner($domain)) {
            throw new DisplayException(__('domains.owner_change.not_offered', ['domain' => $domain->fqdn]));
        }
        if (!in_array($domain->status, [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace], true)) {
            throw new DisplayException(__('domains.owner_change.not_now', ['domain' => $domain->fqdn]));
        }
        // A name change keeps its holder: a contact of the same account; a transfer is to someone else.
        if ($kind === ChangesOwner::NAME && (int) $holder->client_id !== (int) $domain->client_id) {
            throw new DisplayException(__('domains.owner_change.name_same_account'));
        }
        if ($holder->is($domain->registrantContact)) {
            throw new DisplayException(__('domains.owner_change.same_holder', ['domain' => $domain->fqdn]));
        }
        if ($kind === ChangesOwner::OWNER && ($declaration === null || !Storage::disk(self::DISK)->exists($declaration))) {
            throw new DisplayException(__('domains.owner_change.declaration_needed'));
        }
        $price = self::price();
        if (!$sendNow && $price === null) {
            throw new DisplayException(__('domains.owner_change.no_price'));
        }
        $currency = (string) config('settings.default_currency');
        if (!$sendNow && $domain->currency_code !== $currency) {
            throw new DisplayException(__('domains.owner_change.other_currency', ['currency' => $domain->currency_code]));
        }
        $moveTo = $moveToHolder && $kind === ChangesOwner::OWNER && (int) $holder->client_id !== (int) $domain->client_id ? (int) $holder->client_id : null;

        $change = DB::transaction(function () use ($domain, $holder, $kind, $staff, $declaration, $declarationName, $sendNow, $price, $currency, $moveTo) {
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            if (self::openFor($domain) !== null) {
                throw new DisplayException(__('domains.owner_change.one_open', ['domain' => $domain->fqdn]));
            }
            $change = DomainOwnerChange::query()->create([
                'domain_id' => $domain->id, 'kind' => $kind, 'contact_id' => $holder->id, 'move_to_client_id' => $moveTo,
                'declaration_path' => $declaration, 'declaration_name' => $declarationName, 'free' => $sendNow, 'staff_id' => $staff->id,
                'status' => $sendNow ? DomainOwnerChange::STATUS_DUE : DomainOwnerChange::STATUS_AWAITING_PAYMENT,
            ]);
            if (!$sendNow) {
                $client = Client::query()->findOrFail($domain->client_id);
                $invoice = Invoice::query()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID,
                    'due_at' => today()->addDays(7), 'currency_code' => $currency]);
                $invoice->items()->create(['reference_type' => DomainOwnerChange::class, 'reference_id' => $change->id,
                    'kind' => InvoiceItem::KIND_CHARGE, 'price' => OrderPricing::toNet((string) $price, $client), 'quantity' => 1,
                    'description' => __('domains.owner_change.line_' . $kind, ['domain' => $domain->fqdn])]);
            }

            return $change;
        });
        if ($sendNow) {
            SendDomainOwnerChangeJob::dispatch($change->id);
        }

        return $change;
    }

    /** Its invoice paid (ProcessPaidInvoiceService): due, and sent once this commits. */
    public static function paid(InvoiceItem $line): void
    {
        $changed = DomainOwnerChange::query()->whereKey($line->reference_id)->where('status', DomainOwnerChange::STATUS_AWAITING_PAYMENT)
            ->update(['status' => DomainOwnerChange::STATUS_DUE, 'updated_at' => now()]);
        if ($changed === 1) {
            $id = (int) $line->reference_id;
            DB::afterCommit(fn () => SendDomainOwnerChangeJob::dispatch($id));
        }
    }

    /** Due ones not sent (a job lost, the registry down): the cron's. */
    public static function due()
    {
        return DomainOwnerChange::query()->where('status', DomainOwnerChange::STATUS_DUE);
    }

    /**
     * Send it, once: claimed (due → sending) before the registry is asked. Paid (or free) checked again here. Returns its
     * status after.
     */
    public function send(int $id): string
    {
        $change = DomainOwnerChange::query()->with(['domain', 'contact'])->find($id);
        if ($change === null || $change->status !== DomainOwnerChange::STATUS_DUE) {
            return $change->status ?? 'missing';
        }
        if (!$change->free && $change->line?->invoice?->status !== Invoice::STATUS_PAID) {
            return $change->status; // paid, then the payment taken off: not sent
        }
        $claimed = DomainOwnerChange::query()->whereKey($change->id)->where('status', DomainOwnerChange::STATUS_DUE)
            ->update(['status' => DomainOwnerChange::STATUS_SENDING, 'sent_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return DomainOwnerChange::query()->whereKey($change->id)->value('status');
        }
        try {
            $result = $this->manage->changeOwner($change->domain, $change->contact, $change->kind, $change->staff);
        } catch (RegistrarRefused|RegistrarNotAsked|DomainRegistrarNotConfigured $e) {
            // Nothing done there, nothing charged: staff may send it again (or cancel it).
            return $this->end($change, DomainOwnerChange::STATUS_REFUSED, $e->getMessage());
        } catch (Throwable $e) {
            if ($e->getMessage() === __('domains.manage.busy', ['domain' => $change->domain->fqdn])) {
                // Another write held the domain: not asked; the cron sends it again.
                return $this->end($change, DomainOwnerChange::STATUS_DUE, null, sentAt: null);
            }

            // Asked, no answer: maybe done, never asked again by itself (the registry would charge twice).
            return $this->end($change, DomainOwnerChange::STATUS_UNKNOWN, $e->getMessage());
        }
        $awaiting = str_contains((string) ($result->details['owner_change'] ?? ''), 'awaiting');
        if ($awaiting) {
            return $this->end($change, DomainOwnerChange::STATUS_AWAITING_EETT, (string) ($result->details['comments'] ?? '') ?: null,
                handle: (string) ($result->details['owner_contact'] ?? ''));
        }
        $this->complete($change, (string) ($result->details['owner_contact'] ?? ''));

        return DomainOwnerChange::STATUS_DONE;
    }

    /**
     * Done at the registry (at once, or once the EETT approved it, said by staff): the domain's holder is the new one here
     * too, its line applied, and, as asked, the domain moved to the new holder's account.
     */
    public function complete(DomainOwnerChange $change, ?string $handle = null): void
    {
        $handle = $handle ?: (is_string($change->result) && str_starts_with($change->result, 'handle:') ? substr($change->result, 7) : null);
        $domain = Domain::query()->findOrFail($change->domain_id);
        $moved = null;
        if ($change->move_to_client_id !== null && $change->staff !== null && (int) $domain->client_id !== (int) $change->move_to_client_id) {
            try {
                app(MoveToClient::class)->domain($domain, Client::query()->findOrFail($change->move_to_client_id), $change->staff);
                $moved = true;
            } catch (Throwable $e) {
                report($e);
                $moved = $e->getMessage();
            }
        }
        DB::transaction(function () use ($change, $domain, $handle) {
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $domain->registrant_contact_id = $change->contact_id;
            if (filled($handle)) {
                $domain->registrant_handle = $handle;
            }
            $domain->save();
            $change->line?->forceFill(['applied_at' => now()])->save();
        });
        $this->end($change, DomainOwnerChange::STATUS_DONE, is_string($moved) ? __('domains.owner_change.not_moved', ['why' => $moved]) : null, done: true);
    }

    /** Staff: the EETT approved it (seen at the registry). */
    public function approved(DomainOwnerChange $change): void
    {
        if ($change->status !== DomainOwnerChange::STATUS_AWAITING_EETT) {
            throw new DisplayException(__('domains.owner_change.not_awaiting'));
        }
        $this->complete($change);
    }

    /**
     * Staff after a refusal, or after looking at the registry when the answer was lost: send it again (refused: nothing
     * was done), or say it was done there (unknown).
     */
    public function retry(DomainOwnerChange $change): string
    {
        if ($change->status !== DomainOwnerChange::STATUS_REFUSED) {
            throw new DisplayException(__('domains.owner_change.not_refused'));
        }
        $change->forceFill(['status' => DomainOwnerChange::STATUS_DUE])->save();

        return $this->send($change->id);
    }

    public function doneThere(DomainOwnerChange $change): void
    {
        if ($change->status !== DomainOwnerChange::STATUS_UNKNOWN) {
            throw new DisplayException(__('domains.owner_change.not_unknown'));
        }
        $this->complete($change);
    }

    /** Staff: not done there after all (unknown), or no longer wanted (not sent): cancelled, its unpaid invoice with it. */
    public function cancel(DomainOwnerChange $change, string $reason): void
    {
        if (!in_array($change->status, [DomainOwnerChange::STATUS_AWAITING_PAYMENT, DomainOwnerChange::STATUS_DUE,
            DomainOwnerChange::STATUS_REFUSED, DomainOwnerChange::STATUS_UNKNOWN], true)) {
            throw new DisplayException(__('domains.owner_change.not_cancellable'));
        }
        $invoice = $change->line?->invoice;
        if ($invoice !== null && in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
            $kept = CancelOpenInvoices::handle([$invoice], $reason);
            if ($kept !== []) {
                throw new DisplayException((string) collect($kept)->first()?->getMessage());
            }
        }
        // A paid one cancelled: its money stays with the customer's invoice; staff refund it or move it to credit.
        $this->end($change, DomainOwnerChange::STATUS_CANCELLED, $reason);
    }

    private function end(DomainOwnerChange $change, string $status, ?string $said, ?string $handle = null, bool $done = false, mixed $sentAt = false): string
    {
        $change->forceFill(array_filter([
            'status' => $status,
            'result' => $handle !== null && $handle !== '' ? 'handle:' . $handle : ($said === null ? $change->result : mb_substr($said, 0, 2000)),
            'done_at' => $done ? now() : null,
        ], fn ($value) => $value !== null) + ($sentAt === null ? ['sent_at' => null] : []))->save();

        return $status;
    }
}
