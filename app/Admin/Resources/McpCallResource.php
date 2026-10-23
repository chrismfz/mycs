<?php

namespace App\Admin\Resources;

use App\Admin\Resources\McpCallResource\Pages\ListMcpCalls;
use App\Classes\PermissionGuard;
use App\Models\McpCall;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Who called which MCP tool, with what, and how it ended (docs/mcp.md). Read only, 90 days. */
class McpCallResource extends Resource
{
    protected static ?string $model = McpCall::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-robot-2-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $navigationLabel = 'MCP Calls';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('staff'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('staff.name')->label('Staff'),
                TextColumn::make('tool')->searchable(),
                // What was looked up (emails, domains, license keys) needed each tool's own permission: shown to full
                // admins only, whoever else sees the call list (security audit SA-11).
                TextColumn::make('arguments')->state(fn (McpCall $record) => PermissionGuard::isFullAdmin(auth()->user())
                    ? json_encode($record->arguments ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : __('(hidden)'))->limit(80),
                TextColumn::make('outcome')->badge()->color(fn (string $state) => match ($state) {
                    'ok' => 'success',
                    'error' => 'warning',
                    default => 'danger',
                })->tooltip(fn (McpCall $record) => $record->error),
                TextColumn::make('duration_ms')->label('ms')->numeric(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outcome')->options(['ok' => 'OK', 'error' => 'Error', 'denied' => 'Denied']),
                SelectFilter::make('tool')->options(fn () => McpCall::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMcpCalls::route('/')];
    }
}
