<?php

namespace App\Livewire\Knowledgebase;

use App\Livewire\Component;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Http\Exceptions\HttpResponseException;

/** A category's articles, at /knowledgebase/{id}/{name} as WHMCS had it; another name redirects to its own (301). */
class Category extends Component
{
    public KbCategory $category;

    public function mount(KbCategory $category, string $slug = ''): void
    {
        $this->category = $category;
        if ($slug !== KbArticle::slugFor((string) $this->category->localized('name'))) {
            throw new HttpResponseException(redirect()->to($this->category->url(), 301));
        }
    }

    public function render()
    {
        return view('knowledgebase.category', [
            'articles' => $this->category->articles()->published()->orderBy('sort')->orderBy('title')->get(),
        ])->layoutData(['title' => $this->category->localized('name'), 'description' => (string) $this->category->localized('description')]);
    }
}
