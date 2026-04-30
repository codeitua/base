<?php

declare(strict_types=1);

namespace CodeIT\View\Helper;

use CodeIT\Utils\Registry;
use Laminas\View\Helper\AbstractHelper;
class User extends AbstractHelper
{
    public function __invoke()
    {
        try {
            $user = Registry::get('User');
        } catch (\Exception $e) {
            $user = $this->createAnonymousUser();
            try {
                $user->auth(false);
            } catch (\Exception $e) {
            }
            Registry::set('User', $user);
        }
        return $user;
    }
    private function createAnonymousUser() : \Application\Lib\User
    {
        return new \Application\Lib\User();
    }
}
