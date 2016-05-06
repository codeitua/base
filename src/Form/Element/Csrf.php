<?php
namespace CodeIT\Form\Element;

use CodeIT\Validator\CsrfValidator;
use Zend\Form\Element;
use Zend\InputFilter\InputProviderInterface;

class Csrf extends Element implements InputProviderInterface {

	protected $attributes = array(
		'type' => 'hidden',
	);

	public function getValue() {
		return CsrfValidator::getCsrfToken();
	}

	public function getInputSpecification() {
		return [
			'name' => $this->getName(),
			'required' => true,
			'filters' => [
				['name' => 'Zend\Filter\StringTrim'],
			],
			'validators' => [
				$this->getValidator(),
			],
		];
	}

	private function getValidator() {
		return new CsrfValidator();
	}

}
