<?php declare(strict_types=1);

namespace Square\TTCache\Store;

use Psr\SimpleCache\CacheInterface;
use Memcached;

/**
 * PSR Cache interface implementation of memcache.
 */
class MemcachedStore implements CacheInterface
{
    protected Memcached $mc;

    private const MC_SUCCESS = 0;
    private const MC_NOT_FOUND = 16;

    private const MC_VALID_CODES = [
        self::MC_SUCCESS,
        self::MC_NOT_FOUND,
    ];

    public function __construct(Memcached $mc)
    {
        $this->mc = $mc;
    }

    protected function checkResultCode() : void
    {
        if (!in_array($this->mc->getResultCode(), self::MC_VALID_CODES)) {
            throw new CacheStoreException('invalid MC return code', $this->mc->getResultCode());
        }
    }

    public function get($key, $default = null)
    {
        $result = $this->mc->get($key);
        $this->checkResultCode();
        return $result;
    }

    public function set($key, $value, $ttl = null)
    {
        $result = $this->mc->set($key, $value, $ttl ?? 0);
        $this->checkResultCode();
        return $result;
    }

    public function delete($key)
    {
        $result = $this->mc->delete($key);
        $this->checkResultCode();
        return $result;
    }

    public function clear(): bool
    {
        throw new CacheStoreException('not implemented on purpose');
    }

    public function getMultiple($keys, $default = null)
    {
        $result = $this->mc->getMulti($keys);
        $this->checkResultCode();
        return $result;
    }

    public function setMultiple($values, $ttl = null)
    {
        $result = $this->mc->setMulti($values, $ttl);
        $this->checkResultCode();
        return $result;
    }

    public function deleteMultiple($keys)
    {
        $result = $this->mc->deleteMulti($keys);
        $this->checkResultCode();
        return true;
    }

    public function has($key)
    {
        throw new CacheStoreException('not implemented');
    }
}
