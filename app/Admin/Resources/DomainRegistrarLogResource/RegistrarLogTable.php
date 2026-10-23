<?php

namespace App\Admin\Resources\DomainRegistrarLogResource;

use App\Admin\Resources\DomainResource;
use App\Models\DomainRegistrarLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;

/**
 * A registrar log's columns and its view, the same for one domain (its relation manager) and for all of them (the
 * Registrar Log). Secrets were masked before they were written; an EPP code is never here.
 */
final class RegistrarLogTable
{
    /** @return list<TextColumn> */
    public static function columns(bool $withDomain): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
            $withDomain ? TextColumn::make('domain.fqdn')->label(__('Domain'))->searchable()
                ->url(fn (DomainRegistrarLog $record) => $record->domain_id !== null && DomainResource::canAccess()
                    ? DomainResource::getUrl('view', ['record' => $record->domain_id]) : null) : null,
            $withDomain ? TextColumn::make('registrarConnection.label')->label(__('Registrar'))
                ->state(fn (DomainRegistrarLog $record) => $record->registrarConnection === null ? null
                    : ($record->registrarConnection->label ?: $record->registrarConnection->registrar))->placeholder('—') : null,
            TextColumn::make('action')->label(__('Action')),
            TextColumn::make('status')->label(__('Status'))->badge()
                ->color(fn (string $state) => match ($state) {
                    DomainRegistrarLog::STATUS_FAILED => 'danger',
                    DomainRegistrarLog::STATUS_OK => 'success',
                    default => 'gray',
                }),
            // Staff, or the customer in the client area (D7-2), else the system.
            TextColumn::make('by')->label(__('By'))->placeholder(__('System'))
                ->state(fn (DomainRegistrarLog $record) => match (true) {
                    $record->staff_id !== null => $record->staff?->name,
                    $record->user_id !== null => $record->user?->name . ' (' . __('customer') . ')',
                    default => null,
                }),
            TextColumn::make('error')->label(__('Error'))->limit(80)->placeholder('—')->wrap(),
        ]));
    }

    public static function viewAction(): ViewAction
    {
        return ViewAction::make()->schema(fn (Schema $schema) => $schema->components([
            TextEntry::make('request')->label(__('Request'))
                ->state(fn (DomainRegistrarLog $record) => json_encode($record->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->fontFamily('mono')->columnSpanFull(),
            TextEntry::make('response')->label(__('Response'))
                ->state(fn (DomainRegistrarLog $record) => json_encode($record->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->fontFamily('mono')->columnSpanFull(),
            TextEntry::make('transactions')->label(__('Registry transactions'))->placeholder('—')
                ->state(fn (DomainRegistrarLog $record) => $record->transactions === null ? null : collect($record->transactions)
                    ->map(fn (array $t) => ($t['command'] ?? '?') . ' ' . ($t['code'] ?? (($t['http'] ?? 0) === 0 ? __('no answer') : 'HTTP ' . $t['http']))
                        . '  clTRID ' . ($t['clTRID'] ?? '—') . '  svTRID ' . ($t['svTRID'] ?? '—'))
                    ->all())
                ->listWithLineBreaks()->fontFamily('mono')->columnSpanFull(),
            TextEntry::make('error')->label(__('Error'))->placeholder('—')->columnSpanFull(),
        ]));
    }
}
