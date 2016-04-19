<?php
namespace CodeIT\Cache;

/**
 * Class RedisWrapper
 * 
 * @method int delete($key1, $key2 = null, $key3 = null)
 * @method int del($key1, $key2 = null, $key3 = null)
 * @method bool setex($key, $ttl, $value)
 * @method bool setOption($name, $value)
 * @method bool set($key, $value, $timeout = 0)
 * @method bool mset(array $array)
 * @method string|bool get($key)
 * @method array mget(array $array)
 * @method true flushDB()
 * @method mixed evaluate($script, $args = array(), $numKeys = 0)
 */
class RedisWrapper {

	protected $connection;

	/**
	 * @var bool
	 */
	protected $isConnected = false;

	protected $host;

	protected $port;

	protected $db;

	protected $password;

	protected $options = [];

	/**
	 * RedisWrapper constructor.
	 * @param string $host
	 * @param int $port
	 * @param int|null $db
	 * @param array $options
	 * @param null $password
	 */
	public function __construct($host = 'localhost', $port = 6379, $db = null, array $options = [], $password = null) {
		$this->host = $host;
		$this->port = $port;
		$this->db = $db;
		$this->password = $password;
		$this->options = $options;
	}

	public function connect($force = false) {
		if (!$this->isConnected || $force) {
			$connection = new \Redis();
			$connection->pconnect($this->host, $this->port);

			if ($this->password) {
				$connection->auth($this->password);
			}

			if (is_int($this->db)) {
				$connection->select($this->db);
			}

			foreach ($this->options as $optionName => $optionValue) {
				$connection->setOption($optionName, $optionValue);
			}

			$this->connection = $connection;
			$this->isConnected = true;
		}
	}

	public function pconnect($force = false) {
		$this->connection($force);
	}

	public function __call($name, $arguments) {
		if (!method_exists($this->connection, $name)) {
			throw new \Exception(sprintf('No method with name %s', $name));
		}

		return call_user_func_array([$this->connection, $name], $arguments);
	}
}
