<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Utils;

use CodeIT\Utils\Registry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class RegistryTest extends TestCase
{
    protected function tearDown() : void
    {
        Registry::_unsetInstance();
    }
    public function testSetAndGetValue() : void
    {
        Registry::set('service', 'value');
        self::assertSame('value', Registry::get('service'));
        self::assertTrue(Registry::isRegistered('service'));
    }

    public function testNullValueIsRegistered() : void
    {
        Registry::set('nullable', null);

        self::assertTrue(Registry::isRegistered('nullable'));
        self::assertNull(Registry::get('nullable'));
    }

    public function testMissingKeyThrowsRuntimeException() : void
    {
        $this->expectException(RuntimeException::class);
        Registry::get('missing');
    }
}
