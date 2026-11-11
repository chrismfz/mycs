<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Admin\Resources\InvoiceResource\RelatedInvoiceLines;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The service's invoice lines (RelatedInvoiceLines): its own, its package changes' and its addons', each saying which.
 * «Invoices» next to it stays the list per invoice, with each invoice's total.
 */
class RelatedInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceItems';

    /** @var Collection<int, Service>|null its addons by id, read once a request */
    private ?Collection $addons = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Related invoices');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return RelatedInvoiceLines::canView();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** @return Collection<int, Service> */
    private function addons(): Collection
    {
        $service = $this->getOwnerRecord();

        return $this->addons ??= $service instanceof Service ? $service->addons()->with('product')->get()->keyBy('id') : new Collection;
    }

    /** @return Builder<InvoiceItem> */
    protected function getTableQuery(): Builder|Relation|null
    {
        $service = $this->getOwnerRecord();
        abort_unless($service instanceof Service, 404);
        $services = [$service->id, ...$this->addons()->keys()->all()];

        return InvoiceItem::query()->where(fn (Builder $lines) => $lines
            ->where(fn (Builder $own) => $own->where('reference_type', (new Service)->getMorphClass())->whereIn('reference_id', $services))
            ->orWhere(fn (Builder $changes) => $changes->where('reference_type', (new ServiceUpgrade)->getMorphClass())
                ->whereIn('reference_id', $service->upgrade()->select('id'))));
    }

    public function type(InvoiceItem $line): string
    {
        $what = match (true) {
            $line->reference_type === (new ServiceUpgrade)->getMorphClass() => __('Package change'),
            (int) $line->reference_id !== (int) $this->getOwnerRecord()->getKey() => __('Addon')
                . (($name = $this->addons()->get((int) $line->reference_id)?->product?->name) ? ': ' . $name : ''),
            default => __('Service'),
        };

        return $what . match ($line->kind) {
            InvoiceItem::KIND_CHARGE => ' · ' . __('Charge'),
            InvoiceItem::KIND_DISCOUNT => ' · ' . __('Discount'),
            default => '',
        };
    }

    public function table(Table $table): Table
    {
        return RelatedInvoiceLines::table($table, fn (InvoiceItem $record) => $this->type($record));
    }
}
