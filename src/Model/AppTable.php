<?php
namespace CodeIT\Model;

use CodeIT\Utils\Registry;
use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Adapter\Adapter;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\Sql;
use Zend\Db\Sql\AbstractSql;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Expression;

abstract class AppTable extends TableGateway {

	/**
	 * item ID
	 * 
	 * @var int
	 */
	public $id;

	/**
	 * List of fields from DB table
	 * 
	 * @var array
	 */
	protected $goodFields = [];

	/**
	 * Private fields list that should be removed from passing to clients
	 * 
	 * @var []
	 */
	protected $privateFields = [];

	/**
	 * Join clause for find() method, used for local tables
	 * 
	 * @var string
	 */
	protected $findJoin='';

	/**
	 * Group by condition for find() method
	 * 
	 * @var string
	 */
	protected $groupBy;
	
	/**
	 * Having condition for find() method
	 * 
	 * @var string
	 */
	protected $having;
	
	/**
	 * Table "ID" field name
	 */
	const ID_COLUMN = 'id';
	
	/**
	 * opened transactions counter 
	 * 
	 * @var int
	 */
	private $transactionsCounter;

	/**
	 * Creates table and sets id if neccessary
	 * @param string $tableName
	 * @param int $id
	 * @param mixed $databaseSchema
	 * @param ResultSet $selectResultPrototype
	 */
	public function __construct($tableName, $id=null, $databaseSchema = null, ResultSet $selectResultPrototype = null) {
		parent::__construct($tableName, $this->getAdapter(), $databaseSchema, $selectResultPrototype);

		if ($id) {
			$this->setId($id);
		}
	}

	/**
	 * @return \BjyProfiler\Db\Adapter\ProfilingAdapter|Adapter
	 */
	public function getAdapter() {
		if (!$this->adapter) {
			$this->adapter = Registry::get('sm')->get('dbAdapter');
		}

		return $this->adapter;
	}

	/**
	 * Returns Select instance
	 *
	 * @param null|string $table table name
	 * @return \Zend\Db\Sql\Select
	 */
	protected function getSelect($table = null) {
		if(!$table) {
			$table = $this->table;
		}

		return new Select($table);
	}

	/**
	 * Runs SQL query
	 *
	 * @param AbstractSql $sql
	 * @param array $params
	 * @return array|ResultSet
	 * @throws \Exception
	 */
	protected function execute(AbstractSql $sql, $params=array()) {
		try {
			$statement = $this->adapter->createStatement();
			$sql->prepareStatement($this->adapter, $statement);

			$resultSet = new ResultSet();
			$dataSource = $statement->execute($params);
			if($sql instanceof \Zend\Db\Sql\Insert) {
				return $dataSource->getGeneratedValue();
			}
			elseif($sql instanceof \Zend\Db\Sql\Update) {
				return $dataSource->getAffectedRows();
			}
			$resultSet->initialize($dataSource);
			return $resultSet;
		}
		catch(\Exception $e) {
			if(DEBUG) {
				$previousMessage = '';
				if($e->getPrevious()) {
					$previousMessage = ': '.$e->getPrevious()->getMessage();
				}
				throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage."<br>
					SQL Query was:<br><br>\n\n".$sql->getSqlString($this->adapter->platform));
				//\Zend\Debug::dump($e);
			}
		}
		return array();
	}

