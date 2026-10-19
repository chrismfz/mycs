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

            return $product === null ? $this->shop() : $this->checkout($product, $request);
        }
        if ($request->filled('gid')) {
            $category = Category::query()->where('whmcs_id', (int) $request->query('gid'))->first();

            return $category === null ? $this->shop() : redirect()->route('category.show', ['category' => $category]);
        }
        if ($request->filled('domain')) {
            // ?query=example.gr, or WHMCS's ?sld=example&tld=.gr (code review of #216: the ending was lost).
            $name = $request->filled('query') ? (string) $request->query('query')
                : trim((string) $request->query('sld')) . ($request->filled('sld') && $request->filled('tld') ? '.' . ltrim((string) $request->query('tld'), '.') : '');
            $name = trim($name);

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
        // Its slug, or the one the importer gave it when another group's product had it (`slug-2`, code review of #216):
        // WHMCS slugs are per group, ours are not.
        $found = $category->products()->reachable()->with('category')->where('slug', $product)->first()
            ?? $category->products()->reachable()->with('category')->where('slug', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $product) . '-%')
                ->get()->first(fn (Product $candidate) => preg_match('/^' . preg_quote($product, '/') . '-\\d+$/', (string) $candidate->slug) === 1);

        return $found === null ? redirect()->route('category.show', ['category' => $category]) : $this->checkout($found, $request);
    }

    /** viewinvoice.php?id=N, in every old invoice mail: the invoice, after the login (WHMCS's invoice ids are kept). */
    public function invoice(Request $request): RedirectResponse
    {
        $id = (string) $request->query('id');

        return ctype_digit($id) ? redirect()->to('/invoices/' . $id) : redirect()->route('invoices');
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

    /** The checkout, with the plan of WHMCS's ?billingcycle= and its ?promocode= as our ?coupon= (code review of #216). */
    private function checkout(Product $product, Request $request): RedirectResponse
    {
        $parameters = ['category' => $product->category, 'product' => $product];
        $cycle = $request->query('billingcycle');
        $plan = $this->plan($product, is_string($cycle) ? strtolower($cycle) : '');
        if ($plan !== null) {
            $parameters['plan'] = $plan->id;
        }
        if (is_string($request->query('promocode')) && $request->query('promocode') !== '') {
            $parameters['coupon'] = $request->query('promocode');
        }

        return redirect()->route('products.checkout', $parameters);
    }

    /**
     * The product's plan for a WHMCS cycle, of those the visitor can buy (priced in their currency, ADV-3 of #216); none
     * (the checkout's default) when it has no such plan.
     */
    private function plan(Product $product, string $cycle): ?Plan
    {
        $months = self::CYCLE_MONTHS[$cycle] ?? null;

        return $product->availablePlans(session('currency', config('settings.default_currency')))->first(fn (Plan $plan) => match (true) {
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
