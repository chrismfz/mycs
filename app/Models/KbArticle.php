<?php

namespace App\Models;

use App\Support\Knowledgebase\Localized;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An article of the knowledgebase (docs/knowledgebase.md): HTML written by staff (RichEditor), shown sanitized, never
 * compiled. In the instance's language, translations optional; in one or more categories; its tags a list.
 */
class KbArticle extends Model
{
    use Localized;

    protected $fillable = ['title', 'slug', 'body', 'tags', 'sort', 'is_published', 'translations'];

    protected $casts = ['tags' => 'array', 'translations' => 'array', 'is_published' => 'boolean', 'imported_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (KbArticle $article) {
            if (blank($article->slug)) {
                $article->slug = self::slugFor((string) $article->title);
            }
            $article->tags = array_values(array_unique(array_filter(array_map(fn ($tag) => trim((string) $tag), $article->tags ?? []))));
        });
        static::deleted(function (KbArticle $article) {
            if ($article->whmcs_id !== null) {
                DB::table('kb_whmcs_tombstones')->insertOrIgnore(['ref' => 'article:' . $article->whmcs_id, 'created_at' => now()]);
            }
        });
    }

    /** WHMCS's Greek letters, one by one (its addresses in Google: «Επαναφορά αρχείων» → «epanafora-archeion»). */
    private const GREEK = ['α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i',
        'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't',
        'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o'];

    /** An address from a title, as WHMCS made it, in lower case: Greek letter by letter, the rest as Str::slug. */
    public static function slugFor(string $title): string
    {
        $plain = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize(mb_strtolower($title), \Normalizer::FORM_D));

        return Str::slug(strtr((string) $plain, self::GREEK)) ?: 'article';
    }

    /** @return BelongsToMany<KbCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(KbCategory::class, 'kb_article_category', 'article_id', 'category_id');
    }

    /** @param  Builder<KbArticle>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** Its address as WHMCS made it: /knowledgebase/{id}/{slug}.html. */
    public function url(): string
    {
        return route('kb.article', ['article' => $this->id, 'slug' => $this->slug]);
    }

    /** The body for the page: staff's HTML, sanitized (as a product's description), never compiled as Blade. */
    public function safeBody(): string
    {
        return Str::sanitizeHtml((string) $this->localized('body'));
    }

    /** A plain summary (search results, the page's description). */
    public function excerpt(int $length = 160): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $this->localized('body')), ENT_QUOTES | ENT_HTML5))), $length);
    }

    /** Whether it carries this tag, as its address writes it (WHMCS: spaces as dashes). */
    public function hasTag(string $tag): bool
    {
        $wanted = self::tagKey($tag);

        return collect($this->tags ?? [])->contains(fn ($own) => self::tagKey((string) $own) === $wanted);
    }

    public static function tagKey(string $tag): string
    {
        return mb_strtolower(str_replace([' ', '_'], '-', trim($tag)));
    }

    public static function tagUrl(string $tag): string
    {
        return route('kb.tag', ['tag' => str_replace(' ', '-', trim($tag))]);
    }
}
