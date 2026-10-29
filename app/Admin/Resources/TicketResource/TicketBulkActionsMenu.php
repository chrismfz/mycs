<?php

namespace App\Admin\Resources\TicketResource;

use App\Models\Staff;
use App\Models\Ticket;
use App\Support\Tickets\TicketBulkActions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * «With selected» on staff's ticket list, as WHMCS (owner 2026-10-25, docs/tickets.md §6μ). Pin, close and merge need
 * «Update Tickets» on every ticket selected; delete «Bulk Delete Tickets»; block-and-delete «Block Senders» too. A
 * refusal (a permission, another requestor, a client's ticket) refuses the whole selection and names why.
 */
final class TicketBulkActionsMenu
{
    /** @return list<BulkAction> */
    public static function make(): array
    {
        return [
            BulkAction::make('pin')
                ->label(__('Pin'))
                ->icon('ri-pushpin-line')
                ->authorize(fn () => self::staff()?->hasPermission('admin.tickets.update') ?? false)
                ->action(fn (Collection $records) => self::run($records, 'update',
                    fn () => __(':count pinned.', ['count' => app(TicketBulkActions::class)->pin($records, true)])))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('unpin')
                ->label(__('Unpin'))
                ->icon('ri-unpin-line')
                ->authorize(fn () => self::staff()?->hasPermission('admin.tickets.update') ?? false)
                ->action(fn (Collection $records) => self::run($records, 'update',
                    fn () => __(':count unpinned.', ['count' => app(TicketBulkActions::class)->pin($records, false)])))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('merge')
                ->label(__('Merge'))
                ->icon('heroicon-o-arrows-pointing-in')
                ->authorize(fn () => self::staff()?->hasPermission('admin.tickets.update') ?? false)
                ->modalHeading(fn (Collection $records) => __('Merge :count tickets', ['count' => $records->count()]))
                ->schema(fn (Collection $records) => [
                    Select::make('target_id')
                        ->label(__('Ticket to keep'))
                        ->required()
                        ->options($records->sortBy('id')->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => '#' . $ticket->reference . ' · ' . $ticket->subject])->all())
                        ->default(app(TicketBulkActions::class)->oldest($records)?->id)
                        ->selectablePlaceholder(false),
                    Text::make(__('The others move into it: their messages with each sender, their files, and the Message-IDs of our mails, so a reply to an old mail lands in the ticket kept. They close as merged. Nothing is mailed. Tickets of another client or guest address, or already merged, refuse the merge.')),
                ])
                ->modalSubmitActionLabel(__('Merge'))
                ->action(function (Collection $records, array $data) {
                    self::run($records, 'update', function () use ($records, $data) {
                        $target = $records->firstWhere('id', (int) ($data['target_id'] ?? 0));
                        if (!$target instanceof Ticket) {
                            throw new InvalidArgumentException(__('The ticket to keep must be one of those selected.'));
                        }
                        $kept = app(TicketBulkActions::class)->merge($records, $target, self::staff());

                        return __(':count merged into #:reference.', ['count' => $records->count() - 1, 'reference' => $kept->reference]);
                    });
                })
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('close')
                ->label(__('Close'))
                ->icon('heroicon-o-lock-closed')
                ->authorize(fn () => self::staff()?->hasPermission('admin.tickets.update') ?? false)
                ->requiresConfirmation()
                ->modalHeading(fn (Collection $records) => __('Close :count tickets?', ['count' => $records->count()]))
                ->modalDescription(fn (Collection $records) => self::numbers($records))
                ->action(fn (Collection $records) => self::run($records, 'update',
                    fn () => __(':count closed.', ['count' => app(TicketBulkActions::class)->close($records)])))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('delete')
                ->label(__('Delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->authorize(fn () => self::staff()?->hasPermission('admin.tickets.deleteAny') ?? false)
                ->requiresConfirmation()
                ->modalHeading(fn (Collection $records) => __('Delete :count tickets?', ['count' => $records->count()]))
                ->modalDescription(fn (Collection $records) => new HtmlString(e(self::numbers($records)) . '<br>' . e(self::requestorsLine($records)) . '<br>'
                    . e(self::mergedLine($records))
                    . e(__('This cannot be undone: the tickets, their messages and files are deleted for good. Each is written to the audit log.'))))
                ->modalSubmitActionLabel(fn (Collection $records) => __('Delete :count', ['count' => $records->count()]))
                ->action(fn (Collection $records) => self::run($records, null,
                    fn () => __(':count deleted.', ['count' => app(TicketBulkActions::class)->delete($records)])))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('blockAndDelete')
                ->label(__('Block sender & Delete'))
                ->icon('ri-mail-forbid-line')
                ->color('danger')
                ->authorize(fn () => self::staffHas(['admin.tickets.deleteAny', 'admin.ticket_blocked_senders.create']))
                ->modalHeading(fn (Collection $records) => __('Block :senders and delete :count tickets?',
                    ['senders' => trans_choice('{1} 1 sender|[2,*] :count senders', count(app(TicketBulkActions::class)->senders($records))), 'count' => $records->count()]))
                ->schema(fn (Collection $records) => [
                    Text::make(self::numbers($records)),
                    Text::make(self::sendersLine($records)),
                    Text::make(self::mergedLine($records)),
                    Checkbox::make('whole_domain')->label(__('Block their whole domain (@domain), not only the address')),
                    Text::make(__('Their next mail is dropped: no ticket, no quarantine. Their mail waiting in the quarantine is dismissed. The tickets are deleted for good. Only guests\' tickets: a client\'s refuses the action.')),
                ])
                ->modalSubmitActionLabel(__('Block & delete'))
                ->action(function (Collection $records, array $data) {
                    self::run($records, null, function () use ($records, $data) {
                        $staff = self::staff();
                        if (!$staff instanceof Staff) {
                            throw new InvalidArgumentException(__('Not allowed.'));
                        }
                        $blocked = app(TicketBulkActions::class)->blockAndDelete($records, $staff, (bool) ($data['whole_domain'] ?? false));

                        return __(':count deleted; blocked: :senders.', ['count' => $records->count(), 'senders' => implode(', ', $blocked)]);
                    });
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }

    /** @param  list<string>  $permissions */
    private static function staffHas(array $permissions): bool
    {
        $staff = self::staff();

        return $staff !== null && array_filter($permissions, fn (string $permission) => !$staff->hasPermission($permission)) === [];
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    /**
     * Runs a bulk action, or refuses all of it: a ticket staff may not $ability (update), or the action's own refusal.
     *
     * @param  Collection<int, Ticket>  $records
     * @param  \Closure(): string  $do  the success message
     */
    private static function run(Collection $records, ?string $ability, \Closure $do): void
    {
        $staff = self::staff();
        $denied = $ability === null ? null : $records->first(fn (Ticket $ticket) => $staff === null || !$staff->can($ability, $ticket));
        if ($staff === null || $denied !== null) {
            Notification::make()->title($denied !== null
                ? __('Not allowed on #:reference: nothing was changed.', ['reference' => $denied->reference])
                : __('Not allowed.'))->danger()->send();

            return;
        }
        try {
            $message = $do();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title(e($e->getMessage()))->body(__('Nothing was changed.'))->danger()->send();

            return;
        }
        Notification::make()->title($message)->success()->send();
    }

    /** @param  Collection<int, Ticket>  $records */
    private static function numbers(Collection $records): string
    {
        $numbers = $records->sortBy('id')->map(fn (Ticket $ticket) => '#' . $ticket->reference);

        return $numbers->take(20)->implode(', ') . ($numbers->count() > 20 ? ' ' . __('and :count more', ['count' => $numbers->count() - 20]) : '') . '.';
    }

    /**
     * Whose they are: each guest's address or account, and whoever else wrote in them by mail.
     *
     * @param  Collection<int, Ticket>  $records
     */
    private static function requestorsLine(Collection $records): string
    {
        $who = $records->map(fn (Ticket $ticket) => $ticket->isGuest() ? strtolower(trim((string) $ticket->guest_email)) : $ticket->requesterName() . ' (' . __('Client') . ' #' . $ticket->client_id . ')')
            ->merge(app(TicketBulkActions::class)->senders($records))->filter()->unique()->values();

        return __('Requestors') . ': ' . $who->take(20)->implode(', ') . ($who->count() > 20 ? ' ' . __('and :count more', ['count' => $who->count() - 20]) : '') . '.';
    }

    /**
     * The tickets merged into those selected, deleted with them (else they would come back as empty tickets).
     *
     * @param  Collection<int, Ticket>  $records
     */
    private static function mergedLine(Collection $records): string
    {
        $merged = app(TicketBulkActions::class)->mergedInto($records);

        return $merged->isEmpty() ? '' : __('Also deleted, merged into them: :numbers.', ['numbers' => $merged->map(fn (Ticket $ticket) => '#' . $ticket->reference)->implode(', ')]) . ' ';
    }

    /** @param  Collection<int, Ticket>  $records */
    private static function sendersLine(Collection $records): string
    {
        $senders = app(TicketBulkActions::class)->senders($records);

        return __('Senders') . ': ' . ($senders === [] ? '—' : implode(', ', array_slice($senders, 0, 20)) . (count($senders) > 20 ? ' ' . __('and :count more', ['count' => count($senders) - 20]) : '')) . '.';
    }
}
