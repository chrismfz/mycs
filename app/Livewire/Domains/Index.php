<?php

namespace App\Livewire\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Livewire\Traits\RemembersFilter;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\RegistrarRefused;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

/**
 * The customer's domains (D7-2, as WHMCS's «My Domains»): name, registration and expiry, auto renew, status; filtered
 * by active, expired (still recoverable) and ended. Each opens its page, where it is managed. Several at once (as
 * WHMCS's renewal cart and «bulkdomain», used by myip's customers every day): renewed together on one invoice, their
 * nameservers set, auto renew on or off, locked or unlocked, each as its own page would, under the same checks.
 */
class Index extends Component
{
    use RemembersFilter, WithPagination;

    public const FILTERS = [
        'active' => [DomainStatus::Active, DomainStatus::PendingRegister, DomainStatus::PendingTransfer],
        'expired' => [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption],
        'ended' => [DomainStatus::Cancelled, DomainStatus::TransferredAway, DomainStatus::Deleted],
    ];

    #[Url('status', except: '')]
    public string $status = '';

    #[Url('q', except: '')]
    public string $search = '';

    /** At most this many domains in one bulk action (each is its own registrar call). */
    public const BULK_MAX = 20;

    /** @var list<int|string> the domains ticked (their ids: only the account's own are ever read) */
    public array $selected = [];

    /** @var array<int, mixed> nameservers for the ticked domains, as the browser sends them */
    public array $bulkNameservers = ['', ''];

    /** The bulk form shown: '', 'nameservers'. */
    public string $bulkForm = '';

    /** An unknown filter (an old or crafted link, review R10) shows them all. */
    public function mount(): void
    {
        $this->recallFilter('domains');
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
        $this->rememberFilter('domains');
    }

