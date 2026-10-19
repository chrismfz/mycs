<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\DomainTld;

/**
 * The home page for visitors (docs/admin-ux.md, «Μενού επισκέπτη»): a start, not the company's shop window, which is
 * its own site. The home text, a domain search, the categories of the menu with a «from» price, and a way in for
 * customers. A logged-in customer goes to the dashboard, as WHMCS's client area.
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
        return view('home', [
            'categories' => Category::query()->inMenu()->with(['products.plans.prices'])->get(),
            'domains' => DomainTld::query()->where('is_active', true)->exists(),
            'title' => __('navigation.home'),
        ]);
    }
}
