<?php

namespace App\Services\Reports;

use App\Enums\DomainStatus;
use App\Models\Category;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Income by product (owner 2026-10-28, the approved mockup; docs/income-planning.md): what each category and product
 * brought in a year against the same period a year before (as ClientIncome), with its active services, what it brings
 * in per service, what its services bring in a year if renewed, and the services new and cancelled in the year.
 *
 * Each paid invoice's net (RevenueForecast::netByInvoice: its notes in their net share, never a credit purchase) is
 * shared out over its lines by their net, to the cent (the total is Income & forecast's): a service's line to its
 * product and the product's category; a domain's to «Domains», by its extension (an imported line with no type, by its
 * WHMCS id and its name in the description); anything else (setup, by hand, upgrades) to «Other».
 */
final class ProductIncome
{
    /** The groups that are not a category of the catalogue. */
    public const DOMAINS = 'domains';

    public const OTHER = 'other';

    public const NO_CATEGORY = 'none';

    /**
     * @return array{from: CarbonImmutable, until: CarbonImmutable, last_from: CarbonImmutable, last_until: CarbonImmutable, to_date: bool,
     *               groups: list<array{key: string, name: string, received: int, last_year: int, services: int|null, annual: int, new: int|null, ended: int|null,
     *                                  items: list<array{key: string, name: string, received: int, last_year: int, services: int|null, annual: int, new: int|null, ended: int|null}>}>,
     *               total: int, last_year_total: int}
     */
    public static function year(string $currency, int $year): array
    {
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        // As ClientIncome: this year to the end of today against the same days a year before; else whole years.
        $toDate = $year === (int) $now->format('Y');
        $until = $toDate ? $now->addDay()->startOfDay() : $from->addYear();
        $lastUntil = $toDate ? $now->subYearNoOverflow()->addDay()->startOfDay() : $from;

        $cells = [];
        foreach (['received' => [$from, $until], 'last_year' => [$from->subYear(), $lastUntil]] as $column => [$start, $end]) {
            foreach (self::byItem($currency, $start, $end) as $key => $cents) {
                $cells[$key][$column] = ($cells[$key][$column] ?? 0) + $cents;
            }
        }

        // What each product and extension has now, and what came and went in the year.
        foreach (Service::query()->where('status', Service::STATUS_ACTIVE)->where('currency_code', $currency)
            ->selectRaw('product_id, COUNT(*) AS count')->groupBy('product_id')->pluck('count', 'product_id') as $product => $count) {
            $cells['product:' . $product]['services'] = (int) $count;
        }
        foreach (ClientIncome::renewingServices($currency) as $service) {
            if (($yearly = ClientIncome::serviceYearly($service)) !== null) {
                $key = 'product:' . $service->product_id;
                $cells[$key]['annual_exact'] = ($cells[$key]['annual_exact'] ?? BigDecimal::zero())->plus($yearly);
            }
        }
        foreach (Service::query()->where('currency_code', $currency)->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw('product_id, COUNT(*) AS count')->groupBy('product_id')->pluck('count', 'product_id') as $product => $count) {
            $cells['product:' . $product]['new'] = (int) $count;
        }
        foreach (ServiceCancellation::query()->join('services', 'services.id', '=', 'service_cancellations.service_id')
            ->where('services.currency_code', $currency)->where('service_cancellations.created_at', '>=', $from)
            ->where('service_cancellations.created_at', '<', $until)
            ->selectRaw('services.product_id, COUNT(*) AS count')->groupBy('services.product_id')->pluck('count', 'product_id') as $product => $count) {
            $cells['product:' . $product]['ended'] = (int) $count;
        }

        // Active: registered and ours (grace and redemption too); ended: gone for good in the year. An expired one may
        // still be renewed (RenewalBilling::BILLED_DOMAINS): neither.
        $live = [DomainStatus::Active->value, DomainStatus::Grace->value, DomainStatus::Redemption->value];
        $gone = [DomainStatus::TransferredAway->value, DomainStatus::Cancelled->value, DomainStatus::Deleted->value];
        foreach (Domain::query()->where('currency_code', $currency)->get(['id', 'tld', 'status', 'created_at', 'expires_at']) as $domain) {
            $key = 'tld:' . self::tld((string) $domain->tld);
            $status = $domain->status->value;
            $cells[$key]['services'] = ($cells[$key]['services'] ?? 0) + (in_array($status, $live, true) ? 1 : 0);
            if ($domain->created_at !== null && $domain->created_at >= $from && $domain->created_at < $until) {
                $cells[$key]['new'] = ($cells[$key]['new'] ?? 0) + 1;
            }
            if (in_array($status, $gone, true) && $domain->expires_at !== null && $domain->expires_at >= $from && $domain->expires_at < $until) {
                $cells[$key]['ended'] = ($cells[$key]['ended'] ?? 0) + 1;
            }
        }
        foreach (ClientIncome::renewingDomains($currency) as $domain) {
            if (($yearly = ClientIncome::domainYearly($domain)) !== null) {
                $key = 'tld:' . self::tld((string) $domain->tld);
                $cells[$key]['annual_exact'] = ($cells[$key]['annual_exact'] ?? BigDecimal::zero())->plus($yearly);
            }
        }

        $groups = self::group($cells);

        return [
            'from' => $from, 'until' => $until, 'last_from' => $from->subYear(), 'last_until' => $lastUntil, 'to_date' => $toDate,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'received')),
            'last_year_total' => array_sum(array_column($groups, 'last_year')),
        ];
    }

    /**
     * What the invoices paid in [from, until) brought in, net, in cents, by item: 'product:<id>', 'tld:<ext>' or 'other'.
     * Each invoice's net shared out over its lines by their net; the last line takes what rounding leaves, so the
     * items add up to the invoices (and to Income & forecast).
     *
     * @return array<string, int>
     */
    private static function byItem(string $currency, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $invoices = fn () => Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        $nets = RevenueForecast::netByInvoice($invoices());
        if ($nets === []) {
            return [];
        }
        $owners = $invoices()->pluck('client_id', 'id');
        $lines = DB::table('invoice_items')->whereIn('invoice_id', $invoices()->select('id'))
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->orderBy('invoice_id')->orderBy('id')
            ->get(['id', 'invoice_id', 'reference_type', 'reference_id', 'description', 'line_total', 'tax_amount'])->groupBy('invoice_id');

        // Where a line goes: a service's product, a domain's extension.
        $all = $lines->flatten(1);
        $products = Service::query()->whereIn('id', $all->where('reference_type', Service::class)->pluck('reference_id')->unique()->all())
            ->pluck('product_id', 'id');
        $domainIds = $all->filter(fn ($line) => $line->reference_type === Domain::class || ($line->reference_type === null && $line->reference_id !== null))
            ->pluck('reference_id')->unique()->all();
        $domains = Domain::query()->whereIn('id', $domainIds)->get(['id', 'client_id', 'fqdn', 'tld'])->keyBy('id');

        $out = [];
        foreach ($nets as $invoice => $net) {
            $shares = [];
            foreach ($lines->get($invoice, collect()) as $line) {
                $key = self::item($line, $products, $domains, $owners[$invoice] ?? null);
                $cents = RevenueForecast::cents(number_format((float) $line->line_total - (float) $line->tax_amount, 2, '.', ''));
                $shares[] = [$key, $cents];
            }
            $sum = array_sum(array_column($shares, 1));
            if ($shares === [] || $sum === 0) {
                $out[self::OTHER] = ($out[self::OTHER] ?? 0) + $net; // nothing to share by: a note on lines of nothing

                continue;
            }
            $given = 0;
            foreach ($shares as $i => [$key, $cents]) {
                $part = $i === count($shares) - 1 ? $net - $given
                    : BigDecimal::of($net)->multipliedBy($cents)->dividedBy($sum, 0, RoundingMode::HalfUp)->toInt();
                $given += $part;
                $out[$key] = ($out[$key] ?? 0) + $part;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int|string, mixed>  $products
     * @param  Collection<int, Domain>  $domains
     */
    private static function item(object $line, Collection $products, Collection $domains, mixed $client): string
    {
        if ($line->reference_type === Service::class && isset($products[$line->reference_id])) {
            return 'product:' . $products[$line->reference_id];
        }
        $domain = $line->reference_id === null ? null : $domains->get($line->reference_id);
        if ($domain !== null && $line->reference_type === Domain::class) {
            return 'tld:' . self::tld((string) $domain->tld);
        }
        // An imported WHMCS «Domain» line has no type, only the domain's id: its, when the domain is the invoice's
        // client's and its name is in the line's description (owner 2026-10-28).
        if ($domain !== null && $line->reference_type === null && (int) $domain->client_id === (int) $client
            && $domain->fqdn !== '' && mb_stripos((string) $line->description, (string) $domain->fqdn) !== false) {
            return 'tld:' . self::tld((string) $domain->tld);
        }

        return self::OTHER;
    }

    /** An extension as shown: «.gr», whatever the stored form. */
    private static function tld(string $tld): string
    {
        return '.' . ltrim(mb_strtolower($tld), '.');
    }

    /**
     * The items in their groups, the largest first: categories (by the product's), «Domains» (by extension), and
     * «Other» last-but-sorted like the rest. A group's figures add up its items'.
     *
     * @param  array<string, array<string, mixed>>  $cells
     * @return list<array<string, mixed>>
     */
    private static function group(array $cells): array
    {
        $productIds = array_map(fn ($key) => (int) substr($key, 8), array_filter(array_keys($cells), fn ($key) => str_starts_with($key, 'product:')));
        $products = Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'category_id'])->keyBy('id');
        $categories = Category::query()->whereIn('id', $products->pluck('category_id')->filter()->unique()->all())->pluck('name', 'id');

        $groups = [];
        foreach ($cells as $key => $cell) {
            $item = ['key' => $key, 'received' => $cell['received'] ?? 0, 'last_year' => $cell['last_year'] ?? 0,
                'services' => $cell['services'] ?? 0, 'annual' => isset($cell['annual_exact']) ? ClientIncome::toCents($cell['annual_exact']) : 0,
                'new' => $cell['new'] ?? 0, 'ended' => $cell['ended'] ?? 0];
            if (str_starts_with($key, 'product:')) {
                $product = $products->get((int) substr($key, 8));
                $category = $product?->category_id;
                [$group, $groupName] = $category !== null && $categories->has($category)
                    ? ['category:' . $category, (string) $categories[$category]] : [self::NO_CATEGORY, __('No category')];
                $item['name'] = $product === null ? __('Product') . ' #' . substr($key, 8) : (string) $product->name;
            } elseif (str_starts_with($key, 'tld:')) {
                [$group, $groupName] = [self::DOMAINS, __('Domains')];
                $item['name'] = substr($key, 4);
            } else {
                [$group, $groupName] = [self::OTHER, __('Other (no service)')];
                $item = ['name' => $groupName, 'services' => null, 'new' => null, 'ended' => null] + $item;
            }
            // An item with nothing in either year and no active service is not shown.
            if ($item['received'] === 0 && $item['last_year'] === 0 && !$item['services']) {
                continue;
            }
            $groups[$group] ??= ['key' => $group, 'name' => $groupName, 'items' => []];
            $groups[$group]['items'][] = $item;
        }

        $sort = fn (array $a, array $b) => [$b['received'], $b['last_year'], $a['name']] <=> [$a['received'], $a['last_year'], $b['name']];
        foreach ($groups as $key => $group) {
            usort($group['items'], $sort);
            foreach (['received', 'last_year', 'services', 'annual', 'new', 'ended'] as $column) {
                $values = array_column($group['items'], $column);
                $group[$column] = $key === self::OTHER && in_array($column, ['services', 'new', 'ended'], true) ? null : array_sum($values);
            }
            // «Other» is one line: no products under it.
            if ($key === self::OTHER) {
                $group['items'] = [];
            }
            $groups[$key] = $group;
        }
        $groups = array_values($groups);
        usort($groups, $sort);

        return $groups;
    }
}
