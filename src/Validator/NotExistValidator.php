<?php
namespace CodeIT\Validator;

use CodeIT\Model\AppTable;
use Zend\Validator\AbstractValidator;
use Zend\Db\Sql\Expression;

class NotExistValidator extends AbstractValidator{

	/**
	 * \CodeIT\Model\AppTable
	 */
	private $model;
	private $field;
	private $id;
	private $idFiledName;

	const EXIST = 'aexist';

	protected $messageTemplates = array(
		self::EXIST => 'aexist',
	);

	/**
	 * @param \CodeIT\Model\AppTable
	 * @param string|[] $field
	 * @param int $id
	 * @param int $idFiledName
	 * @param int $message
	 */
	public function __construct(AppTable $model, $field, $id = false, $idFiledName = false, $message = 'Item with the same value already exists') {
		parent::__construct();
		$this->messageTemplates = array(
			self::EXIST => $message,
		);
		$this->setMessage($message);
		$this->model = $model;
		$this->id = $id;
		$this->field = $field;
		$this->idFiledName = $idFiledName ? $idFiledName : 'id';
	}

	public function isValid($value) {
		$where = array(array($this->field, '=', $value));
		if($this->id && is_array($this->id)){
			if(sizeof($this->id) > 1) {
				$expresion = new Expression();
				$sql = ' '.$this->idFiledName.' NOT IN ('.implode(",", $this->id).')';
				$expresion->setExpression($sql);
				$where[] = $expresion;
			}
		}
		else if($this->id) {
			$where[] = array($this->idFiledName, '!=', $this->id);
		}

		if($this->model->find($where)){
			$this->error(self::EXIST);
			return false;
		}
		return true;
	}

}
