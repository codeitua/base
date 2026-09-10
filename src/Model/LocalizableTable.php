<?php

declare(strict_types=1);

namespace CodeIT\Model;

use CodeIT\Utils\Registry;
abstract class LocalizableTable extends CachedTable
{
    /**
     * Table with localized data
     *
     * @var string
     */
    protected $locTable;
    /**
     * Language Id
     *
     * @var int
     */
    protected $lang = 1;
    /**
     * List of fields for data with localized values
     *
     * @var array()
     */
    protected $localFields = [];
    /**
     * LocalizableTable constructor .
     * @param string $table
     */
    public function __construct($table, $id = null)
    {
        // Both have to be known before parent::__construct(), because passing
        // an id makes it load the row through getLocalData() straight away.
        $this->locTable = $table . 'local';
        $this->resolveLang();
        parent::__construct($table, $id);
    }
    /**
     * Takes the active language from the registry, keeping the default when
     * the application does not publish one
     */
    protected function resolveLang()
    {
        if (Registry::isRegistered('lang')) {
            $this->lang = (int) Registry::get('lang');
        }
    }
    /**
     * Returns row from db with specified slug
     *
     * @param string $name
     * @throws Exception\ItemNotFoundException
     * @return \ArrayObject
     */
    public function getByNameWithLang($name)
    {
        $key = base64_encode($name) . '.' . $this->lang;
        $item = $this->cacheGet($key);
        //\Laminas\Debug\Debug::dump($item); die;
        if (!$item) {
            $rows = $this->find([['name', '=', $name]], 1, 0);
            $item = array_pop($rows);
            if (!$item) {
                throw new Exception\ItemNotFoundException(sprintf(_('Item name "%s" not found'), $name), 2001);
            }
            $this->cacheSet($key, $item);
        }
        return $item;
    }
    /**
     * Returns row from db with specified id
     *
     * @param int $id
     * @return \ArrayObject
     */
    public function getUncached($id)
    {
        $item = parent::getUncached($id);
        $item->localData = $this->getLocalData(['id' => $id]);
        return $item;
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
        $item = $this->cacheGet($id . '.' . $this->lang);
        if (!$item) {
            // The complete row is cached; private fields are dropped per call
            // so that a publicOnly read cannot poison the cache for the rest.
            $item = parent::get($id);
            if (isset($item->localData) && isset($item->localData[$this->lang])) {
                $item2 = array_merge((array) $item, (array) $item->localData[$this->lang]);
                unset($item2['localData']);
                $item = new \ArrayObject($item2, \ArrayObject::ARRAY_AS_PROPS);
            }
            $this->cacheSet($id . '.' . $this->lang, $item);
        }
        return $publicOnly ? $this->removePrivateFields($item) : $item;
    }
    /**
     * Localized rows are cached per language, so mget() must read the same
     * keys get() writes
     *
     * @param int|string $id
     * @return string
     */
    protected function itemCacheKey($id)
    {
        return $this->cacheKey($id . '.' . $this->lang);
    }
    /**
     * Keeps cached find() results of one language apart from another's
     *
     * @return array
     */
    protected function findCacheScope()
    {
        return [$this->lang];
    }
    /**
    * Deletes cached value and all its local values
    *
    * @param string $key
    * @return bool
    */
    public function cacheDelete($key)
    {
        parent::cacheDeleteByMask($key);
        return true;
    }
    /**
    * get local data for localized items
    *
    * @param array $params
    * @param int limit
    * @param int $offset
    * @return array
    */
    public function getLocalData($params = [], $limit = null, $offset = 0)
    {
        $select = new \Laminas\Db\Sql\Select();
        $select->columns(['*']);
        $select->from(['cl' => $this->locTable]);
        if ($this->id) {
            $select->where(['id' => $this->id]);
        }
        //include all where settings
        if (isset($params) && is_array($params)) {
            $select->where($params);
        }
        //set user's limit if it's nessesary
        if (isset($limit)) {
            $select->limit($limit);
            //set user's offset if it's nessesary
            if (isset($offset)) {
                $select->offset($offset);
            }
        }
        $results = $this->execute($select);
        $res = [];
        foreach ($results as $result) {
            $res[$result->lang] = $result;
        }
        return $res;
    }
    /**
    * returns row from db with specified id and localData
    * in apropriate structure to fill form
    *
    * @param int $id
    * @return \ArrayObject
    */
    public function getFullLocalData($id)
    {
        $row = $this->getUncached($id);
        //get translations
        foreach ($row->localData as $locItem) {
            foreach ($this->localFields as $field) {
                if (!isset($row->{$field}) || !is_array($row->{$field})) {
                    $row->{$field} = [];
                }
                $row->{$field}[$locItem->lang] = $locItem->{$field};
            }
        }
        return $row;
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
        $this->updateLocData($data);
        parent::set($data, $id, $setDataToObject);
    }
    /**
    * Update or insert local data for localized items
    *
    * @param mixed $data
    */
    public function updateLocData($data)
    {
        $updateData = [];
        foreach ($data as $field => $values) {
            if (in_array($field, $this->localFields) && is_array($values)) {
                foreach ($values as $lang => $value) {
                    $updateData[$lang][$field] = $value;
                }
            }
        }
        foreach ($updateData as $lang => $data) {
            $tValues = [];
            $fields = [];
            $data['id'] = $this->id;
            $data['lang'] = $lang;
            foreach ($data as $key => $value) {
                $tValues[] = ':' . $key;
                $fields[] = $key;
            }
            $this->query('replace into `' . $this->locTable . '` (' . implode(', ', $fields) . ') values (' . implode(', ', $tValues) . ')', $data);
            $this->cacheDelete(base64_encode($this->name) . '.' . $lang);
        }
        $this->cacheDelete($this->id);
        if (!empty($this->name)) {
            $this->cacheDelete(base64_encode($this->name));
        }
        // Localized values are written with raw SQL, past update()
        $this->invalidateFindCache();
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
        $this->setId($id);
        $this->updateLocData($set);
        return $id;
    }
}
