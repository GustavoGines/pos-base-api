<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class ReportCacheService
{
    public const VERSION_KEY = 'reports_cache_version';

    public const TAG = 'reports';

    /**
     * Get the current cache version integer.
     */
    public static function getVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Build a versioned cache key with arbitrary parameter parts.
     */
    public static function key(string $prefix, string ...$params): string
    {
        $version = self::getVersion();
        $paramSuffix = empty($params) ? '' : '_'.implode('_', $params);

        return "{$prefix}_v{$version}{$paramSuffix}";
    }

    /**
     * Execute a cached callback respecting tags when supported.
     */
    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (Cache::supportsTags()) {
            return Cache::tags([self::TAG])->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Invalidate reports cache atomically across all cache stores.
     */
    public static function flush(): void
    {
        if (Cache::supportsTags()) {
            Cache::tags([self::TAG])->flush();
        }

        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, self::getVersion() + 1);

            return;
        }

        $incremented = Cache::increment(self::VERSION_KEY);
        if ($incremented === false) {
            Cache::forever(self::VERSION_KEY, self::getVersion() + 1);
        }
    }
}
