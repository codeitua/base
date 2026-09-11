<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Model;

use CodeIT\Cache\BatchLoader;
use PHPUnit\Framework\TestCase;

final class BatchLoaderTest extends TestCase
{
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        CachedTableProbe::useCache($this->cache);
    }

    private function table(string $name, int $findCacheTtl = 0): CachedTableProbe
    {
        $table = new CachedTableProbe($name, $findCacheTtl);
        $table->ids = [1, 2, 3];
        $table->rows = [
            1 => ['id' => 1, 'name' => $name . ' one'],
            2 => ['id' => 2, 'name' => $name . ' two'],
            3 => ['id' => 3, 'name' => $name . ' three'],
        ];
        return $table;
    }

    private function warm(CachedTableProbe $table, string $name): void
    {
        foreach ($table->rows as $id => $row) {
            $this->cache->store['table.' . $name . '.' . $id] = new \ArrayObject($row, \ArrayObject::ARRAY_AS_PROPS);
        }
    }

    public function testThreeDeferredReadsCostOneBatchedRequest(): void
    {
        $news = $this->table('news');
        $shop = $this->table('shop');
        $portal = $this->table('portal');
        foreach (['news' => $news, 'shop' => $shop, 'portal' => $portal] as $name => $table) {
            $this->warm($table, $name);
        }

        $loader = new BatchLoader($this->cache);
        $deferredNews = $news->deferMget($loader, [1, 2, 3]);
        $deferredShop = $shop->deferMget($loader, [1, 2]);
        $deferredPortal = $portal->deferMget($loader, [3]);

        self::assertSame(0, $this->cache->mgetCalls, 'Nothing may be read before a handle is used.');

        self::assertSame([1, 2, 3], array_keys($deferredNews->rows()));
        self::assertSame(1, $this->cache->mgetCalls, 'All three reads share one request.');
        self::assertSame('shop one', $deferredShop->rows()[1]->name);
        self::assertSame('portal three', $deferredPortal->rows()[3]->name);
        self::assertSame(1, $this->cache->mgetCalls, 'Reading the other handles adds no request.');
    }

    public function testDeferredFindResolvesIdsAndRowsInTwoRequests(): void
    {
        $news = $this->table('news', 300);
        $shop = $this->table('shop', 300);
        $this->warm($news, 'news');
        $this->warm($shop, 'shop');
        // Populate the cached ID lists.
        $news->find([['enabled', '=', 1]]);
        $shop->find([['enabled', '=', 1]]);
        $this->cache->mgetCalls = 0;
        $news->findSimpleCalls = 0;
        $shop->findSimpleCalls = 0;

        $loader = new BatchLoader($this->cache);
        $deferredNews = $news->deferFind($loader, [['enabled', '=', 1]]);
        $deferredShop = $shop->deferFind($loader, [['enabled', '=', 1]]);

        self::assertSame([1, 2, 3], array_keys($deferredNews->rows()));
        self::assertSame([1, 2, 3], array_keys($deferredShop->rows()));
        self::assertSame(2, $this->cache->mgetCalls, 'One request for both ID lists, one for all rows.');
        self::assertSame(0, $news->findSimpleCalls, 'A cached ID list must not query.');
    }

    public function testDeferredReadLoadsWhatTheCacheCouldNotServe(): void
    {
        $news = $this->table('news');
        $this->cache->store['table.news.2'] = new \ArrayObject($news->rows[2], \ArrayObject::ARRAY_AS_PROPS);

        $loader = new BatchLoader($this->cache);
        $rows = $news->deferMget($loader, [1, 2, 3])->rows();

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame(1, $this->cache->mgetCalls);
        self::assertSame(1, $news->getUncachedManyCalls, 'The misses are loaded in one batch.');
    }

    public function testDeferredFindReportsTheTotal(): void
    {
        $news = $this->table('news', 300);
        $news->queryTotal = 42;
        $this->warm($news, 'news');

        $loader = new BatchLoader($this->cache);
        $deferred = $news->deferFind($loader, [['enabled', '=', 1]], 3, 0, 'added desc', true);

        self::assertSame(42, $deferred->total());
        self::assertSame([1, 2, 3], array_keys($deferred->rows()));
    }

    public function testAMissingRowIsLeftOutRatherThanFaked(): void
    {
        $news = $this->table('news');
        unset($news->rows[2]);

        $loader = new BatchLoader($this->cache);
        $rows = $news->deferMget($loader, [1, 2, 3])->rows();

        self::assertSame([1, 3], array_keys($rows));
        self::assertSame([], $news->deleted);
    }
}
