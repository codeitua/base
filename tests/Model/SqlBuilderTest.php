<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use CodeIT\Model\SqlBuilder;
use Laminas\Db\Sql\Expression;
use PHPUnit\Framework\TestCase;

/**
 * The strings here are what AppTable produced before the assembly moved out, so
 * a change in spacing or quoting shows up as a failure rather than as a subtly
 * different query.
 */
final class SqlBuilderTest extends TestCase
{
    private static function quote($value): string
    {
        return "'" . str_replace("'", "\\'", (string) $value) . "'";
    }

    private static function quoteIdentifier(string $column): string
    {
        return '`' . $column . '`';
    }

    public function testFromCarriesTheJoinAndKeepsItsSpacing(): void
    {
        self::assertSame(' from `news`  ', SqlBuilder::from('news'));
        self::assertSame(' from `news` join x on x.id = news.id ', SqlBuilder::from('news', 'join x on x.id = news.id'));
    }

    public function testColumnsRenderEveryAcceptedForm(): void
    {
        self::assertSame('`news`.`id`', SqlBuilder::columns('news', ['id']));
        self::assertSame('`news`.*', SqlBuilder::columns('news', ['*']));
        self::assertSame('`news`.`id`, `news`.`name`', SqlBuilder::columns('news', ['id', 'name']));
        self::assertSame('`other`.title', SqlBuilder::columns('news', [['other', 'title']]));
        self::assertSame('count(*) AS cnt', SqlBuilder::columns('news', ['cnt' => new Expression('count(*)')]));
        self::assertSame('`news`.`id` AS key', SqlBuilder::columns('news', ['key' => 'id']));
    }

    public function testConditionsRenderInTheDocumentedFormat(): void
    {
        $quote = self::quote(...);
        $identifier = self::quoteIdentifier(...);

        self::assertSame("`id` >= '135'", SqlBuilder::condition(['id', '>=', '135'], $quote, $identifier));
        // A qualified column keeps the legacy quoting: only the column half is
        // backticked, which is what every existing query already relies on.
        self::assertSame("user.`name` LIKE 'Bob%'", SqlBuilder::condition(['user.name', 'LIKE', 'Bob%'], $quote, $identifier));
        self::assertSame(
            "`status` IN ('active', 'pending')",
            SqlBuilder::condition(['status', 'IN', ['active', 'pending']], $quote, $identifier),
        );
        self::assertSame('`subscription` IS NULL', SqlBuilder::condition(['subscription', 'IS', null], $quote, $identifier));
        self::assertSame("`name` LIKE '50!%' ESCAPE \"!\"", SqlBuilder::condition(['name', 'LIKE', '50!%', '!'], $quote, $identifier));
    }

    public function testAnExpressionIsUsedVerbatimAsConditionOrColumn(): void
    {
        $quote = self::quote(...);
        $identifier = self::quoteIdentifier(...);

        self::assertSame(
            'FIND_IN_SET("home", portal)',
            SqlBuilder::condition(new Expression('FIND_IN_SET("home", portal)'), $quote, $identifier),
        );
        self::assertSame('raw = 1', SqlBuilder::condition('raw = 1', $quote, $identifier));
        self::assertSame(
            "LOWER(name) = 'bob'",
            SqlBuilder::condition([new Expression('LOWER(name)'), '=', 'bob'], $quote, $identifier),
        );
    }

    public function testSelectQueryMatchesWhatAppTableUsedToConcatenate(): void
    {
        $query = SqlBuilder::selectQuery(
            '`news`.`id`',
            'news',
            'join newslocal on newslocal.id = news.id',
            'where `enabled` = 1',
            SqlBuilder::clauses('group by `news`.`id`', 'having count(*) > 1'),
            'added desc',
            10,
            20,
        );

        self::assertSame(
            'select `news`.`id` from `news` join newslocal on newslocal.id = news.id '
            . 'where `enabled` = 1group by `news`.`id` having count(*) > 1 order by added desc limit 20, 10',
            $query,
        );
    }

    public function testSelectQueryWithoutOrderingOrLimitOmitsBothClauses(): void
    {
        $query = SqlBuilder::selectQuery('`news`.`id`', 'news', '', '', SqlBuilder::clauses(null, null));

        self::assertSame('select `news`.`id` from `news`   ', $query);
        self::assertStringNotContainsString('order by', $query);
        self::assertStringNotContainsString('limit', $query);
    }

    public function testCountQueryMatchesWhatCountUsedToConcatenate(): void
    {
        self::assertSame(
            'select count(*) cnt from `news`  where `enabled` = 1 ',
            SqlBuilder::countQuery('news', '', 'where `enabled` = 1', SqlBuilder::clauses(null, null)),
        );
    }
}
