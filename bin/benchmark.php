#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Measures the cost of the caching model layer over many cycles.
 *
 * Everything this library does is dominated by round trips, not by PHP, so the
 * report puts the number of cache commands next to the time each operation takes.
 * Percentiles matter more than the mean: a lock or a stampede shows up in p99 long
 * before it moves the average.
 *
 * Usage:
 *   php bin/benchmark.php [--cycles=2000] [--rows=50]
 *                         [--redis=127.0.0.1] [--redis-port=6379] [--redis-db=15]
 *                         [--dsn=mysql:host=127.0.0.1;port=3306] [--user=root] [--password=root]
 *
 * The Redis database is flushed at the end, so point it at a scratch index.
 * Without --dsn the lock comparison is skipped; nothing else needs a database.
 */

namespace CodeIT\Benchmark;

use CodeIT\Cache\BatchLoader;
use CodeIT\Cache\CacheInterface;
use CodeIT\Model\CachedTable;
use Laminas\Db\ResultSet\ResultSet;
use PDO;
use Redis;

$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, 'Run composer install first.' . PHP_EOL);
    exit(1);
}
require $autoload;

/** Counts the commands each scenario really issues, which is what the cost is made of. */
final class BenchmarkCache implements CacheInterface
{
    public int $commands = 0;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function get($key)
    {
        $this->commands++;
        return $this->redis->get($key);
    }

    public function mget($keys)
    {
        $this->commands++;
        return $this->redis->mget($keys);
    }

    public function set($key, $value, $ttl = 0, $try = 0)
    {
        $this->commands++;
        return $ttl ? $this->redis->setex($key, $ttl, $value) : $this->redis->set($key, $value);
    }

    public function setMany($pairs, $ttl = 0)
    {
        $this->commands++;                       // one pipeline is one round trip
        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($pairs as $key => $value) {
            $ttl ? $pipeline->setex($key, $ttl, $value) : $pipeline->set($key, $value);
        }
        $pipeline->exec();
        return true;
    }

    public function acquireLock($key, $ttl)
    {
        $this->commands++;
        return (bool) $this->redis->set($key, 1, ['NX', 'PX' => max(1, (int) $ttl)]);
    }

    public function deleteCache($keys)
    {
        $this->commands++;
        return $this->redis->del($keys);
    }

    public function deleteByMask($mask)
    {
        $this->commands++;
        $script = "local cursor = '0' local deleted = 0 repeat"
            . " local res = redis.call('SCAN', cursor, 'MATCH', ARGV[1], 'COUNT', 500)"
            . " cursor = res[1]"
            . " if #res[2] > 0 then deleted = deleted + redis.call('DEL', unpack(res[2])) end"
            . " until cursor == '0' return deleted";
        return (int) $this->redis->eval($script, [$mask . '*'], 0);
    }
}

/** A CachedTable whose database is replaced by a fixed row set. */
class BenchmarkTable extends CachedTable
{
    protected int $rows = 1;

    /** When set, a row load costs a real database round trip, as in an application. */
    protected static ?PDO $pdo = null;

    public function __construct()
    {
    }

