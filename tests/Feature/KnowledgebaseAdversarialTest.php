<?php

namespace Tests\Feature;

use App\Livewire\Knowledgebase\Article;
use App\Models\Currency;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of the knowledgebase (PR #365, docs/knowledgebase.md). One test per finding; each fails on the code
 * as reviewed (d3689698).
 */
class KnowledgebaseAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private KbCategory $category;

    private KbArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->storeSettings(['app_language' => 'el', 'allowed_languages' => ['el', 'en'], 'default_currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        app()->setLocale('el');
        $this->category = KbCategory::query()->create(['name' => 'Email', 'description' => 'Ρυθμίσεις email']);
        $this->article = KbArticle::query()->create(['title' => 'Public article', 'body' => '<p>Public text.</p>']);
        $this->article->categories()->attach($this->category);
    }

    /**
     * ADV-1 (medium): an unpublished article stays readable through a stale Livewire snapshot.
     *
     * Article::mount() checks is_published, but mount runs only on the first GET. Anyone who loaded the page while it was
     * published keeps its snapshot (signed, no expiry) and can POST it to /livewire/update with `$refresh`: render()
     * reloads the article from the database and shows its current title and body, even after staff unpublished it and
     * rewrote it (e.g. to remove something posted by mistake). Fix: check is_published on every request (render() or a
     * hydrate()/boot() hook), e.g. abort_unless($this->article->is_published, 404) in render().
     */
    public function test_an_unpublished_article_is_not_readable_through_an_old_snapshot(): void
    {
        $component = Livewire::test(Article::class, ['article' => $this->article->fresh(), 'slug' => $this->article->slug])->assertSee('Public text.');

        $this->article->update(['is_published' => false, 'title' => 'Draft title', 'body' => '<p>Draft secret 10.0.0.1</p>']);

        $component->call('$refresh')->assertDontSee('Draft secret')->assertDontSee('Draft title');
    }

    /**
     * ADV-2 (low): a tag with «+» (or a percent sequence) 404s from its own link.
     *
     * The router already decodes the path, and Tag::mount() urldecode()s it again: «C++» becomes «C  », «50%2B» becomes
     * «50+», and no article matches. The article page links to a 404, and so do WHMCS's tag addresses redirected from
     * /clients/knowledgebase/tag/… (Google traffic lands on the tags). Fix: drop the urldecode() in Tag::mount().
     */
    public function test_a_tag_with_a_plus_opens_from_its_own_link(): void
    {
        $this->article->update(['tags' => ['C++', '50%2B']]);

        $this->get(KbArticle::tagUrl('C++'))->assertOk()->assertSee('Public article');
        $this->get(KbArticle::tagUrl('50%2B'))->assertOk()->assertSee('Public article');
        $this->get('/clients/knowledgebase/tag/C%2B%2B')->assertStatus(301);
        $this->followingRedirects()->get('/clients/knowledgebase/tag/C%2B%2B')->assertOk()->assertSee('Public article');
    }

    /**
     * ADV-3 (low): a tag with «/» («TCP/IP», «SSL/TLS») gets a link that 404s.
     *
     * KbArticle::tagUrl() goes through route(), which leaves «/» unencoded: the link is /knowledgebase/tag/TCP/IP, and the
     * tag route only takes [^/]. Staff can type such a tag (TagsInput), and the import brings WHMCS's as they are. Fix:
     * map «/» in tagUrl()/tagKey() (as WHMCS maps spaces to dashes), or forbid it in the TagsInput and the import.
     */
    public function test_a_tag_with_a_slash_opens_from_its_own_link(): void
    {
        $this->article->update(['tags' => ['TCP/IP']]);

        $this->get($this->article->url())->assertOk();
        $this->get(KbArticle::tagUrl('TCP/IP'))->assertOk()->assertSee('Public article');
    }

    /**
     * ADV-4 (low): knowledgebase.php with an array in its query answers 500.
     *
     * KnowledgebaseRedirectController::script() casts query('action') and query('search') to string: ?action[]=x or
     * ?search[]=x is «Array to string conversion», an ErrorException, a 500 and an error in the log for anyone (a scanner
     * fills the log). Fix: $request->string('action') / is_string() checks, falling back to the index.
     */
    public function test_knowledgebase_php_with_an_array_redirects_instead_of_failing(): void
    {
        $this->get('/knowledgebase.php?action[]=displayarticle')->assertStatus(301);
        $this->get('/knowledgebase.php?action=search&search[]=x')->assertStatus(301);
    }

    /**
     * ADV-5 (low): /knowledgebase/{id} with an id beyond PHP's integer answers 500.
     *
     * The route takes any digits (whereNumber) and KnowledgebaseRedirectController::id(int $id) gets a string it cannot
     * coerce: TypeError, 500, logged. The article and category routes answer 404 for the same id. Fix: a bounded pattern
     * (e.g. [0-9]{1,18}) on the route, or take a string and cast.
     */
    public function test_an_id_too_large_redirects_instead_of_failing(): void
    {
        $this->get('/knowledgebase/99999999999999999999999')->assertStatus(301)->assertRedirect(route('kb.index'));
    }

    /**
     * ADV-6 (low): an article longer than 500,000 bytes is cut silently on its page.
     *
     * KbArticle::safeBody() uses Filament's sanitizer, configured with withMaxInputLength(500000): Symfony substr()s the
     * input to that length with no warning, so the end of a long article disappears, possibly mid-character. The import
     * leaves data: images as they are (WHMCS's TinyMCE pastes screenshots as base64), so one or two of them are enough.
     * Fix: a sanitizer of our own for the knowledgebase with a larger (or no) cap, or a max length on save and on import
     * that is reported.
     */
    public function test_a_long_article_is_shown_whole(): void
    {
        $image = '<img src="data:image/png;base64,' . str_repeat('A', 520000) . '">';
        $this->article->update(['body' => '<p>Start.</p>' . $image . '<p>THE-END-MARKER</p>']);

        $this->get($this->article->url())->assertOk()->assertSee('<p>THE-END-MARKER</p>', false);
    }
}
