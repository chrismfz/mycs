<?php

namespace App\Livewire\Domains;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\RegistrarRefused;
use App\Support\Actor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Attributes\Renderless;
use RuntimeException;
use Throwable;

/**
 * A customer's domain (D7-2, docs/domains.md §4): its dates, status and nameservers; with `managedomains` (DomainPolicy
 * update) its auto renew (off = do not renew), nameservers, transfer lock, ID protection and EPP code at its registrar,
 * and with `invoices` too «Renew» (its next period's invoice, D7a). The registrar calls are the staff's own
 * (DomainManagementService: one at a time per domain, logged with who asked), at most 10 a minute per login.
 *
 * Staff in «Login as client» act as themselves (review R1/R2 of #176): logged as staff, and only with the admin
 * permission the same change needs there (admin.domains.manage, .epp; admin.invoices.create to bill).
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

    /** Who asks, for the registrar log (R1): staff while they look through the customer's eyes, else the login. */
    private function actor(): Staff|User|null
    {
        return Actor::current();
    }

    /** Staff in «Login as client» need the admin permission the same change needs there (R2); a customer does not. */
    private function staffMay(string $permission): bool
    {
        $actor = Actor::current();

        return !Actor::impersonating() || ($actor instanceof Staff && $actor->hasPermission($permission));
    }

    /** May the login see the account's invoices (where «Renew» leads, R3)? */
    private function seesInvoices(): bool
    {
        if (Actor::impersonating()) {
            return true;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User && (bool) ClientUser::query()->where('client_id', $this->domain->client_id)->where('user_id', $user->id)
            ->whereNull('revoked_at')->first()?->allows('invoices');
    }

    private function limited(string $key): bool
    {
        if (RateLimiter::tooManyAttempts($key = $key . ':' . Auth::id(), 10)) {
            $this->notify(__('domains.portal.too_many'), 'error');

            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** May act at the registrar now: manages it (and staff may there), a registrar with an API, under the limit. */
    private function mayCallRegistrar(string $staffPermission = 'admin.domains.manage'): bool
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay($staffPermission), 404);
        if (!$this->management()->isManageable($this->domain)) {
            $this->notify(__('domains.portal.not_manageable'), 'error');

            return false;
        }

        return !$this->limited('domain-portal');
    }

    /**
     * Run a registrar change and say how it went (R5): our own messages as they are (a bad nameserver, another change
     * under way); the registrar's refusal in plain words, its text kept for staff in the log; anything else reported.
     */
    private function atRegistrar(callable $call, string $done): bool
    {
        try {
            $call();
            $this->domain->refresh();
            $this->notify($done, 'success');

            return true;
        } catch (DisplayException|InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');
        } catch (RegistrarRefused) {
            $this->notify(__('domains.portal.registrar_refused'), 'error');
        } catch (RuntimeException $e) {
            // Ours (another change under way, no EPP code): already in the customer's words; else the registrar's.
            $ours = in_array($e->getMessage(), [__('domains.manage.busy', ['domain' => $this->domain->fqdn]), __('domains.manage.no_epp_code')], true);
            if (!$ours) {
                report($e);
            }
            $this->notify($ours ? $e->getMessage() : __('domains.portal.registrar_failed'), 'error');
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('domains.portal.registrar_failed'), 'error');
        }

        return false;
    }

    /**
     * Auto renew off is «do not renew» (D-14): no renewal invoice, no notices; an invoice already sent stays payable
     * (R8: the confirmation says so). On again, it is billed as usual.
     */
    public function toggleAutoRenew(): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.update'), 404);
        $this->domain->update(['auto_renew' => !$this->domain->auto_renew]);
        $this->notify($this->domain->auto_renew ? __('domains.portal.auto_renew_turned_on') : __('domains.portal.auto_renew_turned_off'), 'success');
    }

    /**
     * Its renewal period (owner 2026-10-17): among the years its TLD prices, from its next renewal; its open, unpaid
     * renewal invoice changes with it (DomainRenewalYears). Staff in «Login as client» need the admin permission (R2).
     */
    public function setRenewalYears(int $years): void
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->staffMay('admin.domains.update'), 404);
        if ($this->limited('domain-years')) {
            return;
        }
        try {
            $outcome = app(DomainRenewalYears::class)->change($this->domain, $years, byStaff: false);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        if ($outcome !== DomainRenewalYears::UNCHANGED) {
            $this->notify(trans_choice($outcome === DomainRenewalYears::INVOICE ? 'domains.portal.years_changed_invoice' : 'domains.portal.years_changed_next',
                $years, ['years' => $years]), 'success');
        }
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
        // Who may, before anything else (R6); then as the registrar call checks them: 2-13 hosts, distinct, its pattern.
        $this->authorize('update', $this->domain);
        // As the browser sent them (any shape): a host each.
        $sent = (array) $this->nameservers;
        $this->nameservers = array_map(fn ($ns) => ['host' => is_scalar($host = data_get($ns, 'host')) ? mb_strtolower(trim((string) $host)) : ''], $sent);
        $this->validate([
            'nameservers' => 'array|min:2|max:13',
            'nameservers.*.host' => ['required', 'string', 'max:253', 'distinct', 'regex:' . DomainManagementService::HOST],
        ]);
        if (!$this->mayCallRegistrar()) {
            return;
        }
        $hosts = array_column($this->nameservers, 'host');
        // The form closes only when the registrar took them (what the customer typed is kept otherwise).
        if ($this->atRegistrar(fn () => $this->management()->setNameservers($this->domain, $hosts, $this->actor()), __('domains.portal.nameservers_saved'))) {
            $this->editNameservers = false;
            $this->mount();
        }
    }

    /** Offered as in the admin (R7): only where the registrar says the domain can be locked. */
    public function lockable(): bool
    {
        return ($this->domain->meta['registrar']['is_lockable'] ?? true) !== false;
    }

    /**
     * ID protection, offered only where its TLD offers it (D-7) and the registrar allows it (R7): whether Openprovider
     * charges for it is not settled (docs/domains.md), so not where the company does not sell it.
     */
    public function privacyOffered(): bool
    {
        return (bool) $this->domain->domainTld?->id_protection && ($this->domain->meta['registrar']['is_private_whois_allowed'] ?? true) !== false;
    }

    public function toggleTransferLock(): void
    {
        if ($this->mayCallRegistrar() && $this->lockable()) {
            $this->atRegistrar(fn () => $this->management()->setTransferLock($this->domain, !$this->domain->transfer_lock, $this->actor()), __('domains.portal.saved'));
        }
    }

    public function toggleIdProtection(): void
    {
        if ($this->mayCallRegistrar() && $this->privacyOffered()) {
            $this->atRegistrar(fn () => $this->management()->setWhoisPrivacy($this->domain, !$this->domain->id_protection, $this->actor()), __('domains.portal.saved'));
        }
    }

    /**
     * The EPP (auth) code, read from the registrar when asked: never kept, never in the page until then, logged as asked
     * (not its value). Staff in «Login as client» need admin.domains.epp, as in the admin (R2). Renderless: kept out of
     * the component's snapshot.
     */
    #[Renderless]
    public function revealEppCode(): ?string
    {
        if (!$this->mayCallRegistrar('admin.domains.epp')) {
            return null;
        }
        $code = null;
        $this->atRegistrar(function () use (&$code) {
            $code = $this->management()->eppCode($this->domain, $this->actor());
        }, __('domains.portal.epp_shown'));

        return $code;
    }

    /**
     * «Renew»: to its renewal invoice if one is open; else its next period invoiced now (D7a: the cron's rules, once per
     * period), with the account's credit applied as to any renewal (the confirmation says so). Needs the invoices too
     * (R3: where it leads). A domain set not to renew renews again once its invoice is made (R9), not before.
     */
    public function renew()
    {
        $this->authorize('update', $this->domain);
        abort_unless($this->seesInvoices() && $this->staffMay('admin.invoices.create'), 404);
        if (($open = $this->openInvoice()) !== null) {
            return $this->redirectRoute('invoices.show', $open, navigate: true);
        }
        if ($this->limited('domain-renew')) {
            return null;
        }
        $wasOff = !$this->domain->auto_renew;
        if ($wasOff) {
            $this->domain->update(['auto_renew' => true]); // billed only with auto renew on (InvoiceSelectedItems)
        }
        try {
            $result = (new InvoiceSelectedItems)->handle([], [$this->domain->fresh()]);
        } catch (Throwable $e) {
            report($e);
            $result = ['invoices' => []];
        }
        if ($result['invoices'] === []) {
            if ($wasOff) {
                $this->domain->update(['auto_renew' => false]);
            }
            // A click a moment ago made it (R9): to that one. Else, in the customer's words (R4), never the staff's reason.
            if (($open = $this->openInvoice()) !== null) {
                return $this->redirectRoute('invoices.show', $open, navigate: true);
            }
            $this->notify(__('domains.portal.cannot_renew_now'), 'error');

            return null;
        }

        return $this->redirectRoute('invoices.show', $result['invoices'][0], navigate: true);
    }

    /**
     * Its open renewal invoice the customer can see (unpaid; a draft is not theirs to see), read now.
     *
     * @phpstan-impure
     */
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
        $manageable = $canManage && $this->staffMay('admin.domains.manage') && $this->management()->isManageable($this->domain);
        $seesInvoices = $this->seesInvoices();

        return view('domains.show', [
            'canManage' => $canManage && $this->staffMay('admin.domains.update'),
            'yearOptions' => $canManage && $this->staffMay('admin.domains.update') && DomainRenewalYears::clientMay($this->domain)
                ? DomainRenewalYears::options($this->domain) : [],
            'manageable' => $manageable,
            'lockable' => $manageable && $this->lockable(),
            'privacyOffered' => $manageable && $this->privacyOffered(),
            'showEpp' => $manageable && $this->staffMay('admin.domains.epp'),
            'renewable' => $canManage && $seesInvoices && $this->staffMay('admin.invoices.create')
                && in_array($this->domain->status, RenewalBilling::BILLED_DOMAINS, true),
            'openInvoice' => $seesInvoices ? $this->openInvoice() : null,
        ])->layoutData([
            'title' => $this->domain->fqdn,
            'sidebar' => true,
        ]);
    }
}
