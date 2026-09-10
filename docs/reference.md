# codeit/base reference

Reference for the table models in `CodeIT\Model`. See `AGENTS.md` for the rules that apply when
writing or migrating application models.

## Class hierarchy

| Class | Use it for |
| --- | --- |
| `CodeIT\Model\AppTable` | A plain table. Every read hits the database. |
| `CodeIT\Model\CachedTable` | A table whose rows should be cached one entry per row. |
| `CodeIT\Model\LocalizableTable` | A cached table with a `<name>local` translations table. |

A model names its table in the constructor and takes an optional id:

```php
<?php

namespace Application\Model;

use CodeIT\Model\AppTable;

class UserTable extends AppTable
{
    public function __construct($userId = null)
    {
        parent::__construct('user', $userId);
    }
}
```

Passing an id calls `setId()`, which loads the row and copies its fields onto declared properties of
the model. Override `const ID_COLUMN` when the primary key is not `id`.

## Reading

### get()

```php
$user = (new UserTable())->get($id);            // \ArrayObject, throws ItemNotFoundException
$user = (new UserTable())->get($id, true);      // without $privateFields
```

On `CachedTable` this is the only method that loads and caches a row. Do not override it; override
`getUncached()` instead — see [Hydrating relations](#hydrating-relations).

### find()

```php
$rows = $table->find($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $publicOnly = false);
```

Returns rows keyed by id. `$limit = 0` means no limit. Pass an initialised variable as `$total` to
receive the unlimited row count:

```php
$total = 0;
$news = $newsTable->find([['enabled', '=', 1]], 10, 0, 'added desc', $total);
```

`AppTable::find()` selects the full rows in one query. `CachedTable::find()` selects the matching ids
and hydrates them with `mget()`, which is a single batched cache request.

#### Condition format

Each condition is a `[column, operator, value]` triple:

```php
['id', '>=', '135']
['user.name', 'LIKE', "{$fullName}%"]
['status', 'IN', ['active', 'pending']]
['subscription', 'IS', null]
```

- Column names are quoted; `table.column` is supported for joined tables.
- Values are quoted, including every element of an `IN` list.
- `LIKE` accepts a fourth element as the `ESCAPE` character.
- A `Laminas\Db\Sql\Expression`, either as the whole condition or as the column, is used verbatim.
- A plain string condition is used verbatim. Never interpolate user input into one.

Joins, grouping, and having are set on the model before the call:

```php
$table->setFindJoin('join `userrole` on `userrole`.`userId` = `user`.`id`');
$table->setGroupBy('group by `user`.`id`');
$table->setHaving('having count(*) > 1');
```

### findSimple()

Same arguments as `find()` plus `$columns`, and returns a `ResultSet` of exactly those columns
without touching the cache. Use it when you need a projection rather than whole rows:

```php
$ids = array_column($table->findSimple($params, 0, 0, false, $total, ['id'])->toArray(), 'id');
```

### mget()

```php
$rows = $table->mget([12, 13, 14]);   // keyed by id, missing rows omitted
```

`CachedTable` only: one batched cache request for the whole set, then `get()` for the ids the cache
did not hold. Rows that no longer exist are omitted, never deleted.

### getList()

Every row of the table, keyed by id. On `CachedTable` an unlimited call is cached whole under
`table.<name>.list`, so use it only for small reference tables.

## Writing

| Method | Effect |
| --- | --- |
| `insert(array $set)` | Inserts and returns the new id. Unknown fields are dropped. |
| `create(array $params)` | Insert, load the new row onto the model, and return its id. |
| `update(array $params, $where)` | Updates and returns affected rows. |
| `set(array $data, $id = false)` | Updates one row and reloads the model from it. |
| `delete($where)` | Accepts an id or a where expression. |
| `deleteById($id)` | Deletes one row by primary key. |

On `CachedTable` each of these drops the affected row from the cache and bumps the table's find
generation. Writes made with raw SQL bypass them: call `cacheDelete($id)` and
`invalidateFindCache()` yourself.

Locks and transactions are available for multi-step writes:

```php
$table->getLock($name, false, 10);
$table->startTransaction();
// ...
$table->commit();      // or rollback()
$table->releaseLock($name);
```

## Caching

### Keys

| Key | Holds |
| --- | --- |
| `table.<name>.<id>` | One hydrated row. |
| `table.<name>.<id>.<lang>` | One hydrated row of a `LocalizableTable`. |
| `table.<name>.list` | The whole `getList()` result. |
| `table.<name>.findgen` | Generation token of the table's cached lists. |
| `table.<name>.find.<generation>.<hash>` | Ids and total of one `find()` query. |

`cacheGet()`, `cacheSet()`, `cacheDelete()`, and `cacheDeleteByMask()` prefix a key part with
`table.<name>.` and are the only supported way to reach the cache from a model.

### Hydrating relations

`getUncached()` is called once per cache miss and its return value is what gets cached, so every
relation and computed field belongs there and is then free on later reads:

```php
class OrdersTable extends CachedTable
{
    public function getUncached($id)
    {
        $order = parent::getUncached($id);
        $order->items = (new OrderItemsTable())->find([['order_id', '=', (int) $id]]);
        return $order;
    }
}
```

Load related rows through the related model's `get()`/`find()`, not with a join, so that each table
keeps its own cache entries.

Treat rows returned by `get()`, `mget()`, and `find()` as read-only. They are shared, so mutating one
corrupts the cached copy for everybody.

### Cached find()

Opt in per model with a TTL in seconds:

```php
class NewsTable extends CachedTable
{
    protected $findCacheTtl = 300;   // 0, the default, queries the ids every time
}
```

Only ids and the total are cached, never the rows, so an edited row shows up in every cached list
immediately. Writes replace `table.<name>.findgen`, which invalidates every list of the table with a
single cache write and needs no key scanning.

Two hooks scope the keys when a table caches rows per language or per tenant:

```php
protected function itemCacheKey($id)   // key shared by get() and mget()
protected function findCacheScope()    // extra parts of the find() cache key
```

`LocalizableTable` implements both for `$this->lang`.

### Backends

| | `CodeIT\Cache\Redis` | `CodeIT\Cache\Memcache` |
| --- | --- | --- |
| `get()` / `set()` / `deleteCache()` | yes | yes |
| `mget()` batched read | yes (`MGET`) | yes (multi-key `get()`) |
| `deleteByMask()` | yes (`SCAN`, non-blocking) | no |

`LocalizableTable` deletes by mask and therefore requires Redis. Plain `CachedTable`, cached `find()`
included, works on either backend.

## Localized tables

`LocalizableTable` reads and writes a second table named `<name>local`, keyed by `id` and `lang`.

```php
class NewsTable extends LocalizableTable
{
    protected $localFields = ['name', 'text'];

    public function __construct($id = null)
    {
        parent::__construct('news', $id);
    }
}
```

- `get()` merges the active language's translation into the row and caches the result per language.
- `getFullLocalData($id)` returns the row with every translation, shaped for a multilanguage form.
- `set()` and `insert()` accept `['name' => [1 => 'English', 2 => 'Ukrainian']]` and write both tables.
- `getByNameWithLang($name)` looks a row up by its slug in the active language.

The active language comes from `CodeIT\Utils\Registry::get('lang')` when the application registers
one, and stays `1` otherwise. Register it once per request:

```php
Registry::set('lang', $langId);
```
