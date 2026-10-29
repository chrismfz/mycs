<?php

namespace App\Admin\Resources;

use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\CreateDomainRegistrarConnection;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\EditDomainRegistrarConnection;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\ListDomainRegistrarConnections;
use App\Contracts\ReadsAccountBalance;
use App\Exceptions\DisplayException;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\RegistrarBalances;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * WHMCS's «Domain Registrars» (docs/domains.md D2, D-21): the accounts MyCS has at registrars. Its credentials are
 * encrypted Settings of the connection; a secret is never shown again (left empty, it is kept). «Test Connection» logs
 * in and changes nothing. A connection TLDs, domains or a registrar log use is kept.
 */
class DomainRegistrarConnectionResource extends Resource
{
    protected static ?string $model = DomainRegistrarConnection::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static ?string $navigationLabel = 'Domain Registrars';

    protected static ?string $modelLabel = 'registrar connection';

    protected static ?int $navigationSort = 5;

    /** @return array<string, array{label?: string, secret?: bool}> the registrar's credential fields (config/domains.php) */
    public static function credentialFields(?string $registrar): array
    {
        $fields = config('domains.registrar_fields.' . $registrar, []);

        return is_array($fields) ? $fields : [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Registrar'))->columns(2)->schema([
                Select::make('registrar')->label(__('Registrar'))->required()->live()
                    ->options(fn () => app(DomainRegistrarRegistry::class)->selectOptions())
                    // Its credentials are that registrar's: another registrar is another connection.
                    ->disabledOn('edit'),
                TextInput::make('label')->label(__('Label'))->required()->maxLength(255),
                Select::make('mode')->label(__('Mode'))->required()->default('sandbox')
                    ->options(['off' => __('Off'), 'sandbox' => __('Sandbox (test)'), 'production' => __('Production (live)')])
                    ->helperText(__('Only «Production» reaches the live registrar; anything else stays on its test system. Openprovider\'s test system is reached over plain HTTP: give it the test account\'s credentials, never the live ones.')),
                Toggle::make('is_active')->label(__('Active'))->default(false),
                Toggle::make('debug_log')->label(__('Debug log'))->default(false)
                    ->helperText(__('Every request and answer to storage/logs/registrar-debug-*.log, passwords and EPP codes hidden; contacts\' names and addresses are in it: kept 14 days. Turn it off after testing.')),
                TextInput::make('low_balance')->label(__('Low balance'))->numeric()->minValue(0)->step('0.01')->nullable()
                    ->visible(fn (Get $get, ?DomainRegistrarConnection $record) => self::readsBalance($get('registrar') ?? $record?->registrar))
                    ->helperText(__('Staff are mailed when the account\'s balance falls under it (read hourly; the dashboard\'s «Registrar credit»). Empty: never for its amount; a suspension is told all the same.')),
            ]),
            // The WHMCS hook DomainMigration as a setting (owner 2026-10-21): a paid renewal transferred instead.
            Section::make(__('Paid renewals'))->columns(1)->visibleOn('edit')->schema([
                Select::make('renew_transfer_to_id')->label(__('On a paid renewal'))->placeholder(__('Renew it here'))
                    // Active ones that take transfers through their API, never one that sends its own back here (a cycle: code
                    // review 7 of #303).
                    ->options(fn (?DomainRegistrarConnection $record) => DomainRegistrarConnection::query()->whereKeyNot($record?->id)
                        ->where('is_active', true)->where('registrar', '!=', DomainRegistrarConnection::MANUAL)
                        ->where(fn ($q) => $q->whereNull('renew_transfer_to_id')->orWhere('renew_transfer_to_id', '!=', $record?->id))->get()
                        ->filter(fn (DomainRegistrarConnection $to) => self::takesTransfers($to->registrar))
                        ->mapWithKeys(fn (DomainRegistrarConnection $to) => [$to->id => __('Transfer it to :label', ['label' => $to->label])])->all())
                    ->helperText(__('Instead of renewing it here: unlocked, its EPP code read, and transferred there with its nameservers. A transfer that added no year is renewed there after it. Only one-year renewals of active names; refused, it stays here and staff decide.')),
            ]),
            Section::make(__('Credentials'))->columns(2)
                ->visible(fn (Get $get) => self::credentialFields($get('registrar')) !== [])
                ->schema(fn (Get $get) => collect(self::credentialFields($get('registrar')))->map(function (array $field, string $key) {
                    $input = TextInput::make("credentials.{$key}")->label($field['label'] ?? $key)->string()->maxLength(500);

                    return ($field['secret'] ?? false)
                        ? $input->password()->revealable(false)->dehydrated(fn ($state) => filled($state))
                            ->helperText(fn (?DomainRegistrarConnection $record) => $record !== null ? __('Stored and never shown: leave empty to keep it.') : null)
                        : $input;
                })->values()->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label(__('Label'))->searchable(),
                TextColumn::make('registrar')->label(__('Registrar'))
                    ->formatStateUsing(fn (string $state) => app(DomainRegistrarRegistry::class)->label($state)),
                TextColumn::make('mode')->label(__('Mode'))->badge()
                    ->color(fn (string $state) => match ($state) {
                        'production' => 'success',
                        'sandbox' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                // On, contacts' details are being written to a file: seen at a glance (review 2 of #193).
                TextColumn::make('debug_log')->label(__('Debug log'))->badge()->color('danger')
                    ->formatStateUsing(fn (bool $state) => $state ? __('On') : null)->placeholder('—'),
                TextColumn::make('balance.amount')->label(__('Balance'))
                    ->color(fn (DomainRegistrarConnection $record) => RegistrarBalances::problemText($record) !== null ? 'danger' : null)
                    ->tooltip(fn (DomainRegistrarConnection $record) => RegistrarBalances::problemText($record))->placeholder('—'),
                TextColumn::make('tlds_count')->label('TLDs')->counts('tlds'),
                TextColumn::make('domains_count')->label(__('domains.domains'))->counts('domains'),
            ])
            ->recordActions([self::testAction(), self::balanceAction(), EditAction::make()]);
    }

