<?php

namespace CodeIT\Validator;

use Laminas\Validator\AbstractValidator;

class EmailSimpleValidator extends AbstractValidator
{

    const INVALID_FORMAT = 'invalidFormat';

    protected $messageTemplates = [
        self::INVALID_FORMAT => 'Email not valid',
    ];

    /**
     * Returns array of validation failure messages
     *
     * @return array
     */
    public function getMessages()
    {
        $result = parent::getMessages();
        if (!empty($result)) {
            $result = [
                self::INVALID_FORMAT => _('Not a valid email format'),
            ];
        }
        return $result;
    }

    public function isValid($value)
    {
        //set validation for each email part
        $user = '[a-zA-Z0-9_\-\.\+\^!#\$%&*+\/\=\?\`\|\{\}~\']+';
        $domain = '.+';

        if (empty($value) || ! preg_match("/^$user@($domain)$/", $value)) {
            $this->error(self::INVALID_FORMAT);
            return false;
        }
        return true;
    }
}
