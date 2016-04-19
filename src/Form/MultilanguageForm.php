<?php
namespace CodeIT\Form;

abstract class MultilanguageForm extends Form {
	
	protected $langs = [];
	
	public function __construct() {
		parent::__construct();
		$this->setLangs();
	}

	protected function setLangs() {
		$langTable = new \Application\Model\LangTable();
		$list = $langTable->find([]);
		foreach($list as $item) {
			$this->langs[] = $item['id'];
		}
	}

	/**
     * Set data to validate and/or populate elements
     *
     * Typically, also passes data on to the composed input filter.
     *
     * @param  array|\ArrayAccess|Traversable $data
     * @return Form|FormInterface
     * @throws Exception\InvalidArgumentException
     */
    public function setData($data) {
		foreach($data as $field=>$value){
			if (is_array($value)) {
				foreach($value as $lang=>$langVal){
					$data[$field.'['.$lang.']'] = ($langVal);
				}
			}
		}
		parent::setData($data);
		return $this;
	}
	
	 /**
     * Retrieve the validated data
     *
     * By default, retrieves normalized values; pass one of the
     * FormInterface::VALUES_* constants to shape the behavior.
     *
     * @param  int $flag
     * @return array|object
     * @throws Exception\DomainException
     */
	public function getData($flag = \Zend\Form\FormInterface::VALUES_NORMALIZED) {
		$data = parent::getData($flag);
		foreach ($this->data as $field => $fieldValue) {
			if (is_array($this->data[$field])) {
				foreach ($this->data[$field] as $lang => $value) {
					if(!is_numeric($lang))
						break;
					$data[$field][$lang] = $data[$field.'['.$lang.']'];
				}
			}
		}
		return $data;
	}
}
