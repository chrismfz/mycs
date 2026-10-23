<?php

namespace App\Admin\Resources\Audits\Schemas;

use App\Models\Audit;
use App\Support\AuditSummary;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;

class AuditInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user_id')
                    ->label('User')
                    ->state(fn (Audit $record): string => AuditSummary::actor($record))
                    ->helperText(fn (Audit $record): ?string => AuditSummary::actorKind($record))
                    ->url(fn (Audit $record): ?string => AuditSummary::actorUrl($record)),
                TextEntry::make('event')
                    ->label('Log Entry')
                    ->state(fn (Audit $record): string => AuditSummary::verb($record->event) . ' ' . AuditSummary::subject($record))
                    ->url(fn (Audit $record): ?string => AuditSummary::subjectUrl($record)),
                TextEntry::make('client')
                    ->label('Client')
                    ->state(fn (Audit $record): ?string => AuditSummary::client($record)?->name)
                    ->url(fn (Audit $record): ?string => AuditSummary::clientUrl(AuditSummary::client($record)))
                    ->placeholder('—'),
                TextEntry::make('user_agent'),
                TextEntry::make('ip_address'),
                TextEntry::make('url'),
                TextEntry::make('created_at')
                    ->dateTime(),
                ViewEntry::make('changes')
                    ->label('Changes')
                    ->view('admin.infolists.components.difference')
                    ->columnSpanFull(),

            ]);
    }
}
