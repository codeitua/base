<?php
namespace CodeIT\Model;

use Application\Lib\Redis as RedisCache;
use CodeIT\Model\Exception\ItemNotFoundException;
use CodeIT\Utils\Registry;
use Zend\Db\ResultSet\ResultSet;

class CachedTable extends AppTable {

	/**
	 * @var RedisCache
	 */
	protected static $cache = null;

	/**
	 * Creates table and sets id if necessary
	 *
	 * @param string $tableName
	 * @param int $id
	 */
	public function __construct($tableName, $id=null) {
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
	public function get($id, $publicOnly=false) {
		$row = $this->cacheGet($id);

		if(!$row) {
			$lockName = $this->getLockName($id);
			$this->getLock($lockName, false, 10);
			// check for data once more
			$row = $this->cacheGet($id);
			if(!$row) {
				$row = $this->getUncached($id);
				$this->cacheSet($id, $row);
			}
			$this->releaseLock($lockName);
		}

		if($publicOnly) {
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
	private function getLockName($id) {
		return SITE_NAME.'.'.$this->table.'.'.$id;
	}

	/**
	 * Returns row from db with specified id
	 *
	 * @param int $id
	 * @return \ArrayObject
	 */
	public function getUncached($id) {
		return parent::get($id);
	}

	/**
	 * Gets cached value
	 *
	 * @param string $key
	 * @return string
	 */
	public function cacheGet($key) {
		return self::$cache->get('table.'.$this->table.'.'.$key);
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
	public function cacheSet($key, $value, $timeout = 0, $try=0) {
		return self::$cache->set('table.'.$this->table.'.'.$key, $value, $timeout, $try);
	}

	/**
	 * Deletes cached value
	 *
	 * @param string $key
	 * @return int
	 */
	public function cacheDelete($key) {
		return self::$cache->deleteCache('table.'.$this->table.'.'.$key);
	}

	/**
	 * Deletes cached values with keys that start with name
	 *
	 * @param string $keyMask
	 */
	public function cacheDeleteByMask($keyMask) {
		self::$cache->deleteByMask('table.'.$this->table.'.'.$keyMask);
	}

	/**
	 * Returns rows from db with specified id
	 *
	 * @param array $ids
	 * @param bool $publicOnly remove private fields
	 * @return \ArrayObject[]
	 */
	public function mget($ids, $publicOnly=false) {
		$keys = [];
		foreach ($ids as $id) {
			$keys[]='table.'.$this->table.'.'.$id;
		}
		$values = self::$cache->mget($keys);
		$result = [];
		foreach ($ids as $num => $id) {
			if (isset($values[$num]) && !empty($values[$num])) {
				if ($publicOnly) {
					$result[$id] = $this->removePrivateFields($values[$num]);
				} else {
					$result[$id] = $values[$num];
				}
			} else {
				try {
					$result[$id] = $this->get($id, $publicOnly);
				} catch(ItemNotFoundException $e) {
					$this->delete($id);
				}
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
	public function insert($set) {
		$id = parent::insert($set);
		$this->cacheDelete('list');
		return $id;
	}

	/**
	 * Searches for items, fetching them by ::get()
	 * 
	 * @param array $params, e.g. array('id', '>=', '135')
	 * @param int $limit, set to 0 or false to no limit
	 * @param int $offset
	 * @param string|bool|false $orderBy
	 * @param int &$total will be set to total count found
	 * @param bool $publicOnly should we return full data or non-private fields only
	 * @return \ArrayObject
	 */
	public function find($params, $limit=0, $offset=0, $orderBy=false, &$total=null, $publicOnly=false) {
		$ids = $this->findSimple($params, $limit, $offset, $orderBy, $total, [static::ID_COLUMN])->toArray();
		$ids = array_column($ids, 'id');
		return $this->mget($ids, $publicOnly);
	}

	/**
	 * Update
	 *
	 * @param  array $params
	 * @param  string|array|closure $where
	 * @param  bool $clearCache
	 * @return int affected rows
	 */
	public function update($params, $where = null, $clearCache=true) {
		$result = parent::update($params, $where);
		if($clearCache && is_array($where) && isset($where['id']) && is_numeric($where['id'])) {
			$this->cacheDelete($where['id']);
		}
		$this->cacheDelete('list');

		return $result;
	}

	/**
	 * Deletes item
	 *
	 * @param Where|\Closure|string|array $where: Item ID or expression
	 * @return bool: true on OK, false on item not found
	 */
	public function delete($where) {
		if(is_numeric($where)) {
			$result = parent::delete(array(static::ID_COLUMN => $where));
			if($result)
				$this->cacheDelete($where);
		}
		else {
			$result = parent::delete($where);
		}

		return (bool)$result;
	}

	/**
	 * Deletes record by id, removes cached data
	 * 
	 * @param mixed $id
	 * @returns bool
	 */
	public function deleteById($id) {
		$rowsAffected = parent::delete([static::ID_COLUMN => $id]);
		$this->cacheDelete($id);
		$this->cacheDelete('list');
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
	public function getList($limit=false, $offset=0, &$total=null) {
		$cachedList = true;
		if($limit || $offset) {
			$cachedList = false;
		}

		if($cachedList && $list = $this->cacheGet('list')) {
			if(!is_null($total)) {
				$total = sizeof($list);
			}
			return $list;
		}
		
		$column = static::ID_COLUMN;
		$select = $this->getSelect($this->table);
		if($limit !== false) {
			$select->limit($limit);
		}
		if($offset) {
			$select->offset($offset);
		}

		$list = $this->execute($select);
		unset($select);

		$res = array();
		foreach($list as $item) {
			$res[$item->$column] = $this->get($item->$column);
		}
		
		if ($cachedList) {
			$this->cacheSet('list', $res);
		}
		
		if(!is_null($total)) {
			$total = sizeof($res);
			if (!$offset || $total == $limit) {
				$total = $this->query('select count(*) cnt from '.$this->table)->current()->cnt;
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
	public function set($data, $id=false, $setDataToObject=true) {
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
