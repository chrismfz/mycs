<?php

namespace App\Admin\Pages;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessageAttachment;
use App\Support\Retention;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * As WHMCS's «System Cleanup»: each log with its size and oldest entry, deleted by hand before a date or by its
 * retention setting (App\Support\Retention), and the files of old closed tickets. Logs only: no money, invoice or
 * ticket text is ever deleted here. Every deletion is logged with who did it.
 */
class SystemCleanup extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-delete-bin-6-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-delete-bin-6-fill';

    protected static ?int $navigationSort = 6;

    protected static string $routePath = 'system-cleanup';

    protected string $view = 'admin.pages.system-cleanup';

    public static function getNavigationLabel(): string
    {
        return __('System Cleanup');
    }

    public function getTitle(): string
    {
        return __('System Cleanup');
    }

    public static function canAccess(): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff && $staff->hasPermission('admin.system_cleanup.run');
    }

    /** @return array<string, array{label: string, description: string, days: int, rows: int, estimated: bool, oldest: ?string, bytes: ?int}> */
    public function logs(): array
    {
        $logs = [];
        foreach (Retention::logs() as $key => $log) {
            $logs[$key] = ['label' => __($log['label']), 'description' => __($log['description']), 'days' => Retention::days($key)] + Retention::stats($key);
        }

        return $logs;
    }

    /** @return array{count: int, bytes: int} the files of tickets closed and quiet before the date given (or all closed) */
    public function attachments(): array
    {
        $query = TicketMessageAttachment::query()->whereHas('ticketMessage.ticket', fn ($ticket) => $ticket->where('status', Ticket::STATUS_CLOSED));

        return ['count' => (clone $query)->count(), 'bytes' => (int) (clone $query)->sum('filesize')];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pruneAll')
                ->label(__('Apply retention now'))
                ->icon('ri-timer-flash-line')
                ->requiresConfirmation()
                ->modalDescription(__('Deletes what each log keeps no longer, by its setting (as the daily cron does). Logs set to 0 are kept.'))
                ->authorize(fn () => self::canAccess())
                ->action(function () {
                    $deleted = Retention::pruneAll();
                    self::record('retention', array_sum($deleted));
                    Notification::make()->success()->title(__(':count entries deleted.', ['count' => array_sum($deleted)]))->send();
                }),
        ];
    }

    /** One log, before a date the staff member chooses (yesterday at most, never today's). */
    public function deleteBeforeAction(): Action
    {
        return Action::make('deleteBefore')
            ->label(__('Delete before…'))
            ->color('danger')
            ->size('sm')
            ->authorize(fn () => self::canAccess())
            ->modalHeading(fn (array $arguments) => __('Delete :log entries', ['log' => __(Retention::logs()[$arguments['key'] ?? '']['label'] ?? '')]))
            ->schema([
                DatePicker::make('before')->label(__('Delete entries before'))->required()->native(false)->maxDate(today()),
            ])
            ->requiresConfirmation()
            ->action(function (array $data, array $arguments) {
                $key = (string) ($arguments['key'] ?? '');
                abort_unless(array_key_exists($key, Retention::logs()), 404);
                $before = Carbon::parse($data['before'])->startOfDay();
                // One run's worth a click (as the cron's): millions of rows never hold a web request past its time.
                $deleted = Retention::pruneBefore($key, $before);
                self::record($key, $deleted, $before);
                Notification::make()->success()->title(__(':count entries deleted.', ['count' => $deleted]))
                    ->body($deleted >= Retention::MAX_PER_RUN ? __('More remain: run it again.') : null)->send();
            });
    }

    /** The files of closed tickets whose last activity was before a date: the messages stay, their files go. */
    public function deleteAttachmentsAction(): Action
    {
        return Action::make('deleteAttachments')
            ->label(__('Delete attachments…'))
            ->color('danger')
            ->authorize(fn () => self::canAccess())
            ->modalHeading(__('Delete the attachments of closed tickets'))
            ->modalDescription(__('The files of closed tickets with no activity since the date are deleted; the tickets and their messages stay.'))
            ->schema([
                DatePicker::make('before')->label(__('Closed tickets with no activity since'))->required()->native(false)->maxDate(today()->subDay()),
            ])
            ->requiresConfirmation()
            ->action(function (array $data) {
                $before = Carbon::parse($data['before'])->startOfDay();
                $deleted = 0;
                // Closed, and quiet since: no message of any kind (an internal note touches no ticket) and the file itself
                // older (review of #410).
                TicketMessageAttachment::query()
                    ->where('created_at', '<', $before)
                    ->whereHas('ticketMessage.ticket', fn ($ticket) => $ticket->where('status', Ticket::STATUS_CLOSED)->where('updated_at', '<', $before)
                        ->whereDoesntHave('messages', fn ($messages) => $messages->where('created_at', '>=', $before)))
                    ->chunkById(500, function ($attachments) use (&$deleted) {
                        foreach ($attachments as $attachment) {
                            $file = $attachment->local_path; // only inside the tickets' own folder
                            if ($file !== null && is_file($file) && !@unlink($file)) {
                                continue; // not removable: the row stays with its file
                            }
                            $attachment->delete();
                            $deleted++;
                        }
                    });
                self::record('ticket_attachments', $deleted, $before);
                Notification::make()->success()->title(__(':count attachments deleted.', ['count' => $deleted]))->send();
            });
    }

    private static function record(string $what, int $count, ?Carbon $before = null): void
    {
        Log::warning('System cleanup', ['staff_id' => Auth::guard('staff')->id(), 'what' => $what, 'deleted' => $count, 'before' => $before?->toDateString()]);
    }
}