    public static function useDatabase(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function make(string $table, int $rows, int $findCacheTtl = 0): static
    {
        $instance = new static();
        $instance->table = $table;
        $instance->rows = $rows;
        $instance->findCacheTtl = $findCacheTtl;
        return $instance;
    }

    public static function useCache(BenchmarkCache $cache): void
    {
        self::$cache = $cache;
        self::$findGenerations = [];
    }

    public static function resetCommandCount(): void
    {
        self::$cache->commands = 0;
    }

    public static function commandCount(): int
    {
        return self::$cache->commands;
    }

    /** Simulates a fresh request, where nothing is memoized yet. */
    /** Starts a scenario as a fresh request would: nothing memoized. */
    public static function freshRequest(): void
    {
        self::$findGenerations = [];
        self::$localRows = [];
    }

    public static function cache(): CacheInterface
    {
        return self::$cache;
    }

    public function warmRows(): void
    {
        for ($id = 1; $id <= $this->rows; $id++) {
            $this->cacheSet($id, $this->row($id));
        }
    }

    public function benchmarkFindKey(): string
    {
        return $this->buildFindCacheKey([['enabled', '=', 1]], $this->rows, 0, 'added desc', false);
    }

    public function getUncached($id)
    {
        self::$pdo?->query('SELECT ' . (int) $id)->fetch();
        return $this->row((int) $id);
    }

    public function findSimple($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $columns = ['id'])
    {
        $result = [];
        for ($id = 1; $id <= $this->rows; $id++) {
            $result[] = ['id' => $id];
        }
        if (!is_null($total)) {
            $total = $this->rows;
        }
        $set = new ResultSet();
        $set->initialize($result);
        return $set;
    }

    public function getLock($name, $params = false, $timeout = 10)
    {
    }

    public function releaseLock($name, $params = false)
    {
    }

    /** A row of roughly the size a content table caches. */
    protected function row(int $id): \ArrayObject
    {
        return new \ArrayObject([
            'id' => $id,
            'name' => 'Benchmark row ' . $id,
            'content' => str_repeat('lorem ipsum dolor sit amet ', 12),
            'added' => 1700000000 + $id,
            'enabled' => 1,
            'photo' => [
                'dir' => ['a' => '/upload/bench/' . $id . '.jpg'],
                'href' => ['a' => '/upload/bench/' . $id . '.jpg'],
            ],
        ], \ArrayObject::ARRAY_AS_PROPS);
    }
}

/** The same table with the batch hook implemented, as a model would. */
final class BatchedBenchmarkTable extends BenchmarkTable
{
    /** One load for the whole batch, the way a model would fetch rows and relations. */
    public function getUncachedMany(array $ids)
    {
        self::$pdo?->query('SELECT ' . implode(',', array_map('intval', $ids)))->fetch();
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->row((int) $id);
        }
        return $rows;
    }
}

final class Report
{
    /** @var list<array{0:string,1:int,2:float,3:float,4:float,5:float,6:float}|string> */
    private array $lines = [];

    public function __construct(private readonly int $cycles)
    {
    }

    public function section(string $title): void
    {
        $this->lines[] = $title;
    }

    /**
     * $prepare puts the cache into the state the scenario needs and is neither
     * timed nor counted, so invalidation never lands in the measurement.
     */
    public function run(string $name, callable $scenario, ?int $cycles = null, ?callable $prepare = null): void
    {
        $cycles ??= $this->cycles;

        for ($i = 0, $warmup = max(10, (int) ($cycles / 100)); $i < $warmup; $i++) {
            if ($prepare !== null) {
                $prepare();
            }
            $scenario();
        }

        $samples = [];
        $commandsUsed = 0;
        for ($i = 0; $i < $cycles; $i++) {
            if ($prepare !== null) {
                $prepare();
            }
            BenchmarkTable::resetCommandCount();
            $start = hrtime(true);
            $scenario();
            $samples[] = (hrtime(true) - $start) / 1e6;
            $commandsUsed += BenchmarkTable::commandCount();
        }
        $commands = $commandsUsed / $cycles;

        sort($samples);
        $this->lines[] = [
            $name,
            $cycles,
            array_sum($samples) / $cycles,
            $this->percentile($samples, 0.50),
            $this->percentile($samples, 0.95),
            $this->percentile($samples, 0.99),
            $commands,
        ];
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, float $quantile): float
    {
        return $sorted[min(count($sorted) - 1, (int) floor($quantile * count($sorted)))];
    }

