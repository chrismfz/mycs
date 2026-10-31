<?php

namespace App\Admin\Pages;

use App\Services\Reports\PriceMismatch;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Prices that differ from the catalog (sweep 9γ, owner 2026-10-30: PriceMismatch): services and domains that renew at a
 * price of their own, the largest difference first, each linking to where staff change it. Customers are named: the
 * services' and the domains' permissions too.
 */
class PriceMismatches extends Page
{
    public const PER_PAGE = 50;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-price-tag-3-fill';

    protected static ?string $slug = 'price-mismatches';

    protected string $view = 'admin.pages.price-mismatches';

    /** '', 'service' or 'domain' */
    public string $type = '';

    /** '', 'below' (renews for less than the catalog) or 'above' */
    public string $direction = '';

    public int $page = 1;

    public function getTitle(): string
    {
        return __('Price mismatches');
    }

    public static function getNavigationLabel(): string
    {
        return __('Price mismatches');
    }

    public static function canAccess(): bool
    {
        $staff = Auth::user();

        return (bool) $staff?->hasPermission('admin.reports.view') && (bool) $staff->hasPermission('admin.services.viewAny')
            && (bool) $staff->hasPermission('admin.domains.viewAny');
    }

    public function updated(): void
    {
        $this->page = 1;
    }

    /** @return list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}> */
    public function rows(): array
    {
        $rows = array_values(array_filter(PriceMismatch::rows(), fn (array $row) => (!in_array($this->type, ['service', 'domain'], true) || $row['type'] === $this->type)
            && match ($this->direction) {
                'below' => (float) $row['difference'] < 0,
                'above' => (float) $row['difference'] > 0,
                default => true,
            }));
        usort($rows, fn (array $a, array $b) => abs((float) $b['difference']) <=> abs((float) $a['difference']) ?: $a['id'] <=> $b['id']);

        return $rows;
    }
}
