<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\Client;
use App\Models\Contact;
use App\Models\License;
use App\Models\LicenseInstall;
use App\Models\Product;
use App\Services\Licensing\LicenseTelemetry;
use App\Services\Ops\Scrub;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The licenses console (L2, beyond WHMCS): every nixpal license with its signs of life, the active ones that went
 * silent, the expired or suspended ones still calling, and the ones that ran past their end and then stopped asking.
 * A row opens its service (where staff reissue or edit it).
 */
class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-key-2-line';

    protected static ?string $cluster = Services::class;

    protected static ?int $navigationSort = 4;

    /** An active license that has not checked in for this long is «silent». */
    public const SILENT_DAYS = 7;

    /** How far back «went quiet after expiry» looks: a worklist, not every license that was ever uninstalled. */
    public const QUIET_DAYS = 90;

    /** The window of the installs' signs: more than one install, a downgrade, a clock far off (the «Signals» tab). */
    public const INSTALLS_DAYS = 7;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['service.product', 'service.client.primaryContact', 'service.client.ownerGrant.user', 'bulkLicense']) // what Client::name reads (review R2)
                // A bulk license's IPs, and the silent ones (docs/zonecloud-bulk-license.md §7.2).
                ->withCount(['ips as listed_ips' => fn (Builder $ips) => $ips->whereNull('removed_at'),
                    'ips as silent_ips' => fn (Builder $ips) => $ips->whereNull('removed_at')->whereNotNull('last_ok_at')->where('last_ok_at', '<', now()->subDays(self::SILENT_DAYS))])
                // What the newest install says it runs, and how many installs (by their id) it ran as (/licensing/v1/check).
                ->addSelect(['newest_version' => LicenseInstall::query()->select('version')->whereColumn('license_installs.license_id', 'licenses.id')
                    ->whereNotNull('version')->orderByDesc('last_seen_at')->limit(1),
                    'recent_installs' => LicenseTelemetry::installsSince(now()->subDays(self::INSTALLS_DAYS))]))
            ->defaultSort('last_access', 'desc')
            ->columns([
                // The whole key opens the customer's software (a bearer value): only to staff who can change services, the
                // others see it as the MCP does, prefix…last4 (security audit SA-11), and find it only by the whole key: a part
                // of it would tell the hidden middle one character at a time (review of #368).
                TextColumn::make('license_key')->label('Key')->fontFamily('mono')
                    ->searchable(query: fn (Builder $query, string $search) => auth()->user()?->hasPermission('admin.services.update')
                        ? $query->where('license_key', 'like', "%{$search}%")
                        : $query->where('license_key', trim($search)))
                    ->formatStateUsing(fn ($state) => auth()->user()?->hasPermission('admin.services.update') ? $state : Scrub::licenseKey($state))
                    ->copyable(fn (): bool => (bool) auth()->user()?->hasPermission('admin.services.update')),
                // Narrow, the whole name on hover: room for the API column.
                // Sortable (owner 2026-11-16): by the main contact's company or name, as the list shows it, and by product.
                TextColumn::make('service.client.name')->label('Client')->limit(18)->tooltip(fn (License $record) => $record->service?->client?->name)
                    // As Contact::displayName: a business's company, else first then last name (review of #877); ties by id.
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy(self::primaryContact("CASE WHEN contacts.type = 'business' AND contacts.company_name <> '' THEN contacts.company_name ELSE contacts.first_name END"), $direction)
                        ->orderBy(self::primaryContact('contacts.last_name'), $direction)
                        ->orderBy('licenses.id', $direction)),
                TextColumn::make('service.product.name')->label('Product')->limit(16)->tooltip(fn (License $record) => $record->service?->product?->name)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(Product::query()->select('products.name')
                        ->join('services', 'services.product_id', '=', 'products.id')
                        ->whereColumn('services.id', 'licenses.service_id')->limit(1), $direction)
                        ->orderBy('licenses.id', $direction)),
                // The endpoint of its last check: v0 zlic.php, v1 /licensing/v1/check (the new releases).
                TextColumn::make('last_endpoint')->label('API')->badge()->placeholder('—')
                    ->color(fn (?string $state) => $state === 'v1' ? 'success' : 'gray')
                    ->tooltip(fn (License $record) => match ($record->last_endpoint) {
                        'v1' => '/licensing/v1/check', 'v0' => 'zlic.php', default => null,
                    })->sortable()->toggleable(),
                TextColumn::make('status')->sortable()->badge()->color(fn (string $state) => match ($state) {
                    License::STATUS_ACTIVE => 'success', License::STATUS_EXPIRED => 'danger', default => 'warning',
                }),
                TextColumn::make('valid_ip')->label('Bound to')->placeholder('—')->searchable(),
                // A bulk license's list («62 · 3 silent»), or a standalone one moving into a bulk license («joins 02/12/2026»).
                TextColumn::make('bulk_ips')->label('IPs')->placeholder('—')
                    ->state(fn (License $record) => match (true) {
                        $record->listed_ips > 0 => $record->listed_ips . ($record->silent_ips > 0 ? ' · ' . $record->silent_ips . ' silent' : ''),
                        $record->bulk_license_id !== null && $record->merged_at !== null => 'joined bulk #' . $record->bulkLicense?->service_id,
                        $record->bulk_license_id !== null => 'joins bulk' . ($record->service?->expires_at ? ' ' . $record->service->expires_at->format('d/m/Y') : ''),
                        default => null,
                    })
                    ->color(fn (License $record) => $record->silent_ips > 0 ? 'warning' : null),
                TextColumn::make('last_ok_at')->label('Last good check')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                TextColumn::make('last_access')->label('Last call')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                TextColumn::make('last_seen_ip')->label('From')->toggleable(),
                TextColumn::make('last_failure')->label('Last failure')->limit(40)->tooltip(fn (License $record) => $record->last_failure)->toggleable(),
                TextColumn::make('reissues')->sortable()->toggleable(),
                TextColumn::make('newest_version')->label('Version')->placeholder('—')->toggleable(),
                TextColumn::make('recent_installs')->label('Installs')->tooltip('Install ids seen in the last ' . self::INSTALLS_DAYS . ' days')->toggleable()
                    ->color(fn (License $record) => $record->recent_installs > 1 && $record->listed_ips === 0 ? 'warning' : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine($s = [License::STATUS_ACTIVE, License::STATUS_REISSUED, License::STATUS_SUSPENDED, License::STATUS_EXPIRED], $s)),
                // One product, one client, one endpoint (owner 2026-11-16): the products and clients that have licenses.
                SelectFilter::make('product')->label('Product')->multiple()
                    ->options(fn () => Product::query()->whereHas('services', fn (Builder $services) => $services->whereHas('license'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => ($data['values'] ?? []) === [] ? $query
                        : $query->whereHas('service', fn (Builder $service) => $service->whereIn('product_id', $data['values']))),
                SelectFilter::make('client')->label('Client')->searchable()
                    ->options(fn () => Client::query()->whereHas('services', fn (Builder $services) => $services->whereHas('license'))
                        ->with(['primaryContact', 'ownerGrant.user'])->get()->mapWithKeys(fn (Client $client) => [$client->id => $client->name])->sort()->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('service', fn (Builder $service) => $service->where('client_id', (int) $data['value'])) : $query),
                SelectFilter::make('last_endpoint')->label('API')
                    ->options(['v1' => 'v1 (/licensing/v1/check)', 'v0' => 'v0 (zlic.php)', 'none' => 'Never called'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'v1', 'v0' => $query->where('last_endpoint', $data['value']),
                        'none' => $query->whereNull('last_endpoint'),
                        default => $query,
                    }),
                Filter::make('bulk')->label('Bulk only')->query(fn (Builder $query) => $query->whereHas('ips')), // ever had a list
                Filter::make('silent_ips')->label('Has silent IPs')->query(fn (Builder $query) => $query->whereHas('ips', fn (Builder $ips) => $ips->whereNull('removed_at')
                    ->whereNotNull('last_ok_at')->where('last_ok_at', '<', now()->subDays(self::SILENT_DAYS)))),
                Filter::make('moving')->label('Moving into bulk')->query(fn (Builder $query) => $query->whereNotNull('bulk_license_id')->whereNull('merged_at')),
            ], layout: FiltersLayout::AboveContentCollapsible)
            // On the page, folded, as the other lists (owner 2026-11-16).
            ->filtersFormColumns(2)
            ->recordUrl(fn (License $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]));
    }

    /** One column of the license's client's main contact, for a sort. */
    private static function primaryContact(string $expression): Builder
    {
        return Contact::query()->selectRaw($expression)
            ->join('services', 'services.client_id', '=', 'contacts.primary_of_client_id')
            ->whereColumn('services.id', 'licenses.service_id')->limit(1);
    }

    public static function getPages(): array
    {
        return ['index' => ListLicenses::route('/')];
    }
}
