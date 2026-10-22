<?php

namespace App\Livewire\Knowledgebase;

use App\Livewire\Component;
use App\Models\KbArticle;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An article, at /knowledgebase/{id}/{slug}.html as WHMCS had it (its links in Google keep working); another slug
 * redirects to its own (301). Counted once a visit (session), as WHMCS's «views».
 */
class Article extends Component
{
    public KbArticle $article;

    public function mount(KbArticle $article, string $slug = ''): void
    {
        abort_unless($article->is_published, 404);
        $this->article = $article;
        if ($slug !== $this->article->slug) {
            throw new HttpResponseException(redirect()->to($this->article->url(), 301));
        }
        $seen = (array) session('kb_seen', []);
        if (!in_array($this->article->id, $seen, true)) {
            KbArticle::query()->whereKey($this->article->id)->increment('views', 1, ['updated_at' => $this->article->updated_at]);
            session(['kb_seen' => array_slice([...$seen, $this->article->id], -200)]);
        }
    }

    public function render()
    {
        return view('knowledgebase.article', [
            'categories' => $this->article->categories()->orderBy('sort')->get(),
        ])->layoutData(['title' => $this->article->localized('title'), 'description' => $this->article->excerpt()]);
    }
}
