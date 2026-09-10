<?php

declare(strict_types=1);

namespace CodeIT\Model;

use CodeIT\Utils\Registry;
use Laminas\Db\ResultSet\ResultSet;
class CachedTable extends AppTable
{
    /**
     * Cache backend, normally a CodeIT\Cache\Redis instance
     *
     * @var object
     */
    protected static $cache = null;
    /**
     * Generation token per table, memoized for the current request
     *
     * @var array
     */
    protected static $findGenerations = [];
    /**
     * TTL in seconds for cached find() ID lists. Zero leaves find() uncached,
     * which is the behaviour existing applications rely on. Set it in a model
     * to opt that table into cached lists.
     *
     * @var int
     */
    protected $findCacheTtl = 0;
    /**
     * Creates table and sets id if necessary
     *
     * @param string $tableName
     * @param int $id
     */
    public function __construct($tableName, $id = null)
    {
        if (!self::$cache) {
            self::$cache = Registry::get('sm')->get('cache');
        }
        parent::__construct($tableName, $id);
    }
    /**
     * Returns row from db with specified id
     *
     * @param int $id
     * @param bool $publicOnly remove private fields
     * @return \ArrayObject
     */
    public function get($id, $publicOnly = false)
    {
        $row = $this->cacheGet($id);
        if (!$row) {
            $lockName = $this->getLockName($id);
            $this->getLock($lockName, false, 10);
            // check for data once more
            $row = $this->cacheGet($id);
            if (!$row) {
                $row = $this->getUncached($id);
                $this->cacheSet($id, $row);
            }
            $this->releaseLock($lockName);
        }
        if ($publicOnly) {
            $row = $this->removePrivateFields($row);
        }
        return $row;
    }
    /**
     * Returns unique lock name for record id
     *
     * @param int $id
     * @return string
     */
    private function getLockName($id)
    {
        return SITE_NAME . '.' . $this->table . '.' . $id;
    }
    /**
     * Returns row from db with specified id
     *
     * @param int $id
     * @return \ArrayObject
     */
    public function getUncached($id)
    {
        return parent::get($id);
    }
    /**
     * Builds the full cache key for a table scoped key part
     *
     * @param string $key
     * @return string
     */
    protected function cacheKey($key)
    {
        return 'table.' . $this->table . '.' . $key;
    }
    /**
     * Cache key holding one hydrated row. Overridden where rows are cached per
     * language or per any other scope, so that mget() reads the keys get()
     * writes.
     *
     * @param int|string $id
     * @return string
     */
    protected function itemCacheKey($id)
    {
        return $this->cacheKey($id);
    }
    /**
     * Gets cached value
     *
     * @param string $key
     * @return string
     */
    public function cacheGet($key)
    {
        return self::$cache->get($this->cacheKey($key));
    }
    /**
     * Assigns a value to a specified cached param
     *
     * @param string $key
     * @param string $value param value
     * @param int $timeout TTL in seconds
     * @param int $try
     * @return bool true on success, false on fail MAX_TRIES times
     */
    public function cacheSet($key, $value, $timeout = 0, $try = 0)
    {
        return self::$cache->set($this->cacheKey($key), $value, $timeout, $try);
    }
    /**
     * Deletes cached value
     *
     * @param string $key
     * @return int
     */
    public function cacheDelete($key)
    {
        return self::$cache->deleteCache($this->cacheKey($key));
    }
    /**
     * Deletes cached values with keys that start with name
     *
     * @param string $keyMask
     */
    public function cacheDeleteByMask($keyMask)
    {
        self::$cache->deleteByMask($this->cacheKey($keyMask));
    }
    /**
     * Returns rows from db with specified id
     *
     * Reads every row with a single batched cache request and only falls back
     * to get() for the ids the cache did not hold.
     *
     * @param array $ids
     * @param bool $publicOnly remove private fields
     * @return \ArrayObject[]
     */
    public function mget($ids, $publicOnly = false)
    {
        $ids = array_values($ids);
        if (!$ids) {
            return [];
        }
        $values = self::$cache->mget(array_map([$this, 'itemCacheKey'], $ids));
        if (!is_array($values)) {
            $values = [];
        }
        $result = [];
        foreach ($ids as $num => $id) {
            if (!empty($values[$num])) {
                $result[$id] = $publicOnly ? $this->removePrivateFields($values[$num]) : $values[$num];
                continue;
            }
            try {
                $result[$id] = $this->get($id, $publicOnly);
            } catch (Exception\ItemNotFoundException $e) {
                // The row was removed between the id lookup and this fetch.
            }
        }
        return $result;
    }
    /**
    * Inserts a record
    *
    * @param array $set
    * @return int last insert Id
    */
    public function insert($set)
    {
        $id = parent::insert($set);
        $this->cacheDelete('list');
        $this->invalidateFindCache();
        return $id;
    }
    /**
    * Searches for items, fetching them by::mget()
    *
    * @param array $params, e.g. ['id', '>=', '135']
    * @param int $limit, set to 0 or false to no limit
    * @param int $offset
    * @param string|bool|false $orderBy
    * @param int &$total will be set to total count found
    * @param bool $publicOnly should we return full data or non-private fields only
    * @return \ArrayObject[]
    */
    public function find($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $publicOnly = false)
    {
        return $this->mget($this->findIds($params, $limit, $offset, $orderBy, $total), $publicOnly);
    }
    /**
    * Returns the matching ids, served from cache when findCacheTtl is set
    *
    * Only the id list and the total are cached: rows keep living in their own
    * per-item keys, so an edited row is picked up by every cached list without
    * invalidating any of them.
    *
    * @param array $params
    * @param int $limit
    * @param int $offset
    * @param string|bool|false $orderBy
    * @param int &$total
    * @return array
    */
    protected function findIds($params, $limit, $offset, $orderBy, &$total)
    {
        if (!$this->findCacheTtl) {
            return $this->queryIds($params, $limit, $offset, $orderBy, $total);
        }
        $key = $this->buildFindCacheKey($params, $limit, $offset, $orderBy, !is_null($total));
        $cached = $this->cacheGet($key);
        if (!is_array($cached) || !array_key_exists('ids', $cached)) {
            $cached = [
                'ids' => $this->queryIds($params, $limit, $offset, $orderBy, $total),
                'total' => $total,
            ];
            $this->cacheSet($key, $cached, $this->findCacheTtl);
        }
        if (!is_null($total)) {
            $total = $cached['total'];
        }
        return $cached['ids'];
    }
    /**
    * Runs the id query behind find()
    *
    * @param array $params
    * @param int $limit
    * @param int $offset
    * @param string|bool|false $orderBy
    * @param int &$total
    * @return array
    */
    private function queryIds($params, $limit, $offset, $orderBy, &$total)
    {
        $rows = $this->findSimple($params, $limit, $offset, $orderBy, $total, [static::ID_COLUMN])->toArray();
        return array_column($rows, static::ID_COLUMN);
    }
    /**
    * Builds the cache key for one find() query
    *
    * The generation token makes every list key of the table unreachable as
    * soon as a write replaces it, so invalidation never scans the keyspace and
    * works the same on Redis and Memcached.
    *
    * @param array $params
    * @param int $limit
    * @param int $offset
    * @param string|bool|false $orderBy
    * @param bool $withTotal
    * @return string
    */
    protected function buildFindCacheKey($params, $limit, $offset, $orderBy, $withTotal)
    {
        $signature = serialize([
            $params,
            (int) $limit,
            (int) $offset,
            $orderBy,
            (bool) $withTotal,
            $this->getFindJoin(),
            $this->getGroupBy(),
            $this->getHaving(),
            $this->findCacheScope(),
        ]);
        return 'find.' . $this->findGeneration() . '.' . substr(hash('sha256', $signature), 0, 16);
    }
    /**
    * Extra values that make a cached find() result unique, for example the
    * active language of a localized table
    *
    * @return array
    */
    protected function findCacheScope()
    {
        return [];
    }
    /**
    * Current generation token of the table's cached lists
    *
    * @return string
    */
    protected function findGeneration()
    {
        if (!isset(self::$findGenerations[$this->table])) {
            $generation = $this->cacheGet('findgen');
            if (!$generation) {
                $generation = uniqid('', true);
                $this->cacheSet('findgen', $generation);
            }
            self::$findGenerations[$this->table] = $generation;
        }
        return self::$findGenerations[$this->table];
    }
    /**
    * Drops every cached list of the table
    *
    * Storing a fresh token needs no coordination between concurrent writers:
    * each of them stores a different value and every one of them invalidates.
    */
    protected function invalidateFindCache()
    {
        if (!$this->findCacheTtl) {
            return;
        }
        self::$findGenerations[$this->table] = uniqid('', true);
        $this->cacheSet('findgen', self::$findGenerations[$this->table]);
    }
    /**
    * Update
    *
    * @param array $params
    * @param string|array|closure $where
    * @param bool $clearCache
    * @return int affected rows
    */
    public function update($params, $where = null, $clearCache = true)
    {
        $result = parent::update($params, $where);
        if ($clearCache && is_array($where) && isset($where['id']) && is_numeric($where['id'])) {
            $this->cacheDelete($where['id']);
        }
        $this->cacheDelete('list');
        $this->invalidateFindCache();
        return $result;
    }
    /**
    * Deletes item
    *
    * @param Where|\Closure|string|array $where: Item ID or expression
    * @return bool: true on OK, false on item not found
    */
    public function delete($where)
    {
        if (is_numeric($where)) {
            $result = parent::delete([static::ID_COLUMN => $where]);
            if ($result) {
                $this->cacheDelete($where);
            }
        } else {
            $result = parent::delete($where);
        }
        if ($result) {
            $this->invalidateFindCache();
        }
        return (bool) $result;
    }
    /**
    * Deletes record by id, removes cached data
    *
    * @param mixed $id
    * @returns bool
    */
    public function deleteById($id)
    {
        $rowsAffected = parent::delete([static::ID_COLUMN => $id]);
        $this->cacheDelete($id);
        $this->cacheDelete('list');
        $this->invalidateFindCache();
        return $rowsAffected;
    }
    /**
    * Returns full items list from cache
    *
    * @param int|bool|false $limit
    * @param int $offset
    * @param int $total callback value: total items
    * @return ResultSet
    */
    public function getList($limit = false, $offset = 0, &$total = null)
    {
        $cachedList = true;
        if ($limit || $offset) {
            $cachedList = false;
        }
        if ($cachedList && ($list = $this->cacheGet('list'))) {
            if (!is_null($total)) {
                $total = sizeof($list);
            }
            return $list;
        }
        $column = static::ID_COLUMN;
        $select = $this->getSelect($this->table);
        if ($limit !== false) {
            $select->limit($limit);
        }
        if ($offset) {
            $select->offset($offset);
        }
        $list = $this->execute($select);
        unset($select);
        $res = [];
        foreach ($list as $item) {
            $res[$item->{$column}] = $this->get($item->{$column});
        }
        if ($cachedList) {
            $this->cacheSet('list', $res);
        }
        if (!is_null($total)) {
            $total = sizeof($res);
            if (!$offset || $total == $limit) {
                $total = $this->query('select count(*) cnt from ' . $this->table)->current()->cnt;
            }
        }
        return $res;
    }
    /**
    * Sets data for current id
    *
    * @param array $data
    * @param int|bool|false $id
    * @param bool $setDataToObject perform setId() call after update
    */
    public function set($data, $id = false, $setDataToObject = true)
    {
        parent::set($data, $id, false);
        $myId = $this->{static::ID_COLUMN};
        if ($id) {
            $myId = $id;
        }
        $this->cacheDelete($myId);
        if ($myId == $this->{static::ID_COLUMN} && $setDataToObject) {
            $this->setId($this->{static::ID_COLUMN});
        }
    }
}
