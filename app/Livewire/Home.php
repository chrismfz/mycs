<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\DomainTld;
use App\Services\Domains\Ordering\DomainOffer;

/**
 * The home page for visitors (docs/admin-ux.md, «Μενού επισκέπτη»): a start, not the company's shop window, which is
 * its own site. The home text, a domain search, the categories of the menu as cards with a «from» price and the domains'
 * cards (as WHMCS's «Browse our Products/Services», owner 2026-10-29), and a way in for customers. A logged-in customer
 * goes to the dashboard, as WHMCS's client area.
 */
class Home extends Component
{
    public function mount(): void
    {
        if (auth('web')->check()) {
            $this->redirect(route('dashboard'));
        }
    }

    public function render()
    {
        $domains = DomainTld::query()->where('is_active', true)->exists();

        return view('home', [
            'categories' => Category::query()->inMenu()->with(['products.plans.prices'])->get(),
            'domains' => $domains,
            // The domains' cards: registering always goes through the search (its form is above), a transfer when allowed.
            'domainCards' => [
                'register' => $domains && DomainOffer::allowed('register'),
                'transfer' => $domains && DomainOffer::allowed('transfer'),
            ],
            'title' => __('navigation.home'),
        ]);
    }
}