    public function updatedStatus(): void
    {
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filter(string $status): void
    {
        $this->status = isset(self::FILTERS[$status]) && $this->status !== $status ? $status : '';
        $this->rememberFilter('domains');
        $this->resetPage();
    }

    /**
     * Auto renew from its row (P4b), as on its page (Show::toggleAutoRenew): the account's own domain, with
     * `managedomains`; staff in «Login as client» need the admin permission.
     */
    public function toggleAutoRenew(int $id): void
    {
        $domain = CurrentClient::model()->domains()->whereKey($id)->firstOrFail();
        $this->authorize('update', $domain);
        abort_unless($this->staffMay(), 404);
        $domain->update(['auto_renew' => !$domain->auto_renew]);
        $this->notify(__($domain->auto_renew ? 'domains.portal.auto_renew_turned_on' : 'domains.portal.auto_renew_turned_off'), 'success');
    }

    private function staffMay(string $permission = 'admin.domains.update'): bool
    {
        $actor = Actor::current();

        return !Actor::impersonating() || ($actor instanceof Staff && $actor->hasPermission($permission));
    }

    /**
     * The ticked domains: the account's own only (an id of another account's is never read), at most BULK_MAX, each one
     * the login may manage (`managedomains`).
     *
     * @return Collection<int, Domain>
     */
    private function ticked(): Collection
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $this->selected), fn (int $id) => $id > 0))), 0, self::BULK_MAX);
        // An ended one is never acted on (and its policy says so): left out before the rest are authorized.
        $domains = CurrentClient::model()->domains()->whereKey($ids)->orderBy('fqdn')->get()->reject(fn (Domain $domain) => $domain->status->isTerminal())->values();
        foreach ($domains as $domain) {
            $this->authorize('update', $domain);
        }

        return $domains;
    }

    /**
     * The page's domains ticked, or all unticked when they all were (only ids: ticked() reads the account's own).
     *
     * @param  array<int, mixed>  $ids
     */
    public function togglePage(array $ids): void
    {
        $ids = array_map('strval', array_filter(array_map('intval', $ids), fn (int $id) => $id > 0));
        $selected = array_map('strval', $this->selected);
        $this->selected = array_diff($ids, $selected) === [] ? array_values(array_diff($selected, $ids)) : array_values(array_unique(array_merge($selected, $ids)));
    }

    /** One bulk action at a time, a few a quarter-hour per login (each may be up to BULK_MAX registrar calls). */
    private function bulkLimited(): bool
    {
        if (RateLimiter::tooManyAttempts($key = 'domain-bulk:' . Auth::id(), 6)) {
            $this->notify(__('domains.portal.too_many'), 'error');

            return true;
        }
        RateLimiter::hit($key, 900);

        return false;
    }

    /** The account's invoices are the login's to see (`invoices`), as a renewal makes one. */
    private function seesInvoices(): bool
    {
        if (Actor::impersonating()) {
            return true;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User && (bool) ClientUser::query()->where('client_id', CurrentClient::model()->id)->where('user_id', $user->id)
            ->whereNull('revoked_at')->first()?->allows('invoices');
    }

    /**
     * The ticked domains renewed together (as its page's «Renew», D7a): each one's next period on one invoice per
     * currency, the account's credit applied as to any renewal; one already on an open renewal invoice goes to that
     * invoice, never billed twice. Auto renew is turned on for those billed (a renewal is billed only with it on), back
     * off for those that were not.
     */
    public function bulkRenew()
    {
        abort_unless($this->seesInvoices() && $this->staffMay('admin.invoices.create'), 404);
        $domains = $this->ticked()->reject(fn (Domain $domain) => $domain->expires_at === null);
        if ($domains->isEmpty()) {
            $this->notify(__('domains.portal.bulk.none_renewable'), 'error');

            return null;
        }
        if ($this->bulkLimited()) {
            return null;
        }
        $open = [];
        $toBill = [];
        foreach ($domains as $domain) {
            $line = DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay(), payable: true);
            $line?->invoice?->status === Invoice::STATUS_UNPAID ? $open[$line->invoice_id] = true : $toBill[] = $domain;
        }
        $turnedOn = [];
        foreach ($toBill as $domain) {
            if (!$domain->auto_renew) {
                $domain->update(['auto_renew' => true]);
                $turnedOn[$domain->id] = $domain;
            }
        }
        $result = ['invoices' => [], 'billed' => [], 'skipped' => [], 'problems' => []];
        if ($toBill !== []) {
            try {
                $result = (new InvoiceSelectedItems)->handle([], array_map(fn (Domain $domain) => $domain->fresh(), $toBill));
            } catch (Throwable $e) {
                report($e);
            }
        }
        $billed = array_flip($result['billed']);
        foreach ($turnedOn as $domain) {
            if (!isset($billed[$domain->fqdn])) {
                $domain->update(['auto_renew' => false]); // not billed: as it was
            }
        }
        $invoices = array_values(array_unique(array_merge($result['invoices'], array_keys($open))));
        // Those meant to be billed and not billed (one already on an open invoice goes to it: renewed, not to tell).
        $notRenewed = collect($toBill)->reject(fn (Domain $domain) => isset($billed[$domain->fqdn]))->pluck('fqdn')->all();
        if ($notRenewed !== []) {
            $this->notify(__('domains.portal.bulk.not_renewed', ['domains' => implode(', ', $notRenewed)]), 'error');
        }
        if ($invoices === []) {
            return null;
        }
        $this->selected = [];

        return count($invoices) === 1 ? $this->redirectRoute('invoices.show', $invoices[0], navigate: true) : $this->redirectRoute('invoices', navigate: true);
    }

    /** Auto renew on or off for the ticked domains (as from each row; off is «do not renew», D-14). */
    public function bulkAutoRenew(bool $on): void
    {
        abort_unless($this->staffMay(), 404);
        $domains = $this->ticked();
        foreach ($domains as $domain) {
            $domain->update(['auto_renew' => $on]);
        }
        $this->notify(__($on ? 'domains.portal.bulk.auto_renew_on' : 'domains.portal.bulk.auto_renew_off', ['count' => $domains->count()]), 'success');
    }

    /** The same nameservers for the ticked domains, each set at its registrar as from its page; how each went is told. */
    public function bulkSetNameservers(): void
    {
        abort_unless($this->staffMay('admin.domains.manage'), 404);
        // As the browser sent them (any shape): a host each.
        $this->bulkNameservers = array_values(array_filter(array_map(fn (mixed $ns) => is_scalar($ns) ? mb_strtolower(trim((string) $ns)) : '', (array) $this->bulkNameservers),
            fn (string $ns) => $ns !== ''));
        $this->validate([
            'bulkNameservers' => 'array|min:2|max:13',
            'bulkNameservers.*' => ['required', 'string', 'max:253', 'distinct', 'regex:' . DomainManagementService::HOST],
        ]);
        $hosts = $this->bulkNameservers;
        $this->atRegistrarEach(fn (DomainManagementService $management, Domain $domain) => $management->setNameservers($domain, $hosts, Actor::current()));
        $this->bulkForm = '';
    }

    /** The ticked domains locked or unlocked against transfer, where their registrar can (as from each page). */
    public function bulkTransferLock(bool $lock): void
    {
        abort_unless($this->staffMay('admin.domains.manage'), 404);
        $this->atRegistrarEach(function (DomainManagementService $management, Domain $domain) use ($lock) {
            if (($domain->meta['registrar']['is_lockable'] ?? true) === false || !$management->registrarSupports($domain, 'supportsTransferLock')) {
                throw new DisplayException(__('domains.portal.bulk.not_lockable'));
            }
            if ((bool) $domain->transfer_lock !== $lock) {
                $management->setTransferLock($domain, $lock, Actor::current());
            }
        });
    }

    /**
     * A registrar change for each ticked domain, one after the other (each its own lock and log in the service), and one
     * message: those done, and each one not done with why, in the customer's words (as its page's).
     */
    private function atRegistrarEach(callable $change): void
    {
        $domains = $this->ticked();
        if ($domains->isEmpty() || $this->bulkLimited()) {
            return;
        }
        $management = app(DomainManagementService::class);
        $done = [];
        $failed = [];
        foreach ($domains as $domain) {
            if (!$management->isManageable($domain)) {
                $failed[$domain->fqdn] = __('domains.portal.not_manageable');

                continue;
            }
            try {
                $change($management, $domain);
                $done[] = $domain->fqdn;
            } catch (DisplayException|InvalidArgumentException $e) {
                $failed[$domain->fqdn] = $e->getMessage();
            } catch (RegistrarRefused) {
                $failed[$domain->fqdn] = __('domains.portal.registrar_refused');
            } catch (RuntimeException $e) {
                $ours = in_array($e->getMessage(), [__('domains.manage.busy', ['domain' => $domain->fqdn])], true);
                if (!$ours) {
                    report($e);
                }
                $failed[$domain->fqdn] = $ours ? $e->getMessage() : __('domains.portal.registrar_failed');
            } catch (Throwable $e) {
                report($e);
                $failed[$domain->fqdn] = __('domains.portal.registrar_failed');
            }
        }
        if ($done !== []) {
            $this->notify(__('domains.portal.bulk.done', ['domains' => implode(', ', $done)]), 'success');
        }
        if ($failed !== []) {
            $this->notify(__('domains.portal.bulk.failed', ['domains' => implode('; ', array_map(fn ($d, $why) => "{$d}: {$why}", array_keys($failed), $failed))]), 'error');
        }
    }

    public function render()
    {
        $domains = CurrentClient::model()->domains();
        $counts = [];
        foreach (self::FILTERS as $name => $statuses) {
            $counts[$name] = (clone $domains)->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, $statuses))->count();
        }
        $query = $domains->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('fqdn')
            ->when($this->status !== '', fn ($q) => $q->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, self::FILTERS[$this->status])))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('fqdn', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->search))) . '%'));

        $page = $query->paginate(config('settings.pagination'));
        // Whose auto renew can be turned on or off from its row. The policy is asked once (review of #230: a grant query
        // per row): past a domain's status, its answer is the account's, the same for every row of this list.
        $open = $page->getCollection()->reject(fn (Domain $domain) => $domain->status->isTerminal());
        $toggleable = $open->isNotEmpty() && $this->staffMay() && Gate::allows('update', $open->first()) ? $open->modelKeys() : [];

        return view('domains.index', [
            'domains' => $page,
            'toggleable' => $toggleable,
            // Bulk actions (as each page's): ticking needs `managedomains`; renewing, the invoices too.
            'bulk' => $toggleable !== [],
            'bulkRenew' => $toggleable !== [] && $this->seesInvoices() && $this->staffMay('admin.invoices.create'),
            'bulkRegistrar' => $toggleable !== [] && $this->staffMay('admin.domains.manage'),
            'counts' => $counts,
        ])->layoutData([
            'title' => __('domains.domains'),
            'sidebar' => true,
        ]);
    }
}
