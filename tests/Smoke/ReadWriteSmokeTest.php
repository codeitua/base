<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Smoke;

use CodeIT\BaseTest\Model\FakeCache;
use CodeIT\Cache\Redis as RedisCache;
use CodeIT\Cache\RedisWrapper;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AppTable/CachedTable against a real database instead of stubs:
 * admin reads (full row), client reads ($publicOnly), and writes that must
 * invalidate the cache. Self-skips when no database or cache is reachable, so
 * it never fails a run that has not started one.
 *
 * SMOKE_DB=sqlite runs in-memory with no setup. The default, SMOKE_DB=mariadb,
 * expects `docker compose up -d` (see docker-compose.yml) and connects with
 * SMOKE_DB_HOST/PORT/NAME/USER/PASSWORD, defaulting to the compose file's own
 * values.
 *
 * SMOKE_CACHE=redis swaps the in-memory FakeCache for the real
 * CodeIT\Cache\Redis backend (needs ext-redis and, by default, the
 * docker-compose `smoke-redis` service), so the mask deletes, batched mget,
 * and rebuild lock run against an actual server instead of an array.
 */
final class ReadWriteSmokeTest extends TestCase
{
    private static ?Adapter $adapter = null;

    public static function setUpBeforeClass(): void
    {
        self::$adapter = self::connect();
        SmokeTable::useAdapter(self::$adapter);
    }

    /**
     * Each test starts fully cold: an empty table and an empty cache, so
     * "cold" and "warm" reads within a test mean what they say regardless of
     * what an earlier test did, and regardless of test order or how many
     * times the file is rerun.
     */
    protected function setUp(): void
    {
        SmokeTable::useCache(self::buildCache());
        self::resetSchema();
    }

