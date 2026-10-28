<?php

namespace App\Livewire\Products;

use App\Livewire\Component;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Show all» of the shop (WHMCS's «Εμφάνιση όλων», owner 2026-10-29): every category of the menu, one after the other,
 * with what its own page lists, the subcategories and the products, in the same cards. Hidden categories and products
 * stay out, as in the menu.
 */
class ShowAll extends Component
{
    public function render()
    {
        // Read here, not kept in a public property: Livewire would load the relations again unfiltered on a later request.
        $listed = fn ($query) => $query->whereHas('children')->orWhereHas('products', fn ($query) => $query->listed());

        return view('products.show-all', [
            'categories' => Category::query()->inMenu()->with([
                'products' => fn (HasMany $query) => $this->listedProducts($query),
                'children' => fn ($query) => $query->where($listed)->orderBy('sort'),
            ])->get()
                // Its page would be a 404: a category the menu keeps for an empty subcategory.
                ->filter(fn (Category $category) => $category->products->isNotEmpty() || $category->children->isNotEmpty()),
        ])->layoutData(['title' => __('product.all_title')]);
    }

    /**
     * A category's products as its page lists them, in the eager load.
     *
     * @param  HasMany<Product, Category>  $query
     */
    private function listedProducts(HasMany $query): void
    {
        $query->listed()->orderBy('sort')->with(['category', 'plans.prices', 'configOptions.children.plans.prices']);
    }
}
