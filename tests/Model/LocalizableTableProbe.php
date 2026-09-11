<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use ArrayObject;
use CodeIT\Model\Exception\ItemNotFoundException;
use CodeIT\Model\LocalizableTable;
use Laminas\Db\ResultSet\ResultSet;

/**
 * LocalizableTable without a database behind it, mirroring CachedTableProbe.
 */
class LocalizableTableProbe extends LocalizableTable
{
    /** @var array id => [lang => fields] */
    public $rows = [];

    /** @var array */
    public $ids = [];

    /** @var int */
    public $findSimpleCalls = 0;

    /** @var int */
    public $getUncachedCalls = 0;

    public function __construct($table = 'news', $findCacheTtl = 0)
    {
        $this->table = $table;
        $this->locTable = $table . 'local';
        $this->findCacheTtl = $findCacheTtl;
        $this->resolveLang();
    }

    public static function useCache(FakeCache $cache): void
    {
        self::$cache = $cache;
        self::$findGenerations = [];
        self::$localRows = [];
    }

    public function useLanguages(array $languages): void
    {
        $this->languages = $languages;
    }

    public function useLanguage($lang): void
    {
        $this->lang = $lang;
    }

    public function findSimple($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $columns = ['id'])
    {
        $this->findSimpleCalls++;
        $rows = [];
        foreach ($this->ids as $id) {
            $rows[] = [static::ID_COLUMN => $id];
        }
        $resultSet = new ResultSet();
        $resultSet->initialize($rows);
        return $resultSet;
    }

    public function getUncached($id)
    {
        $this->getUncachedCalls++;
        if (!isset($this->rows[$id])) {
            throw new ItemNotFoundException('news ' . $id . ' not found');
        }
        $localData = [];
        foreach ($this->rows[$id] as $lang => $fields) {
            $localData[$lang] = new ArrayObject($fields, ArrayObject::ARRAY_AS_PROPS);
        }
        $item = new ArrayObject(['id' => $id], ArrayObject::ARRAY_AS_PROPS);
        $item->localData = $localData;
        return $item;
    }

    public function getLock($name, $params = false, $timeout = 10)
    {
    }

    public function releaseLock($name, $params = false)
    {
    }
}
