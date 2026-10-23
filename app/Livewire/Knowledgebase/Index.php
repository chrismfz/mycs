<?php

namespace App\Livewire\Knowledgebase;

use App\Livewire\Component;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Support\Knowledgebase\KbSearch;
use Livewire\Attributes\Url;

/** The knowledgebase's front page (docs/knowledgebase.md): its categories, the most read articles, and search. */
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function render()
    {
        $search = trim(mb_substr($this->search, 0, 200));

        return view('knowledgebase.index', [
            'search' => $search,
            'results' => $search === '' ? collect() : KbSearch::find($search),
            'categories' => KbCategory::query()->withCount(['articles' => fn ($query) => $query->published()])
                ->orderBy('sort')->orderBy('name')->get()->filter(fn (KbCategory $category) => $category->articles_count > 0),
            'popular' => KbArticle::query()->published()->orderByDesc('views')->limit(10)->get(),
        ])->layoutData(['title' => __('knowledgebase.title'), 'description' => __('knowledgebase.description')]);
    }
}
