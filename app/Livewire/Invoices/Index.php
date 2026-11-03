<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Component;
use App\Livewire\Traits\RemembersFilter;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentStatus;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\SavedMethodCharge;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Index extends Component
{
    use RemembersFilter, WithPagination;

    /** What the filters show (P4b); a refunded invoice is with the cancelled ones: nothing is owed on either. */
    public const FILTERS = [
        'unpaid' => [Invoice::STATUS_UNPAID],
        'paid' => [Invoice::STATUS_PAID],
        'cancelled' => [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED],
    ];

    /** How many of an invoice's lines its row names; the rest are «+N more». */
    public const LINES = 2;

    #[Url('status', except: '')]
    public string $status = '';

    /** @var array<mixed> unpaid invoices chosen to pay at once (Mass Pay, PR F2); a crafted update may send anything (selection()) */
    public array $selected = [];

    /**
     * What each unpaid invoice seen on the list says (PR 5): why it cannot be paid together (MassPay::refusal) and what
     * happens to it now (PaymentStatus). Read once, the first time it is on a page, not on every tick (review of #606:
     * ~19 to ~62 queries an invoice); MassPay::open reads it all again under the locks.
     *
     * @var array<int, array{refusal: string|null, status: string|null, tone: string, showRefusal: bool}>
     */
    #[Locked]
    public array $rows = [];

    public function mount(): void
    {
        $this->recallFilter('invoices');
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
        $this->rememberFilter('invoices');
    }

    public function filter(string $status): void
    {
        $this->status = isset(self::FILTERS[$status]) && $this->status !== $status ? $status : '';
        $this->selected = []; // none chosen out of sight
        $this->rememberFilter('invoices');
        $this->resetPage();
    }

    /**
     * Every invoice on this page that may be paid together (MassPay::refusal), added to those ticked already (on other
     * pages too), up to MassPay::MAX_INVOICES (PR 5, review of #606).
     */
    public function selectAll(): void
    {
        $page = $this->page()->getCollection()->filter(fn (Invoice $invoice) => $invoice->status === Invoice::STATUS_UNPAID
            && $this->row($invoice)['refusal'] === null)->map(fn (Invoice $invoice) => (string) $invoice->id);
        $this->selected = collect($this->tickedIds())->map(fn (int $id) => (string) $id)->merge($page)->unique()
            ->take(MassPay::MAX_INVOICES)->values()->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * The invoices ticked, as the bar shows them (docs/pay-flow-ux.md, PR 5): how many, their total, and why they cannot
     * be paid together yet. Read from the account's own unpaid invoices only (a crafted id counts for nothing);
     * MassPay::open checks everything again under the locks.
     *
     * The ticked ones that count are the account's own unpaid invoices (a crafted id, or one paid meanwhile, counts for
     * nothing), and those are what payTogether sends, so the bar is ready exactly when MassPay::open takes them (review of
     * #606).
     *
     * @return array{ids: list<int>, count: int, total: string|null, problem: string|null}
     */
    public function selection(): array
    {
        $ids = $this->tickedIds();
        $invoices = $ids === [] ? collect() : CurrentClient::model()->invoices()->whereKey($ids)->where('status', Invoice::STATUS_UNPAID)
            ->with(['currency', 'items', 'adjustmentNotes', 'transactions'])->orderBy('id')->get();
        $count = $invoices->count();
        $currencies = $invoices->pluck('currency_code')->unique();
        // A payment of a ticked one that started after it was read (another tab, a SEPA payment): read again for all of
        // them at once, the rest of the refusal as first read (ADV-606-1, at a cost that does not grow: ADV-606-5).
        $onItsWay = $this->onItsWay($invoices);
        $refused = $invoices->first(fn (Invoice $invoice) => in_array((int) $invoice->id, $onItsWay, true) || $this->row($invoice)['refusal'] !== null);
        $problem = match (true) {
            $count < 2 => __('invoices.mass_pay_bar.pick_more'),
            $count > MassPay::MAX_INVOICES => __('invoices.mass_pay_bar.too_many', ['count' => MassPay::MAX_INVOICES]),
            $currencies->count() > 1 => __('invoices.mass_pay_bar.one_currency'),
            $refused !== null => __('invoices.mass_pay_bar.untick', ['invoice' => $refused->displayTitle()]),
            default => null,
        };
        $cents = $invoices->sum(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->remaining));

        return ['ids' => $invoices->pluck('id')->map(fn ($id) => (int) $id)->all(), 'count' => $count, 'problem' => $problem,
            // In the currency's own format, as the cards show their amounts (review of #606).
            'total' => $currencies->count() === 1 ? (string) $invoices->first()->formattedTotal->format($cents / 100) : null];
    }

    /** Mass Pay (PR F2): the chosen invoices become the account's payment group, paid on its page. */
    public function payTogether()
    {
        $selection = $this->selection();
        if ($selection['problem'] !== null) {
            return $this->notify($selection['problem'], 'error');
        }
        $group = (new MassPay)->open(CurrentClient::model(), $selection['ids'], Auth::user());

        return $this->redirect(route('invoices.pay-together', $group), true);
    }

    /**
     * The ones of these with a payment on its way now, in a few queries whatever their number: a payment the gateway
     * processes (theirs, or a Mass Pay group's they are in), or a saved method's charge not settled (SavedMethodCharge::
     * unresolved, of them or of their groups). MassPay::refusal asks the same, one invoice at a time, and open() asks it
     * again under the locks.
     *
     * @param  Collection<int, Invoice>  $invoices  with their transactions loaded
     * @return list<int>
     */
    private function onItsWay(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }
        $ids = $invoices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $groupsOf = DB::table('payment_group_invoice')->whereIn('invoice_id', $ids)->get(['invoice_id', 'payment_group_id'])
            ->groupBy('invoice_id')->map(fn ($rows) => $rows->pluck('payment_group_id')->map(fn ($id) => (int) $id)->all());
        $groups = $groupsOf->flatten()->unique()->values()->all();
        $heldGroups = $groups === [] ? [] : PaymentGroup::query()->whereIn('id', $groups)
            ->whereIn('status', [PaymentGroup::STATUS_OPEN, PaymentGroup::STATUS_REPLACED])
            ->whereHas('transaction', fn ($query) => $query->where('status', InvoiceTransactionStatus::Processing))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unresolved = SavedMethodCharge::unresolved($ids, null, $groups);
        $chargedInvoices = $unresolved->pluck('invoice_id')->map(fn ($id) => (int) $id)->all();
        $chargedGroups = $unresolved->pluck('payment_group_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $invoices->filter(function (Invoice $invoice) use ($groupsOf, $heldGroups, $chargedInvoices, $chargedGroups) {
            $mine = $groupsOf->get($invoice->id, []);

            return $invoice->transactions->contains('status', InvoiceTransactionStatus::Processing)
                || in_array((int) $invoice->id, $chargedInvoices, true)
                || array_intersect($mine, [...$heldGroups, ...$chargedGroups]) !== [];
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<int> the ticked ids that are whole numbers: a crafted update may send anything */
    private function tickedIds(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null, $this->selected))));
    }

    /** @return array{refusal: string|null, status: string|null, tone: string, showRefusal: bool} */
    private function row(Invoice $invoice): array
    {
        if (!isset($this->rows[$invoice->id])) {
            $status = PaymentStatus::for($invoice);
            // Said by the status already (a payment on its way, an ended service): its refusal is not said twice.
            $quiet = in_array($status->state, [PaymentStatus::DUE, PaymentStatus::OVERDUE, PaymentStatus::UNPAID], true);
            $this->rows[$invoice->id] = [
                'refusal' => MassPay::refusal($invoice),
                'status' => $quiet ? null : $status->title(),
                'tone' => $status->tone(),
                'showRefusal' => !$status->waiting() && $status->state !== PaymentStatus::ENDED,
            ];
        }

        return $this->rows[$invoice->id];
    }

    /**
     * What an invoice is for, one line per thing (its lines' first lines, each once), for its row.
     *
     * @return list<string>
     */
    public static function lines(Invoice $invoice): array
    {
        return $invoice->items->map(fn ($item) => trim(strtok((string) $item->description, "\n") ?: ''))
            ->filter()->unique()->values()->all();
    }

    /** @return HasMany<Invoice, Client> */
    private function issued()
    {
        return CurrentClient::model()->invoices()->where('status', '!=', Invoice::STATUS_DRAFT);
    }

    /** @return LengthAwarePaginator<int, Invoice> this page of the list, as shown */
    private function page(): LengthAwarePaginator
    {
        if (!isset(self::FILTERS[$this->status])) { // a crafted update (review of #230): all, not a 500
            $this->status = '';
        }

        return $this->issued()->when($this->status !== '', fn ($q) => $q->whereIn('status', self::FILTERS[$this->status]))
            ->with(['client.primaryContact', 'client.ownerGrant.user', 'snapshot', 'items', 'adjustmentNotes', 'currency'])
            ->orderBy('id', 'desc')->paginate(config('settings.pagination'));
    }

    public function render()
    {
        $counts = ['' => $this->issued()->count()];
        foreach (self::FILTERS as $name => $statuses) {
            $counts[$name] = $this->issued()->whereIn('status', $statuses)->count();
        }
        $invoices = $this->page();
        // Where unpaid invoices are listed (review of #230: not on the paid or cancelled ones).
        $massPay = MassPay::enabled() && $counts['unpaid'] >= 2 && in_array($this->status, ['', 'unpaid'], true);
        // Each unpaid one: what happens to it now (PaymentStatus, PR 2), and why it cannot be paid together (PR 5).
        $rows = $invoices->getCollection()->filter(fn (Invoice $invoice) => $invoice->status === Invoice::STATUS_UNPAID)
            ->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $this->row($invoice)])->all();

        return view('invoices.index', [
            'invoices' => $invoices,
            'counts' => $counts,
            'massPay' => $massPay,
            'rowInfo' => $rows,
            'selection' => $massPay ? $this->selection() : null,
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
