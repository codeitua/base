<?php

declare(strict_types=1);

namespace CodeIT\Cache;

class Memcache implements CacheInterface
{
    /**
     * @var \Memcache
     */
    protected $memc;
    protected $namespace;
    const MAX_TRIES = 10;
    protected $typeConnect = 'global';
    public function __construct($type = 'global')
    {
        $this->memc = new \Memcache();
        $this->typeConnect = $type;
        if (MEMCACHE_ENABLED) {
            $this->connect();
        }
    }
    function __destruct()
    {
        $this->disconnect();
    }
    /**
     * Connects to memcache daemon
     *
     */
    protected function connect()
    {
        $res = $this->memc->connect(MEMCACHE_HOST, 11211);
        $this->namespace = MEMCACHE_NAMESPACE;
        if (MEMCACHE_DEBUG) {
            if ($res) {
                Logger::write('Memcache::connect(): connected');
            } else {
                Logger::write('Memcache::connect(): connect FAILED!');
            }
        }
    }
    /**
    * Disconnects fro, memcache daemon
    *
    */
    public function disconnect()
    {
        if (MEMCACHE_ENABLED) {
            $this->memc->close();
            if (MEMCACHE_DEBUG) {
                Logger::write('Memcache::disconnect(): disconnected');
            }
        }
    }
    /**
    * assigns a value to a specified param
    *
    * @param string $name param name
    * @param string $value param value
    * @param int $timeout TTL in seconds
    * @param int $try
    * @return bool true on success, false on fail MAX_TRIES times
    */
    public function set($name, $value, $timeout = 0, $try = 0)
    {
        $key = $this->namespace . '.' . $name;
        if ($try > self::MAX_TRIES) {
            return false;
        }
        $ret = $this->memc->set($key, $value, 0, $timeout);
        if (!$ret) {
            if (MEMCACHE_DEBUG) {
                Logger::write('Memcache::set(' . $key . '): FAILED!');
            }
            $this->disconnect();
            $this->connect();
            $this->set($name, $value, $timeout, $try + 1);
        }
        if (MEMCACHE_DEBUG) {
            if ($ret) {
                Logger::write('Memcache::set(' . $key . '): value = ' . print_r($value, true));
            }
        }
        return $ret;
    }
    /**
    * get value
    *
    * @param string $name
    * @return string
    */
    public function get($name)
    {
        $key = $this->namespace . '.' . $name;
        $ret = $this->memc->get($key);
        if (MEMCACHE_DEBUG) {
            if (!$ret) {
                Logger::write('Memcache::get(' . $key . '): cache miss');
            } else {
                Logger::write('Memcache::get(' . $key . '): cache HIT!');
            }
        }
        return $ret;
    }
    /**
    * Get the values of all the specified keys, positionally aligned with the
    * requested keys. Missing keys hold false, like CodeIT\Cache\Redis::mget().
    *
    * @param array $keys
    * @return array of mixed
    */
    public function mget($keys)
    {
        if (!MEMCACHE_ENABLED || !$keys) {
            return [];
        }
        $prefixed = [];
        foreach ($keys as $key) {
            $prefixed[] = $this->namespace . '.' . $key;
        }
        // One request for the whole batch: \Memcache::get() accepts an array
        // and answers with the found keys only.
        $found = $this->memc->get($prefixed);
        if (!is_array($found)) {
            $found = [];
        }
        $result = [];
        foreach ($prefixed as $num => $key) {
            $result[$num] = $found[$key] ?? false;
        }
        return $result;
    }
    /**
    * Writes several keys, one call each: the memcached protocol has no batched set
    *
    * @param array $pairs key => value
    * @param int $ttl
    * @return bool
    */
    public function setMany($pairs, $ttl = 0)
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }
    /**
    * Takes a short lived lock in a single command; add() only succeeds when the
    * key is absent. There is no release, the key expires.
    *
    * @param string $key
    * @param int $ttl milliseconds
    * @return bool
    */
    public function acquireLock($key, $ttl)
    {
        if (!MEMCACHE_ENABLED) {
            return true;
        }
        return (bool) $this->memc->add($this->namespace . '.' . $key, 1, 0, max(1, (int) ceil($ttl / 1000)));
    }
    /**
    * memcached cannot enumerate keys, so a mask delete is impossible rather than
    * merely unsupported. Failing loudly beats silently serving stale rows.
    *
    * @param string $mask
    * @throws \RuntimeException
    */
    public function deleteByMask($mask)
    {
        throw new \RuntimeException(
            'CodeIT\\Cache\\Memcache cannot delete by mask; LocalizableTable needs a Redis cache.'
        );
    }
    public function deleteCache($name)
    {
        return $this->memc->delete($this->namespace . '.' . $name);
    }
}
