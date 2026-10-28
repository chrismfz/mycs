<?php

namespace App\Classes;

use App\Helpers\EventHelper;
use App\Livewire\Invoices\Documents;
use App\Models\Category;
use App\Models\DomainTld;
use App\Models\KbArticle;
use App\Models\User;
use App\Services\Domains\Ordering\DomainOffer;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;

class Navigation
{
    public static function getLinks()
    {
        return once(function () {

            // The guest menu (docs/admin-ux.md, «Μενού επισκέπτη»): the shop's categories marked «Show in menu», the
            // domain pages, contact. Home is the logo.
            $categories = Category::query()->inMenu()->get();
            $domains = DomainTld::query()->where('is_active', true)->exists();

            $routes = [
                [
                    'name' => __('navigation.shop'),
                    // «Show all» first, as WHMCS's «Εμφάνιση όλων», a line under it, then the categories.
                    'children' => [
                        ['name' => __('navigation.show_all'), 'url' => route('products.all'), 'separator' => true],
                        ...$categories->map(fn (Category $category) => [
                            'name' => $category->name,
                            'url' => route('category.show', ['category' => $category->slug]),
                        ]),
                    ],
                    'condition' => count($categories) > 0,
                    'separator' => true,
                    'icon' => 'ri-shopping-bag',
                ],
                [
                    'name' => __('navigation.domains'),
                    'children' => [
                        ['name' => __('navigation.domain_search'), 'url' => route('domain.search')],
                        // Its own page (owner 2026-10-20): inside the search, few would find it.
                        ...(DomainOffer::allowed('transfer') ? [['name' => __('navigation.domain_transfer'), 'url' => route('domain.transfer')]] : []),
                        ['name' => __('navigation.domain_pricing'), 'url' => route('domain.pricing')],
                        ['name' => __('navigation.whois'), 'url' => route('domain.whois')],
                    ],
                    'condition' => $domains,
                    'icon' => 'ri-global',
                ],
                [
                    // The knowledgebase (docs/knowledgebase.md), once it has an article to read.
                    'name' => __('navigation.knowledgebase'),
                    'url' => route('kb.index'),
                    'icon' => 'ri-book-open',
                    'condition' => self::hasKnowledgebase(),
                ],
                [
                    // A ticket to support: a guest's by the contact form (docs/tickets.md §3 Ζ), a login's as «New ticket».
                    'name' => __('navigation.contact'),
                    'url' => Auth::guard('web')->check() ? route('tickets.create') : route('contact'),
                    'icon' => 'ri-customer-service-2',
                    // A login whose account does not allow tickets would get a 403 (code review of #217); a guest
                    // goes to the login.
                    'condition' => !config('settings.tickets_disabled', false) && (!Auth::guard('web')->check() || CurrentClient::allows('tickets')),
                ],
            ];

            $routes = EventHelper::itemEvent('navigation', $routes);

            $routes = array_filter($routes, function ($route) {
                return isset($route['condition']) ? $route['condition'] : true;
            });

            return Navigation::markActiveRoute($routes);
        });
    }

    /**
     * The main menu of a logged-in customer (P4a of the portal, owner 2026-10-19): their own pages, one click each, no
     * dropdowns (Home, Services, Domains, Invoices, Tickets, as their grant allows), then «New order»: the shop's
     * categories and the domain pages in one menu, so nothing of the guest menu is lost.
     *
     * @return list<array<string, mixed>>
     */
    public static function getClientLinks(): array
    {
        return once(function () {
            $links = [];
            foreach (self::getDashboardLinks() as $link) {
                if (!isset($link['url']) || !empty($link['children'])) {
                    continue; // the account's pages stay in its own menu
                }
                $links[] = $link['url'] === route('dashboard') ? ['name' => __('navigation.home')] + $link : $link;
            }
            $order = [];
            foreach (self::getLinks() as $guest) {
                if (!empty($guest['children'])) {
                    $order = array_merge($order, array_values($guest['children']));
                }
            }
            if ($order !== []) {
                $links[] = ['name' => __('navigation.new_order'), 'children' => $order, 'icon' => 'ri-add-circle-line',
                    'active' => collect($order)->contains(fn ($child) => (bool) ($child['active'] ?? false))];
            }
            if (self::hasKnowledgebase()) {
                $links[] = ['name' => __('navigation.knowledgebase'), 'url' => route('kb.index'), 'icon' => 'ri-book-open',
                    'active' => request()->routeIs('kb.*')];
            }

            return $links;
        });
    }

