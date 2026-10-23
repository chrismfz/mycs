<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Domains\ContactPush;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Service\OptionTasks;
use App\Services\Service\RetryModuleAction;
use App\Support\LicenseKeyMask;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use RuntimeException;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['service.product', 'service.license', 'service.client', 'domain.client', 'invoiceItem']))
            ->columns([
                // A service's action, or a domain's (D5b-2: its renewal at the registrar).
                TextColumn::make('for')->label('For')
                    ->state(fn (ModuleAction $record) => $record->domain_id !== null
                        ? ($record->domain->fqdn ?? '#' . $record->domain_id) . ($record->invoiceItem ? ' · invoice #' . $record->invoiceItem->invoice_id : '')
                        : '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ($record->service?->domain ? ' (' . LicenseKeyMask::domain($record->service) . ')' : ''))
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
                SelectFilter::make('action')->options(array_combine([...ModuleAction::ACTIONS, ...ModuleAction::DOMAIN_ACTIONS, ModuleAction::DOMAIN_CONTACT, OptionTasks::ACTION],
                    array_map(fn (string $action) => self::label($action), [...ModuleAction::ACTIONS, ...ModuleAction::DOMAIN_ACTIONS, ModuleAction::DOMAIN_CONTACT, OptionTasks::ACTION]))),
            ])
            ->recordActions([
                // Once the automatic retries are over: while one is still queued, a click would race it (two creates).
                Action::make('retry')->label('Retry')->icon('ri-loop-right-line')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN && $record->given_up_at !== null && $record->domain_id === null
                        && $record->action !== OptionTasks::ACTION) // a staff task: done by hand, nothing to run
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
                // A domain's renewal (D5b-2): its outcome said as it is (review 7).
                // A domain ordered (D8b-2): sent to its registrar again (unless a charge is still unconfirmed).
                Action::make('retryDomainOrder')->label('Retry')->icon('ri-loop-right-line')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN
                        && in_array($record->action, [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER], true))
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Registers it, or starts its transfer, at the registrar now (charged to our account there), unless a charge is still unconfirmed: then check the registrar first.')
                    ->action(function (ModuleAction $record) {
                        $outcome = app(DomainOrderFulfilment::class)->fulfil((int) $record->domain_id);
                        $record->refresh();
                        // Each outcome said as it is (code review 8).
                        match ($outcome) {
                            DomainOrderFulfilment::SENT => Notification::make()->success()->title('Sent to the registrar')->send(),
                            DomainOrderFulfilment::WAITING => Notification::make()->warning()->title('Not paid')->body('Its invoice is not paid (or its line is gone): nothing sent.')->send(),
                            DomainOrderFulfilment::DONE => Notification::make()->warning()->title('Nothing to send')->body('Already sent, on hold, or no longer pending (cancelled): close this action.')->send(),
                            DomainOrderFulfilment::BUSY => Notification::make()->warning()->title('Busy')->body('Another change to this domain is under way: try again in a minute.')->send(),
                            default => Notification::make()->danger()->title('Not sent')->body((string) $record->last_error)->send(),
                        };
                    }),
                Action::make('notDone')->label('Not done there')->icon('ri-arrow-go-back-line')->color('gray')
                    // Also on an order closed «stop it» whose request is still unconfirmed (adversarial ADV-4 of #306).
                    ->visible(fn (ModuleAction $record) => ($record->status === ModuleAction::STATUS_OPEN || $record->onHold())
                        && in_array($record->action, [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER], true))
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Checked at the registrar: it was not registered (or its transfer not started). Its unconfirmed request is settled as failed, so Retry can send it.')
                    ->action(fn (ModuleAction $record) => self::orBusy(fn () => $record->markNotDone(self::staff()))),
                Action::make('retryDomain')->label('Retry')->icon('ri-loop-right-line')
                    // Also on a renewal closed «not renewed» whose line is still paid and waiting: only staff renew it then (sweep R5).
                    // The line's latest row only (code review 3 of #306).
                    ->visible(fn (ModuleAction $record) => $record->action === ModuleAction::DOMAIN_RENEW && $record->subject !== ModuleAction::SUBJECT_RELOCK && ($record->status === ModuleAction::STATUS_OPEN
                        || ($record->resolution === ModuleAction::CLOSED_UNRENEWED && $record->invoice_item_id !== null
                            && DomainRenewalService::waitingLines()->whereKey($record->invoice_item_id)->exists()
                            && $record->invoiceItem !== null && DomainRenewalService::closedUnrenewed($record->invoiceItem)
                            && ModuleAction::query()->where('invoice_item_id', $record->invoice_item_id)->where('action', ModuleAction::DOMAIN_RENEW)->max('id') === $record->id)))
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Reads the registrar and renews the paid period it lacks (charged to our account there), unless a charge is still unconfirmed.')
                    ->action(function (ModuleAction $record) {
                        // A closed row retried is open again: its outcome lands on it, and a success completes it (code review 1 of #306).
                        if ($record->resolution === ModuleAction::CLOSED_UNRENEWED) {
                            $record->forceFill(['status' => ModuleAction::STATUS_OPEN, 'resolved_at' => null, 'resolution' => null, 'resolved_by_staff_id' => null])->save();
                        }
                        $outcome = $record->invoice_item_id !== null ? app(DomainRenewalService::class)->renewLine($record->invoice_item_id, self::staff()) : DomainRenewalService::NOTHING;
                        $record->refresh();
                        match ($outcome) {
                            DomainRenewalService::APPLIED, DomainRenewalService::ADOPTED => Notification::make()->success()->title('Renewed')->send(),
                            DomainRenewalService::WAITING => Notification::make()->warning()->title('Not now')
                                ->body('Busy, or a charge is still unconfirmed: check the registrar. Do not mark it renewed unless the registrar shows it.')->send(),
                            DomainRenewalService::NOTHING => Notification::make()->warning()->title('Nothing to do')->body('The renewal is no longer a paid one waiting.')->send(),
                            default => Notification::make()->danger()->title('Not renewed')->body((string) $record->last_error)->send(),
                        };
                    }),
                // P1b-2: the domain's contacts sent again to its registrar (each role's handle, with its contact's details now).
                Action::make('retryContact')->label('Retry')->icon('ri-loop-right-line')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN && $record->action === ModuleAction::DOMAIN_CONTACT)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Sends the contacts of this domain to its registrar again (address, phone, email; never names), as they are now. A handle another contact also has is not sent.')
                    ->action(function (ModuleAction $record) {
                        $sent = ContactPush::retryDomain((int) $record->domain_id);
                        Notification::make()->success()->title($sent > 0 ? 'Queued: it runs in a moment' : 'Nothing to send (ended, switched off or shared): check the row')->send();
                    }),
                Action::make('resolve')->label(fn (ModuleAction $record) => match ($record->action) {
                    ModuleAction::DOMAIN_RENEW => $record->subject === ModuleAction::SUBJECT_RELOCK ? 'Locked by hand' : 'Renewed by hand',
                    ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER => 'Done by hand',
                    ModuleAction::DOMAIN_CONTACT => 'Fixed at the registrar',
                    OptionTasks::ACTION => 'Done',
                    default => 'Mark resolved',
                })
                    ->icon('ri-check-line')->color('gray')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN || $record->onHold()) // «Done by hand» after «stop it» (ADV-4 of #306)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ModuleAction $record) => match ($record->action) {
                        ModuleAction::DOMAIN_RENEW => $record->subject === ModuleAction::SUBJECT_RELOCK ? 'Its transfer lock was put on at the registrar by hand: closes it.' : 'Applies its paid renewal: with a registrar API only if the registrar shows it renewed (its expiry is the registrar\'s); with a manual registrar the expiry moves to the end of the paid period.',
                        ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER => 'Registered or its transfer started at the registrar by hand: the domain counts as sent, the sync takes it on (status, expiry), and its payment as used.',
                        ModuleAction::DOMAIN_CONTACT => 'The contact\'s details were set at the registrar by hand: closes it without sending anything.',
                        OptionTasks::ACTION => 'Done by hand (the IP given or taken back, noted on the service): closes it.',
                        default => 'For an account fixed by hand on the server: closes it without running anything (a create: the service turns active).',
                    })
                    ->action(function (ModuleAction $record) {
                        try {
                            $record->markResolved(self::staff());
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Not applied')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('close')->label(fn (ModuleAction $record) => $record->action === ModuleAction::DOMAIN_RENEW ? 'Close (not renewed)' : 'Close (stop it)')
                    ->icon('ri-close-line')->color('gray')
                    ->visible(fn (ModuleAction $record) => $record->status === ModuleAction::STATUS_OPEN && in_array($record->action, ModuleAction::DOMAIN_ACTIONS, true)
                        && $record->subject !== ModuleAction::SUBJECT_RELOCK)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ModuleAction $record) => $record->action === ModuleAction::DOMAIN_RENEW
                        ? 'Closes it without renewing (refunded, the domain ended, or checked at the registrar: its unconfirmed charge was not done). Its paid period is then never renewed on its own: only «Retry» on this row (listed under Resolved) renews it.'
                        : 'Stops it: never sent to the registrar on its own again (on hold). An unconfirmed request stays unconfirmed: if you checked the registrar and it was not done, use «Not done there» first. Cancel or refund its order separately.')
                    ->action(fn (ModuleAction $record) => self::orBusy(fn () => $record->closeUnrenewed(self::staff()))),
            ])
            ->toolbarActions([
                BulkAction::make('resolve')->label('Mark resolved')->requiresConfirmation()
                    ->authorize(fn () => (bool) self::staff()?->hasPermission('admin.services.update'))
                    // Never a domain's renewal (review 6e): each is decided on its own.
                    ->action(fn (Collection $records) => $records->where('status', ModuleAction::STATUS_OPEN)->whereNull('domain_id')
                        ->each(fn (ModuleAction $record) => $record->markResolved(self::staff()))),
            ]);
    }

    public static function label(string $action): string
    {
        return match ($action) {
            'upgrade' => 'Change Package',
            ModuleAction::DOMAIN_RENEW => 'Domain Renewal',
            ModuleAction::DOMAIN_REGISTER => 'Domain Registration',
            ModuleAction::DOMAIN_TRANSFER => 'Domain Transfer',
            ModuleAction::DOMAIN_CONTACT => 'Domain Contact Update',
            OptionTasks::ACTION => 'Staff Task',
            default => ucfirst($action),
        };
    }

    /** A domain's row acted on under its registrar lock: «busy» said, not an error page. */
    private static function orBusy(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            Notification::make()->warning()->title('Not now')->body($e->getMessage())->send();
        }
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
