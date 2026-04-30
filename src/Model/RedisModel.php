<?php

declare(strict_types=1);

namespace CodeIT\Model;

abstract class AppRedisModel
{
    /**
     * @var \Application\Lib\Redis
     */
    protected static $redis = null;
    protected $modelName = '';
    /**
     * @var \Laminas\Db\Adapter\Adapter
     */
    public $adapter = null;
    public function __construct($modelName)
    {
        $this->modelName = $modelName;
        if (!self::$redis) {
            try {
                self::$redis = \CodeIT\Utils\Registry::get('redis');
            } catch (\Exception $e) {
                self::$redis = new \Application\Lib\Redis();
                \CodeIT\Utils\Registry::set('redis', self::$redis);
            }
        }
        try {
            $adapter = \CodeIT\Utils\Registry::get('dbAdapter');
        } catch (\Exception $e) {
            $adapter = new \Laminas\Db\Adapter\Adapter(\CodeIT\Utils\Registry::get('dbConfig'));
            \CodeIT\Utils\Registry::set('dbAdapter', $adapter);
        }
        $this->adapter = $adapter;
    }
    public function get($id)
    {
        return self::$redis->get($this->getKey($id));
    }
    public function set($id, $value)
    {
        return self::$redis->set($this->getKey($id), $value);
    }
    private function getKey($id)
    {
        return 'model.' . $this->modelName . '.' . $id;
    }
    protected function getLockName($id)
    {
        return SITE_NAME . '.' . $this->modelName . '.' . $id;
    }
    /**
    * Aquires lock (mutex)
    *
    * @param string $id
    * @param array $params
    * @param int $timeout
    */
    public function getLock($id, $timeout = 10)
    {
        $name = $this->getLockName($id);
        $resultSet = $this->adapter->query("select GET_LOCK('{$name}', {$timeout}) as res")->execute();
        $result = $resultSet->current()['res'];
        if (!$result) {
            throw new \Exception('Could not obtain lock on ' . $name);
        }
    }
    /**
    * releases lock (mutex) obtained by getLock
    *
    * @param string $id
    * @param array $params
    */
    public function releaseLock($id)
    {
        $name = $this->getLockName($id);
        $this->adapter->query("select RELEASE_LOCK('{$name}')")->execute();
    }
}
