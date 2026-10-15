<?php

namespace App\Admin\Resources;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Models\McpProposal;
use App\Models\Staff;
use App\Services\Mcp\Proposals;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** What MCP agents proposed (docs/mcp.md): approve (it runs, once) or reject, within a day. */
class McpProposalResource extends Resource
{
    protected static ?string $model = McpProposal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-checkbox-multiple-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $navigationLabel = 'MCP Proposals';

    public static function getNavigationBadge(): ?string
    {
        $pending = McpProposal::query()->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '>', now())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['proposer', 'decider']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Proposed')->dateTime()->sortable(),
                TextColumn::make('proposer.name')->label('By (MCP)'),
                TextColumn::make('summary')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    McpProposal::STATUS_PENDING => 'warning',
                    McpProposal::STATUS_DONE => 'success',
                    McpProposal::STATUS_FAILED => 'danger',
                    default => 'gray',
                })->tooltip(fn (McpProposal $record) => $record->result),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('expires_at')->label('Expires')->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->default(McpProposal::STATUS_PENDING)->options([
                    McpProposal::STATUS_PENDING => 'Pending', McpProposal::STATUS_DONE => 'Done', McpProposal::STATUS_FAILED => 'Failed',
                    McpProposal::STATUS_REJECTED => 'Rejected', McpProposal::STATUS_EXPIRED => 'Expired',
                ]),
            ])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('ri-check-line')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (McpProposal $record) => $record->summary)
                    ->visible(fn (McpProposal $record) => $record->isOpen())
                    ->authorize(fn (McpProposal $record) => self::staff()?->can('decide', $record) ?? false)
                    ->action(function (McpProposal $record): void {
                        try {
                            $done = Proposals::approve($record, self::staff());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->warning()->title($e->getMessage())->send();

                            return;
                        }
                        $done->status === McpProposal::STATUS_DONE
                            ? Notification::make()->success()->title('Done')->body($done->result)->send()
                            : Notification::make()->danger()->title('It failed')->body($done->result)->send();
                    }),
                Action::make('reject')->label('Reject')->icon('ri-close-line')->color('gray')->requiresConfirmation()
                    ->visible(fn (McpProposal $record) => $record->isOpen())
                    ->authorize(fn (McpProposal $record) => self::staff()?->can('decide', $record) ?? false)
                    ->action(fn (McpProposal $record) => Proposals::reject($record, self::staff())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMcpProposals::route('/')];
    }
}
