<?php

namespace App\Livewire\Domains;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRenewalService;
use App\Support\Actor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * A customer's domain (D7-2, docs/domains.md §4): its dates, status and nameservers; with `managedomains` (DomainPolicy
 * update) its auto renew (off = do not renew), nameservers, transfer lock, ID protection and EPP code at its registrar,
 * and «Renew» (its next period's invoice, D7a). The registrar calls are the staff's own (DomainManagementService: one at
 * a time per domain, logged with who asked), at most 10 a minute per login.
 */
class Show extends Component
{
    public Domain $domain;

    /** @var list<array{host: string}> */
    public array $nameservers = [];

    public bool $editNameservers = false;

    public function mount(): void
    {
        $this->nameservers = $this->domain->nameservers->map(fn ($ns) => ['host' => $ns->host])->values()->all();
    }

    private function management(): DomainManagementService
    {
        return app(DomainManagementService::class);
    }

    /** The login that asks, for the registrar log (staff «Login as client» counts as the customer's login). */
    private function actor(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /** May act at the registrar now: manages it, a registrar with an API, under the per-login limit. */
    private function mayCallRegistrar(): bool
    {
        $this->authorize('update', $this->domain);
        if (!$this->management()->isManageable($this->domain)) {
            $this->notify(__('domains.portal.not_manageable'), 'error');

            return false;
        }
        if (RateLimiter::tooManyAttempts($key = 'domain-portal:' . Auth::id(), 10)) {
            $this->notify(__('domains.portal.too_many'), 'error');

            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }

    /** Run a registrar change and say how it went (the registrar's own words when it refuses). */
    private function atRegistrar(callable $call, string $done): void
    {
        try {
            $call();
            $this->domain->refresh();
            $this->notify($done, 'success');
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('domains.portal.registrar_failed'), 'error');
        }
    }

    /** Auto renew off is «do not renew» (D-14): no renewal invoice, no notices; on again, it is billed as usual. */
    public function toggleAutoRenew(): void
    {
        $this->authorize('update', $this->domain);
        $this->domain->update(['auto_renew' => !$this->domain->auto_renew]);
        $this->notify($this->domain->auto_renew ? __('domains.portal.auto_renew_turned_on') : __('domains.portal.auto_renew_turned_off'), 'success');
    }

    public function addNameserver(): void
    {
        if (count($this->nameservers) < 13) {
            $this->nameservers[] = ['host' => ''];
        }
    }

    public function removeNameserver(int $index): void
    {
        unset($this->nameservers[$index]);
        $this->nameservers = array_values($this->nameservers);
    }

    public function saveNameservers(): void
    {
        $this->validate([
            'nameservers' => 'array|min:2|max:13',
            'nameservers.*.host' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/i'],
        ]);
        if (!$this->mayCallRegistrar()) {
            return;
        }
        $hosts = array_map(fn ($ns) => mb_strtolower(trim($ns['host'])), $this->nameservers);
        $this->atRegistrar(fn () => $this->management()->setNameservers($this->domain, $hosts, $this->actor()), __('domains.portal.nameservers_saved'));
        $this->editNameservers = false;
        $this->mount();
    }

    public function toggleTransferLock(): void
    {
        if ($this->mayCallRegistrar()) {
            $this->atRegistrar(fn () => $this->management()->setTransferLock($this->domain, !$this->domain->transfer_lock, $this->actor()), __('domains.portal.saved'));
        }
    }

    public function toggleIdProtection(): void
    {
        if ($this->mayCallRegistrar()) {
            $this->atRegistrar(fn () => $this->management()->setWhoisPrivacy($this->domain, !$this->domain->id_protection, $this->actor()), __('domains.portal.saved'));
        }
    }

    /**
     * The EPP (auth) code, read from the registrar when asked: never kept, never in the page until then, logged as asked
     * (not its value). Renderless: kept out of the component's snapshot.
     */
    #[Renderless]
    public function revealEppCode(): ?string
    {
        if (!$this->mayCallRegistrar()) {
            return null;
        }
        try {
            return $this->management()->eppCode($this->domain, $this->actor());
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('domains.portal.registrar_failed'), 'error');
        }

        return null;
    }

    /**
     * «Renew»: to its renewal invoice if one is open; else its next period invoiced now (D7a: the cron's rules, once per
     * period). A domain set not to renew is set to renew again first: asking to renew says so.
     */
    public function renew()
    {
        $this->authorize('update', $this->domain);
        $open = $this->openInvoice();
        if ($open !== null) {
            return $this->redirectRoute('invoices.show', $open, navigate: true);
        }
        if (!$this->domain->auto_renew) {
            $this->domain->update(['auto_renew' => true]);
        }
        try {
            $result = (new InvoiceSelectedItems)->handle([], [$this->domain->fresh()]);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }
        if ($result['invoices'] === []) {
            $this->notify(collect($result['skipped'])->first() ?? __('domains.portal.nothing_to_renew'), 'error');

            return null;
        }

        return $this->redirectRoute('invoices.show', $result['invoices'][0], navigate: true);
    }

    /** Its open renewal invoice the customer can see (unpaid; a draft is not theirs to see). */
    private function openInvoice(): ?Invoice
    {
        if ($this->domain->expires_at === null) {
            return null;
        }
        $line = DomainRenewalService::billedLine($this->domain, $this->domain->expires_at->copy()->startOfDay(), payable: true);

        return $line?->invoice?->status === Invoice::STATUS_UNPAID ? $line->invoice : null;
    }

    public function render()
    {
        $canManage = Gate::allows('update', $this->domain);
        $renewable = in_array($this->domain->status, RenewalBilling::BILLED_DOMAINS, true);

        return view('domains.show', [
            'canManage' => $canManage,
            'manageable' => $canManage && $this->management()->isManageable($this->domain),
            'renewable' => $canManage && $renewable,
            'openInvoice' => $this->openInvoice(),
            'impersonating' => Actor::impersonating(),
        ])->layoutData([
            'title' => $this->domain->fqdn,
            'sidebar' => true,
        ]);
    }
}
