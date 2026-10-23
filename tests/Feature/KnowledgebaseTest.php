<?php

namespace Tests\Feature;

use App\Admin\Resources\KbArticleResource\Pages\CreateKbArticle;
use App\Admin\Resources\KbArticleResource\Pages\EditKbArticle;
use App\Admin\Resources\KbArticleResource\Pages\ListKbArticles;
use App\Livewire\Knowledgebase\Index;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\Currency;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\Knowledgebase\KbSearch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The knowledgebase (owner 2026-10-23, docs/knowledgebase.md): public, at WHMCS's own addresses, WHMCS's others redirected
 * (301); staff's HTML shown sanitized; search without accents or case; translations optional; suggestions while a ticket
 * is written; kept by staff with its own permission.
 */
class KnowledgebaseTest extends TestCase
{
    use RefreshDatabase;

    private KbCategory $category;

    private KbArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.app_language' => 'el', 'settings.allowed_languages' => ['el', 'en'], 'settings.default_currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        app()->setLocale('el');
        $this->category = KbCategory::query()->create(['name' => 'Email', 'description' => 'Ρυθμίσεις email']);
        $this->article = KbArticle::query()->create(['title' => 'Επαναφορά αρχείων - Jetbackup', 'body' => '<p>Από το <b>cPanel</b>.</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
            'tags' => ['JetBackup', 'Restore files']]);
        $this->article->categories()->attach($this->category);
    }

    public function test_an_article_at_whmcs_address_sanitized_and_counted_once_a_visit(): void
    {
        $this->assertSame('epanafora-archeion-jetbackup', $this->article->slug);
        $url = "/knowledgebase/{$this->article->id}/epanafora-archeion-jetbackup.html";
        $this->assertSame(url($url), $this->article->url());

        $this->get($url)->assertOk()->assertSee('Επαναφορά αρχείων - Jetbackup')->assertSee('<b>cPanel</b>', false)
            ->assertDontSee('<script>alert(1)', false)->assertDontSee('onerror', false);
        $this->get($url)->assertOk();
        $this->assertSame(1, $this->article->fresh()->views, 'once a visit');

        // WHMCS's own slug (another case, its dashes): the same article, redirected to its address for good.
        $this->get("/knowledgebase/{$this->article->id}/epanafora-archeion---Jetbackup.html")->assertStatus(301)->assertRedirect($url);
        $this->article->update(['is_published' => false]);
        $this->get($url)->assertNotFound();
    }

    public function test_category_and_tag_pages(): void
    {
        $this->get("/knowledgebase/{$this->category->id}/email")->assertOk()->assertSee($this->article->title);
        $this->get("/knowledgebase/{$this->category->id}/old-name")->assertStatus(301)->assertRedirect($this->category->url());
        $this->get('/knowledgebase/tag/restore-Files')->assertOk()->assertSee($this->article->title);
        $this->get('/knowledgebase/tag/nothing-here')->assertNotFound();
        $this->get('/knowledgebase')->assertOk()->assertSee('Email');
    }

    public function test_whmcs_other_addresses_redirect_for_good(): void
    {
        $this->get("/clients/knowledgebase/{$this->article->id}/anything.html")->assertStatus(301)->assertRedirect($this->article->url());
        $this->get("/clients/knowledgebase/{$this->category->id}/Email")->assertStatus(301)->assertRedirect($this->category->url());
        $this->get('/clients/knowledgebase/tag/Restore-files')->assertStatus(301)->assertRedirect(route('kb.tag', ['tag' => 'Restore-files']));
        $this->get("/clients/knowledgebase.php?action=displayarticle&id={$this->article->id}")->assertStatus(301)->assertRedirect($this->article->url());
        $this->get("/knowledgebase.php?action=displaycat&catid={$this->category->id}")->assertStatus(301)->assertRedirect($this->category->url());
        $this->get("/knowledgebase/{$this->article->id}")->assertStatus(301)->assertRedirect($this->article->url());
        $this->get('/clients/knowledgebase/999/gone.html')->assertStatus(301)->assertRedirect(route('kb.index'));
        $this->get('/clients/knowledgebase')->assertStatus(301)->assertRedirect(route('kb.index'));
    }

    public function test_search_without_accents_or_case(): void
    {
        Livewire::test(Index::class)->set('search', 'ΕΠΑΝΑΦΟΡΑ')->assertSee($this->article->title);
        Livewire::test(Index::class)->set('search', 'jetbackup')->assertSee($this->article->title);
        Livewire::test(Index::class)->set('search', 'ανύπαρκτο θέμα')->assertSee(__('knowledgebase.no_results'));
    }

    public function test_a_translation_when_there_is_one_else_the_instances_text(): void
    {
        app()->setLocale('en');
        $this->assertSame($this->article->title, $this->article->localized('title'), 'none yet: the Greek one');
        $this->article->update(['translations' => ['en' => ['title' => 'Restore files - Jetbackup', 'body' => '<p>From cPanel.</p>']]]);
        $this->get($this->article->url())->assertOk(); // the session's locale may differ; the model decides:
        $this->assertSame('Restore files - Jetbackup', $this->article->fresh()->localized('title'));
        app()->setLocale('el');
        $this->assertSame('Επαναφορά αρχείων - Jetbackup', $this->article->fresh()->localized('title'));
    }

    public function test_a_ticket_being_written_is_offered_articles(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->livewireAs($user)->test(TicketCreate::class)->set('subject', 'Θέλω επαναφορά αρχείων')
            ->assertSee(__('knowledgebase.suggestions'))->assertSee($this->article->title);
    }

    public function test_staff_keep_it_with_its_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $without = Staff::factory()->create(['role_id' => Role::create(['name' => 'None', 'permissions' => []])->id]);
        $this->actingAs($without, 'staff')->get(route('filament.admin.resources.kb-articles.index'))->assertForbidden();

        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'KB', 'permissions' => ['admin.knowledgebase.viewAny', 'admin.knowledgebase.create']])->id]);
        $this->livewireAs($staff)->test(ListKbArticles::class)->assertOk()->assertSee($this->article->title);
        $this->livewireAs($staff)->test(CreateKbArticle::class)
            ->fillForm(['title' => 'Πώς αλλάζω κωδικό', 'categories' => [$this->category->id], 'body' => '<p>Από το portal.</p>', 'tags' => ['Password']])
            ->call('create')->assertHasNoFormErrors();
        $new = KbArticle::query()->where('title', 'Πώς αλλάζω κωδικό')->sole();
        $this->assertSame(['pos-allazo-kodiko', [$this->category->id]], [$new->slug, $new->categories()->pluck('kb_categories.id')->all()]);
        $this->assertStringStartsWith('<p>', $new->body, 'saved as HTML, not the editor\'s JSON');
    }

    /** Code review 1: Greek capitals and accents are the same letters («Η» and «η», «Υ» and «ύ»). */
    public function test_search_with_greek_capitals_and_accents(): void
    {
        $article = KbArticle::query()->create(['title' => 'Υπηρεσίες Ηλεκτρονικού ταχυδρομείου', 'body' => '<p>ΨΗΦΙΑΚΌ ΕΥΡΥΖΩΝΙΚΗΣ</p>']);

        foreach (['υπηρεσιες', 'ηλεκτρονικου', 'ψηφιακο', 'ευρυζωνικής'] as $words) {
            $this->assertTrue(KbSearch::find($words)->contains('id', $article->id), $words);
        }
    }

    /** Code review 4: an imported article is edited as its HTML, so a save of its title keeps WHMCS's markup. */
    public function test_an_imported_article_keeps_its_html_when_saved(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $html = '<div class="alert alert-info"><p style="color:red">Note</p><iframe src="https://www.youtube.com/embed/x"></iframe></div>';
        KbArticle::query()->whereKey($this->article->id)->update(['body' => $html, 'whmcs_id' => $this->article->id]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'KB2', 'permissions' => ['admin.knowledgebase.viewAny', 'admin.knowledgebase.update']])->id]);

        $this->livewireAs($staff)->test(EditKbArticle::class, ['record' => $this->article->getRouteKey()])
            ->assertFormSet(['html_source' => true])
            ->fillForm(['title' => 'Renamed'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['Renamed', $html], [$this->article->fresh()->title, $this->article->fresh()->body]);
    }

    /** Code review: a translation is what the page shows in that language. */
    public function test_the_page_shows_the_translation(): void
    {
        $this->article->update(['translations' => ['en' => ['title' => 'Restore files - Jetbackup', 'body' => '<p>From cPanel, in English.</p>']]]);

        $this->withSession(['locale' => 'en'])->get($this->article->url())->assertOk()->assertSee('in English')->assertSee('Restore files - Jetbackup');
    }

    /** Adversarial R2-3, the other way: from the editor to HTML, the text goes along and what is written there is saved. */
    public function test_switching_to_html_takes_the_editors_text(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'KB3', 'permissions' => ['admin.knowledgebase.viewAny', 'admin.knowledgebase.update']])->id]);
        KbArticle::query()->whereKey($this->article->id)->update(['body' => '<p>Written in the editor</p>']);

        $this->livewireAs($staff)->test(EditKbArticle::class, ['record' => $this->article->getRouteKey()])
            ->assertFormSet(['html_source' => false])
            ->fillForm(['html_source' => true])
            ->assertFormSet(fn (array $state) => str_contains((string) $state['body_source'], 'Written in the editor') ? [] : ['body_source' => 'Written in the editor'])
            ->fillForm(['body_source' => '<div class="note">Now as HTML</div>'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('<div class="note">Now as HTML</div>', $this->article->fresh()->body);
    }
}
