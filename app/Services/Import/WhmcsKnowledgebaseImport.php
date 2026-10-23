<?php

namespace App\Services\Import;

use App\Models\KbArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * WHMCS's knowledgebase (docs/knowledgebase.md): categories, articles, their links and tags, with the same ids (its
 * addresses, /knowledgebase/{id}/…, keep working). Read-only on WHMCS. Re-runnable:
 *  - an article or category edited here since it was imported keeps our version; one deleted here stays deleted
 *    (kb_whmcs_tombstones); an id taken by one made here is counted and not imported (parity fails);
 *  - views: WHMCS's, never fewer than ours.
 * Languages: WHMCS's rows without a language are the instance's; a translation (an article with `parentid`, a category with
 * `catid`, in «greek», «english», …) goes into its original's `translations`. A private article is imported unpublished;
 * hidden and sub categories are counted (none at myip or nixpal). tbldynamic_translations of the knowledgebase is counted,
 * not imported (none at either).
 * Images: <img src> of http(s) (or relative to WHMCS's address) downloaded to the public disk and pointed to there, so an
 * article no longer depends on the old site; only real images (jpeg, png, gif, webp: never svg), 5 MB at most; one that
 * fails is counted and keeps its address.
 */
final class WhmcsKnowledgebaseImport
{
    /** WHMCS's language names => ours. */
    public const LOCALES = ['english' => 'en', 'greek' => 'el', 'german' => 'de', 'french' => 'fr', 'italian' => 'it', 'spanish' => 'es',
        'dutch' => 'nl', 'portuguese-pt' => 'pt', 'portuguese-br' => 'pt', 'russian' => 'ru', 'turkish' => 'tr', 'arabic' => 'ar', 'danish' => 'da'];

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, mixed> */
    private array $counts = ['categories' => 0, 'articles' => 0, 'kept_edited' => 0, 'deleted_here' => 0, 'id_taken' => 0, 'unpublished' => 0,
        'translations' => 0, 'translations_unknown' => 0, 'links' => 0, 'tags' => 0, 'images' => 0, 'images_failed' => 0,
        'hidden_categories' => 0, 'subcategories' => 0, 'dynamic_translations' => 0, 'errors' => []];

    /** @var array<string, string|null> an image's address => ours (null: failed), once a run */
    private array $images = [];

    public function __construct(private readonly PDO $pdo, private readonly ?string $baseUrl = null, private readonly bool $dryRun = false, private readonly bool $withImages = true) {}

    /** @return array<string, mixed> the counts, and parity: WHMCS's and ours */
    public function run(): array
    {
        $base = $this->baseUrl ?? $this->systemUrl();
        DB::beginTransaction();
        try {
            $categories = $this->categories();
            $this->articles($categories, $base);
            $parity = $this->parity();
            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->counts['errors'][] = $e->getMessage();
            $parity = ['whmcs' => [], 'mycs' => ['failed' => 1]];
        }
        $this->counts['dynamic_translations'] = $this->dynamicTranslations();

        return $this->counts + ['parity' => $parity];
    }

    /** @return list<int> the WHMCS categories that are ours now */
    private function categories(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tblknowledgebasecats ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $ours = [];
        foreach ($rows as $row) {
            if ((int) ($row['catid'] ?? 0) > 0) {
                continue; // a translation, below
            }
            $id = (int) $row['id'];
            $this->counts['hidden_categories'] += ($row['hidden'] ?? '') === 'on' ? 1 : 0;
            $this->counts['subcategories'] += (int) ($row['parentid'] ?? 0) > 0 ? 1 : 0;
            if ($this->tombstoned('category:' . $id)) {
                $this->counts['deleted_here']++;

                continue;
            }
            $existing = DB::table('kb_categories')->where('id', $id)->first();
            if ($existing !== null && $existing->whmcs_id === null) {
                $this->counts['id_taken']++;

                continue;
            }
            $ours[] = $id;
            if (self::editedHere($existing)) {
                $this->counts['kept_edited']++;

                continue;
            }
            $now = now();
            $values = ['name' => Str::limit(trim((string) $row['name']), 250, ''), 'description' => trim((string) $row['description']) ?: null,
                'imported_at' => $now, 'updated_at' => $now];
            if ($existing === null) {
                DB::table('kb_categories')->insert($values + ['id' => $id, 'whmcs_id' => $id, 'sort' => 0, 'created_at' => $now]);
            } else {
                DB::table('kb_categories')->where('id', $id)->update($values);
            }
            $this->counts['categories']++;
        }
        foreach ($rows as $row) {
            if ((int) ($row['catid'] ?? 0) > 0 && in_array((int) $row['catid'], $ours, true)) {
                $this->translate('kb_categories', (int) $row['catid'], (string) $row['language'], ['name' => trim((string) $row['name']), 'description' => trim((string) $row['description'])]);
            }
        }

        return $ours;
    }

    /** @param list<int> $categories */
    private function articles(array $categories, ?string $base): void
    {
        $rows = $this->pdo->query('SELECT * FROM tblknowledgebase ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $tags = [];
        foreach ($this->pdo->query('SELECT articleid, tag FROM tblknowledgebasetags ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $tag) {
            $tags[(int) $tag['articleid']][] = trim((string) $tag['tag']);
        }
        $links = [];
        foreach ($this->pdo->query('SELECT articleid, categoryid FROM tblknowledgebaselinks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $links[(int) $link['articleid']][] = (int) $link['categoryid'];
        }
        $ours = [];
        foreach ($rows as $row) {
            if ((int) $row['parentid'] > 0) {
                continue; // a translation, below
            }
            $id = (int) $row['id'];
            if ($this->tombstoned('article:' . $id)) {
                $this->counts['deleted_here']++;

                continue;
            }
            $existing = DB::table('kb_articles')->where('id', $id)->first();
            if ($existing !== null && $existing->whmcs_id === null) {
                $this->counts['id_taken']++;

                continue;
            }
            $ours[] = $id;
            if (self::editedHere($existing)) {
                // Edited here since: ours is kept, only WHMCS's views counted.
                DB::table('kb_articles')->where('id', $id)->update(['views' => max((int) $existing->views, (int) $row['views'])]);
                $this->counts['kept_edited']++;

                continue;
            }
            $title = Str::limit(trim((string) $row['title']), 250, '');
            $articleTags = array_values(array_unique(array_filter($tags[$id] ?? [], fn ($tag) => $tag !== '')));
            $published = ($row['private'] ?? '') !== 'on';
            $this->counts['unpublished'] += $published ? 0 : 1;
            $now = now();
            $values = ['title' => $title, 'slug' => KbArticle::slugFor($title), 'body' => $this->withLocalImages((string) $row['article'], $base),
                'tags' => $articleTags === [] ? null : json_encode($articleTags, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'sort' => max(0, min(65535, (int) $row['order'])),
                'is_published' => $published, 'imported_at' => $now, 'updated_at' => $now];
            if ($existing === null) {
                DB::table('kb_articles')->insert($values + ['id' => $id, 'whmcs_id' => $id, 'views' => max(0, (int) $row['views']), 'created_at' => $now]);
            } else {
                DB::table('kb_articles')->where('id', $id)->update($values + ['views' => max((int) $existing->views, (int) $row['views'])]);
            }
            $this->counts['articles']++;
            $this->counts['tags'] += count($articleTags);
            $theirs = array_values(array_unique(array_intersect($links[$id] ?? [], $categories)));
            DB::table('kb_article_category')->where('article_id', $id)->delete();
            DB::table('kb_article_category')->insert(array_map(fn (int $category) => ['article_id' => $id, 'category_id' => $category], $theirs));
            $this->counts['links'] += count($theirs);
        }
        foreach ($rows as $row) {
            if ((int) $row['parentid'] > 0 && in_array((int) $row['parentid'], $ours, true)) {
                $this->translate('kb_articles', (int) $row['parentid'], (string) $row['language'],
                    ['title' => trim((string) $row['title']), 'body' => $this->withLocalImages((string) $row['article'], $base)]);
            }
        }
    }

    /** @param array<string, string> $fields */
    private function translate(string $table, int $id, string $language, array $fields): void
    {
        $locale = self::LOCALES[strtolower(trim($language))] ?? null;
        if ($locale === null) {
            $this->counts['translations_unknown']++;

            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null || self::editedHere($row)) {
            return; // edited here: its translations are ours too
        }
        $translations = json_decode((string) ($row->translations ?? ''), true) ?: [];
        $translations[$locale] = $fields;
        DB::table($table)->where('id', $id)->update(['translations' => json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $this->counts['translations']++;
    }

    /** The article's images, downloaded and pointed to here. */
    private function withLocalImages(string $html, ?string $base): string
    {
        if (!$this->withImages) {
            return $html;
        }

        return (string) preg_replace_callback('/(<img\b[^>]*?(?<![\w-])src\s*=\s*)(["\'])(.*?)\2/is', function (array $m) use ($base) {
            $src = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            if (preg_match('#^data:image/[a-z+.-]+;base64,#i', $src)) {
                // A screenshot pasted into WHMCS: kept as a file, not as megabytes of text in the article (ADV-6).
                $local = $this->store((string) base64_decode(substr($src, strpos($src, ',') + 1), true));
            } else {
                $url = $this->absolute($src, $base);
                $local = $url === null ? null : $this->download($url);
            }

            return $local === null ? $m[0] : $m[1] . $m[2] . e($local) . $m[2];
        }, $html);
    }

    private function absolute(string $src, ?string $base): ?string
    {
        $src = trim($src);
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }
        if ($src === '' || $base === null || preg_match('#^[a-z][a-z0-9+.-]*:#i', $src)) {
            return null; // data:, javascript:, … or nowhere to resolve it against
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return str_starts_with($src, '/') ? $root . $src : rtrim($base, '/') . '/' . $src;
    }

    private function download(string $url): ?string
    {
        if (array_key_exists($url, $this->images)) {
            return $this->images[$url];
        }
        if ($this->dryRun) {
            $this->counts['images']++;

            return $this->images[$url] = null;
        }
        try {
            $response = Http::timeout(20)->withOptions([
                'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                // A size told too large: not read at all.
                'on_headers' => function ($headers) {
                    if ((int) $headers->getHeaderLine('Content-Length') > self::IMAGE_MAX_BYTES) {
                        throw new \RuntimeException('too large');
                    }
                },
            ])->get($url);

            return $this->images[$url] = $this->store($response->successful() ? $response->body() : '');
        } catch (Throwable) {
            $this->counts['images_failed']++;

            return $this->images[$url] = null;
        }
    }

    /** An image's bytes kept on the public disk, once by their content; null (counted) when they are no image of ours. */
    private function store(string $body): ?string
    {
        $type = $body === '' ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (strlen($body) > self::IMAGE_MAX_BYTES || !isset(self::IMAGE_TYPES[$type])) {
            $this->counts['images_failed']++;

            return null;
        }
        if ($this->dryRun) {
            $this->counts['images']++;

            return null;
        }
        $path = 'kb/whmcs/' . sha1($body) . '.' . self::IMAGE_TYPES[$type];
        Storage::disk('public')->put($path, $body);
        $this->counts['images']++;

        // From the site's root, not APP_URL's host: an import run before the cut-over (code review 6).
        return '/storage/' . $path;
    }

    /** Changed here since it was imported (its updated_at later than its import's): ours is kept. */
    private static function editedHere(?object $row): bool
    {
        return $row !== null && $row->imported_at !== null && (string) $row->updated_at > (string) $row->imported_at;
    }

    private function tombstoned(string $ref): bool
    {
        return DB::table('kb_whmcs_tombstones')->where('ref', $ref)->exists();
    }

    private function systemUrl(): ?string
    {
        try {
            $url = $this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL'")->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function dynamicTranslations(): int
    {
        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM tbldynamic_translations WHERE related_type LIKE 'knowledgebase%'")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{whmcs: array<string, int>, mycs: array<string, int>} */
    private function parity(): array
    {
        // WHMCS's rows, against ours of them (imported, or deleted here): one gone from WHMCS since is no difference
        // (code review 7).
        $ids = fn (string $sql) => array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        $articles = $ids('SELECT id FROM tblknowledgebase WHERE parentid = 0');
        $categories = $ids('SELECT id FROM tblknowledgebasecats WHERE catid = 0');
        $ours = fn (string $table, string $kind, array $theirs) => count(array_intersect($theirs, array_merge(
            DB::table($table)->whereNotNull('whmcs_id')->pluck('whmcs_id')->map(fn ($id) => (int) $id)->all(),
            DB::table('kb_whmcs_tombstones')->where('ref', 'like', $kind . ':%')->pluck('ref')->map(fn ($ref) => (int) substr($ref, strlen($kind) + 1))->all(),
        )));

        return [
            'whmcs' => ['articles' => count($articles), 'categories' => count($categories)],
            'mycs' => ['articles' => $ours('kb_articles', 'article', $articles), 'categories' => $ours('kb_categories', 'category', $categories)],
        ];
    }
}
