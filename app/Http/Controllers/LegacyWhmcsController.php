<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * WHMCS's addresses, sent for good (301) to ours at the cut-over (ROADMAP «Cut-over», the 301 map): the store and its
 * products (their old slugs too, `whmcs_product_slugs`), the cart's «add» links, the client area's pages, invoices and
 * tickets, announcements, `index.php?rp=…`. With /clients in front (myip's WHMCS lived there; myip.gr sends /clients/* here)
 * and without it (nixpal's at clients.nixpal.com). Only to our own addresses, by route: a path is never sent anywhere
 * else. Anything else of WHMCS's goes to the home page, so a link from Google never ends in a 404. The knowledgebase has
 * its own (KnowledgebaseRedirectController).
 */
class LegacyWhmcsController
{
    /** /clients/{path}: anything under WHMCS's old folder. */
    public function clients(Request $request, ?string $path = null): RedirectResponse
    {
        return redirect()->to($this->target(trim((string) $path, '/'), $request), 301);
    }

    /** A WHMCS address without /clients (nixpal's host): the route's own path. */
    public function bare(Request $request): RedirectResponse
    {
        // Decoded, as /clients/{path} is (a slug in another script: review of #676).
        return redirect()->to($this->target(trim($request->decodedPath(), '/'), $request), 301);
    }

    /** index.php?rp=/store/… (WHMCS without friendly URLs, or its own links): from the home page's middleware. */
    public static function rp(string $rp): string
    {
        return (new self)->path(trim($rp, '/'), new Request);
    }

    private function target(string $path, Request $request): string
    {
        $path = mb_substr($path, 0, 500);
        // WHMCS's «index.php/…» style of friendly URLs (review of #676).
        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, strlen('index.php/'));
        }
        if (in_array($path, ['', 'index.php'], true) && is_string($rp = $request->query('rp'))) {
            return $this->path(trim(mb_substr($rp, 0, 500), '/'), $request);
        }

        return $this->path($path, $request);
    }

    /** Our address for one of WHMCS's paths (no /clients, no leading slash) and its query. */
    private function path(string $path, Request $request): string
    {
        $query = fn (string $key) => is_string($value = $request->query($key)) ? mb_substr($value, 0, 255) : '';
        $segments = explode('/', $path);

        return match (true) {
            $segments[0] === 'store' => $this->store($segments[1] ?? null, $segments[2] ?? null),
            $path === 'cart.php' => $this->cart($query),
            $path === 'clientarea.php' => $this->clientArea($query('action'), (int) $query('id')),
            $path === 'viewinvoice.php' => $this->invoice((int) $query('id')),
            $path === 'submitticket.php' => route('tickets.create'),
            $path === 'supporttickets.php' => route('tickets'),
            $path === 'viewticket.php' => $this->ticket($query('tid')),
            in_array($path, ['login.php', 'dologin.php', 'login'], true) => route('login'),
            $path === 'register.php' => route('register'),
            in_array($path, ['pwreset.php', 'password/reset'], true) || str_starts_with($path, 'password/reset/') => route('password.request'),
            $path === 'domainchecker.php' => route('domain.search', array_filter(['domain' => $query('domain')])),
            in_array($path, ['domain/pricing', 'domainpricing.php'], true) => route('domain.pricing'),
            in_array($path, ['contact.php', 'contact'], true) => route('contact'),
            $segments[0] === 'announcements' || $path === 'announcements.php' => $this->announcement($segments[2] ?? null),
            $segments[0] === 'knowledgebase' => (new KnowledgebaseRedirectController)->clients(implode('/', array_slice($segments, 1)))->getTargetUrl(),
            $path === 'knowledgebase.php' => (new KnowledgebaseRedirectController)->script($request)->getTargetUrl(),
            $segments[0] === 'cart' && ($segments[1] ?? '') === 'domain' => route('domains'),
            in_array($segments[0], ['account', 'user'], true) => route('account'),
            default => route('home'),
        };
    }

    /** /store, /store/{group}, /store/{group}/{product}: by the slug WHMCS gave it (an old one too), else its group's page. */
    private function store(?string $group, ?string $slug): string
    {
        $group = strtolower((string) $group);
        $slug = strtolower((string) $slug);
        if ($slug !== '') {
            $id = DB::table('whmcs_product_slugs')->where('group_slug', $group)->where('slug', $slug)->value('product_id');
            $product = $id !== null ? Product::query()->with('category')->find($id)
                : Product::query()->with('category')->where('slug', $slug)->whereHas('category', fn ($q) => $q->where('slug', $group))->first();
            if ($product !== null && ($category = $product->category) instanceof Category) {
                return route('products.show', [$category->slug, $product->slug]);
            }
        }
        $category = $group !== '' ? Category::query()->where('slug', $group)->first() : null;

        return $category !== null ? route('category.show', $category->slug) : route('products.all');
    }

    /** cart.php: «add» of a product (pid) or a group (gid), a domain to register or transfer, else the store. */
    private function cart(\Closure $query): string
    {
        if (ctype_digit($pid = $query('pid'))) {
            $product = Product::query()->with('category')->where('whmcs_id', (int) $pid)->first();
            if ($product !== null && ($category = $product->category) instanceof Category) {
                return route('products.show', [$category->slug, $product->slug]);
            }
        }
        if (ctype_digit($gid = $query('gid'))) {
            $category = Category::query()->where('whmcs_id', (int) $gid)->first();
            if ($category !== null) {
                return route('category.show', $category->slug);
            }
        }

        return match (true) {
            $query('domain') === 'register' => route('domain.search', array_filter(['domain' => $query('query')])),
            $query('domain') === 'transfer' => route('domain.transfer'),
            $query('a') === 'view' || $query('a') === 'checkout' => route('cart'),
            default => route('products.all'),
        };
    }

    /**
     * clientarea.php?action=…: the page here (one service, domain or invoice by its id: WHMCS's ids are kept). Only to a
     * page the one logged in may view: a guest's redirect, or a customer's for another's id, never tells whether the id
     * exists (review of #676, EKDOSI-ZLIC-API-5).
     */
    private function clientArea(string $action, int $id): string
    {
        $id = auth()->check() ? $id : 0;

        return match ($action) {
            'products', 'services' => route('services'),
            'productdetails' => self::viewable(Service::class, $id) ? route('services.show', $id) : route('services'),
            'domains' => route('domains'),
            'domaindetails' => self::viewable(Domain::class, $id) ? route('domains.show', $id) : route('domains'),
            'invoices', 'masspay', 'addfunds' => route('invoices'),
            'details', 'contacts', 'changepw', 'security' => route('account'),
            'emails' => route('account.emails'),
            'creditcard', 'paymentmethods' => route('account.payment-methods'),
            default => route('dashboard'),
        };
    }

    private function invoice(int $id): string
    {
        return self::viewable(Invoice::class, $id) ? route('invoices.show', $id) : route('invoices');
    }

    /**
     * Whether the one logged in may view this record (the page's own `can:view`).
     *
     * @param  class-string<Model>  $model
     */
    private static function viewable(string $model, int $id): bool
    {
        $record = auth()->check() && $id > 0 ? $model::query()->find($id) : null;

        return $record !== null && Gate::allows('view', $record);
    }

    /** viewticket.php?tid=…: the ticket of that number (its own page checks who may see it), else the list. */
    private function ticket(string $tid): string
    {
        $ticket = auth()->check() && $tid !== '' ? Ticket::query()->where('reference', $tid)->value('id') : null;

        return $ticket !== null ? route('tickets.show', $ticket) : route('tickets');
    }

    /** /announcements[/{id}/{slug}.html]: ours by its slug (the import made it from the same title), else the list. */
    private function announcement(?string $slug): string
    {
        if (!Route::has('announcements.index')) {
            return route('home'); // the extension is off
        }
        $slug = preg_replace('/\.html$/', '', strtolower((string) $slug));
        if ($slug !== '' && DB::table('ext_announcements')->where('slug', $slug)->exists()) {
            return route('announcements.show', $slug);
        }

        return route('announcements.index');
    }
}
