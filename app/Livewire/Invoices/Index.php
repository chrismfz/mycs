<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Livewire\Traits\RemembersFilter;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentStatus;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    /** Every invoice on this page that may be paid together (MassPay::refusal), up to MassPay::MAX_INVOICES (PR 5). */
    public function selectAll(): void
    {
        $this->selected = $this->page()->getCollection()->filter(fn (Invoice $invoice) => $invoice->status === Invoice::STATUS_UNPAID
            && MassPay::refusal($invoice) === null)->take(MassPay::MAX_INVOICES)->map(fn (Invoice $invoice) => (string) $invoice->id)->values()->all();
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
     * @return array{count: int, total: string|null, problem: string|null}
     */
    public function selection(): array
    {
        // A crafted update may send anything: only whole numbers count.
        $ids = array_values(array_filter(array_map(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null, $this->selected)));
        $invoices = $ids === [] ? collect() : CurrentClient::model()->invoices()->whereKey($ids)->where('status', Invoice::STATUS_UNPAID)->get();
        $count = $invoices->count();
        $currencies = $invoices->pluck('currency_code')->unique();
        $problem = match (true) {
            $count < 2 => __('invoices.mass_pay_bar.pick_more'),
            $count > MassPay::MAX_INVOICES => __('invoices.mass_pay_bar.too_many', ['count' => MassPay::MAX_INVOICES]),
            $currencies->count() > 1 => __('invoices.mass_pay_bar.one_currency'),
            default => null,
        };
        $cents = $invoices->sum(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->remaining));

        return ['count' => $count, 'problem' => $problem,
            'total' => $currencies->count() === 1 ? number_format($cents / 100, 2) . ' ' . $currencies->first() : null];
    }

    /** Mass Pay (PR F2): the chosen invoices become the account's payment group, paid on its page. */
    public function payTogether()
    {
        $group = (new MassPay)->open(CurrentClient::model(), $this->selected, Auth::user());

        return $this->redirect(route('invoices.pay-together', $group), true);
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
        $unpaid = $invoices->getCollection()->filter(fn (Invoice $invoice) => $invoice->status === Invoice::STATUS_UNPAID);

        return view('invoices.index', [
            'invoices' => $invoices,
            'counts' => $counts,
            'massPay' => $massPay,
            'statuses' => $unpaid->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => PaymentStatus::for($invoice)])->all(),
            'refusals' => $massPay ? $unpaid->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => MassPay::refusal($invoice)])->all() : [],
            'selection' => $massPay ? $this->selection() : null,
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
