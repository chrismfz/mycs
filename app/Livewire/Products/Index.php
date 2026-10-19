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
        $this->products = $this->category->products()->listed()->with($with)->orderBy('sort')->get();
        // A hidden category (none of its products listed) opened by its link, as WHMCS: its products that can still be
        // ordered, not the retired ones.
        if ($this->products->isEmpty()) {
            $this->products = $this->category->products()->reachable()->with($with)->orderBy('sort')->get();
        }
        $this->childCategories = $this->category->children()->where(function ($query) {
            $query->whereHas('children')->orWhereHas('products', function ($query) {
                $query->where('hidden', false);
            });
        })->orderBy('sort')->get();
        $this->categories = Category::whereNull('parent_id')->where(function ($query) {
            $query->whereHas('children')->orWhereHas('products', function ($query) {
                $query->where('hidden', false);
            });
        })->orderBy('sort')->get();
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
