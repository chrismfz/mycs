<?php

namespace Tests\Feature;

use App\Admin\Resources\KbArticleResource\Pages\EditKbArticle;
use App\Models\Currency;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Import\WhmcsKnowledgebaseImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #365, round 2: the fixes of «fix(knowledgebase): the reviews of #365» (8e786561).
 */
class KnowledgebaseAdversarialRound2Test extends TestCase
{
    use RefreshDatabase;

    /** A 1×1 PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    private KbCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['app_language' => 'el', 'allowed_languages' => ['el', 'en'], 'default_currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        app()->setLocale('el');
        Http::preventStrayRequests();
        $this->category = KbCategory::query()->create(['name' => 'Email']);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'KB-adv2', 'permissions' => ['admin.knowledgebase.viewAny',
            'admin.knowledgebase.create', 'admin.knowledgebase.update']])->id]);
    }

    private function whmcs(string $article): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tblknowledgebase (id INTEGER PRIMARY KEY, title TEXT, article TEXT, views INT, useful INT, votes INT, private TEXT, "order" INT, parentid INT, language TEXT)');
        $pdo->exec('CREATE TABLE tblknowledgebasecats (id INTEGER PRIMARY KEY, parentid INT, name TEXT, description TEXT, hidden TEXT, catid INT, language TEXT)');
        $pdo->exec('CREATE TABLE tblknowledgebaselinks (id INTEGER PRIMARY KEY, categoryid INT, articleid INT)');
        $pdo->exec('CREATE TABLE tblknowledgebasetags (id INTEGER PRIMARY KEY, articleid INT, tag TEXT)');
        $pdo->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $pdo->exec("INSERT INTO tblconfiguration VALUES ('SystemURL', 'https://www.example.test/clients/')");
        $pdo->exec("INSERT INTO tblknowledgebasecats VALUES (3, 0, 'Email', '', '', 0, '')");
        $pdo->prepare("INSERT INTO tblknowledgebase VALUES (65, 'Pasted screenshot', ?, 1, 0, 0, '', 0, 0, '')")->execute([$article]);
        $pdo->exec('INSERT INTO tblknowledgebaselinks (categoryid, articleid) VALUES (3, 65)');

        return $pdo;
    }

    /**
     * R2-1 (high): a large pasted screenshot empties the whole article. withLocalImages() runs preg_replace_callback with a
     * lazy `(.*?)\2` over the src; on a data: URI of a few MB PCRE gives up («Backtrack limit exhausted», with pcre.jit=1
     * already from ~600 KB) and returns null, which `(string)` turns into '' — the article is imported with an empty body,
     * silently (no error, parity equal). The very case ADV-6's fix was for (screenshots pasted into WHMCS).
     */
    public function test_a_large_pasted_screenshot_does_not_empty_the_article(): void
    {
        Storage::fake('public');
        // A real PNG followed by padding: ~4.4 MB of bytes (under the 5 MB cap), ~6 MB of base64.
        $png = self::PNG . str_repeat("\0", (int) (4.4 * 1024 * 1024));
        $html = '<p>Before the screenshot.</p><img src="data:image/png;base64,' . base64_encode($png) . '"><p>After it.</p>';

        $counts = (new WhmcsKnowledgebaseImport($this->whmcs($html)))->run();

        $body = (string) KbArticle::query()->findOrFail(65)->body;
        $this->assertSame([], $counts['errors']);
        $this->assertStringContainsString('Before the screenshot.', $body, 'the article\'s text is kept');
        $this->assertStringContainsString('After it.', $body);
    }

    /**
     * R2-2 (medium): the translations still go through the RichEditor (CR-4 fixed only the main body). Saving an imported
     * article — even only its title, with «Edit as HTML» on — rewrites its imported English translation: WHMCS's classes,
     * styles and iframes are dropped.
     */
    public function test_saving_an_imported_article_keeps_its_translations_html(): void
    {
        $html = '<div class="alert alert-info"><p style="color:red">Note</p><iframe src="https://www.youtube.com/embed/x"></iframe></div>';
        $article = KbArticle::query()->create(['title' => 'Άρθρο', 'body' => '<p>Ελληνικά</p>',
            'translations' => ['en' => ['title' => 'Article', 'body' => $html]]]);
        $article->categories()->attach($this->category);
        KbArticle::query()->whereKey($article->id)->update(['whmcs_id' => $article->id]);

        $this->livewireAs($this->staff())->test(EditKbArticle::class, ['record' => $article->getRouteKey()])
            ->assertFormSet(['html_source' => true])
            ->fillForm(['title' => 'Μετονομασία'])->call('save')->assertHasNoFormErrors();

        $this->assertSame($html, $article->fresh()->translations['en']['body']);
    }

    /**
     * R2-3 (low-medium): switching «Edit as HTML» off mid-edit silently drops the HTML just written. The editor still holds
     * the body as it was loaded, the textarea is no longer dehydrated, so the save writes the old body.
     */
    public function test_html_edits_survive_switching_to_the_editor(): void
    {
        $article = KbArticle::query()->create(['title' => 'Άρθρο', 'body' => '<p>Old text</p>']);
        $article->categories()->attach($this->category);
        KbArticle::query()->whereKey($article->id)->update(['whmcs_id' => $article->id]);

        $this->livewireAs($this->staff())->test(EditKbArticle::class, ['record' => $article->getRouteKey()])
            ->assertFormSet(['html_source' => true])
            ->fillForm(['body_source' => '<p>New text written as HTML</p>'])
            ->fillForm(['html_source' => false])
            ->call('save')->assertHasNoFormErrors();

        $this->assertStringContainsString('New text written as HTML', (string) $article->fresh()->body);
    }

    /**
     * R2-4 (low): a tag longer than 64 characters links to a 404. Tag::mount() cuts the address to 64 characters, while
     * hasTag() compares the article's whole tag (TagsInput and the importer allow any length; the route takes 200).
     */
    public function test_a_long_tag_links_to_its_page(): void
    {
        $tag = 'Ρυθμίσεις λογαριασμού email σε Outlook, Thunderbird και κινητές συσκευές';
        $this->assertGreaterThan(64, mb_strlen($tag));
        $article = KbArticle::query()->create(['title' => 'Outlook', 'body' => '<p>x</p>', 'tags' => ['Short', $tag]]);
        $article->categories()->attach($this->category);

        $this->get(KbArticle::tagUrl('Short'))->assertOk()->assertSee('Outlook'); // the page itself works
        $this->get(KbArticle::tagUrl($tag))->assertOk()->assertSee('Outlook');
    }
}
