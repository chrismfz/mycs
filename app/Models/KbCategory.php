<?php

namespace App\Models;

use App\Support\Knowledgebase\Localized;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** A category of the knowledgebase (docs/knowledgebase.md): its name in the instance's language, translations optional. */
class KbCategory extends Model
{
    use Localized;

    protected $fillable = ['name', 'description', 'sort', 'translations'];

    protected $casts = ['translations' => 'array'];

    protected static function booted(): void
    {
        static::deleted(function (KbCategory $category) {
            if ($category->whmcs_id !== null) {
                DB::table('kb_whmcs_tombstones')->insertOrIgnore(['ref' => 'category:' . $category->whmcs_id, 'created_at' => now()]);
            }
        });
    }

    /** @return BelongsToMany<KbArticle, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(KbArticle::class, 'kb_article_category', 'category_id', 'article_id');
    }

    /** Its address as WHMCS made it: /knowledgebase/{id}/{name}. */
    public function url(): string
    {
        return route('kb.category', ['category' => $this->id, 'slug' => KbArticle::slugFor((string) $this->localized('name'))]);
    }
}
