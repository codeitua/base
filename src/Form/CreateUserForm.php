<?php

namespace CodeIT\Form;

use Laminas\InputFilter\Factory as InputFactory;
use Laminas\InputFilter\InputFilter;

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
    
        $this->add(array(
            'name' => 'email',
        ));

        $this->add(array(
            'name' => 'password',
            'type' => 'password',
        ));

        $this->add(array(
            'name' => 'level',
            'type' => '\Laminas\Form\Element\Select',
            'attributes' => array(
                'options' => array(
                    'registered' => _('Registered user'),
                    'admin' => _('Admin'),
                ),
            )
        ));
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

            $notemptyValidator = array(
                'name' => 'notEmpty',
                'options' => array (
                    'messages' => array(
                        \Laminas\Validator\NotEmpty::IS_EMPTY => _("This field is required"),
                    ),
                ),
                'break_chain_on_failure' => true,
            );

            $inputFilter->add($factory->createInput(array(
                'name' => 'email',
                'required' => true,
                'filters' => array(
                    array('name' => 'StripTags'),
                    array('name' => 'StringTrim'),
                ),
                'validators' => array(
                    $notemptyValidator,
                    new \CodeIT\Validator\EmailSimpleValidator(),
                    new \CodeIT\Validator\NotExistValidator(new \Application\Model\UserTable(), 'email', false, 'id', "This email is already taken. Please try another one"),
                    new \Laminas\Validator\StringLength(array(
                        'min' => 0,
                        'max' => 50,
                        'message' => _('Email can not be longer than 50 characters'),
                    )),
                ),
            )));

            $inputFilter->add($factory->createInput(array(
                'name' => 'password',
                'required' => true,
                'filters' => array(
                    array('name' => 'StringTrim'),
                ),
                'validators' => array(
                    new \Laminas\Validator\StringLength(array(
                        'min' => 8,
                        'message' => _('The input is less than 8 characters long'),
                    )),
                ),
            )));

            $inputFilter->add($factory->createInput(array(
                'name' => 'level',
                'required' => false,
                'validators' => array(
                    $notemptyValidator,
                ),
            )));

            $this->inputFilter = $inputFilter;
        }

        return $this->inputFilter;
    }
}
