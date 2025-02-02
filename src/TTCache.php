<?php declare(strict_types=1);

namespace Square\TTCache;

use Closure;
use Psr\SimpleCache\CacheInterface;
use Square\TTCache\ReturnDirective\BypassCacheInterface;
use Square\TTCache\Store\TaggedStore;
use Square\TTCache\Tags\HeritableTag;
use Square\TTCache\Tags\TagInterface;

/**
 * TagTreeCache is a caching class that builds a tree of tags as it caches values so that
 * a tag applied to a nested value is also applied to the wrapping value.
 * This way clearing the tag for the nested value also clears the wrapping one even if the wrapping call
 * never specifically declared a dependency on this tag.
 */
class TTCache
{
    /**
     * How long to keep the tags in cache for
     */
    protected const TAGS_TTL = 0;

    /**
     * The tree structure of tag hashes
     */
    protected ?TagNode $tree = null;

    protected Closure $keyHasher;

    protected TaggedStore $cache;

    public function __construct(CacheInterface $cache, Closure $keyHasher = null)
    {
        $this->cache = new TaggedStore($cache);
        $this->keyHasher = $keyHasher ?? fn ($x) => $x;
    }

    protected function hashedKey(string $k) : string
    {
        return 'k:'.($this->keyHasher)($k);
    }

    protected function hashedTag(string $t) : string
    {
        return 't:'.($this->keyHasher)($t);
    }

    /**
     * Cache the result of a callback at the given key
     *
     * @param string $key                       The unique key where this value will be cached
     * @param callable $cb                      The callback to compute the value to cache
     * @param int $ttl                          How long this value should stay in cache. A ttl applied in a nested
     *                                          call to `remember` will also apply to any value coming in a wrapping
     *                                          call to the "remember" setting the ttl. If multiple such TTL calls
     *                                          exist in nested calls, the shortest one will win.
     * @param array<string|TagInterface> $tags A list of tags / surrogate keys that can be used to clear this value
     *                                          out of cache. For example, if many different calls to remember exist
     *                                          in the codebase that render a user's data and all of those are tagged
     *                                          with 'user:1' (1 being the user's id), then using 'user:1' to clear
     *                                          the cache would eliminate all values that were tagged with this from
     *                                          the cache. A nested call to remember that uses tags will have all its
     *                                          tags applied to all wrapping calls to `remember`
     *
     * @return mixed
     * @throws \Throwable
     * @phpstan-ignore-next-line
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function remember(string $key, int $ttl, array $tags, callable $cb)
    {
        $hkey = $this->hashedkey($key);
        $htags = array_map([$this, 'hashedTag'], $tags);
        $isRoot = $this->initTree();

        // Retrieve it from local cache if possible
        if ($this->tree->inCache($hkey)) {
            $r = $this->tree->getFromCache($hkey);
            $this->tree->child($r->tags);
            $this->resetTree($isRoot);
            return $r->value;
        }

        $r = $this->cache->get($hkey);
        if ($r) {
            $this->tree->child($r->tags);
            $this->resetTree($isRoot);
            return $r->value;
        }

        ['readonly' => $roCache, 'taghashes' => $tagHashes] = $this->cache->fetchOrMakeTagHashes($htags, $ttl);

        // Advance in the tree nodes
        $parent = $this->advanceTree($tagHashes, $tags);

        try {
            $value = $cb();
            if ($value instanceof BypassCacheInterface) {
                $roCache = true;
                $value = $value->value();
            }
        } catch (\Throwable $t) {
            $this->resetTree($isRoot);
            throw $t;
        }

        // Rewind the tree nodes
        $tagHashes = $this->tree->tagHashes();
        $this->tree = $parent;

        if (!$roCache) {
            $value = $this->cache->store($hkey, $ttl, $tagHashes, $value);
        }

        if ($isRoot) {
            $this->tree = null;
        }
        return $value;
    }

    public function wrap(array $tags, callable $cb)
    {
        $htags = array_map([$this, 'hashedTag'], $tags);
        $isRoot = $this->initTree();

        ['taghashes' => $tagHashes] = $this->cache->fetchOrMakeTagHashes($htags, 0);

        // Advance in the tree nodes
        $parent = $this->advanceTree($tagHashes, $tags);

        try {
            $value = $cb();
        } catch (\Throwable $t) {
            $this->resetTree($isRoot);
            throw $t;
        }

        // Rewind the tree nodes
        $this->tree = $parent;

        if ($isRoot) {
            $this->tree = null;
        }
        return $value;
    }

    protected function initTree() : bool
    {
        $isRoot = false;
        if ($this->tree === null) {
            $isRoot = true;
            $this->tree = new TagNode();
        }
        return $isRoot;
    }

    protected function resetTree(bool $isRoot)
    {
        if ($isRoot) {
            $this->tree = null;
        }
    }

    protected function advanceTree(array $taghashes, array $tags) : TagNode
    {
        $parent = $this->tree;
        $this->tree = $parent->child($taghashes);
        $this->tree->addHeritableTags(array_map(
            [$this, 'hashedTag'],
            array_filter($tags, fn ($t) => $t instanceof HeritableTag)
        ));

        return $parent;
    }

    /**
     * Pre-loads a set of keys in the current node's local cache.
     * The preloaded data can be retrieved directly from memory from this node's
     * scope or any descendant node instead of going to the cache store.
     */
    public function load(array $keys)
    {
        $hkeys = array_map([$this, 'hashedKey'], $keys);

        $validValues = $this->cache->getMultiple($hkeys);
        foreach ($validValues as $tv) {
            $this->rawTags(array_keys($tv->tags));
        }
        $this->tree->addToCache($validValues);
    }

    /**
     * Applies a set of given tags without hashing them (useful for re-using tags directly)
     *
     * @param array $tags
     * @return void
     */
    protected function rawTags(array $tags) : void
    {
        if (!$this->tree) {
            return;
        }
        ['taghashes' => $tagHashes] = $this->cache->fetchOrMakeTagHashes($tags);
        $this->tree->child($tagHashes);
    }

    /**
     * Makes any value associated with any of the given tags invalid in the cache
     *
     * @param string ...$tags
     * @return void
     */
    public function clearTags(string ...$tags) : void
    {
        $htags = array_map([$this, 'hashedTag'], $tags);
        $this->cache->clearTags(...$htags);
    }
}
