<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CacheableBuilder extends Builder
{
    /**
     * The generation every cached query's key carries. A flush gives it a new value, so every earlier entry is out of
     * reach (and expires on its own TTL). Never Cache::flush(): the file store keeps its locks and the scheduler's
     * mutexes in the cache directory, and a flush released them under running work (sweep 3, L-DEPLOY-MIGRATIONS-1).
     */
    public const GENERATION_KEY = 'query_cache_generation';

    public function get($columns = ['*'])
    {
        $cacheKey = $this->generateCacheKey();

        return Cache::remember($cacheKey, 3600, function () use ($columns) {
            return parent::get($columns);
        });
    }

    public function first($columns = ['*'])
    {
        $cacheKey = $this->generateCacheKey() . '_first';

        return Cache::remember($cacheKey, 3600, function () use ($columns) {
            return parent::first($columns);
        });
    }

    /** Every cached query out of reach, and nothing else in the cache touched (locks, mutexes, settings, rate limits). */
    public static function flushQueryCache(): void
    {
        Cache::forever(self::GENERATION_KEY, Str::random(16));
    }

    protected function generateCacheKey()
    {
        // Unique key based on the generation, the SQL and its bindings
        return 'query_' . Cache::get(self::GENERATION_KEY, '0') . '_' . md5($this->toSql() . implode(',', $this->getBindings()));
    }

    public function clearCache()
    {
        Cache::forget($this->generateCacheKey());
        Cache::forget($this->generateCacheKey() . '_first');
    }
}
