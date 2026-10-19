<?php

namespace App\Livewire\Products;

use App\Livewire\Component;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;

class Index extends Component
{
    public $products;

    public $categories;

    public $childCategories;

    public Category $category;

    public function mount()
    {
        $with = ['category', 'plans.prices', 'configOptions.children.plans.prices'];
        $listed = fn ($query) => $query->whereHas('children')->orWhereHas('products', fn ($query) => $query->listed());
        $this->products = $this->category->products()->listed()->with($with)->orderBy('sort')->get();
        $this->childCategories = $this->category->children()->where($listed)->orderBy('sort')->get();
        // The categories of the menu (docs/admin-ux.md, «Μενού επισκέπτη»; code review of #217).
        $this->categories = Category::query()->inMenu()->get();
        // A hidden category opened by its link, as WHMCS: nothing of it is listed, neither products nor subcategories, so
        // its products that can still be ordered. Not for a category shown through its subcategories, whose own hidden
        // products stay unlisted (ADV-2 of #216).
        if ($this->products->isEmpty() && $this->childCategories->isEmpty()) {
            $this->products = $this->category->products()->reachable()->with($with)->orderBy('sort')->get();
        }
        if (count($this->products) === 0 && count($this->childCategories) === 0) {
            abort(404);
        }
    }

    public function render()
    {
        return view('products.index')->layoutData([
            'title' => $this->category->name,
            'image' => $this->category->image ? Storage::url($this->category->image) : null,
        ]);
    }
}
