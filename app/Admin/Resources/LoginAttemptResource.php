<?php

namespace App\Admin\Resources;

use App\Admin\Resources\LoginAttemptResource\Pages\ListLoginAttempts;
use App\Models\LoginAttempt;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «Login History» (owner 2026-10-23): every sign-in to the client area and the admin, good or not, for its retention
 * (90 days by default: App\Support\Retention).
 * Read only.
 */
class LoginAttemptResource extends Resource
{
    protected static ?string $model = LoginAttempt::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-login-box-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-login-box-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $slug = 'login-history';

    public static function getNavigationLabel(): string
    {
        return __('Login History');
    }

    public static function getModelLabel(): string
    {
        return __('Login History');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** The account it was, linked when the staff member may open it. */
    public static function account(LoginAttempt $attempt): ?string
    {
        return $attempt->staff->name ?? $attempt->user->name ?? null;
    }

    public static function accountUrl(LoginAttempt $attempt): ?string
    {
        return match (true) {
            $attempt->staff !== null && StaffResource::canEdit($attempt->staff) => StaffResource::getUrl('edit', ['record' => $attempt->staff]),
            $attempt->user !== null && UserResource::canEdit($attempt->user) => UserResource::getUrl('edit', ['record' => $attempt->user]),
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'staff']))
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('area')->label(__('Area'))->badge()
                    ->formatStateUsing(fn (string $state) => $state === LoginAttempt::STAFF ? __('Admin') : __('Client area'))
                    ->color(fn (string $state) => $state === LoginAttempt::STAFF ? 'warning' : 'gray'),
                TextColumn::make('account')->label(__('Account'))->placeholder('—')
                    ->state(fn (LoginAttempt $record) => self::account($record))
                    ->url(fn (LoginAttempt $record) => self::accountUrl($record)),
                TextColumn::make('email')->label(__('Email'))->placeholder('—')->searchable(),
                TextColumn::make('ip_address')->label(__('IP Address'))->placeholder('—')->searchable(),
                TextColumn::make('result')->label(__('Result'))->badge()
                    ->formatStateUsing(fn (string $state) => __(LoginAttempt::RESULTS[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        LoginAttempt::SUCCESS, LoginAttempt::REMEMBERED => 'success',
                        LoginAttempt::THROTTLED => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('user_agent')->label(__('Browser'))->placeholder('—')->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('area')->label(__('Area'))
                    ->options([LoginAttempt::CLIENT => __('Client area'), LoginAttempt::STAFF => __('Admin')]),
                SelectFilter::make('result')->label(__('Result'))
                    ->options(fn (): array => array_map(fn (string $label) => __($label), LoginAttempt::RESULTS)),
                Filter::make('failed')->label(__('Not successful'))
                    ->query(fn (Builder $query): Builder => $query->whereNotIn('result', [LoginAttempt::SUCCESS, LoginAttempt::REMEMBERED])),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', Carbon::parse($date)->startOfDay()->addDay()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLoginAttempts::route('/')];
    }
}
