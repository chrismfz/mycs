<?php

namespace App\Admin\Resources;

use App\Admin\Resources\McpOauthGrantResource\Pages\ListMcpOauthGrants;
use App\Models\McpOauthGrant;
use App\Models\Staff;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** The MCP clients staff approved (docs/mcp.md): who, which client, how, when last used; Revoke. */
class McpOauthGrantResource extends Resource
{
    protected static ?string $model = McpOauthGrant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shield-keyhole-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $navigationLabel = 'MCP Authorizations';

    /** Own grants only, unless they manage the API keys. */
    public static function getEloquentQuery(): Builder
    {
        $staff = Auth::guard('staff')->user();
        $query = parent::getEloquentQuery()->with(['client', 'staff', 'apiKey']);

        return $staff instanceof Staff && $staff->hasPermission('admin.api_keys.viewAny') ? $query : $query->where('staff_id', $staff?->getAuthIdentifier() ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('client.name')->label('Client'),
                TextColumn::make('staff.name')->label('Staff'),
                TextColumn::make('approved_by')->label('Approved with')->formatStateUsing(fn (McpOauthGrant $record) => $record->approved_by === 'key'
                    ? 'API key: ' . ($record->apiKey->name ?? '—') : 'Admin login'),
                TextColumn::make('created_at')->label('Approved')->dateTime()->sortable(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('last_ip')->label('Last token from')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('revoked_at')->label('Revoked')->since()->placeholder('—')->tooltip(fn (McpOauthGrant $record) => $record->revoked_reason),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Active')->default(true)
                    ->queries(true: fn (Builder $query) => $query->whereNull('revoked_at'), false: fn (Builder $query) => $query->whereNotNull('revoked_at')),
            ])
            ->recordActions([
                Action::make('revoke')->label('Revoke')->color('danger')->icon('ri-close-circle-line')->requiresConfirmation()
                    ->visible(fn (McpOauthGrant $record) => $record->revoked_at === null)
                    ->authorize(fn (McpOauthGrant $record) => Auth::guard('staff')->user()?->can('revoke', $record) ?? false)
                    ->action(function (McpOauthGrant $record): void {
                        $record->revoke('Revoked by ' . (Auth::guard('staff')->user()->name ?? 'staff'));
                        Notification::make()->title('Revoked: its tokens stop working now')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMcpOauthGrants::route('/')];
    }
}
