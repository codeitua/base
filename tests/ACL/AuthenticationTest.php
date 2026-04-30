<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\ACL;

use CodeIT\ACL\Authentication;
use CodeIT\Utils\Registry;
use Laminas\Mvc\Controller\AbstractActionController;
use PHPUnit\Framework\TestCase;
final class AuthenticationTest extends TestCase
{
    protected function tearDown() : void
    {
        Registry::_unsetInstance();
    }
    public function testAllowedActionContinuesDispatch() : void
    {
        Registry::set('User', new class
        {
            public function getRole() : string
            {
                return 'admin';
            }
        });
        $acl = new class
        {
            public function call(string $method, array $args) : bool
            {
                return match ($method) {
                    'hasResource', 'isAllowed' => true,
                    default => false,
                };
            }
        };
        $controller = new class extends AbstractActionController
        {
        };
        $auth = new Authentication();
        $auth->setAclClass($acl);
        self::assertFalse($auth->preDispatch(['controller' => 'users', 'action' => 'index'], $controller));
    }
}
