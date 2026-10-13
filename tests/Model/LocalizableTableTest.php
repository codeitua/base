<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use CodeIT\Model\CachedTable;
use CodeIT\Model\LocalizableTable;
use CodeIT\Utils\Registry;
use Laminas\Db\Adapter\Adapter;
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

    private function database(): Adapter
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for localization query regression tests.');
        }

        $adapter = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $adapter->query(
            'CREATE TABLE news (id INTEGER PRIMARY KEY, name TEXT, enabled INTEGER)',
            Adapter::QUERY_MODE_EXECUTE
        );
        $adapter->query(
            'CREATE TABLE newslocal (id INTEGER, lang INTEGER, name TEXT, content TEXT, PRIMARY KEY (id, lang))',
            Adapter::QUERY_MODE_EXECUTE
        );
        foreach ([1 => 'First', 6 => 'Sixth'] as $id => $name) {
            $adapter->query('INSERT INTO news (id, name, enabled) VALUES (?, ?, 1)', [$id, 'Base ' . $name]);
            foreach ([1 => 'Default', 3 => 'English'] as $lang => $language) {
                $adapter->query(
                    'INSERT INTO newslocal (id, lang, name, content) VALUES (?, ?, ?, ?)',
                    [$id, $lang, $language . ' ' . $name, $language . ' content ' . $name]
                );
            }
        }
        return $adapter;
    }

    private function databaseTable(Adapter $adapter, ?int $id = null): LocalizableTable
    {
        return new class ($adapter, $id) extends LocalizableTable {
            public function __construct(Adapter $adapter, ?int $id)
            {
                $this->adapter = $adapter;
                parent::__construct('news', $id);
            }
        };
    }

    public function testExplicitTranslationIdOverridesTheBoundRowId(): void
    {
        Registry::set('lang', 3);
        $table = $this->databaseTable($this->database(), 6);

        $translations = $table->getLocalData(['id' => 1]);

        self::assertCount(2, $translations);
        self::assertSame('Default First', $translations[1]->name);
        self::assertSame('English First', $translations[3]->name);
        foreach ($translations as $translation) {
            self::assertSame(1, (int) $translation->id);
        }
    }

    public function testTranslationQueriesWithoutAnExplicitIdKeepTheBoundRow(): void
    {
        Registry::set('lang', 3);
        $table = $this->databaseTable($this->database(), 6);

        $translations = $table->getLocalData();
        self::assertCount(2, $translations);
        self::assertSame('English Sixth', $translations[3]->name);

        $filtered = $table->getLocalData(['lang' => 3]);
        self::assertCount(1, $filtered);
        self::assertSame(6, (int) $filtered[3]->id);
    }

    public function testBoundModelSingleReadsCacheTheRequestedTranslation(): void
    {
        Registry::set('lang', 3);
        $adapter = $this->database();
        $table = $this->databaseTable($adapter, 6);

        self::assertSame('English First', $table->get(1)->name);
        self::assertSame('English content First', $this->cache->store['table.news.1.3']->content);

        // A raw write cannot invalidate the cache: this proves the next read is a cache hit.
        $adapter->query('UPDATE newslocal SET name = ? WHERE id = 1 AND lang = 3', ['Changed behind cache']);
        CachedTable::forgetLocalRows();
        self::assertSame('English First', $this->databaseTable($adapter)->get(1)->name);

        Registry::set('lang', 1);
        self::assertSame('Default First', $this->databaseTable($adapter, 6)->get(1)->name);
        self::assertSame('Default First', $this->cache->store['table.news.1.1']->name);
        self::assertSame('English First', $this->cache->store['table.news.1.3']->name);
    }

    public function testBoundModelFindCachesTheRequestedTranslations(): void
    {
        Registry::set('lang', 3);
        $adapter = $this->database();
        $table = $this->databaseTable($adapter, 6);

        $cold = $table->find([['enabled', '=', 1]], 0, 0, 'id asc');
        self::assertSame('English First', $cold[1]->name);
        self::assertSame('English Sixth', $cold[6]->name);
        self::assertSame('English First', $this->cache->store['table.news.1']->localData[3]->name);
        self::assertSame('English content First', $this->cache->store['table.news.1.3']->content);

        $adapter->query('UPDATE newslocal SET name = ? WHERE id = 1 AND lang = 3', ['Changed behind cache']);
        CachedTable::forgetLocalRows();
        $warm = $this->databaseTable($adapter)->find([['enabled', '=', 1]], 0, 0, 'id asc');
        self::assertSame('English First', $warm[1]->name);

        Registry::set('lang', 1);
        $otherLanguage = $this->databaseTable($adapter, 6)->find([['enabled', '=', 1]], 0, 0, 'id asc');
        self::assertSame('Default First', $otherLanguage[1]->name);
        self::assertSame('Default Sixth', $otherLanguage[6]->name);
        self::assertSame('English First', $this->cache->store['table.news.1.3']->name);
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
        foreach (
            ['table.news.1', 'table.news.1.1', 'table.news.1.2',
                  'table.news.10', 'table.news.10.1', 'table.news.123.2'] as $key
        ) {
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

    public function testEditingALocalizedRowIsVisibleWithinTheSameRequest(): void
    {
        $table = $this->table();
        $table->useLanguage(2);
        self::assertSame('Перший', $table->get(1)->name);

        // A localized write goes through this override, not CachedTable's.
        $table->rows[1][2]['name'] = 'Змінений';
        $table->cacheDelete(1);

        self::assertSame('Змінений', $table->get(1)->name, 'The remembered row must not survive the write.');
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
