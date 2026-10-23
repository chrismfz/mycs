<?php

namespace App\Support\Knowledgebase;

use App\Models\KbArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Search of the published articles (docs/knowledgebase.md), for the knowledgebase's page and the suggestions while a ticket
 * is written. Words of 3 letters or more, compared without accents or case (Greek too: «Email», «εμαιλ», «ΕΠΑΝΑΦΟΡΆ»), in
 * the title (3), the tags (2) and the text (1), every language of the article. The knowledgebase is small (myip: 97): the
 * articles' words are read once and cached until one changes.
 */
final class KbSearch
{
    private const MAX_WORDS = 8;

    /** @return Collection<int, KbArticle> the best first, at most $limit */
    public static function find(string $query, int $limit = 20): Collection
    {
        $words = self::words($query);
        if ($words === []) {
            return collect();
        }
        $scores = [];
        foreach (self::corpus() as $id => [$title, $tags, $body]) {
            $score = 0;
            foreach ($words as $word) {
                $score += (str_contains($title, $word) ? 3 : 0) + (str_contains($tags, $word) ? 2 : 0) + (str_contains($body, $word) ? 1 : 0);
            }
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $limit);
        $articles = KbArticle::query()->published()->whereKey($ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $articles->get($id))->filter()->values();
    }

    /** @return list<string> */
    public static function words(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', self::normalize(mb_substr($query, 0, 200))) ?: [];

        return array_slice(array_values(array_unique(array_filter($words, fn ($word) => mb_strlen($word) >= 3))), 0, self::MAX_WORDS);
    }

    private static function normalize(string $text): string
    {
        return KbArticle::plain(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> article id => its title, tags and text, normalized */
    private static function corpus(): array
    {
        $version = KbArticle::query()->published()->count() . ':' . KbArticle::query()->max('updated_at');

        return Cache::remember('kb-search:' . md5($version), 600, function () {
            $corpus = [];
            foreach (KbArticle::query()->published()->get(['id', 'title', 'body', 'tags', 'translations']) as $article) {
                $translations = collect($article->translations ?? []);
                $corpus[(int) $article->id] = [
                    self::normalize($article->title . ' ' . $translations->pluck('title')->implode(' ')),
                    self::normalize(implode(' ', $article->tags ?? [])),
                    self::normalize($article->body . ' ' . $translations->pluck('body')->implode(' ')),
                ];
            }

            return $corpus;
        });
    }
}
