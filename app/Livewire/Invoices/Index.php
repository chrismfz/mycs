<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Livewire\Traits\RemembersFilter;
use App\Models\Invoice;
use App\Services\Invoice\MassPay;
use App\Support\CurrentClient;
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

    /** @var list<int|string> unpaid invoices chosen to pay at once (Mass Pay, PR F2) */
    public array $selected = [];

    public function mount(): void
    {
        $this->recallFilter('invoices');
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
    }

    public function filter(string $status): void
    {
        $this->status = isset(self::FILTERS[$status]) && $this->status !== $status ? $status : '';
        $this->rememberFilter('invoices');
        $this->resetPage();
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

    public function render()
    {
        $client = CurrentClient::model();
        $issued = fn () => $client->invoices()->where('status', '!=', Invoice::STATUS_DRAFT);
        $counts = ['' => $issued()->count()];
        foreach (self::FILTERS as $name => $statuses) {
            $counts[$name] = $issued()->whereIn('status', $statuses)->count();
        }

        return view('invoices.index', [
            'invoices' => $issued()->when($this->status !== '', fn ($q) => $q->whereIn('status', self::FILTERS[$this->status]))
                ->with(['client.primaryContact', 'client.ownerGrant.user', 'snapshot', 'items', 'transactions', 'currency'])
                ->orderBy('id', 'desc')->paginate(config('settings.pagination')),
            'counts' => $counts,
            'massPay' => MassPay::enabled() && $counts['unpaid'] >= 2,
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
