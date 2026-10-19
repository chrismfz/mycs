<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The WHMCS links the companies' sites and old emails point to (cart.php, store/…, clientarea.php, submitticket.php,
 * …), sent to our pages, so that at the cut-over only their host changes (docs/admin-ux.md, «Απευθείας links»). The
 * catalog is found by the WHMCS id the importer keeps (`whmcs_id`), or by the slug it brought over. Temporary
 * redirects: a mapping can still change without browsers keeping the old one.
 */
class WhmcsLinkController extends Controller
{
    /** WHMCS «billingcycle» → months of a recurring plan. */
    private const CYCLE_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36];

    /** cart.php: a product (pid), a category (gid), a domain to register or transfer, or the cart itself. */
    public function cart(Request $request): RedirectResponse
    {
        if ($request->filled('pid')) {
            $product = Product::query()->where('whmcs_id', (int) $request->query('pid'))->reachable()->with('category')->first();

            return $product === null ? $this->shop() : $this->checkout($product, $request->query('billingcycle'));
        }
        if ($request->filled('gid')) {
            $category = Category::query()->where('whmcs_id', (int) $request->query('gid'))->first();

            return $category === null ? $this->shop() : redirect()->route('category.show', ['category' => $category]);
        }
        if ($request->filled('domain')) {
            $name = trim((string) ($request->query('query') ?? $request->query('sld') ?? ''));

            return redirect()->route('domain.search', $name === '' ? [] : ['domain' => $name]);
        }

        return redirect()->route('cart');
    }

    /** store/{group}/{product?} (WHMCS's friendly URLs), with ?billingcycle= as on the companies' sites. */
    public function store(Request $request, string $group, ?string $product = null): RedirectResponse
    {
        $category = Category::query()->where('slug', $group)->first();
        if ($category === null) {
            return $this->shop();
        }
        if ($product === null) {
            return redirect()->route('category.show', ['category' => $category]);
        }
        $found = $category->products()->where('slug', $product)->reachable()->with('category')->first();

        return $found === null ? redirect()->route('category.show', ['category' => $category]) : $this->checkout($found, $request->query('billingcycle'));
    }

    /** clientarea.php and its ?action= pages; the login comes first where needed. */
    public function clientArea(Request $request): RedirectResponse
    {
        return redirect()->route(match ((string) $request->query('action')) {
            'domains' => 'domains',
            'products', 'services' => 'services',
            'invoices' => 'invoices',
            'details' => 'account',
            default => 'dashboard',
        });
    }

    private function checkout(Product $product, mixed $cycle): RedirectResponse
    {
        $parameters = ['category' => $product->category, 'product' => $product];
        $plan = $this->plan($product, is_string($cycle) ? strtolower($cycle) : '');
        if ($plan !== null) {
            $parameters['plan'] = $plan->id;
        }

        return redirect()->route('products.checkout', $parameters);
    }

    /** The product's plan for a WHMCS cycle; none (the checkout's default) when it has no such plan. */
    private function plan(Product $product, string $cycle): ?Plan
    {
        $months = self::CYCLE_MONTHS[$cycle] ?? null;

        return $product->plans()->get()->first(fn (Plan $plan) => match (true) {
            $months !== null => $plan->type === 'recurring' && match ($plan->billing_unit) {
                'month' => $plan->billing_period,
                'year' => $plan->billing_period * 12,
                default => null,
            } === $months,
            $cycle === 'onetime' => $plan->type === 'one-time',
            $cycle === 'free' => $plan->type === 'free',
            default => false,
        });
    }

    private function shop(): RedirectResponse
    {
        return redirect()->route('home');
    }
}
