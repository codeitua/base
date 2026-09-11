<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use ArrayObject;
use CodeIT\Model\CachedTable;
use CodeIT\Model\Exception\ItemNotFoundException;
use Laminas\Db\ResultSet\ResultSet;

/**
 * CachedTable without a database behind it: the id query and the row loader
 * are answered from fixtures and counted.
 */
class CachedTableProbe extends CachedTable
{
    /** @var array id => row fields */
    public $rows = [];

    /** @var array ids the id query returns */
    public $ids = [];

    /** @var int */
    public $findSimpleCalls = 0;

    /** @var int */
    public $getUncachedCalls = 0;

    /** @var int */
    public $getUncachedManyCalls = 0;

    /** @var array ids passed to delete() */
    public $deleted = [];

    /** @var int|null */
    public $queryTotal = null;

    public function __construct($table = 'news', $findCacheTtl = 0)
    {
        $this->table = $table;
        $this->findCacheTtl = $findCacheTtl;
    }

    public static function useCache(FakeCache $cache): void
    {
        self::$cache = $cache;
        self::$findGenerations = [];
    }

    public function usePrivateFields(array $fields): void
    {
        $this->privateFields = $fields;
    }

    public function invalidateLists(): void
    {
        $this->invalidateFindCache();
    }

    public function findSimple($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $columns = ['id'])
    {
        $this->findSimpleCalls++;
        if (!is_null($total)) {
            $total = $this->queryTotal ?? count($this->ids);
        }
        $rows = [];
        foreach ($this->ids as $id) {
            $rows[] = [static::ID_COLUMN => $id];
        }
        $resultSet = new ResultSet();
        $resultSet->initialize($rows);
        return $resultSet;
    }

    public function getUncachedMany(array $ids)
    {
        $this->getUncachedManyCalls++;
        return parent::getUncachedMany($ids);
    }

    public function getUncached($id)
    {
        $this->getUncachedCalls++;
        if (!isset($this->rows[$id])) {
            throw new ItemNotFoundException('news ' . $id . ' not found');
        }
        return new ArrayObject($this->rows[$id], ArrayObject::ARRAY_AS_PROPS);
    }

    public function delete($where)
    {
        $this->deleted[] = $where;
        return true;
    }

    public function getLock($name, $params = false, $timeout = 10)
    {
    }

    public function releaseLock($name, $params = false)
    {
    }
}
