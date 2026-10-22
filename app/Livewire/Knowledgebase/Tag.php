<?php

namespace App\Livewire\Knowledgebase;

use App\Livewire\Component;
use App\Models\KbArticle;

/** The articles of a tag, at /knowledgebase/tag/{tag} as WHMCS had it (spaces as dashes, any case). */
class Tag extends Component
{
    public string $tag;

    public function mount(string $tag): void
    {
        $this->tag = mb_substr(urldecode($tag), 0, 64);
    }

    public function render()
    {
        $articles = KbArticle::query()->published()->whereNotNull('tags')->orderBy('sort')->orderBy('title')->get()
            ->filter(fn (KbArticle $article) => $article->hasTag($this->tag))->values();
        abort_if($articles->isEmpty(), 404);
        $name = collect($articles->first()->tags)->first(fn ($own) => KbArticle::tagKey((string) $own) === KbArticle::tagKey($this->tag)) ?? $this->tag;

        return view('knowledgebase.tag', ['articles' => $articles, 'name' => $name])
            ->layoutData(['title' => __('knowledgebase.tag_title', ['tag' => $name])]);
    }
}
