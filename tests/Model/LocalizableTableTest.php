<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use CodeIT\Utils\Registry;
use PHPUnit\Framework\TestCase;

final class LocalizableTableTest extends TestCase
{
    /** @var FakeCache */
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        LocalizableTableProbe::useCache($this->cache);
    }

    protected function tearDown(): void
    {
        Registry::_unsetInstance();
    }

    private function table($findCacheTtl = 0): LocalizableTableProbe
    {
        $table = new LocalizableTableProbe('news', $findCacheTtl);
        $table->ids = [1, 2];
        $table->rows = [
            1 => [1 => ['name' => 'First'], 2 => ['name' => 'Перший']],
            2 => [1 => ['name' => 'Second'], 2 => ['name' => 'Другий']],
        ];
        return $table;
    }

    public function testBatchedReadUsesTheKeysTheLanguageAwareGetWrites(): void
    {
        $table = $this->table();
        $table->useLanguage(2);

        $items = $table->find([]);

        // Two batched reads on a cold cache: the per-language keys, then the
        // untranslated rows the languages share. Never one read per row.
        self::assertSame(2, $this->cache->mgetCalls);
        self::assertSame(['table.news.1.2', 'table.news.2.2'], $this->cache->mgetKeys[0]);
        self::assertSame(['table.news.1', 'table.news.2'], $this->cache->mgetKeys[1]);
        self::assertSame('Перший', $items[1]->name);
        self::assertArrayHasKey('table.news.1.2', $this->cache->store);

        // A second pass is served entirely from the first batch.
        $table->find([]);
        self::assertSame(2, $table->getUncachedCalls);
    }

    public function testEachLanguageKeepsItsOwnCachedIdList(): void
    {
        $table = $this->table(60);

        $table->find([['enabled', '=', 1]]);
        $table->useLanguage(2);
        $table->find([['enabled', '=', 1]]);
        $table->find([['enabled', '=', 1]]);

        self::assertSame(2, $table->findSimpleCalls);
    }

    public function testDeletingARowDoesNotTakeItsNumericNeighboursWithIt(): void
    {
        $table = $this->table();
        foreach (['table.news.1', 'table.news.1.1', 'table.news.1.2',
                  'table.news.10', 'table.news.10.1', 'table.news.123.2'] as $key) {
            $this->cache->store[$key] = 'cached';
        }

        $table->cacheDelete(1);

        self::assertArrayNotHasKey('table.news.1', $this->cache->store);
        self::assertArrayNotHasKey('table.news.1.1', $this->cache->store);
        self::assertArrayNotHasKey('table.news.1.2', $this->cache->store);
        self::assertArrayHasKey('table.news.10', $this->cache->store, 'Row 10 is not row 1.');
        self::assertArrayHasKey('table.news.10.1', $this->cache->store);
        self::assertArrayHasKey('table.news.123.2', $this->cache->store);
    }

    public function testDeclaredLanguagesMakeInvalidationOneExactDelete(): void
    {
        $table = $this->table();
        $table->useLanguages([1, 2, 3, 4]);
        foreach (['table.news.7', 'table.news.7.1', 'table.news.7.4'] as $key) {
            $this->cache->store[$key] = 'cached';
        }
        $this->cache->maskDeletes = 0;

        $table->cacheDelete(7);

        self::assertSame(0, $this->cache->maskDeletes, 'Declared languages must not scan the keyspace.');
        self::assertArrayNotHasKey('table.news.7', $this->cache->store);
        self::assertArrayNotHasKey('table.news.7.1', $this->cache->store);
        self::assertArrayNotHasKey('table.news.7.4', $this->cache->store);
    }

    public function testActiveLanguageComesFromTheRegistry(): void
    {
        Registry::set('lang', '3');
        $table = $this->table();

        $table->find([]);

        self::assertSame(['table.news.1.3', 'table.news.2.3'], $this->cache->mgetKeys[0]);
    }

    public function testDefaultLanguageIsKeptWhenTheRegistryHasNone(): void
    {
        $table = $this->table();

        $table->find([]);

        self::assertSame(['table.news.1.1', 'table.news.2.1'], $this->cache->mgetKeys[0]);
    }
}
