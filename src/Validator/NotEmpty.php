<?php
namespace CodeIT\Validator;

class NotEmpty extends \Zend\Validator\NotEmpty {

    /**
     * Constructor
     *
     * @param  array|Traversable|int $options OPTIONAL
     */
    public function __construct($options = null) {
		$this->messageTemplates = [
	        static::IS_EMPTY => _('No fields complete'),
			static::INVALID  => _('Invalid type given'),
		];

		if(is_null($options)) {
			parent::__construct();
		}
		else {
			parent::__construct($options);
		}
	}

}
