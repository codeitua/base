<?php

declare(strict_types=1);

namespace CodeIT\Form;

use Laminas\InputFilter\Factory as InputFactory;
use Laminas\InputFilter\InputFilter;
use Laminas\Form\Element\Password;
use Laminas\Validator\Callback;

class CreateUserForm extends Form
{
    /**
     * @var Laminas\InputFilter\InputFilter;
     */
    protected $inputFilter;
    /**
     * constructor
     *
     * @param string $level
     * @return CreateUserForm
     */
    public function __construct()
    {
        parent::__construct('createuser');
        $this->add([
            'name' => 'email',
        ]);
        $this->add([
            'name' => 'password',
            'type' => Password::class,
        ]);
        $this->add([
            'name' => 'level',
            'type' => \Laminas\Form\Element\Select::class,
            'attributes' => [
                'options' => [
                    'user' => _('User'),
                    'manager' => _('Manager'),
                    'hr' => _('HR'),
                    'admin' => _('Admin'),
                ],
            ],
        ]);
    }

    public function getMessages($elementName = null)
    {
        $errors = parent::getMessages($elementName);
        $result = '';
        if (!$elementName) {
            foreach ($errors as $field => $errorSet) {
                foreach ($errorSet as $erName => $erText) {
                    $result .= $field . ': ' . $erName . ' - ' . $erText . "\n";
                }
            }
        } else {
            foreach ($errors as $erName => $erText) {
                $result .= $erName . ' - ' . $erText . "\n";
            }
        }
        return $result;
    }
    public function getInpFilter()
    {
        if (!$this->inputFilter) {
            $inputFilter = new InputFilter();
            $factory = new InputFactory();
            $notemptyValidator = [
                'name' => 'notEmpty',
                'options' => [
                    'messages' => [
                        \Laminas\Validator\NotEmpty::IS_EMPTY => _('This field is required'),
                    ],
                ],
                'break_chain_on_failure' => true,
            ];
            $inputFilter->add($factory->createInput(['name' => 'email', 'required' => true, 'filters' => [['name' => 'StripTags'], ['name' => 'StringTrim']], 'validators' => [$notemptyValidator, new \CodeIT\Validator\EmailSimpleValidator(), new Callback(['callback' => static function ($value) {
                return !class_exists(\Application\Model\User::class) || \Application\Model\User::getByEmail($value) === false;
            }, 'messages' => [Callback::INVALID_VALUE => _('This email is already taken. Please try another one')]]), new \Laminas\Validator\StringLength(['min' => 0, 'max' => 50, 'message' => _('Email can not be longer than 50 characters')])]]));
            $inputFilter->add($factory->createInput(['name' => 'password', 'required' => true, 'filters' => [['name' => 'StringTrim']], 'validators' => [$notemptyValidator, new \Laminas\Validator\StringLength(['min' => 8])]]));
            $inputFilter->add($factory->createInput([
                'name' => 'level',
                'required' => false,
                'validators' => [$notemptyValidator],
            ]));
            $this->inputFilter = $inputFilter;
        }

        return $this->inputFilter;
    }
}
