<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * WHMCS's published announcements (`tblannouncements`) into the Announcements extension, with their dates, so the news page
 * keeps them (nixpal 4). Only the published ones, the parent language (a translation row has `parentid`); the HTML as written
 * (the extension sanitises on show). The extension's table is there once the extension is installed: without it, said.
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
        if ($rows !== [] && !Schema::hasTable('ext_announcements')) {
            return ['counts' => $counts, 'notes' => [count($rows) . ' announcement(s) not imported: the Announcements extension is not installed here (install it and run the import again).']];
        }
        foreach ($rows as $row) {
            $title = mb_substr(trim(html_entity_decode((string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 255);
            if ((int) $row['published'] !== 1 || (int) $row['parentid'] !== 0 || $title === '') {
                $counts['skipped']++;

                continue;
            }
            $slug = Str::slug($title) ?: 'announcement-' . $row['id'];
            $slug = DB::table('ext_announcements')->where('slug', $slug)->exists() ? "{$slug}-{$row['id']}" : $slug;
            DB::table('ext_announcements')->insert([
                'title' => $title, 'content' => (string) $row['announcement'], 'description' => Str::limit(trim(strip_tags((string) $row['announcement'])), 200),
                'published_at' => (string) $row['date'], 'is_active' => true, 'slug' => $slug, 'created_at' => (string) $row['date'], 'updated_at' => (string) $row['date'],
            ]);
            $counts['announcements']++;
        }

        return ['counts' => $counts, 'notes' => []];
    }
}
