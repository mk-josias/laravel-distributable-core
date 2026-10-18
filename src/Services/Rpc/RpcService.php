<?php

declare(strict_types=1);

namespace Distributable\Services\Rpc;

use Closure;
use Distributable\Config\Rpc;
use Distributable\Contracts\Colocation;
use Distributable\Contracts\Rpc\Transport;
use Distributable\Exceptions\ServiceException;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base of the implementation a contract is bound to on the caller's side. It lives next to the
 * contract, in the namespace of the service it calls, so every caller has it.
 */
abstract class RpcService
{
    /** Long by design: the owner forgets a key when it writes; expiry only catches a missed forget. */
    protected const int DEFAULT_TTL = 604800;

    /** The service this one calls, read from its namespace. */
    public string $service {
        get => app(Colocation::class)->serviceOf(static::class)
            ?? throw ServiceException::outsideService(static::class);
    }

    public function __construct(protected readonly Transport $transport) {}

    /**
     * Calls $method of the contract this service is mapped to, on the service that implements it:
     * in this process when it runs here, over its transport otherwise.
     *
     * @param  array<string, mixed>  $arguments  by name, as the contract's method declares them
     */
    protected function call(string $method, array $arguments = []): mixed
    {
        $contract = app(RpcServices::class)->contractOf(static::class)
            ?? throw ServiceException::unmappedRpcService(static::class);

        return $this->transport->invoke($this->service, $contract, $method, $arguments);
    }

    /**
     * Returns $key from the cache; on a miss, $fetch the raw answer and keep it for $ttl. The cache
     * holds the raw answer and $map runs on every read, so a changed shape never outlives a deploy.
     *
     * @template T
     *
     * @param  Closure(): mixed  $fetch
     * @param  Closure(array<string, mixed>): T  $map
     * @param  list<string>|null  $tags
     * @return T|null
     */
    protected function readThrough(string $key, int $ttl, Closure $fetch, Closure $map, ?array $tags = null): mixed
    {
        $raw = $this->read($key, $tags);

        if (! is_array($raw)) {
            $raw = $fetch();

            if (! is_array($raw)) {
                return null;
            }

            $this->write($key, $raw, $ttl, $tags);
        }

        return $this->mapOrForget($key, $raw, $map, $tags);
    }

    /**
     * A read-through whose lifetime comes from the mapped answer (a token kept until it expires);
     * a lifetime of zero or less is returned as absent and not kept.
     *
     * @template T
     *
     * @param  Closure(): mixed  $fetch
     * @param  Closure(array<string, mixed>): T  $map
     * @param  Closure(T): int  $ttlOf  seconds
     * @return T|null
     */
    protected function readThroughUntil(string $key, Closure $fetch, Closure $map, Closure $ttlOf): mixed
    {
        $raw = $this->read($key);

        if (is_array($raw)) {
            return $this->mapOrForget($key, $raw, $map);
        }

        $raw = $fetch();
        $mapped = is_array($raw) ? $this->mapOrForget($key, $raw, $map) : null;

        if ($mapped === null || ($ttl = $ttlOf($mapped)) <= 0) {
            return null;
        }

        $this->write($key, $raw, $ttl);

        return $mapped;
    }

    /**
     * An answer is kept as JSON, so a service in another language reads the same key. On Redis it is
     * written as is, under the key Laravel uses: its own put() would serialize it in PHP. With tags,
     * Laravel's put() still files the key under them, so flushing a tag drops it.
     *
     * @param  array<array-key, mixed>  $raw
     * @param  list<string>|null  $tags
     */
    private function write(string $key, array $raw, int $ttl, ?array $tags = null): void
    {
        $cache = $this->cache($tags);
        $store = $cache->getStore();
        $json = json_encode($raw, JSON_THROW_ON_ERROR);

        if (! $store instanceof RedisStore || $cache instanceof TaggedCache) {
            $cache->put($key, $json, $ttl);
        }

        if ($store instanceof RedisStore) {
            $store->connection()->setex($store->getPrefix().$this->itemKey($cache, $key), $ttl, $json);
        }
    }

    /**
     * @param  list<string>|null  $tags
     * @return array<array-key, mixed>|null
     */
    private function read(string $key, ?array $tags = null): ?array
    {
        $cache = $this->cache($tags);
        $store = $cache->getStore();
        $json = $store instanceof RedisStore
            ? $store->connection()->get($store->getPrefix().$this->itemKey($cache, $key))
            : $cache->get($key);
        $raw = is_string($json) ? json_decode($json, true) : null;

        return is_array($raw) ? $raw : null;
    }

    private function itemKey(Repository $cache, string $key): string
    {
        return $cache instanceof TaggedCache ? $cache->taggedItemKey($key) : $key;
    }

    /** @param list<string>|null $tags the ones the entry was written under */
    protected function forget(string $key, ?array $tags = null): void
    {
        $this->cache($tags)->forget($key);
    }

    /**
     * The store shared by every process (microservices.rpc.cache): the caller keeps the answer, the
     * owner forgets it, so both must reach the same one.
     *
     * @param  list<string>|null  $tags
     */
    protected function cache(?array $tags = null): Repository
    {
        $store = Cache::store(app(Rpc::class)->getCacheStore());

        return $tags !== null && $store->getStore() instanceof TaggableStore ? $store->tags($tags) : $store;
    }

    /**
     * An answer $map cannot read anymore is dropped from the cache and read as absent.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>|null  $tags
     */
    private function mapOrForget(string $key, array $raw, Closure $map, ?array $tags = null): mixed
    {
        try {
            return $map($raw);
        } catch (Throwable $e) {
            Log::warning('Discarded an RPC answer that no longer maps', ['key' => $key, 'raw' => $raw, 'error' => $e->getMessage()]);
            $this->forget($key, $tags);

            return null;
        }
    }
}
