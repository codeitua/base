<?php

namespace CodeIT\Cache;

class Memcache
{
    /**
    * @var \Memcache
    */
    protected $memc;
    protected $namespace;
    protected const MAX_TRIES = 10;

    protected $typeConnect = 'global';

    public function __construct($type = 'global')
    {
        $this->memc = new \Memcache();
        $this->typeConnect = $type;
        if (MEMCACHE_ENABLED) {
            $this->connect();
        }
    }

    public function __destruct()
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
    * @param int $timeout
    * @param int $try
    * @return bool true on success, false on fail MAX_TRIES times
    */
    public function set($name, $value, $flag = false, $timeout = 0, $try = 0)
    {
        $key = $this->namespace . '.' . $name;
        if ($try > self::MAX_TRIES) {
            return false;
        }
        $ret = $this->memc->set($key, ($value), $flag, $timeout);
        if (!$ret) {
            if (MEMCACHE_DEBUG) {
                Logger::write('Memcache::set(' . $key . '): FAILED!');
            }
            $this->disconnect();
            $this->connect();
            $this->set($key, ($value), $flag, $timeout, $try + 1);
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
        return ($ret);
    }

    public function deleteCache($name)
    {
        return $this->memc->delete($this->namespace . '.' . $name);
    }
}
