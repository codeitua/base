<?php

declare(strict_types=1);

namespace CodeIT\Cache;

class Redis
{
    /**
     * @var CodeIT\Cache\RedisWrapper
     */
    protected $redis;
    protected $namespace;
    protected $connected = false;
    protected $config = [];
    const MAX_TRIES = 10;
    /**
     * construct redis cache objecy
     *
     * @param [] $config
     * @param \CodeIT\Cache\RedisWrapper $redis
     */
    public function __construct($config, $redis)
    {
        $this->config = $config;
        $this->redis = $redis;
        if ($this->config['enabled']) {
            $this->connect();
        }
    }
    /**
     * Connects to redis daemon
     *
     */
    protected function connect()
    {
        $this->redis->connect();
        if (empty($this->config['namespace'])) {
            $this->config['namespace'] = '';
            // create key to be sure it won't break deletion by mask
        } else {
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->config['namespace']);
        }
        $this->connected = true;
    }
    /**
    * assigns a value to a specified param
    *
    * @param string $name param name
    * @param string $value param value
    * @param int $timeout TTL in seconds (month by default)
    * @param int $try
    * @return bool true on success, false on fail MAX_TRIES times
    */
    public function set($key, $value, $ttl = 2678400, $try = 0)
    {
        if (!$this->config['enabled']) {
            return;
        }
        if ($try > self::MAX_TRIES) {
            return false;
        }
        if ($ttl) {
            $ret = $this->redis->setex($key, $ttl, $value);
        } else {
            $ret = $this->redis->set($key, $value);
        }
        if (!$ret) {
            $this->connect();
            $this->set($key, $value, $ttl, $try + 1);
        }
        return $ret;
    }
    /**
    * get value
    *
    * @param string $key
    * @return string
    */
    public function get($key)
    {
        if (!$this->config['enabled']) {
            return;
        }
        $ret = $this->redis->get($key);
        return $ret;
    }
    static function myHandler()
    {
        //throw new \Exception('Bad keys!');
    }
    /**
    * Get the values of all the specified keys. If one or more keys dont exist,
    * the array will contain FALSE at the position of the key .
    *
    * @param array $names keys to fetch
    * @return array of mixed
    */
    public function mget($keys)
    {
        if (!$this->config['enabled']) {
            return;
        }
        try {
            $oldHandler = set_error_handler('\\CodeIT\\Cache\\Redis::myHandler');
            $ret = @$this->redis->mGet($keys);
        } catch (\Exception $e) {
            return [];
        } finally {
            if (isset($oldHandler)) {
                set_error_handler($oldHandler);
            }
        }
        return $ret;
    }
    /**
    * Writes several keys in one pipeline, so a batch of rows costs one round
    * trip instead of one per row
    *
    * @param array $pairs key => value
    * @param int $ttl TTL in seconds, 0 to keep until deleted
    * @return bool
    */
    public function setMany($pairs, $ttl = 0)
    {
        if (!$this->config['enabled'] || !$pairs) {
            return false;
        }
        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($pairs as $key => $value) {
            if ($ttl) {
                $pipeline->setex($key, $ttl, $value);
            } else {
                $pipeline->set($key, $value);
            }
        }
        $pipeline->exec();
        return true;
    }
    /**
    * Takes a short lived lock in a single command.
    *
    * There is no release: the key expires. A caller that loses the race must not
    * wait for it, because a queue of waiting workers costs far more than the
    * duplicated work the lock was meant to avoid.
    *
    * @param string $key
    * @param int $ttl milliseconds
    * @return bool true when this process took the lock
    */
    public function acquireLock($key, $ttl)
    {
        if (!$this->config['enabled']) {
            return true;
        }
        return (bool) $this->redis->set($key, 1, ['NX', 'PX' => max(1, (int) $ttl)]);
    }
    /**
    * Remove specified keys .
    *
    * @param mixed $keys: string with key or array of keys
    * @return int Number of keys deleted
    */
    public function deleteCache($keys)
    {
        if ($this->connected) {
            try {
                return $this->redis->del($keys);
            } catch (\Throwable $e) {
                return $this->redis->delete($keys);
            }
        }
        return false;
    }
    /**
    * deletes all the keys that start with name
    *
    * @param string $name
    */
    public function deleteByMask($name)
    {
        if (!$this->connected) {
            return 0;
        }
        $mask = sprintf('%s*', $name);
        // SCAN instead of KEYS: the same work, without blocking the server for
        // the whole keyspace. Deleting inside the script keeps the pattern and
        // the keys on the server side, past any client key prefixing.
        $script = "local cursor = '0' local deleted = 0 repeat"
            . " local res = redis.call('SCAN', cursor, 'MATCH', ARGV[1], 'COUNT', 500)"
            . " cursor = res[1]"
            . " if #res[2] > 0 then deleted = deleted + redis.call('DEL', unpack(res[2])) end"
            . " until cursor == '0' return deleted";
        try {
            return (int) $this->redis->eval($script, [$this->config['namespace'] . $mask], 0);
        } catch (\Throwable $e) {
            return (int) $this->redis->evaluate($script, [$this->config['namespace'] . $mask]);
        }
    }
}
