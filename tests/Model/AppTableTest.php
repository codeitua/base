<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AppTableTest extends TestCase
{
    /**
     * find() conditions carry whatever the caller has, and this package is
     * strict about types.
     */
    public function testQuoteValueAcceptsNonStringConditionValues(): void
    {
        $table = (new ReflectionClass(CachedTableProbe::class))->newInstanceWithoutConstructor();

        self::assertSame("'1'", $table->quoteValue(1));
        self::assertSame("'2.5'", $table->quoteValue(2.5));
        self::assertSame("''", $table->quoteValue(null));
        self::assertSame("'O\\'Brien'", $table->quoteValue("O'Brien"));
        self::assertSame("'C:\\\\\\\\tmp'", $table->quoteValue('C:\\\\tmp'));
    }
}
