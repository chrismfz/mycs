<?php

namespace App\Admin\Resources;

use App\Admin\Resources\DomainRegistrarLogResource\Pages\ListDomainRegistrarLogs;
use App\Admin\Resources\DomainRegistrarLogResource\RegistrarLogTable;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * «Registrar Log» (owner 2026-10-23): every call to a registrar for every domain, the domain sync's failures and
 * changes among them, as WHMCS's Module Log for domains. Read only; each domain's own is on its page.
 */
class DomainRegistrarLogResource extends Resource
{
    protected static ?string $model = DomainRegistrarLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $slug = 'registrar-log';

    public static function getNavigationLabel(): string
    {
        return __('Registrar Log');
    }

    public static function getModelLabel(): string
    {
        return __('Registrar Log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['domain', 'registrarConnection', 'staff', 'user']))
            ->columns(RegistrarLogTable::columns(withDomain: true))
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    DomainRegistrarLog::STATUS_FAILED => __('Failed'),
                    DomainRegistrarLog::STATUS_OK => 'OK',
                    DomainRegistrarLog::STATUS_PENDING => __('Pending'),
                    DomainRegistrarLog::STATUS_ADOPTED => __('Adopted'),
                ]),
                // The actions there are, read at most every ten minutes: a DISTINCT over the whole log.
                SelectFilter::make('action')->label(__('Action'))
                    ->options(fn (): array => collect(Cache::remember('registrar-log.filter.actions', 600,
                        fn () => DomainRegistrarLog::query()->distinct()->orderBy('action')->pluck('action')->all()))
                        ->mapWithKeys(fn (string $action) => [$action => $action])->all()),
                SelectFilter::make('registrar_connection_id')->label(__('Registrar'))
                    ->options(fn (): array => DomainRegistrarConnection::query()->orderBy('label')->get()
                        ->mapWithKeys(fn (DomainRegistrarConnection $c) => [$c->id => $c->label ?: $c->registrar])->all()),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', Carbon::parse($date)->startOfDay()->addDay()))),
            ])
            ->recordActions([RegistrarLogTable::viewAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDomainRegistrarLogs::route('/')];
    }
}
