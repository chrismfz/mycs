<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Service\RetryModuleAction;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

/**
 * WHMCS's «Pending Module Actions» (Module Queue), and the module log: what the server modules were asked to do. The
 * open ones failed (retried automatically 3 times, then staff's): retry once the cause is fixed, or mark resolved when
 * the account was fixed by hand.
 */
class ModuleActionResource extends Resource
{
    protected static ?string $model = ModuleAction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-loop-right-line';

    protected static ?string $cluster = Services::class;

    protected static ?string $navigationLabel = 'Pending Module Actions';

    protected static ?string $modelLabel = 'module action';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $open = ModuleAction::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // A service's action, or a domain's (D5b-2: its renewal at the registrar).
                TextColumn::make('for')->label('For')
                    ->state(fn (ModuleAction $record) => $record->domain_id !== null
                        ? ($record->domain->fqdn ?? '#' . $record->domain_id) . ($record->invoiceItem ? ' · invoice #' . $record->invoiceItem->invoice_id : '')
                        : '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ($record->service?->domain ? ' (' . $record->service->domain . ')' : ''))
                    ->url(fn (ModuleAction $record) => $record->domain_id !== null ? DomainResource::getUrl('view', ['record' => $record->domain_id])
                        : ServiceResource::getUrl('edit', ['record' => $record->service_id])),
                TextColumn::make('client')->label('Client')->state(fn (ModuleAction $record) => $record->service !== null ? $record->service->client?->name : $record->domain?->client?->name),
                // «upgrade» is WHMCS's ChangePackage: an upgrade or a downgrade sends the new product's package.
                TextColumn::make('action')->badge()->formatStateUsing(fn (string $state) => self::label($state)),
                TextColumn::make('module')->toggleable(),
                TextColumn::make('status')->badge()
                    ->state(fn (ModuleAction $record) => match (true) {
                        $record->status !== ModuleAction::STATUS_OPEN => $record->status,
                        $record->given_up_at !== null => 'needs staff',
                        default => 'retrying',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'needs staff' => 'danger',
                        'retrying' => 'warning',
                        'completed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('attempts'),
                TextColumn::make('last_error')->label('Last error')->wrap()->limit(200)->tooltip(fn (ModuleAction $record) => $record->last_error),
                TextColumn::make('last_attempt_at')->label('Last attempt')->dateTime()->sortable(),
                TextColumn::make('resolution')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ModuleAction::STATUS_OPEN => 'Pending (failed)',
                    ModuleAction::STATUS_COMPLETED => 'Completed',
                    ModuleAction::STATUS_RESOLVED => 'Resolved',
                ])->default(ModuleAction::STATUS_OPEN),
                SelectFilter::make('action')->options(array_combine([...ModuleAction::ACTIONS, ModuleAction::DOMAIN_RENEW],
                    array_map(fn (string $action) => self::label($action), [...ModuleAction::ACTIONS, ModuleAction::DOMAIN_RENEW]))),
            ])
            ->recordActions([
                // Once the automatic retries are over: while one is still queued, a click would race it (two creates).
                Action::make('retry')->label('Retry')->icon('ri-loop-right-line')
                    // A domain's renewal has no automatic retry queued (the cron's daily run is its retry): any time.
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN
                        && ($record->given_up_at !== null || $record->action === ModuleAction::DOMAIN_RENEW))
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Runs the action on the server now (and sends its email, as the automatic run would).')
                    ->action(function (ModuleAction $record) {
                        try {
                            $done = RetryModuleAction::run($record);
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('The server refused it again')->body($e->getMessage())->send();

                            return;
                        }
                        $done
                            ? Notification::make()->success()->title('Done')->send()
                            : Notification::make()->warning()->title('Nothing to do')
                                ->body("The service is {$record->service?->status} now: mark the action resolved if the account is as it should be.")->send();
                    }),
                Action::make('resolve')->label('Mark resolved')->icon('ri-check-line')->color('gray')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ModuleAction $record) => $record->action === ModuleAction::DOMAIN_RENEW
                        ? 'The domain was renewed by hand at its registrar: its paid renewal is applied and its expiry moved to the end of the paid period.'
                        : 'For an account fixed by hand on the server: closes it without running anything (a create: the service turns active).')
                    ->action(fn (ModuleAction $record) => $record->markResolved(self::staff())),
            ])
            ->toolbarActions([
                BulkAction::make('resolve')->label('Mark resolved')->requiresConfirmation()
                    ->authorize(fn () => (bool) self::staff()?->hasPermission('admin.services.update'))
                    ->action(fn (Collection $records) => $records->where('status', ModuleAction::STATUS_OPEN)
                        ->each(fn (ModuleAction $record) => $record->markResolved(self::staff()))),
            ]);
    }

    public static function label(string $action): string
    {
        return match ($action) {
            'upgrade' => 'Change Package',
            ModuleAction::DOMAIN_RENEW => 'Domain Renewal',
            default => ucfirst($action),
        };
    }

    private static function staff(): ?Staff
    {
        $user = auth('staff')->user();

        return $user instanceof Staff ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuleActions::route('/'),
        ];
    }
}