    public function render(int $rows, int $payloadBytes): void
    {
        printf('%scodeit/base model layer%s', PHP_EOL, PHP_EOL);
        printf('row payload %d bytes, list size %d%s%s', $payloadBytes, $rows, PHP_EOL, PHP_EOL);
        printf("%-32s %8s %9s %9s %9s %9s %8s%s", 'scenario', 'cycles', 'mean ms', 'p50', 'p95', 'p99', 'cmds', PHP_EOL);
        printf('%s%s', str_repeat('-', 93), PHP_EOL);

        foreach ($this->lines as $line) {
            if (is_string($line)) {
                printf('%s%s%s', PHP_EOL, $line, PHP_EOL);
                continue;
            }
            [$name, $cycles, $mean, $p50, $p95, $p99, $commands] = $line;
            printf(
                "%-32s %8d %9.4f %9.4f %9.4f %9.4f %8s%s",
                $name,
                $cycles,
                $mean,
                $p50,
                $p95,
                $p99,
                $commands > 0 ? number_format($commands, 1) : '-',
                PHP_EOL,
            );
        }
        printf('%s"cmds" is cache commands per cycle: compare it against "mean ms".%s', PHP_EOL, PHP_EOL);
    }
}

function main(array $options): int
{
    if (!extension_loaded('redis')) {
        fwrite(STDERR, 'The redis extension is required.' . PHP_EOL);
        return 1;
    }
    if (!defined('SITE_NAME')) {
        define('SITE_NAME', 'codeit-base-benchmark');
    }

    $cycles = max(1, (int) ($options['cycles'] ?? 2000));
    $rows = max(1, (int) ($options['rows'] ?? 50));

    $redis = new Redis();
    $redis->pconnect((string) ($options['redis'] ?? '127.0.0.1'), (int) ($options['redis-port'] ?? 6379));
    $redis->select((int) ($options['redis-db'] ?? 15));
    $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    $redis->flushDB();

    BenchmarkTable::useCache(new BenchmarkCache($redis));
    $report = new Report($cycles);

    $report->section('warm reads');
    $table = BenchmarkTable::make('bench', $rows);
    $table->warmRows();
    $report->run(
        'get() warm row',
        static fn() => $table->get(1),
        null,
        static fn() => BenchmarkTable::freshRequest(),
    );
    $report->run('get() warm row, read twice', static function () use ($table): void {
        $table->get(1);
        $table->get(1);
    }, null, static fn() => BenchmarkTable::freshRequest());

    $fresh = static fn() => BenchmarkTable::freshRequest();
    $ten = range(1, min(10, $rows));
    $report->run('mget() warm, ' . count($ten) . ' rows', static fn() => $table->mget($ten), null, $fresh);

    $all = range(1, $rows);
    $report->run('mget() warm, ' . $rows . ' rows', static fn() => $table->mget($all), null, $fresh);

    $cached = BenchmarkTable::make('bench', $rows, 300);
    $cached->warmRows();
    $cached->find([['enabled', '=', 1]]);
    $report->run('find() warm, cached ids', static fn() => $cached->find([['enabled', '=', 1]]), null, $fresh);
    $report->run('find() warm, uncached ids', static fn() => $table->find([['enabled', '=', 1]]), null, $fresh);
    $report->run(
        'find() warm, first of a request',
        static fn() => $cached->find([['enabled', '=', 1]]),
        null,
        static fn() => BenchmarkTable::freshRequest(),
    );

    if (isset($options['dsn'])) {
        BenchmarkTable::useDatabase(new PDO(
            (string) $options['dsn'],
            (string) ($options['user'] ?? 'root'),
            (string) ($options['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ));
    }

    $report->section('five warm lists, the shape of a home page');
    $tables = [];
    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        $tables[$name] = BenchmarkTable::make('bench' . $name, 10, 300);
        $tables[$name]->warmRows();
        $tables[$name]->find([['enabled', '=', 1]]);
    }
    $report->run('5 find() calls, one at a time', static function () use ($tables): void {
        foreach ($tables as $one) {
            $one->find([['enabled', '=', 1]]);
        }
    }, null, $fresh);
    $report->run('5 find() calls, deferred', static function () use ($tables): void {
        $loader = new BatchLoader(BenchmarkTable::cache());
        $handles = [];
        foreach ($tables as $one) {
            $handles[] = $one->deferFind($loader, [['enabled', '=', 1]]);
        }
        foreach ($handles as $handle) {
            $handle->rows();
        }
    }, null, $fresh);

    $report->section('cold reads, where a deploy or a Redis restart lands'
        . (isset($options['dsn']) ? ', one real query per load' : ', no database'));
    $report->run(
        'get() one cold row',
        static fn() => $table->get(1),
        null,
        static function () use ($table): void {
            BenchmarkTable::freshRequest();
            $table->cacheDelete(1);
        },
    );
    $report->run(
        'mget() ' . $rows . ' cold, per row',
        static fn() => $table->mget($all),
        max(20, (int) ($cycles / 20)),
        static function () use ($table, $rows): void {
            BenchmarkTable::freshRequest();
            for ($id = 1; $id <= $rows; $id++) {
                $table->cacheDelete($id);
            }
        },
    );

    $batched = BatchedBenchmarkTable::make('bench', $rows);
    $report->run(
        'mget() ' . $rows . ' cold, batched',
        static fn() => $batched->mget($all),
        max(20, (int) ($cycles / 20)),
        static function () use ($batched, $rows): void {
            BenchmarkTable::freshRequest();
            for ($id = 1; $id <= $rows; $id++) {
                $batched->cacheDelete($id);
            }
        },
    );

    if (isset($options['dsn'])) {
        $pdo = new PDO(
            (string) $options['dsn'],
            (string) ($options['user'] ?? 'root'),
            (string) ($options['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $locks = new Redis();
        $locks->pconnect((string) ($options['redis'] ?? '127.0.0.1'), (int) ($options['redis-port'] ?? 6379));
        $locks->select((int) ($options['redis-db'] ?? 15));

        $report->section('lock primitives taken on every cache miss');
        $lockName = SITE_NAME . '.bench.1';
        $report->run('mysql GET_LOCK + RELEASE_LOCK', static function () use ($pdo, $lockName): void {
            $pdo->query("SELECT GET_LOCK('" . $lockName . "', 10)")->fetch();
            $pdo->query("SELECT RELEASE_LOCK('" . $lockName . "')")->fetch();
        });

        $release = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0";
        $report->run('redis SET NX PX + owned release', static function () use ($locks, $release, $lockName): void {
            $token = (string) random_int(1, PHP_INT_MAX);
            $locks->set($lockName, $token, ['NX', 'PX' => 3000]);
            $locks->eval($release, [$lockName, $token], 1);
        });
    }

    $report->section('PHP-side work, for scale');
    $payload = (string) $redis->rawCommand('get', 'table.bench.1');
    $report->run('unserialize one row', static fn() => unserialize($payload));
    $report->run('buildFindCacheKey()', static fn() => $cached->benchmarkFindKey());

    // Serializer and payload shape are the things people reach for first. The
    // numbers are here so the decision is made against a round trip, not a guess.
    $row = unserialize($payload);
    $asArray = (array) $row;
    $report->run('serialize row, php', static fn() => serialize($row));
    if (function_exists('igbinary_serialize')) {
        $report->run('serialize row, igbinary', static fn() => igbinary_serialize($row));
    }
    $report->run('serialize row as plain array', static fn() => serialize($asArray));
    $report->run(
        'rehydrate a plain array row',
        static fn() => new \ArrayObject(unserialize(serialize($asArray)), \ArrayObject::ARRAY_AS_PROPS),
    );

    printf(
        '%spayload: ArrayObject %d bytes, plain array %d bytes%s%s',
        PHP_EOL,
        strlen(serialize($row)),
        strlen(serialize($asArray)),
        function_exists('igbinary_serialize')
            ? ', igbinary ' . strlen(igbinary_serialize($row)) . ' bytes'
            : '',
        PHP_EOL,
    );

    $report->render($rows, strlen($payload));
    $redis->flushDB();

    return 0;
}

$options = getopt('', [
    'cycles::', 'rows::', 'redis::', 'redis-port::', 'redis-db::',
    'dsn::', 'user::', 'password::', 'help',
]) ?: [];
if (isset($options['help'])) {
    fwrite(STDOUT, (string) file_get_contents(__FILE__, false, null, 0, 1100));
    exit(0);
}

exit(main($options));
