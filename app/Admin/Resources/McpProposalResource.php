<?php

namespace App\Admin\Resources;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Models\McpProposal;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mcp\Proposals;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
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
        // Only those they may decide (or asked): a domain's action is not counted for service-only staff (#584 review, F1).
        $staff = self::staff();
        $pending = self::getEloquentQuery()->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '>', now())->get()
            ->filter(fn ($proposal) => $proposal instanceof McpProposal && Proposals::followable($proposal, $staff))->count();

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

    /** Their own proposals, and those they may decide (review R5: a resend's recipients and subject are not for everyone). */
    public static function getEloquentQuery(): Builder
    {
        return Proposals::visibleTo(parent::getEloquentQuery(), self::staff());
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
                    McpProposal::STATUS_PENDING => 'Pending', McpProposal::STATUS_APPROVED => 'Running', McpProposal::STATUS_DONE => 'Done', McpProposal::STATUS_FAILED => 'Failed',
                    McpProposal::STATUS_REJECTED => 'Rejected', McpProposal::STATUS_EXPIRED => 'Expired',
                ]),
            ])
            ->recordActions([
                // The whole of it, without leaving the list (owner 2026-11-19: the summary is cut): read only.
                Action::make('view')->label('View')->icon('ri-eye-line')->color('gray')
                    ->modalHeading(fn (McpProposal $record) => 'Proposal #' . $record->id . ' (' . $record->status . ')')
                    ->modalContent(fn (McpProposal $record) => self::details($record))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                // A drafted reply is used from its ticket, never sent by approving it.
                Action::make('openTicket')->label('Open the ticket')->icon('ri-chat-1-line')
                    ->visible(fn (McpProposal $record) => $record->type === Proposals::TICKET_REPLY && $record->isOpen())
                    ->url(fn (McpProposal $record) => TicketResource::getUrl('edit', ['record' => (int) ($record->payload['ticket_id'] ?? 0)])),
                Action::make('approve')->label('Approve')->icon('ri-check-line')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (McpProposal $record) => $record->summary)
                    ->visible(fn (McpProposal $record) => $record->isOpen() && $record->type !== Proposals::TICKET_REPLY)
                    ->authorize(fn (McpProposal $record) => self::staff()?->can('decide', $record) ?? false)
                    ->action(function (McpProposal $record): void {
                        try {
                            $done = Proposals::approve($record, self::staff());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->warning()->title(e($e->getMessage()))->send();

                            return;
                        }
                        $done->status === McpProposal::STATUS_DONE
                            ? Notification::make()->success()->title('Done')->body(e($done->result))->send()
                            : Notification::make()->danger()->title('It failed')->body(e($done->result))->send();
                    }),
                Action::make('reject')->label('Reject')->icon('ri-close-line')->color('gray')->requiresConfirmation()
                    ->visible(fn (McpProposal $record) => $record->isOpen())
                    ->authorize(fn (McpProposal $record) => self::staff()?->can('decide', $record) ?? false)
                    ->action(fn (McpProposal $record) => Proposals::reject($record, self::staff())),
            ]);
    }

    /**
     * What a proposal says, whole: its summary, a drafted reply's text as written (escaped, links as plain text: it may
     * carry what a customer wrote, as on the ticket page), and what came of it.
     */
    public static function details(McpProposal $record): HtmlString
    {
        $html = '<div class="space-y-3 text-sm">';
        $html .= '<p class="text-gray-500 dark:text-gray-400">' . e(($record->proposer->name ?? '—') . ', ' . $record->created_at?->format('d/m/Y H:i')) . '</p>';
        $html .= '<p class="whitespace-pre-wrap break-words">' . e((string) $record->summary) . '</p>';
        if ($record->type === Proposals::TICKET_REPLY) {
            // The ticket by its reference too (owner 2026-11-19), linked: older summaries name only its id.
            $ticket = Ticket::query()->find((int) ($record->payload['ticket_id'] ?? 0));
            if ($ticket !== null) {
                $html .= '<p>' . e(__('Ticket')) . ': <a class="text-primary-600 hover:underline dark:text-primary-400" href="'
                    . e(TicketResource::getUrl('edit', ['record' => $ticket->id])) . '">' . e(Proposals::ticketName($ticket)) . '</a> «'
                    . e((string) $ticket->subject) . '»</p>';
            }
            $html .= '<p class="font-semibold">' . e(__('Drafted reply (MCP)')) . '</p>'
                . '<pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 font-sans dark:bg-white/5">'
                . e((string) ($record->payload['message'] ?? '')) . '</pre>';
        }
        if (filled($record->result)) {
            $html .= '<p class="text-gray-500 dark:text-gray-400 whitespace-pre-wrap break-words">' . e((string) $record->result) . '</p>';
        }

        return new HtmlString($html . '</div>');
    }

    public static function getPages(): array
    {
        return ['index' => ListMcpProposals::route('/')];
    }
}
