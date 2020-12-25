<?php

namespace CodeIT\View\Helper;

use Application\Model\UserTable;
use CodeIT\Utils\Registry;
use Laminas\View\Helper\AbstractHelper;

class User extends AbstractHelper
{

    public function __invoke()
    {
        try {
            $user = Registry::get('User');
        } catch (\Exception $e) {
            $user = new \Application\Lib\User();
            try {
                $user->auth(false);
            } catch (\Exception $e) {
            }
            Registry::set('User', $user);
        }
        return $user;
    }
}
