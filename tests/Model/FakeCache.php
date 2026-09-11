<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use CodeIT\Cache\CacheInterface;

/**
 * In-memory stand-in for CodeIT\Cache\Redis that counts the requests a model
 * makes, so tests can assert reads are batched.
 */
final class FakeCache implements CacheInterface
{
    /** @var array */
    public $store = [];

    /** @var int */
    public $mgetCalls = 0;

    /** @var int */
    public $getCalls = 0;

    /** @var int */
    public $maskDeletes = 0;

    /** @var array key sets of every mget, in order */
    public $mgetKeys = [];

    public function get($key)
    {
        $this->getCalls++;
        return $this->store[$key] ?? false;
    }

    public function set($key, $value, $ttl = 0, $try = 0)
    {
        $this->store[$key] = $value;
        return true;
    }

    public function mget($keys)
    {
        $this->mgetCalls++;
        $this->mgetKeys[] = $keys;
        $values = [];
        foreach ($keys as $key) {
            $values[] = $this->store[$key] ?? false;
        }
        return $values;
    }

    public function setMany($pairs, $ttl = 0)
    {
        foreach ($pairs as $key => $value) {
            $this->store[$key] = $value;
        }
        return true;
    }

    public function acquireLock($key, $ttlMs)
    {
        if (isset($this->store[$key])) {
            return false;
        }
        $this->store[$key] = 1;
        return true;
    }

    public function deleteCache($keys)
    {
        $deleted = 0;
        foreach ((array) $keys as $key) {
            if (isset($this->store[$key])) {
                $deleted++;
            }
            unset($this->store[$key]);
        }
        return $deleted;
    }

    public function deleteByMask($mask)
    {
        $this->maskDeletes++;
        $deleted = 0;
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $mask)) {
                unset($this->store[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }
}
