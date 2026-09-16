<?php

declare(strict_types=1);

namespace CodeIT\BaseTest\Smoke;

use CodeIT\Cache\CacheInterface;
use CodeIT\Model\CachedTable;
use Laminas\Db\Adapter\Adapter;

/**
 * CachedTable wired to an injected Adapter and cache, bypassing Registry so
 * the smoke tests can point it at a real database without a full Laminas app.
 */
final class SmokeTable extends CachedTable
{
    protected $goodFields = ['id', 'title', 'secret', 'enabled'];
    protected $privateFields = ['secret'];
    protected $findCacheTtl = 60;

    private static ?Adapter $sharedAdapter = null;

    public static function useAdapter(Adapter $adapter): void
    {
        self::$sharedAdapter = $adapter;
    }

    public static function useCache(CacheInterface $cache): void
    {
        self::$cache = $cache;
        self::$findGenerations = [];
        self::$localRows = [];
    }

    public function __construct($id = null)
    {
        parent::__construct('smoke_news', $id);
    }

    public function getAdapter()
    {
        return self::$sharedAdapter;
    }
}
