<?php

namespace App\Mcp\Tools;

use App\Models\Category;
use App\Models\ClientGroup;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Ops\NotFound;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The catalogue as staff see it in the admin (owner 2026-11-14): products with their categories, plans and prices,
 * the categories, the coupons and the client groups. Each part only to staff whose role may see it there. Read only:
 * a change of price is staff's, in the admin.
 */
#[Name('catalog')]
#[Description('The catalogue: `products` (category, hidden/retired/addon, server, each plan\'s cycle and price and setup fee per currency, active services), `categories` (parent, products), `coupons` (code, type, value, products, cycles, dates, uses), `client_groups` (discount, flags, accounts). `product` gives one product by id or name; `query` filters products and coupons by name or code. Each part only if your role may see it. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class CatalogTool extends McpTool
{
    public const PARTS = ['products' => 'admin.products.viewAny', 'categories' => 'admin.categories.viewAny',
        'coupons' => 'admin.coupons.viewAny', 'client_groups' => 'admin.client_groups.viewAny'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'part' => $schema->string()->description('products (default), categories, coupons or client_groups.'),
            'product' => $schema->string()->description('One product, by id or its exact name.'),
            'query' => $schema->string()->description('Products or coupons whose name or code holds this text.'),
            'retired' => $schema->boolean()->description('Products: list the retired ones too (default no).'),
            'limit' => $schema->integer()->description('List size (default 50, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.products.viewAny';
    }

    protected function run(Request $request): array
    {
        $part = trim((string) ($request->get('part') ?? '')) ?: 'products';
        if (!array_key_exists($part, self::PARTS)) {
            throw new \InvalidArgumentException('`part` is one of: ' . implode(', ', array_keys(self::PARTS)) . '.');
        }
        $staff = $request->user();
        if (!$staff instanceof Staff || !$staff->hasPermission(self::PARTS[$part])) {
            throw new \InvalidArgumentException("Your role may not see the {$part}.");
        }
        $limit = self::limit($request, 50, 200);
        $query = trim((string) $request->get('query'));

        return match ($part) {
            'products' => self::products($request, $query, $limit),
            'categories' => ['categories' => Category::query()->with('parent')->withCount('products')->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->full_slug ?: $category->slug,
                    'parent' => $category->parent?->name, 'in_menu' => (bool) $category->show_in_menu, 'products' => $category->products_count])->all()],
            'coupons' => self::coupons($query, $limit),
            'client_groups' => ['client_groups' => ClientGroup::query()->withCount('clients')->orderBy('name')->get()
                ->map(fn (ClientGroup $group) => ['id' => $group->id, 'name' => $group->name, 'discount_percent' => (string) $group->discount_percent,
                    'applies_to_domains' => $group->applies_to_domains, 'separate_invoices' => $group->separate_invoices,
                    'dunning_exempt' => $group->dunning_exempt, 'no_overdue_reminders' => $group->no_overdue_reminders, 'accounts' => $group->clients_count])->all()],
        };
    }

    /** @return array<string, mixed> */
    private static function products(Request $request, string $query, int $limit): array
    {
        $base = Product::query()->with(['category', 'server', 'plans.prices'])
            ->withCount(['services as active_services' => fn (Builder $q) => $q->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])]);
        $one = trim((string) $request->get('product'));
        if ($one !== '') {
            $product = (ctype_digit($one) ? (clone $base)->find((int) $one) : null)
                ?? (clone $base)->where('name', $one)->first()
                ?? throw new NotFound("No product {$one}.");

            return ['product' => self::product($product) + ['description' => mb_substr(strip_tags((string) $product->description), 0, 2000)]];
        }
        $products = $base->when(!$request->get('retired'), fn (Builder $q) => $q->where('retired', false))
            ->when($query !== '', fn (Builder $q) => $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [self::like($query)]))
            ->orderBy('category_id')->orderBy('sort')->orderBy('name')->limit($limit)->get();

        return ['products' => $products->map(fn (Product $product) => self::product($product))->all()];
    }

    /** @return array<string, mixed> */
    private static function product(Product $product): array
    {
        return [
            'id' => $product->id, 'name' => $product->name, 'category' => $product->category?->name,
            'hidden' => (bool) $product->hidden, 'retired' => (bool) $product->retired, 'addon' => (bool) $product->is_addon,
            'taxable' => (bool) $product->taxable, 'server' => $product->server?->name, 'stock' => $product->stock,
            'active_services' => (int) $product->getAttribute('active_services'),
            'plans' => $product->plans->map(fn (Plan $plan) => [
                'id' => $plan->id, 'name' => $plan->name, 'type' => $plan->type,
                'cycle' => $plan->type === 'recurring' ? $plan->billing_period . ' ' . $plan->billing_unit : $plan->type,
                'prices' => $plan->prices->map(fn (Price $price) => ['currency' => $price->currency_code, 'price' => self::money($price->price),
                    'setup_fee' => self::money($price->setup_fee)])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function coupons(string $query, int $limit): array
    {
        return ['coupons' => Coupon::query()->with('products')->withCount('uses')
            ->when($query !== '', fn (Builder $q) => $q->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [self::like($query)]))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Coupon $coupon) => [
                'id' => $coupon->id, 'code' => $coupon->code, 'type' => $coupon->type, 'value' => (string) $coupon->value,
                'applies_to' => $coupon->applies_to, 'recurring' => $coupon->recurring, 'customers' => $coupon->customers,
                'products' => $coupon->products->map(fn (Product $product) => '#' . $product->id . ' ' . $product->name)->all(),
                'billing_cycles' => $coupon->billing_cycles, 'starts_at' => $coupon->starts_at?->toDateString(), 'expires_at' => $coupon->expires_at?->toDateString(),
                'max_uses' => $coupon->max_uses, 'max_uses_per_user' => $coupon->max_uses_per_user, 'once_per_client' => $coupon->once_per_client,
                'uses' => $coupon->uses_count,
            ])->all()];
    }

    private static function money(mixed $amount): ?string
    {
        return $amount === null ? null : (string) BigDecimal::of((string) $amount)->toScale(2);
    }

    /** % and _ literally, case aside (as TicketResource::whereContains). */
    private static function like(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)) . '%';
    }
}
