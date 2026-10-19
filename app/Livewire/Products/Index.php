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
        $this->products = $this->category->products()->where('hidden', false)->with(['category', 'plans.prices', 'configOptions.children.plans.prices'])->orderBy('sort')->get();
        $this->childCategories = $this->category->children()->where(function ($query) {
            $query->whereHas('children')->orWhereHas('products', function ($query) {
                $query->where('hidden', false);
            });
        })->orderBy('sort')->get();
        // The categories of the menu (docs/admin-ux.md, «Μενού επισκέπτη»; code review of #217).
        $this->categories = Category::query()->inMenu()->get();
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
