<?php
namespace CodeIT\Model;

use Zend\Db\ResultSet\ResultSet;
use Zend\Db\Sql;
use Zend\Db\Sql\AbstractSql;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Expression;

/**
 * specific PostgreSQL methods for AppTable
 */
abstract class AppTablePgSQL extends AppTable {
	
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
			$lastInsertValue = $this->adapter->getDriver()->getLastGeneratedValue("public.\"{$this->table}_id_seq\"");
                        return $lastInsertValue;
		}
		throw new \Exception('Insert to "'.$this->table.'" failed. Set was '.print_r($set, true));
	}
	
	public function getLock($name, $params = false, $timeout = 10)
	{
		$timeStart = time();
		$key = crc32($name);
		$result = false;
		while ($result === false) {
			$resultSet = $this->query("select pg_try_advisory_lock($key) as res");
			$result = $resultSet->current()->res;
			if(!$result) {
				if(time() - $timeStart > $timeout) {
					return false;
				}
				sleep(1);
			}
		}
		return true;		
	}
	
	public function releaseLock($name, $params = false)
	{
		$key = crc32($name);
		$this->query("select pg_advisory_unlock($key)");
	}
	
	public function buildSelect($columns) {
		$tColumns=[];
		foreach($columns as $alias => $column) {
			$selectColumn = '';
			if (is_object($column) && ($column instanceof Expression)) {
				$expression = $column->getExpression();
				$selectColumn = $expression;
			}
			elseif (is_array($column)) {
				$selectColumn = 'public."'.$column[0].'".'.$column[1].''; //to get columns from different tables when join
			} 
			else {
				if ($column == '*') {
					$selectColumn = 'public."'.$this->table.'".*';//to get all table columns
				}
				else {
					$selectColumn = 'public."'.$this->table.'"."'.$column.'"';
				}
			}
			if (is_string($alias) && !empty($alias)) {
				$selectColumn .= ' AS '.$alias;
			}
			$tColumns[] = $selectColumn;
		}

		return implode(', ', $tColumns);
	}
	
	protected function buildWhere($params) {
		if ($whereParams = $this->processWhereParams($params)) {
			return 'where '.implode(' AND ', $whereParams);
		}

		return '';
	}
	
	protected function buildSelectQuery($where, $limit=0, $offset=0, $orderBy=false, $columns=['id'], $join=false) {
		
		return 'select '.$this->buildSelect($columns).' from public."'.$this->table.'" '.$this->getFindJoin().' '.
			$where.
			($join ? $join : '').
			($this->getGroupBy() ? $this->getGroupBy() : '').
			' '.($this->getHaving() ? $this->getHaving() : '').
			($orderBy ? ' order by ' . $this->processOrderBy($orderBy) : '').
			($limit ? ' limit '.(int)$limit : '').
			($offset ? ' offset '.(int)$offset : '');
	}
	
	/**
	 * accept field name or array [{table name}, {field name + [direction]}]
	 * 
	 * @param string|array $orderBy
	 */
	protected function processOrderBy($orderBy) {
        if($orderBy instanceof Expression) {
            return $orderBy->getExpression();;
        }
		elseif(is_array($orderBy)) {
            $table = $orderBy[0];
            $field = $orderBy[1];
		}
		else {
            $table = $this->table;
            $field = $orderBy;
		}
        
        $fieldArr = explode(' ', $field);
        
        return 'public."'.$table.'"."'.$fieldArr[0].'"'.(isset($fieldArr[1]) ? " ".$fieldArr[1] : '');
	}
	
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
				$param[0] = substr_replace($param[0], '"', strpos($param[0], '.')+1, 0).'"';
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
	
	public function count($params = '') {
		$where = '';
		if (is_array($params))
			$where = $this->buildWhere($params);
		elseif (is_string($params))
			$where = $params;
		
		return $this->query('select count(*) cnt from public."'.$this->table.'" '.$this->findJoin.' '.$where. ($this->getGroupBy() ? $this->getGroupBy() : '').' '.($this->getHaving() ? $this->getHaving() : ''))->current()->cnt;
	}
	
	public function quoteValue($value) {
       return $this->getAdapter()->getPlatform()->quoteValue($value);
	}
	
}