	/**
	 * Makes and executes SQL query
	 *
	 * @param string $query
	 * @param mixed $params
	 * @return mixed
	 * @throws \Exception
	 */
	protected function query($query, $params=false) {
		if(!$params) {
			$params = Adapter::QUERY_MODE_EXECUTE;
		}
		try {
			$resultSet = $this->adapter->query($query, $params);
		}
		catch(\Exception $e) {
			if(DEBUG) {
				$previousMessage = '';
				if($e->getPrevious()) {
					$previousMessage = ': '.$e->getPrevious()->getMessage();
				}
				throw new \Exception('SQL Error: '.$e->getMessage().': '.$previousMessage."<br>
					SQL Query was:<br><br>\n\n".$query."<br>params: ".print_r($params, true));
			}
		}
		return $resultSet;
	}


	/**
	 * Acquires lock (mutex)
	 *
	 * @param string $name
	 * @param bool|false $params
	 * @param int $timeout
	 * @throws \Exception
	 */
	public function getLock($name, $params=false, $timeout=10) {
		$resultSet = $this->query("select GET_LOCK(".$this->quoteValue($name).", $timeout) as res", $params);
		$result = $resultSet->current()->res;
		if (!$result) {
			throw new Exception\CannotGetLockException('Could not obtain lock on '.$name);
		}
	}

	/**
	 * Releases lock (mutex) obtained by getLock
	 *
	 * @param string $name
	 * @param array|bool|false $params
	 */
	public function releaseLock($name, $params=false) {
		$this->query("select RELEASE_LOCK(".$this->quoteValue($name).")", $params);
	}

	/**
	 * Starts transaction
	 */
	public function startTransaction() {
		if(!$this->transactionsCounter) {
			$this->adapter->getDriver()->getConnection()->beginTransaction();
		}
		$this->transactionsCounter++;
	}

	/**
	 * Commits transaction
	 */
	public function commit() {
		$this->transactionsCounter--;
		if(!$this->transactionsCounter) {
			$this->adapter->getDriver()->getConnection()->commit();
		}
	}

	/**
	 * Rollbacks transaction
	 */
	public function rollback() {
		$this->transactionsCounter--;
		if(!$this->transactionsCounter) {
			$this->adapter->getDriver()->getConnection()->rollback();
		}
	}

	/**
	 * Inserts a record
	 *
	 * @param array$set
	 * @return int
	 * @throws \Exception
	 */
	public function insert($set) {
		$set = $this->removeUnnecessaryFields($set);
		if(parent::insert($set)) {
			return $this->lastInsertValue;
		}
		throw new \Exception('Insert to "'.$this->table.'" failed. Set was '.print_r($set, true));
	}

	/**
	 * Searches for items, fetching them by ::get()
	 * 
	 * @param array $params, e.g. ['id', '>=', '135']
	 * @param int $limit, set to 0 or false to no limit
	 * @param int $offset
	 * @param string|bool|false $orderBy
	 * @param int &$total will be set to total count found
	 * @param bool $publicOnly should we return full data or non-private fields only
	 * @return ResultSet|[]
	 */
	public function find($params, $limit=0, $offset=0, $orderBy=false, &$total=null, $publicOnly=false) {
		$items = $this->findSimple($params, $limit, $offset, $orderBy, $total, ['*']);
		if($publicOnly) {
			$result = [];
			foreach($items as $item) {
				$result[$item->id] = $this->removePrivateFields($item);
			}
			unset($items);
		}
		else {
			$result = $items;
		}

		return $result;
	}

	/**
	 * Searches for items and returns ResultSet
	 * 
	 * @param array $params, e.g. arrat('id', '>=', '135')
	 * @param int $limit, set to 0 or false to no limit
	 * @param int $offset
	 * @param string|bool|false $orderBy
	 * @param int &$total will be set to total count found
	 * @param array $columns fileds which should be inclued in the result
	 * @return ResultSet
	 */
	public function findSimple($params, $limit=0, $offset=0, $orderBy=false, &$total=null, $columns=['id']) {
		$where = $this->buildWhere($params);
		if(!is_null($total)) {
			$total = $this->count($where);
		}

		return $this->query($this->buildSelectQuery($where, $limit, $offset, $orderBy, $columns));
	}

	/**
	 * Builds where condition string from the $params array
	 * 
	 * @param array $params
	 * @return string
	 */
	protected function buildWhere($params) {
		if ($whereParams = $this->processWhereParams($params)) {
			return 'where '.implode(' AND ', $whereParams);
		}

		return '';
	}
	
	/**
	 * Builds the select query from arguments
	 * 
	 * @param string $where
	 * @param integer $limit
	 * @param integer $offset
	 * @param string|bool|false $orderBy
	 * @param array $columns
	 * @return string
	 */
	protected function buildSelectQuery($where, $limit=0, $offset=0, $orderBy=false, $columns=['id'], $join=false) {
		return 'select '.$this->buildSelect($columns).' from `'.$this->table.'` '.$this->getFindJoin().' '.
			$where.
			($join ? $join : '').
			($this->getGroupBy() ? $this->getGroupBy() : '').
			' '.($this->getHaving() ? $this->getHaving() : '').
			($orderBy ? ' order by '.$orderBy : '').
			($limit ? ' limit '.((int)$offset) .', '.((int)$limit) : '');
	}

	/**
	 * Builds a select condition using the $columns
	 * 
	 * @param array $columns
	 * @return type
	 */
	protected function buildSelect($columns) {
		$tColumns=[];
		foreach($columns as $alias => $column) {
			$selectColumn = '';
			if (is_object($column) && ($column instanceof Expression)) {
				$expression = $column->getExpression();//
				$selectColumn = $expression;
			}
			elseif (is_array($column)) {
				$selectColumn = '`'.$column[0].'`.'.$column[1].''; //to get columns from different tables when join
			} else {
				if ($column == '*')
					$selectColumn = '`'.$this->table.'`.*';//to get all table columns
				else 
					$selectColumn = '`'.$this->table.'`.`'.$column.'`';
			}
			if (is_string($alias) && !empty($alias)) {
				$selectColumn .= ' AS '.$alias;
			}
			$tColumns[] = $selectColumn;
		}

		return implode(', ', $tColumns);
	} 

	/**
	 * process array of where paramethers and 
	 * convert to array of paramethers as strings 
	 * @param array $params
	 * @return array
	 */
	protected function processWhereParams($params) {
		$platform = $this->getAdapter()->getPlatform();
		$whereParams = array();
		foreach($params as $param) {
			$whereParams []= $this->prepareParam($param) ;
		}
		return $whereParams;
	}

	/**
	 * Processes condition array into string
	 *
	 * @param mixed $param
	 * @return string
	 * @throws \Exception
	 */
	protected function prepareParam($param) {
		$platform = $this->getAdapter()->getPlatform();
		if ($param instanceof Expression) {
			$set = $param->getExpression();
		} else if (is_string($param)) {
			$set = $param;
		} else {
			if (is_object($param[0]) && ($param[0] instanceof Expression)) {
				$expression = $param[0]->getExpression();
				$param[0] = $expression;
			}
			else if (strpos($param[0], '.') === false) {
				$param[0] = $platform->quoteIdentifierChain($param[0]);
			} else {
				$param[0] = substr_replace($param[0], "`", strpos($param[0], '.')+1, 0).'`';
			}
			$set = $param[0] . ' ' . $param[1] . ' ';
			if (strtolower($param[1]) == 'in') {
				if (is_array($param[2])) {
					$set .= '(';
					$list = array();
					foreach ($param[2] as $par) {
						$list[] = $this->quoteValue($par);
					}
					$set .= implode(',', $list) . ')';
				} else {
					$set .= '(\'' . $this->quoteValue($param[2]) . '\')';
				}
			}
			else if (strtolower($param[1]) == 'like') {
				$set .= $this->quoteValue($param[2]);
				if (!empty($param[3])) { // add escape character
					$set .= ' ESCAPE "'.$param[3].'"';
				}
			}
			else if (isset($param[2])) {
				$set .= $this->quoteValue($param[2]);
			} else {
				$set .= 'NULL';
			}
		}
		return $set;
	}

	/**
	 * Returns the row counts by the $params
	 *
	 * @param array|string $params
	 * @return mixed
	 */
	public function count($params = '') {
		$where = '';
		if (is_array($params))
			$where = $this->buildWhere($params);
		elseif (is_string($params))
			$where = $params;
		
		return $this->query('select count(*) cnt from `'.$this->table.'` '.$this->findJoin.' '.$where. ($this->getGroupBy() ? $this->getGroupBy() : '').' '.($this->getHaving() ? $this->getHaving() : ''))->current()->cnt;
	}

	/**
	 * Sets group by closer
	 * @param string $group
	 */
	public function setGroupBy($group) {
		$this->groupBy = $group;
	}

	/**
	 * Gets group by closer
	 * 
	 * @return string
	 */
	public function getGroupBy() {
		return $this->groupBy;
	}

	/**
	 * Sets having closer
	 * 
	 * @param string $having
	 */
	public function setHaving($having) {
		$this->having = $having;
	}

	/**
	 * Gets having closer
	 * 
	 * @return string $having
	 */
	public function getHaving() {
		return $this->having;
	}

	/**
	 * Creates item, sets id.
	 *
	 * @param array $params
	 * @return id
	 */
	public function create($params) {
		$id = $this->insert($params);
		$this->setId($id);
		return $id;
	}

	/**
	 * Returns current id
	 *
	 * @return $id int
	 */
	public function getId() {
		return $this->{static::ID_COLUMN};
	}

	/**
	 * Sets Id. Checks whether entry exists.
	 *
	 * @param int $id
	 * @returns item
	 */
	public function setId($id) {
		$this->{static::ID_COLUMN } = $id;
		$item = $this->get($id);

		foreach($item as $field => $value) {
			if(property_exists($this, $field)) {
				$this->$field = $value;
			}
		}

		return $item;
	}

	/**
	 * Returns row from db with specified id
	 *
	 * @param int $id
	 * @param bool|false $publicOnly
	 * @return \ArrayObject
	 * @throws \Exception
	 */
	public function get($id, $publicOnly=false) {
		$row = $this->select(array(static::ID_COLUMN => $id))
			->current();
		if(!$row) {
			throw new Exception\ItemNotFoundException(ucfirst($this->table).' '.$id.' not found');
		}

		if ($publicOnly) {
			$row = $this->removePrivateFields($row);
		}
		
		return $row;
	}

	/**
	 * Removes fields marked as private from public content (used in ::get())
	 * 
	 * @param \ArrayObject $item
	 * @returns \ArrayObject $item
	 */
	public function removePrivateFields($item) {
		if(is_object($item)) {
			$item = clone $item;
		}
		foreach($this->privateFields as $field) {
			unset($item[$field]);
		}
		
		return $item;
	}

	/**
	 * sets data for current id
	 *
	 * @param array $data
	 * @param int|bool|false $id
	 * @param bool $setDataToObject perform setId() call after update
	 */
	public function set($data, $id=false, $setDataToObject=true) {
		$myId = $this->{static::ID_COLUMN};
		if($id) {
			$myId = $id;
		}
		$this->update($data, [static::ID_COLUMN => $myId]);
		if(($myId == $this->{static::ID_COLUMN}) && $setDataToObject)
			$this->setId($this->{static::ID_COLUMN});
	}

    /**
     * Update
     *
     * @param array                $params
     * @param string|array|closure $where
     * @param null|array           $joins
     * @return int affected rows
     */
    public function update($params, $where = null, array $joins = null)
    {
        $params = $this->removeUnnecessaryFields($params);
        if (empty($params)) {
            return 0;
        }

        $result = parent::update($params, $where);

        return $result;
    }

	/**
	 * Deletes record by id
	 * 
	 * @param mixed $id
	 * @returns altered rows
	 */
	public function deleteById($id) {
		$rowsAffected = $this->delete(array(static::ID_COLUMN => $id));

		return $rowsAffected;
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
		}
		else {
			$result = parent::delete($where);
		}

		return (bool)$result;
	}

