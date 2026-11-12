<?php

namespace App\Services\Import;

use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * WHMCS's published announcements (`tblannouncements`) into the Announcements extension, with their dates, so the news page
 * keeps them (nixpal 4). Only the published ones, the parent language (a translation row has `parentid`); the HTML as written
 * (the extension sanitises on show). Without the extension (the cut-over's import runs on an empty database), it is
 * installed as the admin's «Install» does, and enabled (the admin's leaves it off): WHMCS showed them, so the news page
 * keeps them (rehearsal of 2026-11-06). One already there keeps its own on/off.
 */
final class WhmcsAnnouncementImport
{
    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $counts = ['announcements' => 0, 'skipped' => 0];
        try {
            $rows = $this->whmcs->query('SELECT id, date, title, announcement, published, parentid FROM tblannouncements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['counts' => $counts, 'notes' => []];
        }
        $notes = [];
        if ($rows !== [] && !self::extensionTable()) {
            $extension = Extension::query()->firstOrCreate(['type' => 'other', 'extension' => 'Announcements'], ['name' => 'Announcements', 'enabled' => true]);
            ExtensionHelper::call($extension, 'installed', mayFail: true);
            if (!self::extensionTable()) {
                return ['counts' => $counts, 'notes' => [count($rows) . ' announcement(s) not imported: the Announcements extension could not be installed (install it and run the import again before go-live, or after it write them by hand under Announcements).']];
            }
            $notes[] = $extension->enabled
                ? 'The Announcements extension was installed and enabled for WHMCS\'s announcements.'
                : 'The Announcements extension is installed but disabled here: enable it for WHMCS\'s announcements to show.';
        }
        foreach ($rows as $row) {
            $title = mb_substr(trim(html_entity_decode((string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 255);
            if ((int) $row['published'] !== 1 || (int) $row['parentid'] !== 0 || $title === '') {
                $counts['skipped']++;

                continue;
            }
            // Once: the same title on the same day is the one a first run brought. The extension's text column holds 64 KB.
            if (DB::table('ext_announcements')->where('title', $title)->where('published_at', (string) $row['date'])->exists() || strlen((string) $row['announcement']) > 60000) {
                $counts['skipped']++;

                continue;
            }
            $slug = Str::slug($title) ?: 'announcement-' . $row['id'];
            $slug = DB::table('ext_announcements')->where('slug', $slug)->exists() ? "{$slug}-whmcs-{$row['id']}" : $slug;
            if (DB::table('ext_announcements')->where('slug', $slug)->exists()) {
                $counts['skipped']++;

                continue;
            }
            DB::table('ext_announcements')->insert([
                'title' => $title, 'content' => (string) $row['announcement'], 'description' => Str::limit(trim(strip_tags((string) $row['announcement'])), 200),
                'published_at' => (string) $row['date'], 'is_active' => true, 'slug' => $slug, 'created_at' => (string) $row['date'], 'updated_at' => (string) $row['date'],
            ]);
            $counts['announcements']++;
        }

        return ['counts' => $counts, 'notes' => $notes];
    }

    /**
     * Whether the extension's table is there (asked again after installing it: the answer changes).
     *
     * @phpstan-impure
     */
    private static function extensionTable(): bool
    {
        return Schema::hasTable('ext_announcements');
    }
}
