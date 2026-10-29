<?php

namespace App\Admin\Resources;

use App\Admin\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Models\FailedJob;
use App\Support\LogText;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-error-warning-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-error-warning-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payload')->formatStateUsing(function ($state) {
                    $state = json_decode($state);

                    // List displayName
                    return $state->displayName;
                }),
                // The queue keeps the exception as thrown: shown masked unless «Full logs» (App\Support\LogText).
                TextColumn::make('exception')->formatStateUsing(function ($state) {
                    return explode("\n", (string) LogText::of($state))[0];
                })->wrap()->tooltip(function ($state) {
                    return LogText::of($state);
                })->limit(200),
                TextColumn::make('failed_at')->dateTime(),
            ])
            ->poll()
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasPermission('admin.failed_jobs.retry')) // runs the job again (AUTH-3)
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record): void {
                        Artisan::call("queue:retry {$record->uuid}");
                        Notification::make()
                            ->title("The job with uuid '{$record->uuid}' has been pushed back onto the queue.")
                            ->success()
                            ->send();
                    }),
                Action::make('delete')
                    ->label('Mark as Resolved')
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasPermission('admin.failed_jobs.delete'))
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (FailedJob $failedJob) {
                        $failedJob->delete();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('retry')
                    ->label('Retry')
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasPermission('admin.failed_jobs.retry')) // runs the job again (AUTH-3)
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            try {
                                Artisan::call("queue:retry {$record->uuid}");
                            } catch (Exception $e) {
                                report($e);
                                Notification::make()
                                    ->title(e($e->getMessage()))
                                    ->warning()
                                    ->send();
                            }
                        }
                        Notification::make()
                            ->title("{$records->count()} jobs have been pushed back onto the queue.")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('delete')
                    ->label('Mark as Resolved')
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasPermission('admin.failed_jobs.delete'))
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->delete();
                        }
                        Notification::make()
                            ->title("{$records->count()} jobs have been marked as resolved.")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('failed_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedJobs::route('/'),
        ];
    }
}