	/**
	 * Returns full items list
	 *
	 * @param int|bool|false $limit
	 * @param int $offset
	 * @param int|null $total
	 * @return ResultSet
	 * @throws \Exception
	 */
	public function getList($limit=false, $offset=0, &$total=null) {
		$select = $this->getSelect($this->table);
		if ($limit !== false) {
			$select->limit($limit);
		}
		if ($offset) {
			$select->offset($offset);
		}

		$list = $this->execute($select);
		if (!is_null($total)) {
			$total = $this->query('select count(*) cnt from '.$this->table)->current()->cnt;
		}

		return $list;
	}

	/**
	 * Returns column value from 1st line of query
	 * 
	 * @param string $query
	 * @param array $params
	 * @returns string value
	 */
	public function getCell($query, $params=array()) {
		$q = (array)$this->query($query, $params)->current();
		return current($q);
	}

	/**
	 * Replace for bad platform function
	 * 
	 * @param string $value
	 * @return string
	 */
	function quoteValue($value) {
		$res = str_replace('\\', '\\\\', $value);
		$res = str_replace('\'', '\\\'', $res);
		return '\'' . $res . '\'';
	}

	protected function removeUnnecessaryFields($params){
		$params = (array)$params;
		foreach($params as $key => $field) {
			if(!in_array($key, $this->goodFields)) {
				unset($params[$key]);
			}
			else {
				if($field===false){
					$params[$key] = 0;
				}
			}
		}
		return $params;
	}

	/**
	 * Sets join expression for find() method
	 * 
	 * @param string $join
	 */
	public function setFindJoin($join) {
		$this->findJoin = $join;
	}

	/**
	 * Returns join expression for find() method
	 * 
	 * @return string
	 */
	public function getFindJoin() {
		return $this->findJoin;
	}

}