    private static function hasKnowledgebase(): bool
    {
        return once(fn () => KbArticle::query()->published()->exists());
    }

    // Get navigation items for user dropdown menu
    public static function getAccountDropdownLinks()
    {
        return once(function () {

            $routes = [
                [
                    'name' => __('navigation.dashboard'),
                    'url' => route('dashboard'),
                ],
                [
                    'name' => __('navigation.tickets'),
                    'url' => route('tickets'),
                    'condition' => !config('settings.tickets_disabled', false) && CurrentClient::allows('tickets'),
                ],
                [
                    'name' => __('navigation.account'),
                    'url' => route('account'),
                ],
                [
                    'name' => __('navigation.switch_account'),
                    'url' => route('account.accounts'),
                    'condition' => Auth::check() && self::hasSeveralAccounts(),
                ],
                [
                    'name' => __('navigation.admin'),
                    'url' => route('filament.admin.pages.dashboard'),
                    'spa' => false,
                    'condition' => Auth::guard('staff')->check(),
                ],
            ];

            $routes = EventHelper::itemEvent('navigation.account-dropdown', $routes);

            $routes = array_filter($routes, function ($route) {
                return isset($route['condition']) ? $route['condition'] : true;
            });

            return Navigation::markActiveRoute($routes);
        });
    }

    public static function getDashboardLinks()
    {
        return once(function () {

            $routes = [
                [
                    'name' => __('navigation.dashboard'),
                    'url' => route('dashboard'),
                    'icon' => 'ri-function',
                    'condition' => Auth::check(),
                    'priority' => 10,
                ],
                [
                    'name' => __('navigation.services'),
                    'url' => route('services'),
                    'icon' => 'ri-archive-stack',
                    'condition' => Auth::check() && CurrentClient::allows('products'),
                    'priority' => 20,
                ],
                [
                    'name' => __('navigation.domains'),
                    'url' => route('domains'),
                    'icon' => 'ri-global',
                    'condition' => Auth::check() && CurrentClient::allows('domains')
                        && CurrentClient::model()?->domains()->exists(),
                    'priority' => 25,
                ],
                [
                    'name' => __('navigation.invoices'),
                    'url' => route('invoices'),
                    'icon' => 'ri-receipt',
                    'separator' => true,
                    'condition' => Auth::check() && CurrentClient::allows('invoices'),
                    'priority' => 30,
                ],
                [
                    // «Τα παραστατικά μου» (E2b): with the Ekdosi bridge on and a document to show.
                    'name' => __('invoices.my_documents'),
                    'url' => route('documents'),
                    'icon' => 'ri-file-list-3',
                    'condition' => Auth::check() && CurrentClient::allows('invoices') && Documents::shown(),
                    'priority' => 31,
                ],
                [
                    'name' => __('navigation.tickets'),
                    'url' => route('tickets'),
                    'icon' => 'ri-customer-service',
                    'separator' => true,
                    'condition' => Auth::check() && !config('settings.tickets_disabled', false) && CurrentClient::allows('tickets'),
                    'priority' => 40,
                ],
                [
                    'name' => __('navigation.account'),
                    'icon' => 'ri-settings-3',
                    'condition' => Auth::check(),
                    'priority' => 50,
                    'children' => EventHelper::itemEvent(
                        'navigation.account',
                        [
                            [
                                'name' => __('navigation.personal_details'),
                                'url' => route('account'),
                                'params' => [],
                                'priority' => 10,
                            ],
                            [
                                'name' => __('navigation.security'),
                                'url' => route('account.security'),
                                'params' => [],
                                'priority' => 20,
                            ],
                            [
                                'name' => __('account.credits'),
                                'url' => route('account.credits'),
                                'params' => [],
                                'condition' => config('settings.credits_enabled') && CurrentClient::allows('invoices'),
                                'priority' => 30,
                            ],
                            [
                                'name' => __('account.payment_methods'),
                                'url' => route('account.payment-methods'),
                                'condition' => CurrentClient::allows('invoices'),
                                'priority' => 40,
                            ],
                            [
                                'name' => __('navigation.notifications'),
                                'url' => route('account.notifications'),
                                'priority' => 50,
                            ],
                            [
                                'name' => __('account.emails.title'),
                                'url' => route('account.emails'),
                                'condition' => CurrentClient::allows('emails'),
                                'priority' => 52,
                            ],
                            [
                                'name' => __('navigation.contacts'),
                                'url' => route('account.contacts'),
                                'condition' => CurrentClient::allows('contacts'),
                                'priority' => 55,
                            ],
                            [
                                'name' => __('navigation.users'),
                                'url' => route('account.users'),
                                'condition' => self::ownsCurrentAccount(),
                                'priority' => 60,
                            ],
                            [
                                'name' => __('navigation.switch_account'),
                                'url' => route('account.accounts'),
                                'condition' => self::hasSeveralAccounts(),
                                'priority' => 70,
                            ],
                        ]
                    ),
                ],
            ];

            $routes = EventHelper::itemEvent('navigation.dashboard', $routes);

            $routes = array_filter($routes, function ($route) {
                return isset($route['condition']) ? $route['condition'] : true;
            });
            // Children too: an area the login's grant does not allow is not offered (docs/accounts.md, PR 2b).
            foreach ($routes as &$route) {
                if (isset($route['children'])) {
                    $route['children'] = array_values(array_filter($route['children'], fn ($child) => $child['condition'] ?? true));
                }
            }
            unset($route);

            return Navigation::markActiveRoute($routes);
        });
    }

