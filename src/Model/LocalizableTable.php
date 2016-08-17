<?php
namespace CodeIT\Model;

abstract class LocalizableTable extends CachedTable {

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
	 * LocalizableTable constructor.
	 * @param string $table
	 */
	public function __construct($table, $id=null) {
		parent::__construct($table, $id);
		$this->locTable = $this->table.'local';
	}

	/**
	 * Returns row from db with specified slug
	 *
	 * @param string $name
	 * @throws Exception\ItemNotFoundException
	 * @return \ArrayObject
	 */
	public function getByNameWithLang($name) {
		$key = base64_encode($name).'.'.$this->lang;
		$item = $this->cacheGet($key);
		if(!$item) {
			$rows = $this->find([
				['name', '=', $name],
				], 1, 0);
			$item = array_pop($rows);
			if (!$item) {
				throw new Exception\ItemNotFoundException(
					sprintf(_('Item name "%s" not found'), $name), 2001);
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
	public function getUncached($id) {
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
	public function get($id, $publicOnly=false) {
		$item = $this->cacheGet($id.'.'.$this->lang);
		if(!$item) {
			$item = parent::get($id, $publicOnly);
			if(isset($item->localData) && isset($item->localData[$this->lang])) {
				$item2 = array_merge((array)$item, (array)$item->localData[$this->lang]);
				unset($item2['localData']);
				$item = new \ArrayObject($item2, \ArrayObject::ARRAY_AS_PROPS);
			}
			$this->cacheSet($id.'.'.$this->lang, $item);
		}

		return $item;
	}

	/**
	 * Returns rows from db with specified id
	 *
	 * @param array $ids
	 * @return \ArrayObject
	 */
	public function mget($ids, $publicOnly=false) {
		$keys = [];
		foreach($ids as $id) {
			$keys[]='table.'.$this->table.'.'.$id.'.'.$this->lang;
		}
		$values = self::$cache->mget($keys);
		$result = [];
		foreach($ids as $num => $id) {
			if(isset($values[$num]) && !empty($values[$num])) {
				$result[$id] = $values[$num];
			}
			else {
				$result[$id] = $this->get($id);
			}
		}

		return $result;
	}

	/**
	 * Deletes cached value and all its local values
	 *
	 * @param string $key
	 * @return bool
	 */
	public function cacheDelete($key) {
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
	public function getLocalData($params=[], $limit = null, $offset = 0) {
		$select = new \Zend\Db\Sql\Select;
		$select->columns(array('*'));
		$select->from(array('cl'=>$this->locTable));
		if($this->id) {
			$select->where(['id' => $this->id]);
		}

		//include all where settings
		if (isset($params) && is_array($params)){
			$select->where($params);
		}
		//set user's limit if it's nessesary
		if (isset($limit)){
			$select->limit($limit);

			//set user's offset if it's nessesary
			if (isset($offset)){
				$select->offset($offset);
			}
		}

		$results = $this->execute($select);
		$res = [];
		foreach($results as $result) {
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
	public function getFullLocalData($id) {
		$row = $this->getUncached($id);
		//get translations
		foreach($row->localData as $locItem){
			foreach ( $this->localFields as $field){
				if (!isset($row->{$field}) || !is_array($row->{$field})){
					$row->{$field} = array();
				}

				$row->{$field}[$locItem->lang] = $locItem->$field;
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
	public function set($data, $id=false, $setDataToObject=true) {
		$this->updateLocData($data);
		parent::set($data, $id, $setDataToObject);
	}

	/**
	 * Update or insert local data for localized items
	 *
	 * @param mixed $data
	 */
	public function updateLocData($data) {
		$updateData = [];
		foreach ($data as $field => $values) {
			if(in_array($field, $this->localFields) && is_array($values)) {
				foreach($values as $lang => $value) {
					$updateData[$lang][$field] = $value;
				}
			}
		}

		foreach($updateData as $lang => $data) {
			$tValues = []; $fields = [];
			$data['id'] = $this->id;
			$data['lang'] = $lang;
			foreach($data as $key => $value) {
				$tValues []= ':'.$key;
				$fields []= $key;
			}
			$this->query('replace into `'.$this->locTable.'` ('.implode(', ', $fields).') values ('.implode(', ', $tValues).')', $data);
		}

		$this->cacheDelete($this->id);

		if (!empty($this->name)) {
			$this->cacheDelete(base64_encode($this->name));
		}
	}

	/**
	 * Inserts a record
	 *
	 * @param array $set
	 * @return int last insert Id
	 */
	public function insert($set) {
		$id = parent::insert($set);
		$this->setId($id);
		$this->updateLocData($set);

		return $id;
	}

}