    private static function buildCache()
    {
        if ((getenv('SMOKE_CACHE') ?: 'fake') !== 'redis') {
            return new FakeCache();
        }
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not loaded; cannot run SMOKE_CACHE=redis.');
        }
        $wrapper = new RedisWrapper(
            getenv('SMOKE_REDIS_HOST') ?: '127.0.0.1',
            (int) (getenv('SMOKE_REDIS_PORT') ?: 6379)
        );
        // A fresh namespace per test: ids restart at 1 after resetSchema(), and
        // without this a leftover key from an earlier test would look like a
        // warm hit for a row that, this test, was never read yet.
        $namespace = 'codeit-base-smoke:' . bin2hex(random_bytes(4)) . ':';
        $cache = new RedisCache(['enabled' => true, 'namespace' => $namespace], $wrapper);
        try {
            $cache->set('smoke.ping', '1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Smoke redis unreachable: ' . $e->getMessage());
        }
        return $cache;
    }

    private static function connect(): Adapter
    {
        $driver = getenv('SMOKE_DB') ?: 'mariadb';
        $config = $driver === 'sqlite'
            ? ['driver' => 'Pdo_Sqlite', 'database' => ':memory:']
            : [
                'driver' => 'Pdo_Mysql',
                'hostname' => getenv('SMOKE_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('SMOKE_DB_PORT') ?: 3306),
                'database' => getenv('SMOKE_DB_NAME') ?: 'codeit_base_smoke',
                'username' => getenv('SMOKE_DB_USER') ?: 'root',
                'password' => getenv('SMOKE_DB_PASSWORD') ?: 'root',
                'charset' => 'utf8mb4',
            ];
        $adapter = new Adapter($config);
        try {
            $adapter->getDriver()->getConnection()->connect();
        } catch (\Throwable $e) {
            self::markTestSkipped("Smoke database unreachable ({$driver}): " . $e->getMessage());
        }
        return $adapter;
    }

    private static function resetSchema(): void
    {
        $sqlite = (getenv('SMOKE_DB') ?: 'mariadb') === 'sqlite';
        $idColumn = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY';
        self::$adapter->query('DROP TABLE IF EXISTS smoke_news', Adapter::QUERY_MODE_EXECUTE);
        self::$adapter->query(
            "CREATE TABLE smoke_news ($idColumn, title VARCHAR(191) NOT NULL, "
                . 'secret VARCHAR(191) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1)',
            Adapter::QUERY_MODE_EXECUTE
        );
    }

    public function testAdminReadSeesPrivateFieldsClientReadDoesNot(): void
    {
        $table = new SmokeTable();
        $id = $table->insert(['title' => 'Launch', 'secret' => 'internal-note', 'enabled' => 1]);

        $admin = $table->get($id);
        self::assertSame('internal-note', $admin->secret);

        $client = (new SmokeTable())->get($id, true);
        self::assertFalse(isset($client->secret), 'client read must not see private fields');
        self::assertSame('Launch', $client->title);
    }

    public function testFindHonoursPublicOnly(): void
    {
        $table = new SmokeTable();
        $table->insert(['title' => 'A', 'secret' => 's1', 'enabled' => 1]);
        $table->insert(['title' => 'B', 'secret' => 's2', 'enabled' => 1]);

        foreach ($table->find([['enabled', '=', 1]]) as $row) {
            self::assertTrue(isset($row->secret), 'admin find() must keep private fields');
        }
        foreach ($table->find([['enabled', '=', 1]], 0, 0, false, $total, true) as $row) {
            self::assertFalse(isset($row->secret), 'client find() must strip private fields');
        }
    }

    public function testUpdatePersistsAndInvalidatesTheCachedRow(): void
    {
        $table = new SmokeTable();
        $id = $table->insert(['title' => 'Before', 'secret' => 's', 'enabled' => 1]);
        $table->get($id); // warms the row cache

        $table->set(['title' => 'After'], $id);

        $fresh = (new SmokeTable())->get($id);
        self::assertSame('After', $fresh->title, 'update() must invalidate the cached row, not serve a stale one');
    }

    public function testInsertInvalidatesCachedFindLists(): void
    {
        $table = new SmokeTable();
        $before = 0;
        $table->find([['enabled', '=', 1]], 0, 0, false, $before); // warms the cached id list

        $table->insert(['title' => 'New', 'secret' => 's', 'enabled' => 1]);

        $after = 0;
        (new SmokeTable())->find([['enabled', '=', 1]], 0, 0, false, $after);
        self::assertGreaterThan($before, $after, 'insert() must invalidate the cached find() generation');
    }

    /**
     * A write that bypasses the model can never invalidate the cache, so it is
     * the way to tell a genuinely warm read from one that quietly re-queries
     * the database. If a warm get() ever started doing that, this is the test
     * that would go from "stale" to "correct" for the wrong reason and fail.
     */
    public function testWarmRowCacheServesStaleDataUntilAModelWriteInvalidatesIt(): void
    {
        $table = new SmokeTable();
        $id = $table->insert(['title' => 'Cold', 'secret' => 's', 'enabled' => 1]);

        self::assertSame('Cold', $table->get($id)->title, 'a cold read must reflect the database');

        self::rawExec('UPDATE smoke_news SET title = ? WHERE id = ?', ['Changed behind the cache', $id]);
        self::assertSame(
            'Cold',
            (new SmokeTable())->get($id)->title,
            'a warm read must be served from cache, not re-query the database'
        );

        $table->set(['title' => 'Via model'], $id);
        self::assertSame(
            'Via model',
            (new SmokeTable())->get($id)->title,
            'update() must invalidate the cached row so the next read is current'
        );
    }

    /** Same proof as above, one level up: for the cached find() id list. */
    public function testWarmFindListStaysStaleUntilAModelWriteInvalidatesIt(): void
    {
        $table = new SmokeTable();
        $table->insert(['title' => 'A', 'secret' => 's', 'enabled' => 1]);

        $cold = 0;
        $table->find([['enabled', '=', 1]], 0, 0, false, $cold);
        self::assertSame(1, $cold, 'a cold find() must count what is actually in the table');

        self::rawExec(
            'INSERT INTO smoke_news (title, secret, enabled) VALUES (?, ?, 1)',
            ['Hidden from the warm list', 's']
        );
        $warm = 0;
        (new SmokeTable())->find([['enabled', '=', 1]], 0, 0, false, $warm);
        self::assertSame(1, $warm, 'a warm find() must serve the cached id list, not re-query the database');

        $table->insert(['title' => 'Via model', 'secret' => 's', 'enabled' => 1]);
        $after = 0;
        (new SmokeTable())->find([['enabled', '=', 1]], 0, 0, false, $after);
        self::assertSame(
            3,
            $after,
            'insert() must invalidate the cached find() generation, picking up every row now in the table'
        );
    }

    private static function rawExec(string $sql, array $params): void
    {
        self::$adapter->query($sql, $params);
    }
}
