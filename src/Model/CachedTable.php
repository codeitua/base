<?php

declare(strict_types=1);

namespace CodeIT\Model;

use CodeIT\Cache\BatchLoader;
use CodeIT\Cache\CacheInterface;
use CodeIT\Cache\Deferred;
use CodeIT\Utils\Registry;
use Laminas\Db\ResultSet\ResultSet;
class CachedTable extends AppTable
{
    /**
     * Cache backend
     *
     * @var CacheInterface
     */
    protected static ?CacheInterface $cache = null;
    /**
     * Generation token per table, memoized for the current request
     *
     * @var array
     */
    protected static $findGenerations = [];
    /** Field carrying the generation a cached find() entry was written under. */
    protected const GENERATION_FIELD = 'g';
    /**
     * TTL in seconds for cached find() ID lists. Zero leaves find() uncached,
     * which is the behaviour existing applications rely on. Set it in a model
     * to opt that table into cached lists.
     *
     * @var int
     */
    protected $findCacheTtl = 0;
    /**
     * Milliseconds a rebuild lock is held. Acquiring it costs one cache command
     * and it is released by expiry, never by a second command. Zero turns the
     * stampede guard off.
     *
     * @var int
     */
    protected $rebuildLockTtl = 3000;
    /**
     * Rows already read in this request, keyed by their cache key.
     *
     * A page asks for the same row repeatedly, once per product for its
     * category and so on, and every one of those was a round trip. Cleared by
     * every write, and shared for the life of the process, which under php-fpm
     * is one request. A long running worker must call forgetLocalRows().
     *
     * @var array
     */
    protected static $localRows = [];
    /**
     * Whether reads are remembered for the rest of the request
     *
     * @var bool
     */
    protected $localRowCache = true;
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
        $key = $this->itemCacheKeyPart($id);
        $row = $this->localRow($id);
        if (!$row) {
            $row = $this->cacheGet($key);
        }
        if (!$row) {
            // One command, released by expiry. Losing the race never blocks and
            // never holds a database connection: look once more, then rebuild
            // anyway, because a duplicate read costs far less than a queue.
            if (!$this->acquireRebuildLock($id)) {
                $row = $this->cacheGet($key);
            }
            if (!$row) {
                $row = $this->prepareCachedRow($this->getUncached($id));
                $this->cacheSet($key, $row);
            }
        }
        $this->rememberRow($id, $row);
        if ($publicOnly) {
            $row = $this->removePrivateFields($row);
        }
        return $row;
    }
    /**
     * A row already read in this request, or null
     *
     * @param int|string $id
     * @return \ArrayObject|null
     */
    protected function localRow($id)
    {
        return $this->localRowCache ? (self::$localRows[$this->itemCacheKey($id)] ?? null) : null;
    }
    /**
     * @param int|string $id
     * @param \ArrayObject $row
     */
    protected function rememberRow($id, $row)
    {
        if ($this->localRowCache && $row) {
            self::$localRows[$this->itemCacheKey($id)] = $row;
        }
    }
    /**
     * Forgets rows remembered for this request.
     *
     * Call it between requests in a worker that keeps the process alive, such as
     * RoadRunner or Swoole; php-fpm resets statics on its own.
     */
    public static function forgetLocalRows()
    {
        self::$localRows = [];
    }
    /**
     * Marks this process as the one rebuilding a row, in a single cache command.
     *
     * Returns true when nobody else is rebuilding, and when the guard is switched
     * off with $rebuildLockTtl = 0.
     *
     * @param int|string $id
     * @return bool
     */
    protected function acquireRebuildLock($id)
    {
        if (!$this->rebuildLockTtl) {
            return true;
        }
        return (bool) self::$cache->acquireLock($this->cacheKey('lock.' . $id), $this->rebuildLockTtl);
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
        return $this->cacheKey($this->itemCacheKeyPart($id));
    }
    /**
     * Table scoped part of the key holding one row, the value get() and the
     * batched write back both pass to cacheSet()
     *
     * @param int|string $id
     * @return string
     */
    protected function itemCacheKeyPart($id)
    {
        return (string) $id;
    }
    /**
     * Turns a row loaded by getUncached() into the shape that belongs under
     * itemCacheKey(), for tables that cache a row per language or per tenant
     *
     * @param \ArrayObject $row
     * @return \ArrayObject
     */
    protected function prepareCachedRow($row)
    {
        return $row;
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
        unset(self::$localRows[$this->cacheKey($key)]);
        // A localized row is remembered per language, so drop those as well.
        $prefix = $this->cacheKey($key) . '.';
        foreach (array_keys(self::$localRows) as $remembered) {
            if (strpos($remembered, $prefix) === 0) {
                unset(self::$localRows[$remembered]);
            }
        }
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
        $wanted = [];
        $result = [];
        foreach ($ids as $id) {
            $row = $this->localRow($id);
            $result[$id] = $row;
            if (!$row) {
                $wanted[$id] = $this->itemCacheKey($id);
            }
        }

        $values = $wanted ? self::$cache->mget(array_values($wanted)) : [];
        if (!is_array($values)) {
            $values = [];
        }
        $values = array_combine(array_keys($wanted), array_pad($values, count($wanted), false));

        $missing = [];
        foreach ($ids as $id) {
            if ($result[$id]) {
                continue;
            }
            if (!empty($values[$id])) {
                $result[$id] = $values[$id];
                $this->rememberRow($id, $values[$id]);
                continue;
            }
            // The position is already held, so the caller keeps its order.
            $missing[] = $id;
        }

        if ($missing) {
            $loaded = $this->loadRows($missing);
            foreach ($missing as $id) {
                if (isset($loaded[$id])) {
                    $result[$id] = $loaded[$id];
                    $this->rememberRow($id, $loaded[$id]);
                } else {
                    // The row was removed between the id lookup and this fetch.
                    unset($result[$id]);
                }
            }
        }

        if ($publicOnly) {
            foreach ($result as $id => $row) {
                $result[$id] = $this->removePrivateFields($row);
            }
        }
        return $result;
    }
    /**
     * Loads the rows a batched read could not serve and caches them.
     *
     * One call to getUncachedMany() and one batched write back, instead of the
     * lock, read, query and write that fetching each row on its own would cost.
     *
     * @param array $ids
     * @return array<int|string, \ArrayObject>
     */
    protected function loadRows(array $ids)
    {
        $rows = [];
        $pairs = [];
        foreach ($this->getUncachedMany($ids) as $id => $row) {
            $row = $this->prepareCachedRow($row);
            $rows[$id] = $row;
            $pairs[$this->itemCacheKeyPart($id)] = $row;
        }
        $this->cacheSetMany($pairs);
        return $rows;
    }
    /**
     * Loads several rows at once.
     *
     * The default keeps the one row contract, so a model that only overrides
     * getUncached() behaves exactly as before. Override this as well to load a
     * whole batch and its relations in a few queries, which is what turns a cold
     * list from one query per row into a handful.
     *
     * @param array $ids
     * @return array<int|string, \ArrayObject> keyed by id, missing ids left out
     */
    public function getUncachedMany(array $ids)
    {
        $rows = [];
        foreach ($ids as $id) {
            try {
                $rows[$id] = $this->getUncached($id);
            } catch (Exception\ItemNotFoundException $e) {
                // A row that no longer exists is left out of the result.
            }
        }
        return $rows;
    }
    /**
     * Writes several rows in one batch
     *
     * @param array<string, mixed> $pairs key part => value
     * @param int $timeout
     */
    protected function cacheSetMany(array $pairs, $timeout = 0)
    {
        if (!$pairs) {
            return;
        }
        $prefixed = [];
        foreach ($pairs as $key => $value) {
            $prefixed[$this->cacheKey($key)] = $value;
        }
        self::$cache->setMany($prefixed, $timeout);
    }
    /**
     * Registers a batched read of these ids with a loader, instead of reading now.
     *
     * Nothing is fetched until the returned handle is read, and by then every
     * other read registered with the same loader is served in the same commands.
     *
     * @param BatchLoader $loader
     * @param array $ids
     * @param bool $publicOnly
     * @return Deferred
     */
    public function deferMget(BatchLoader $loader, array $ids, $publicOnly = false)
    {
        $ids = array_values($ids);
        $served = [];

        return $loader->defer(
            function () use ($ids, &$served) {
                return $served ? [] : array_map([$this, 'itemCacheKey'], $ids);
            },
            function (array $byKey) use ($ids, &$served): void {
                foreach ($ids as $id) {
                    $key = $this->itemCacheKey($id);
                    if (!empty($byKey[$key])) {
                        $served[$id] = $byKey[$key];
                    }
                }
            },
            function () use ($ids, &$served, $publicOnly) {
                return [$this->completeBatch($ids, $served, $publicOnly), null];
            },
        );
    }
    /**
     * Registers a batched find() with a loader.
     *
     * With findCacheTtl set this costs no round trip of its own: the ID list is
     * read in the loader's first batch and the rows in its second. Without it the
     * ID query still runs when the handle is read, because SQL cannot be batched
     * across tables without an event loop.
     *
     * @param BatchLoader $loader
     * @param array $params
     * @param int $limit
     * @param int $offset
     * @param string|bool|false $orderBy
     * @param bool $withTotal
     * @param bool $publicOnly
     * @return Deferred
     */
    public function deferFind(
        BatchLoader $loader,
        $params,
        $limit = 0,
        $offset = 0,
        $orderBy = false,
        $withTotal = false,
        $publicOnly = false
    ) {
        $ids = null;
        $total = $withTotal ? 0 : null;
        $served = [];
        $round = 0;

        return $loader->defer(
            function () use ($params, $limit, $offset, $orderBy, $withTotal, &$ids, &$round) {
                $round++;
                if ($round === 1) {
                    if (!$this->findCacheTtl) {
                        return [];
                    }
                    return [
                        $this->cacheKey('findgen'),
                        $this->cacheKey($this->buildFindCacheKey($params, $limit, $offset, $orderBy, $withTotal)),
                    ];
                }
                if ($ids === null) {
                    return [];
                }
                return array_map([$this, 'itemCacheKey'], $ids);
            },
            function (array $byKey) use ($params, $limit, $offset, $orderBy, $withTotal, &$ids, &$total, &$served): void {
                if ($ids === null) {
                    if ($this->findCacheTtl && !empty($byKey[$this->cacheKey('findgen')])) {
                        self::$findGenerations[$this->table] = $byKey[$this->cacheKey('findgen')];
                    }
                    $key = $this->findCacheTtl
                        ? $this->cacheKey($this->buildFindCacheKey($params, $limit, $offset, $orderBy, $withTotal))
                        : null;
                    $cached = $key !== null ? ($byKey[$key] ?? false) : false;
                    if ($this->entryIsCurrent($cached, $this->findGeneration())) {
                        $ids = $cached['ids'];
                        $total = $cached['total'];
                    } else {
                        $ids = $this->findIds($params, $limit, $offset, $orderBy, $total);
                    }
                    return;
                }
                foreach ($ids as $id) {
                    $key = $this->itemCacheKey($id);
                    if (!empty($byKey[$key])) {
                        $served[$id] = $byKey[$key];
                    }
                }
            },
            function () use (&$ids, &$total, &$served, $publicOnly) {
                return [$this->completeBatch($ids ?? [], $served, $publicOnly), $total];
            },
        );
    }
    /**
     * Fills the gaps a batched read left, in the order the caller asked for
     *
     * @param array $ids
     * @param array $served rows the cache already handed over, keyed by id
     * @param bool $publicOnly
     * @return array<int|string, mixed>
     */
    private function completeBatch(array $ids, array $served, $publicOnly)
    {
        $missing = [];
        foreach ($ids as $id) {
            if (!isset($served[$id])) {
                $missing[] = $id;
            }
        }
        if ($missing) {
            $served += $this->loadRows($missing);
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($served[$id])) {
                $result[$id] = $publicOnly ? $this->removePrivateFields($served[$id]) : $served[$id];
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

        // One command for the generation and the entry together, because the key
        // no longer depends on the token: an entry stamped with an older one is a
        // miss, and the next write overwrites it in place rather than orphaning it.
        [$generation, $cached] = $this->readWithGeneration($key);
        if (!$this->entryIsCurrent($cached, $generation)) {
            $cached = [
                self::GENERATION_FIELD => $generation,
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
    * Reads the table's generation token and one cached entry in a single command
    *
    * @param string $key
    * @return array{0: string, 1: mixed} the token and the entry
    */
    protected function readWithGeneration($key)
    {
        if (isset(self::$findGenerations[$this->table])) {
            return [self::$findGenerations[$this->table], $this->cacheGet($key)];
        }

        $values = self::$cache->mget([$this->cacheKey('findgen'), $this->cacheKey($key)]);
        $generation = is_array($values) && !empty($values[0]) ? $values[0] : null;
        if ($generation === null) {
            $generation = uniqid('', true);
            $this->cacheSet('findgen', $generation);
        }
        self::$findGenerations[$this->table] = $generation;

        return [$generation, is_array($values) ? ($values[1] ?? false) : false];
    }
    /**
    * True when a cached entry was written under the generation still in force
    *
    * @param mixed $entry
    * @param string $generation
    * @return bool
    */
    protected function entryIsCurrent($entry, $generation)
    {
        return is_array($entry)
            && array_key_exists('ids', $entry)
            && ($entry[self::GENERATION_FIELD] ?? null) === $generation;
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
        return 'find.' . substr(hash('sha256', $signature), 0, 12);
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
        $id = is_array($where) ? ($where[static::ID_COLUMN] ?? $where['id'] ?? null) : null;
        if ($clearCache && is_numeric($id)) {
            $this->cacheDelete($id);
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
        return (bool) $this->deleteRow($where, is_numeric($where) ? $where : null);
    }
    /**
    * Deletes and drops whatever the deleted rows leave behind in the cache
    *
    * @param Where|\Closure|string|array $where
    * @param int|string|null $id the row to forget, when the caller knows it
    * @return int affected rows
    */
    private function deleteRow($where, $id)
    {
        if (is_numeric($where)) {
            $where = [static::ID_COLUMN => $where];
        }
        $result = parent::delete($where);
        if ($result) {
            if ($id !== null) {
                $this->cacheDelete($id);
            }
            $this->cacheDelete('list');
            $this->invalidateFindCache();
        }
        return $result;
    }
    /**
    * Deletes record by id, removes cached data
    *
    * @param mixed $id
    * @returns bool
    */
    public function deleteById($id)
    {
        return $this->deleteRow([static::ID_COLUMN => $id], $id);
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
        // Only the ids are cached: the rows live in their own entries, so an
        // edited row shows through without rebuilding the list.
        $ids = $cachedList ? $this->cacheGet('list') : false;
        if (!is_array($ids)) {
            $column = static::ID_COLUMN;
            $select = $this->getSelect($this->table);
            if ($limit !== false) {
                $select->limit($limit);
            }
            if ($offset) {
                $select->offset($offset);
            }
            $ids = [];
            foreach ($this->execute($select) as $item) {
                $ids[] = $item->{$column};
            }
            unset($select);
            if ($cachedList) {
                $this->cacheSet('list', $ids);
            }
        }
        $res = $this->mget($ids);
        if (!is_null($total)) {
            $total = sizeof($res);
            if (!$offset || $total == $limit) {
                $total = $this->count();
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
        // parent::set() goes through update(), which already dropped this row,
        // the list and the find generation. Only the reload is left to do.
        $myId = parent::set($data, $id, false);
        if ($myId == $this->getId() && $setDataToObject) {
            $this->setId($this->getId());
        }
        return $myId;
    }
}