    /** Its registrar takes transfers through its API (a target of «transfer it to»). */
    public static function takesTransfers(string $registrar): bool
    {
        try {
            return app(DomainRegistrarRegistry::class)->for($registrar)->capabilities()->supportsTransfer;
        } catch (Throwable) {
            return false;
        }
    }

    /** Its registrar tells its balance (G8-1). */
    public static function readsBalance(?string $registrar): bool
    {
        try {
            return $registrar !== null && app(DomainRegistrarRegistry::class)->for($registrar) instanceof ReadsAccountBalance;
        } catch (Throwable) {
            return false;
        }
    }

    /** «Read balance» (G8-1): what is left with the registrar, now; a read. */
    public static function balanceAction(): Action
    {
        return Action::make('balance')->label(__('Read balance'))->icon('heroicon-o-banknotes')
            ->visible(fn (DomainRegistrarConnection $record) => static::canEdit($record) && $record->isUsable() && app(RegistrarBalances::class)->reads($record))
            ->action(function (DomainRegistrarConnection $record) {
                app(RegistrarBalances::class)->refresh($record);
                $balance = $record->balance ?? [];
                $error = $balance['error'] ?? null;
                Notification::make()->title($error !== null ? __('Could not read the balance') : __('Balance: :amount', ['amount' => trim(($balance['amount'] ?? '—') . ' ' . ($balance['currency'] ?? ''))]))
                    ->body(e($error ?? RegistrarBalances::problemText($record)))->{$error !== null ? 'danger' : 'success'}()->send();
            });
    }

    /** «Test Connection»: logs in with the stored credentials; never changes anything at the registrar. */
    public static function testAction(): Action
    {
        return Action::make('test')->label(__('Test Connection'))->icon('heroicon-o-signal')
            // It logs in with the stored secret: those who may change the connection (review F4).
            ->visible(fn (DomainRegistrarConnection $record) => static::canEdit($record))
            ->action(function (DomainRegistrarConnection $record) {
                if ($record->registrar === DomainRegistrarConnection::MANUAL) {
                    Notification::make()->info()->title(__('A manual connection calls no registrar.'))->send();

                    return;
                }
                $factory = app(DomainRegistrarFactory::class);
                $ok = $factory->for($record)->ping($factory->credentialsFor($record));
                $notification = Notification::make()->title($ok ? __('Connected.') : __('The registrar refused the login or could not be reached.'));
                ($ok ? $notification->success() : $notification->danger())->send();
            });
    }

    /** Delete refused with a message (not a 500) while TLDs, domains or a log use it. */
    public static function deleteAction(): Action
    {
        return Action::make('delete')->label(__('Delete'))->color('danger')->icon('heroicon-o-trash')->requiresConfirmation()
            ->visible(fn (DomainRegistrarConnection $record) => static::canDelete($record))
            ->action(function (DomainRegistrarConnection $record, Action $action) {
                try {
                    $record->delete(); // its deleting guard throws a DisplayException while it is in use
                } catch (\Exception $e) {
                    if (!$e instanceof DisplayException) {
                        throw $e;
                    }
                    Notification::make()->danger()->title(e($e->getMessage()))->send();
                    $action->halt();
                }
                $action->redirect(static::getUrl('index'));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomainRegistrarConnections::route('/'),
            'create' => CreateDomainRegistrarConnection::route('/create'),
            'edit' => EditDomainRegistrarConnection::route('/{record}/edit'),
        ];
    }
}
