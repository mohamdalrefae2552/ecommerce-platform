<?php

declare(strict_types=1);

namespace App\Modules\Products\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Cache-aside for the public catalogue (requirement 3): categories, product
 * list pages and product details, WITHOUT the stock (stock is asked live
 * from the inventory service on every read and composed afterwards).
 *
 * Invalidation by versioned keys: every key embeds the current catalogue
 * version; a committed product or category write increments it
 * (InvalidateCatalog), so the next read misses and rebuilds from the
 * database. Old entries are never read again and expire with the TTL. One
 * atomic INCR invalidates every page and every detail at once, without
 * listing keys and without races between "delete" and a concurrent refill.
 *
 * What is NOT cached and why: see docs/performance-report.md.
 */
final readonly class CatalogCache
{
    private const string VERSION_KEY = 'catalog:version';

    /** Shape of the cached values; change it when their structure changes. */
    private const string SHAPE = 's1';

    public function __construct(
        private Repository $cache,
        private DatabaseManager $db,
    ) {}

    /**
     * @template T of array
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $name, Closure $load): array
    {
        $key = 'catalog:'.self::SHAPE.':v'.$this->version().':'.$name;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var T $cached */
            return $cached;
        }

        $value = $this->fromPrimary($load);
        $this->cache->put($key, $value, $this->ttl());

        return $value;
    }

    /**
     * Like remember(), but a null result (unknown id) is not stored, so
     * random ids cannot fill the cache.
     *
     * @template T of array
     *
     * @param  Closure(): (T|null)  $load
     * @return T|null
     */
    public function rememberFound(string $name, Closure $load): ?array
    {
        $key = 'catalog:'.self::SHAPE.':v'.$this->version().':'.$name;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var T $cached */
            return $cached;
        }

        $value = $this->fromPrimary($load);

        if ($value !== null) {
            $this->cache->put($key, $value, $this->ttl());
        }

        return $value;
    }

    /**
     * Makes every cached catalogue entry unreachable.
     */
    public function invalidate(): void
    {
        // Initialise first: INCR on a missing key would restart at 1.
        $this->version();

        if ($this->cache->increment(self::VERSION_KEY) === false) {
            $this->cache->forever(self::VERSION_KEY, $this->freshVersion());
        }
    }

    private function version(): int
    {
        $version = $this->cache->get(self::VERSION_KEY);

        if (is_numeric($version)) {
            return (int) $version;
        }

        // A lost version key must never restart at a number whose entries
        // may still be alive: start from the current time in milliseconds.
        $this->cache->add(self::VERSION_KEY, $this->freshVersion());
        $version = $this->cache->get(self::VERSION_KEY);

        return is_numeric($version) ? (int) $version : $this->freshVersion();
    }

    private function freshVersion(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }

    /**
     * A cache fill reads the primary, never the replica (ADR-0014): right
     * after an edit the replica may still have the old row, which would
     * otherwise be stored under the new version until the TTL.
     *
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    private function fromPrimary(Closure $load): mixed
    {
        return $this->db->connection()->usingWritePdo(static fn (): mixed => $load());
    }

    private function ttl(): int
    {
        return max(1, Config::integer('catalog.cache_ttl'));
    }
}