    /** The login owns the current account (or staff act as its owner): it manages the account's users. */
    private static function ownsCurrentAccount(): bool
    {
        $client = CurrentClient::model();

        return $client !== null && (Actor::staffMayAs('profile') // staff: with the admin permission (SA-5)
            || $client->grants()->where('user_id', Auth::id())->whereNotNull('owner_of_client_id')->whereNull('revoked_at')->exists());
    }

    private static function hasSeveralAccounts(): bool
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && !Actor::impersonating() && $user->clients()->count() > 1;
    }

    public static function getActiveRoute()
    {
        $route = request()->route()->getName();
        $routes = [
            ...self::getLinks(),
            ...self::getAccountDropdownLinks(),
            ...self::getDashboardLinks(),
        ];
        // Get current parnet of the route
        $parent = null;
        foreach ($routes as $item) {
            if ($item['route'] == $route) {
                $parent = $item;
                break;
            }
            if (isset($item['children'])) {
                foreach ($item['children'] as $child) {
                    if ($child['route'] == $route) {
                        $parent = $item;
                        break;
                    }
                }
            }
        }

        return $parent;
    }

    /**
     * Set `active` to true if the route is currently active,
     * or falce if route isn't active (prevents `Undefined array key "active"` errors)
     *
     * @return array routes
     */
    public static function markActiveRoute(array $routes): array
    {
        $currentRoute = request()->livewireUrl();

        foreach ($routes as &$route) {
            // Make route a url
            if (isset($route['route']) && !isset($route['url'])) {
                $route['url'] = route($route['route'], $route['params'] ?? []);
            }

            if (isset($route['children'])) {
                foreach ($route['children'] as &$child) {
                    // Make route a url
                    if (isset($child['route']) && !isset($child['url'])) {
                        $child['url'] = route($child['route'], $child['params'] ?? []);
                    }

                    $child['active'] = self::isActiveRoute($child, $currentRoute);

                    if (isset($child['icon'])) {
                        $child['icon'] .= $child['active'] ? '-fill' : '-line';
                    }
                }
            }

            $route['active'] = self::isActiveRoute($route, $currentRoute);

            if (isset($route['icon'])) {
                $route['icon'] .= $route['active'] ? '-fill' : '-line';
            }
        }

        return $routes;
    }

    private static function isActiveRoute(array $route, string $currentRoute): bool
    {
        if (($route['url'] ?? '') === $currentRoute) {
            return true;
        }

        if (!empty($route['children'])) {
            foreach ($route['children'] as $child) {
                if (($child['url'] ?? '') === $currentRoute) {
                    return true;
                }
            }
        }

        return false;
    }
}
