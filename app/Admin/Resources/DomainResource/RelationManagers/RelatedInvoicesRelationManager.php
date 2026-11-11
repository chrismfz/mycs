<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Admin\Resources\InvoiceResource\RelatedInvoiceLines;
use App\Models\InvoiceItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The domain's invoice lines (RelatedInvoiceLines): its registration or transfer, its renewals with their period, its fees. */
class RelatedInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceItems';

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

    /** What its kind says; a line with no kind is a renewal only when it has a period (an imported one may have neither). */
    public static function type(InvoiceItem $line): ?string
    {
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
