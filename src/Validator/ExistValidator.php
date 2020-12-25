<?php

namespace CodeIT\Validator;

use Laminas\Validator\AbstractValidator;
use Laminas\Db\Sql\Expression;

class ExistValidator extends AbstractValidator
{

    /**
     * @var [] of \CodeIT\Model\AppTable
     */
    private $models;
    private $fields;

    const NOTEXIST = 'aexist';

    protected $messageTemplates = array(
        self::NOTEXIST => 'aexist',
    );

    /**
     * @param \CodeIT\Model\AppTable|[] $models
     * @param string|[] $fields
     * @param string $message
     */
    public function __construct($models, $fields, $message = 'Item is not found')
    {
        parent::__construct();
        $this->messageTemplates = array(
            self::NOTEXIST => $message,
        );
        $this->setMessage($message);
        if (!is_array($models)) {
            $models = [$models];
        }
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        foreach ($models as $model) {
            if (!is_subclass_of($model, '\CodeIT\Model\AppTable')) {
                throw new \Exception('Bad model passed: \CodeIT\Model\AppTable expected');
            }
        }
        $this->models = $models;
        $this->fields = $fields;
    }

    public function isValid($value)
    {
        foreach ($this->models as $model) {
            foreach ($this->fields as $field) {
                if ($model->select([$field => $value])->count()) {
                    return true;
                }
            }
        }
        
        $this->error(self::NOTEXIST);
        return false;
    }
}
