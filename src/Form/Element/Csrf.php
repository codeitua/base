<?php

namespace CodeIT\Form\Element;

use CodeIT\Validator\CsrfValidator;
use Laminas\Form\Element;
use Laminas\InputFilter\InputProviderInterface;

class Csrf extends Element implements InputProviderInterface
{

    protected $attributes = array(
        'type' => 'hidden',
    );

    public function getValue()
    {
        return CsrfValidator::getCsrfToken();
    }

    public function getInputSpecification()
    {
        return [
            'name' => $this->getName(),
            'required' => true,
            'filters' => [
                ['name' => 'Laminas\Filter\StringTrim'],
            ],
            'validators' => [
                $this->getValidator(),
            ],
        ];
    }

    private function getValidator()
    {
        return new CsrfValidator();
    }
}
