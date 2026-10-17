<?php

namespace App\Classes;

use App\Helpers\EventHelper;
use App\Models\Category;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;

class Navigation
{
    public static function getLinks()
    {
        return once(function () {

            $categories = Category::whereNull('parent_id')->where(function ($query) {
                $query->whereHas('children')->orWhereHas('products', function ($query) {
                    $query->where('hidden', false);
                });
            })->orderBy('sort')->get();

            $routes = [
                [
                    'name' => __('navigation.home'),
                    'url' => route('home'),
                    'icon' => 'ri-home-2',
                ],
                [
                    'name' => __('navigation.shop'),
                    'children' => $categories->map(function ($category) {
                        return [
                            'name' => $category->name,
                            'url' => route('category.show', ['category' => $category->slug]),
                        ];
                    })->toArray(),
                    'condition' => count($categories) > 0,
                    'separator' => true,
                    'icon' => 'ri-shopping-bag',
                ],
            ];

            $routes = EventHelper::itemEvent('navigation', $routes);

            $routes = array_filter($routes, function ($route) {
                return isset($route['condition']) ? $route['condition'] : true;
            });

            return Navigation::markActiveRoute($routes);
        });
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

        return $client !== null && (Actor::impersonating()
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
