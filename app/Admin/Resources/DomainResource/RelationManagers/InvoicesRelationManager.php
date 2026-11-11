<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Admin\Resources\InvoiceResource\RelatedInvoiceLines;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\InvoiceItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The domain's invoice lines (RelatedInvoiceLines): its registration or transfer, its renewals with their period, its fees,
 * and the fee of a change of its holder (billed on the change, not on the domain: DomainOwnerChanges).
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Invoices');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return RelatedInvoiceLines::canView();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** @return Builder<InvoiceItem> */
    protected function getTableQuery(): Builder|Relation|null
    {
        $domain = $this->getOwnerRecord();
        abort_unless($domain instanceof Domain, 404);

        return InvoiceItem::query()->where(fn (Builder $lines) => $lines
            ->where(fn (Builder $own) => $own->where('reference_type', $domain->getMorphClass())->where('reference_id', $domain->id))
            ->orWhere(fn (Builder $changes) => $changes->where('reference_type', (new DomainOwnerChange)->getMorphClass())
                ->whereIn('reference_id', DomainOwnerChange::query()->where('domain_id', $domain->id)->select('id'))));
    }

    /** What its kind says; a line with no kind is a renewal only when it has a period (an imported one may have neither). */
    public static function type(InvoiceItem $line): ?string
    {
        if ($line->reference_type === (new DomainOwnerChange)->getMorphClass()) {
            return __('domains.owner_change.title');
        }

        return match ($line->kind) {
            InvoiceItem::KIND_REGISTER => __('Registration'),
            InvoiceItem::KIND_TRANSFER => __('Transfer'),
            InvoiceItem::KIND_DISCOUNT => __('Discount'),
            null => $line->period_start !== null || $line->period_end !== null ? __('Renewal') : null,
            default => in_array($line->kind, InvoiceItem::STAGE_FEES, true) ? __('Late fee') : __('Charge'),
        };
    }

    public function table(Table $table): Table
    {
        return RelatedInvoiceLines::table($table, fn (InvoiceItem $record) => self::type($record));
    }
}
