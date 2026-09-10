<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use PHPUnit\Framework\TestCase;

if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'codeit-base-test');
}

final class CachedTableTest extends TestCase
{
    /** @var FakeCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        CachedTableProbe::useCache($this->cache);
    }

    private function table($findCacheTtl = 0): CachedTableProbe
    {
        $table = new CachedTableProbe('news', $findCacheTtl);
        $table->ids = [1, 2, 3];
        $table->rows = [
            1 => ['id' => 1, 'name' => 'First', 'secret' => 'a'],
            2 => ['id' => 2, 'name' => 'Second', 'secret' => 'b'],
            3 => ['id' => 3, 'name' => 'Third', 'secret' => 'c'],
        ];
        return $table;
    }

    public function testFindReadsWarmRowsWithASingleBatchedCacheRequest(): void
    {
        $table = $this->table();
        foreach ($table->rows as $id => $row) {
            $this->cache->store['table.news.' . $id] = new \ArrayObject($row, \ArrayObject::ARRAY_AS_PROPS);
        }

        $items = $table->find([['enabled', '=', 1]]);

        self::assertSame([1, 2, 3], array_keys($items));
        self::assertSame(1, $this->cache->mgetCalls);
        self::assertSame(0, $table->getUncachedCalls);
        self::assertSame(
            ['table.news.1', 'table.news.2', 'table.news.3'],
            $this->cache->mgetKeys
        );
    }

    public function testFindLoadsOnlyTheRowsTheBatchDidNotReturn(): void
    {
        $table = $this->table();
        $this->cache->store['table.news.2'] = new \ArrayObject($table->rows[2], \ArrayObject::ARRAY_AS_PROPS);

        $items = $table->find([]);

        self::assertSame([1, 2, 3], array_keys($items));
        self::assertSame(1, $this->cache->mgetCalls);
        self::assertSame(2, $table->getUncachedCalls);
        self::assertSame('Second', $items[2]->name);
    }

    public function testAMissingRowIsSkippedInsteadOfDeleted(): void
    {
        $table = $this->table();
        unset($table->rows[2]);

        $items = $table->find([]);

        self::assertSame([1, 3], array_keys($items));
        self::assertSame([], $table->deleted);
    }

    public function testPublicOnlyStripsPrivateFieldsFromCachedRowsToo(): void
    {
        $table = $this->table();
        $table->usePrivateFields(['secret']);
        $this->cache->store['table.news.1'] = new \ArrayObject($table->rows[1], \ArrayObject::ARRAY_AS_PROPS);

        $items = $table->find([], 0, 0, false, $ignored, true);

        self::assertArrayNotHasKey('secret', (array) $items[1]);
        self::assertArrayNotHasKey('secret', (array) $items[2]);
        // The cached row itself keeps every field for the next full read.
        self::assertArrayHasKey('secret', (array) $this->cache->store['table.news.1']);
    }

    public function testIdQueryRunsEveryTimeWhileFindCachingIsOff(): void
    {
        $table = $this->table();

        $table->find([['enabled', '=', 1]]);
        $table->find([['enabled', '=', 1]]);

        self::assertSame(2, $table->findSimpleCalls);
    }

    public function testCachedFindSkipsTheIdQueryAndKeepsTheTotal(): void
    {
        $table = $this->table(60);
        $table->queryTotal = 17;

        $firstTotal = 0;
        $table->find([['enabled', '=', 1]], 3, 0, 'added desc', $firstTotal);
        $secondTotal = 0;
        $items = $table->find([['enabled', '=', 1]], 3, 0, 'added desc', $secondTotal);

        self::assertSame(1, $table->findSimpleCalls);
        self::assertSame(17, $firstTotal);
        self::assertSame(17, $secondTotal);
        self::assertSame([1, 2, 3], array_keys($items));
    }

    public function testDifferentQueriesDoNotShareACachedIdList(): void
    {
        $table = $this->table(60);

        $table->find([['enabled', '=', 1]], 3, 0, 'added desc');
        $table->find([['enabled', '=', '1']], 3, 0, 'added desc');
        $table->find([['enabled', '=', 1]], 3, 0, 'added asc');
        $table->find([['enabled', '=', 1]], 5, 0, 'added desc');

        self::assertSame(4, $table->findSimpleCalls);
    }

    public function testAWriteDropsEveryCachedIdListOfTheTable(): void
    {
        $table = $this->table(60);
        $table->find([['enabled', '=', 1]]);
        $table->find([['enabled', '=', 2]]);
        self::assertSame(2, $table->findSimpleCalls);

        $table->invalidateLists();
        $table->find([['enabled', '=', 1]]);
        $table->find([['enabled', '=', 2]]);

        self::assertSame(4, $table->findSimpleCalls);
    }

    public function testAWriteLeavesTheCacheAloneWhileFindCachingIsOff(): void
    {
        $table = $this->table();
        $table->find([['enabled', '=', 1]]);
        $before = $this->cache->store;

        $table->invalidateLists();

        self::assertSame($before, $this->cache->store);
    }

    public function testCachedRowsSurviveAListInvalidation(): void
    {
        $table = $this->table(60);
        $table->find([]);
        self::assertSame(3, $table->getUncachedCalls);

        $table->invalidateLists();
        $table->find([]);

        self::assertSame(3, $table->getUncachedCalls, 'Rows must not be reloaded when only the list changed.');
    }
}
