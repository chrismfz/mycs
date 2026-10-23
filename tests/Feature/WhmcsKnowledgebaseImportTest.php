<?php

namespace Tests\Feature;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Import\WhmcsKnowledgebaseImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

/** WHMCS's knowledgebase (docs/knowledgebase.md): same ids, links, tags, translations, images here; re-runnable, parity. */
class WhmcsKnowledgebaseImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    /** A 1×1 PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['settings.app_language' => 'el', 'settings.allowed_languages' => ['el', 'en']]);
        $this->whmcs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->whmcs->exec('CREATE TABLE tblknowledgebase (id INTEGER PRIMARY KEY, title TEXT, article TEXT, views INT, useful INT, votes INT, private TEXT, "order" INT, parentid INT, language TEXT)');
        $this->whmcs->exec('CREATE TABLE tblknowledgebasecats (id INTEGER PRIMARY KEY, parentid INT, name TEXT, description TEXT, hidden TEXT, catid INT, language TEXT)');
        $this->whmcs->exec('CREATE TABLE tblknowledgebaselinks (id INTEGER PRIMARY KEY, categoryid INT, articleid INT)');
        $this->whmcs->exec('CREATE TABLE tblknowledgebasetags (id INTEGER PRIMARY KEY, articleid INT, tag TEXT)');
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('SystemURL', 'https://www.example.test/clients/')");
        $this->whmcs->exec("INSERT INTO tblknowledgebasecats VALUES (3, 0, 'Email', 'Email settings', '', 0, '')");
        $this->whmcs->exec("INSERT INTO tblknowledgebasecats VALUES (4, 0, 'Hosting', '', '', 0, '')");
        $this->whmcs->exec("INSERT INTO tblknowledgebasecats VALUES (9, 0, 'Email EN', 'Email settings (en)', '', 3, 'english')");
        $this->whmcs->exec("INSERT INTO tblknowledgebase VALUES (65, 'Υπάρχει τρόπος να δω ποια email έχουν μπλοκαριστεί;', "
            . "'<p>Δείτε:</p><img src=\"http://www.example.test/images/kbase/3mail.jpg\"><img src=\"images/kbase/rel.png\"><img src=\"https://evil.example.test/x.svg\">', 98137, 3, 4, '', 2, 0, '')");
        $this->whmcs->exec("INSERT INTO tblknowledgebase VALUES (66, 'Private one', '<p>Staff</p>', 5, 0, 0, 'on', 1, 0, '')");
        $this->whmcs->exec("INSERT INTO tblknowledgebase VALUES (70, 'Is there a way to see blocked email?', '<p>See:</p>', 0, 0, 0, '', 0, 65, 'english')");
        $this->whmcs->exec('INSERT INTO tblknowledgebaselinks (categoryid, articleid) VALUES (3, 65), (4, 65), (3, 66)');
        $this->whmcs->exec("INSERT INTO tblknowledgebasetags (articleid, tag) VALUES (65, 'Bad Users'), (65, 'Email'), (66, 'Staff')");
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '.jpg') || str_ends_with($request->url(), 'rel.png') => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
                default => Http::response('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 200, ['Content-Type' => 'image/svg+xml']),
            };
        });
    }

    private function import(): array
    {
        return (new WhmcsKnowledgebaseImport($this->whmcs))->run();
    }

    public function test_same_ids_links_tags_translations_and_parity(): void
    {
        $counts = $this->import();

        $this->assertSame([], $counts['errors']);
        $this->assertSame($counts['parity']['whmcs'], $counts['parity']['mycs']);
        $article = KbArticle::query()->findOrFail(65);
        $this->assertSame(['yparchei-tropos-na-do-poia-email-echoyn-mplokaristei', 98137, ['Bad Users', 'Email'], [3, 4], true],
            [$article->slug, $article->views, $article->tags, $article->categories()->orderBy('kb_categories.id')->pluck('kb_categories.id')->all(), $article->is_published]);
        $this->assertSame('Is there a way to see blocked email?', $article->translations['en']['title']);
        $this->assertSame('Email settings (en)', KbCategory::query()->findOrFail(3)->translations['en']['description']);
        $this->assertFalse(KbArticle::query()->findOrFail(66)->is_published, 'private: imported unpublished');
        $this->assertNull(KbArticle::query()->find(70), 'a translation is not an article');
        $this->assertTrue($article->hasTag('bad-users'));
    }

    public function test_images_brought_here_never_an_svg(): void
    {
        $counts = $this->import();

        $body = KbArticle::query()->findOrFail(65)->body;
        $this->assertSame([2, 1], [$counts['images'], $counts['images_failed']]);
        $this->assertStringNotContainsString('www.example.test/images/kbase/3mail.jpg', $body);
        $this->assertStringNotContainsString('src="images/kbase/rel.png"', $body, 'relative to WHMCS\'s address, brought too');
        $this->assertStringContainsString('https://evil.example.test/x.svg', $body, 'not an image we take: left as it was');
        $this->assertCount(1, Storage::disk('public')->allFiles('kb/whmcs'), 'the same bytes once');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.example.test/clients/images/kbase/rel.png');
    }

    public function test_run_again_keeps_what_staff_changed_or_deleted(): void
    {
        $this->import();
        $this->travel(5)->minutes();
        KbArticle::query()->findOrFail(65)->update(['title' => 'Ours now']);
        KbArticle::query()->findOrFail(66)->delete();
        $this->whmcs->exec('UPDATE tblknowledgebase SET views = 99000 WHERE id = 65');

        $counts = $this->import();

        $this->assertSame([1, 1], [$counts['kept_edited'], $counts['deleted_here']]);
        $this->assertSame(['Ours now', 99000], [KbArticle::query()->findOrFail(65)->title, KbArticle::query()->findOrFail(65)->views]);
        $this->assertNull(KbArticle::query()->find(66), 'deleted here stays deleted');
        $this->assertSame($counts['parity']['whmcs'], $counts['parity']['mycs']);
    }

    public function test_an_id_taken_here_is_not_overwritten_and_parity_says_so(): void
    {
        KbArticle::query()->forceCreate(['id' => 65, 'title' => 'Made here', 'slug' => 'made-here', 'body' => '<p>x</p>']);

        $counts = $this->import();

        $this->assertSame(1, $counts['id_taken']);
        $this->assertSame('Made here', KbArticle::query()->findOrFail(65)->title);
        $this->assertNotSame($counts['parity']['whmcs'], $counts['parity']['mycs']);
    }

    /** A screenshot pasted into WHMCS (a data: image) becomes a file; anything else in data: stays as it was. */
    public function test_a_pasted_image_becomes_a_file(): void
    {
        $this->whmcs->exec("UPDATE tblknowledgebase SET article = '<img src=\"data:image/png;base64," . base64_encode(self::PNG) . "\"><img src=\"data:image/svg+xml;base64,PHN2Zy8+\">' WHERE id = 66");

        $this->import();

        $body = KbArticle::query()->findOrFail(66)->body;
        $this->assertStringNotContainsString('data:image/png', $body);
        $this->assertStringContainsString('/storage/kb/whmcs/', $body);
        $this->assertStringContainsString('data:image/svg+xml', $body, 'not an image we take');
    }

    /** Code review 5, 7: data-src is not src; a row gone from WHMCS since is no parity difference. */
    public function test_data_src_is_not_src_and_parity_after_whmcs_deletes(): void
    {
        $this->whmcs->exec("UPDATE tblknowledgebase SET article = '<img data-src=\"https://www.example.test/a.jpg\" src=\"https://www.example.test/b.jpg\">' WHERE id = 65");
        $this->import();
        $this->assertStringContainsString('data-src="https://www.example.test/a.jpg"', KbArticle::query()->findOrFail(65)->body);
        $this->assertStringContainsString('src="/storage/kb/whmcs/', KbArticle::query()->findOrFail(65)->body, 'from the site\'s root');

        KbArticle::query()->findOrFail(66)->delete();
        $this->whmcs->exec('DELETE FROM tblknowledgebase WHERE id = 66');
        $counts = $this->import();

        $this->assertSame($counts['parity']['whmcs'], $counts['parity']['mycs']);
    }
}
