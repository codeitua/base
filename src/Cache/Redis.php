<?php

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
            $this->config['namespace'] = ''; // create key to be sure it won't break deletion by mask
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
     * the array will contain FALSE at the position of the key.
     *
     * @param array $names keys to fetch
     * @return array of mixed
     */
    public function mget($keys)
    {
        if (!$this->config['enabled']) {
            return;
        }

        $oldHandler = set_error_handler('\CodeIT\Cache\Redis::myHandler');

        try {
            $ret = @$this->redis->mGet($keys);
        } catch (\Exception $e) {
            return [];
        }
        set_error_handler($oldHandler);
        return $ret;
    }

    /**
     * Remove specified keys.
     *
     * @param mixed $keys: string with key or array of keys
     * @return int Number of keys deleted
     */
    public function deleteCache($keys)
    {
        if ($this->connected) {
            return $this->redis->delete($keys);
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
        if ($this->connected) {
            $mask = sprintf('%s*', $name);
            $this->redis->evaluate("return redis.call('del', unpack(redis.call('keys', ARGV[1])))", [$this->config['namespace'] . $mask]);
        }
    }
}
