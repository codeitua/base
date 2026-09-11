# CodeIT Base Reference

`codeit/base` contains reusable CodeIT components for Laminas applications. The `CodeIT` namespace follows the shape of a standard Laminas/ZF2-style module and provides base controllers, table models, validators, filters, view helpers, form helpers, cache utilities, and general utilities used to start internal projects faster.

The package is kept in a separate repository because most projects can reuse these components unchanged. When a project needs different behavior, the usual approach is to extend the base class and override only the project-specific parts.

## File Structure

The main source tree is `src/`:

- `CodeIT\Controller` - base web, API, plugin, and command-facing controller helpers.
- `CodeIT\ACL` - authentication and ACL pre-dispatch integration.
- `CodeIT\Cache` - Redis and Memcache cache adapters and wrappers.
- `CodeIT\Model` - database table models and cache-aware table models.
- `CodeIT\Form` - base Laminas form classes, including localized-content forms.
- `CodeIT\Validator` - reusable validators for common table and form checks.
- `CodeIT\Filter` - reusable input filters.
- `CodeIT\View\Helper` - helpers for rendering forms and application data.
- `CodeIT\Utils` - small shared utility classes.

## Main Classes

### `CodeIT\Controller\AbstractController`

Base class for normal web controllers. Controllers that run only from CLI or controllers dedicated to REST/API handling should use a more specific base class instead.

Main responsibilities:

- Creates and stores the current user instance.
- Runs authorization before the dispatched action.
- Provides helpers for breadcrumbs and common view responses.
- Provides a uniform JSON response structure for AJAX actions.

Important properties:

- `$user` - current `Application\Lib\User` instance.
- `$lang` - current language identifier. The base class defaults it to `1`; applications may replace or extend this behavior.
- `$breadcrumbs` - breadcrumb data passed to the layout.
- `$isAjax` - whether the current action should be treated as AJAX.
- `$returnForbidden` - when true, forbidden actions return an explicit forbidden response instead of redirect-style behavior.

Important methods:

- `ready()` - called during dispatch before the action runs. It initializes `$user` and is the right place for subclasses to initialize controller dependencies that depend on the current user or locale.
- `setEventManager()` - attaches the dispatch listener that runs `ready()` and ACL authorization.
- `returnError()` - returns an error view model using the `error/index` template.
- `setBreadcrumbs()` - prepares breadcrumbs for the layout.
- `forbiddenAction()` - returns a 403 response for forbidden resources.
- `sendJSONResponse()` - returns a uniform JSON view response.
- `sendJSONError()` - returns a uniform JSON error response.
- `sendJSONRedirect()` - returns a uniform JSON redirect instruction.

Example:

```php
return $this->sendJSONResponse([
    'user' => $this->user->getProfileData(),
]);
```

Example error response:

```php
try {
    $user = $this->user->checkLogin($data['email'], $data['password']);
} catch (\Exception $e) {
    return $this->sendJSONError($e->getMessage(), $e->getCode());
}
```

Example redirect response:

```php
if ($this->user->getId()) {
    return $this->sendJSONRedirect(URL);
}
```

### `CodeIT\Controller\AbstractApiController`

Base class for REST/API controllers. It extends Laminas `AbstractRestfulController` and returns `JsonModel` responses.

Main responsibilities:

- Adds CORS response headers.
- Handles `OPTIONS` preflight requests.
- Initializes the current user.
- Runs ACL authorization before the requested API method.
- Parses JSON, query-string, form, file, and delete request data.
- Provides standard JSON success, error, authentication-required, and forbidden responses.

Important methods:

- `ready()` - initializes CORS, user state, and language state.
- `getRequestData()` - normalizes incoming request data.
- `returnData()` - returns JSON data with a configurable HTTP status code.
- `returnError()` - returns a JSON error with a configurable HTTP status code.
- `returnAuthenticationRequired()` - returns HTTP 401.
- `forbiddenAction()` - returns HTTP 403.

### `CodeIT\ACL\Authentication`

Pre-dispatch authentication and authorization helper. It expects the application to provide `Application\Lib\Acl`, where application-specific roles, resources, and rules are defined.

The application ACL class usually extends Laminas `Laminas\Permissions\Acl\Acl` and defines which roles may access which controller resources and actions.

Typical ACL concepts:

- `addRole()` - registers user roles. Roles may inherit permissions from another role.
- `addResource()` - registers controller resources that can be protected.
- `allow()` - grants access to an entire controller resource or selected actions.
- `deny()` - denies access to an entire controller resource or selected actions.

Example:

```php
$this->addRole('guest');
$this->addRole('user', 'guest');
$this->addRole('admin', 'user');

$this->addResource('Application\Controller\Index');

$this->allow('manager', 'Admin\Controller\User');
$this->allow('guest', 'Application\Controller\User', ['info']);
$this->deny('manager', 'Admin\Controller\User', ['delete']);
```

### `CodeIT\Cache\Redis`

Redis-backed cache adapter used by the table and Active Record layers in applications that register it as the `cache` service.

The current constructor accepts cache configuration and a `CodeIT\Cache\RedisWrapper` instance:

```php
'service_manager' => [
    'factories' => [
        'cache' => function ($container) {
            $appConfig = $container->get('ApplicationConfig');
            if ($container->has('Config')) {
                $appConfig = array_replace_recursive($appConfig, $container->get('Config'));
            }

            return new CodeIT\Cache\Redis(
                $appConfig['cache'],
                $container->get('redis')
            );
        },
        'redis' => function ($container) {
            $appConfig = $container->get('ApplicationConfig');
            if ($container->has('Config')) {
                $appConfig = array_replace_recursive($appConfig, $container->get('Config'));
            }

            $config = $appConfig['redis'];

            return new CodeIT\Cache\RedisWrapper(
                $config['host'],
                $config['port'],
                $config['db'],
                $config['options']
            );
        },
    ],
],
```

Configuration example:

```php
'cache' => [
    'enabled' => true,
    'namespace' => 'my-app:',
],
'redis' => [
    'host' => '127.0.0.1',
    'port' => 6379,
    'db' => 0,
    'options' => [
        \Redis::OPT_SERIALIZER => \Redis::SERIALIZER_IGBINARY,
    ],
],
```

Important methods:

- `set($key, $value, $ttl = 2678400)` - stores a value.
- `get($key)` - returns a value.
- `mget($keys)` - returns multiple values.
- `deleteCache($keys)` - deletes one or more keys.
- `deleteByMask($name)` - deletes keys by prefix mask.

### `CodeIT\Cache\Memcache`

Memcache-backed cache class for projects that use Memcache instead of Redis.

## Database Table Classes

`CodeIT\Model\AppTable`, `CodeIT\Model\CachedTable`, and `CodeIT\Model\LocalizableTable` provide table-oriented data access on top of Laminas DB.

### `CodeIT\Model\AppTable`

Abstract base class for table models. It extends Laminas `Laminas\Db\TableGateway\TableGateway`.

Main benefits:

- Built-in search helpers with filtering, grouping, joins, and ordering.
- Safe create/update behavior through the `$goodFields` allow-list.
- Optional hiding of private fields for public/API responses through `$privateFields`.
- Transaction helpers.
- MySQL advisory lock helpers.

Important properties:

- `$table` - database table name handled by the model.
- `$id` - current row identifier.
- `$goodFields` - list of fields allowed in `create()`, `insert()`, `set()`, and `update()`.
- `$privateFields` - fields removed when `get()` or `find()` is called with `$publicOnly = true`.
- `ID_COLUMN` - primary identifier column name. Defaults to `id`.

Every concrete table model should call the parent constructor with the table name and optional ID:

```php
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

Important methods:

- `get($id, $publicOnly = false)` - loads one row by ID.
- `find($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $publicOnly = false)` - searches rows using condition arrays.
- `findSimple($params, $limit = 0, $offset = 0, $orderBy = false, &$total = null, $columns = ['id'])` - searches and returns a Laminas `ResultSet`.
- `setGroupBy()`, `getGroupBy()`, `setHaving()`, `getHaving()`, `setFindJoin()`, `getFindJoin()` - configure SQL fragments for search queries.
- `insert($set)` - inserts a row and returns the new ID.
- `create($params)` - inserts a row and sets the current model ID.
- `update($params, $where = null)` - updates rows.
- `set($data, $id = false, $setDataToObject = true)` - updates the current row or a supplied row ID.
- `deleteById($id)` - deletes one row by ID.
- `delete($where)` - deletes rows by condition.
- `getId()` - returns the current ID.
- `setId($id)` - loads a row by ID and copies matching properties to the object.
- `getLock()` and `releaseLock()` - MySQL advisory lock helpers.
- `startTransaction()`, `commit()`, `rollback()` - transaction helpers.

`find()` accepts condition arrays such as:

```php
['id', '>=', '135']
['user.name', 'LIKE', "{$fullName}%"]
['status', 'IN', ['active', 'pending']]
['subscription', 'IS', null]
```

The third value may be omitted when the intended SQL value is `NULL`:

```php
['subscription', 'IS']
```

Example:

```php
$socialnetworkTable = new \Application\Model\User\SocialnetworkTable();
$socialnetworkProfiles = $socialnetworkTable->find([
    ['userId', '=', $id],
], false, 0);
```

Extended example:

```php
namespace Application\Model;

use CodeIT\Model\AppTable;

class UserTable extends AppTable
{
    protected $name;
    protected $level;
    protected $image;
    protected $department;
    protected $department_id;

    protected $goodFields = [
        'name',
        'level',
        'department_id',
    ];

    public function __construct($userId = null)
    {
        parent::__construct('user', $userId);
    }

    public function setId($id)
    {
        $item = parent::setId($id);
        $this->department = new DepartmentTable($this->department_id);

        return $item;
    }

    public function get($id, $publicOnly = false)
    {
        $item = parent::get($id, $publicOnly);
        $item->image = 'noimage.jpg';

        $id = (int) $id;
        if (is_readable(IMAGES_DIR . '/users/' . $id . '.jpg')) {
            $item->image = $id . '.jpg';
        }

        return $item;
    }
}
```

### `CodeIT\Model\CachedTable`

Cache-aware table model that extends `CodeIT\Model\AppTable`.

Main benefits:

- Provides the same general API as `AppTable`, making it easy to add or remove caching for a table model.
- Caches complete rows and reads them back without hitting the database.
- Clears row/list cache entries when data is changed through the built-in mutation methods.

Important methods:

- `get($id, $publicOnly = false)` - loads from cache first, then calls `getUncached()` and stores the result.
- `getUncached($id)` - loads directly from the database. Override this when a model enriches rows with computed fields or file-system data.
- `mget($ids, $publicOnly = false)` - loads many rows by ID using cache.
- `find()` - finds matching IDs and loads rows through `mget()`.
- `update()` - updates rows and clears row cache when the `where` condition identifies a numeric ID.
- `set()` - updates the current row and clears its cache.
- `deleteById()` - deletes a row and clears its cache.
- `delete()` - deletes by condition. Numeric conditions behave like delete-by-ID.
- `cacheGet()`, `cacheSet()`, `cacheDelete()`, `cacheDeleteByMask()` - direct cache helpers.

When caching is enabled, be careful with custom write queries. If a subclass updates or deletes data without using `set()`, `update()`, `delete()`, or `deleteById()`, it must clear the relevant cache keys itself.

Extended example:

```php
namespace Application\Model;

use CodeIT\Model\CachedTable;

class UserTable extends CachedTable
{
    protected $name;
    protected $level;
    protected $image;
    protected $department;
    protected $department_id;

    protected $goodFields = [
        'name',
        'level',
        'department_id',
    ];

    public function __construct($userId = null)
    {
        parent::__construct('user', $userId);
    }

    public function setId($id)
    {
        $item = parent::setId($id);
        $this->department = new DepartmentTable($this->department_id);

        return $item;
    }

    public function getUncached($id)
    {
        $item = parent::getUncached($id);
        $item->image = 'noimage.jpg';

        $id = (int) $id;
        if (is_readable(IMAGES_DIR . '/users/' . $id . '.jpg')) {
            $item->image = $id . '.jpg';
        }

        $item->department = (new DepartmentTable())->get($item->department_id);

        return $item;
    }
}
```

If `getUncached()` enriches a row using data from another table or another external source, plan cache invalidation for every dependency.

### `CodeIT\Model\LocalizableTable`

Localized table model that extends `CodeIT\Model\CachedTable`. It is intended for models where localized field values are stored in a separate table.

Main benefits:

- Reuses the same table API as `CachedTable`.
- Merges localized values for the current language into loaded rows.
- Provides helper methods for loading and saving localized data in a form-friendly structure.

Important properties:

- `$locTable` - table that stores localized values. Defaults to `{table}local`.
- `$lang` - current language ID.
- `$localFields` - fields stored in the localized table. Do not include `id` or `lang` in this list.

Important methods:

- `getByNameWithLang($name)` - loads an item by `name` for the current language and caches it by name/language.
- `getLocalData($params = [], $limit = null, $offset = 0)` - returns localized data grouped by language.
- `getFullLocalData($id)` - returns the base row plus localized fields grouped for form editing.
- `updateLocData($data)` - inserts or updates localized rows for the current ID.
- `get()`, `getUncached()`, `set()`, and `insert()` - override the `CachedTable` behavior to account for localized data.

Example:

```php
$template = $this->getByNameWithLang('Forgot password');
```

Example form-loading flow:

```php
if ($id > 0) {
    try {
        $data = (array) $this->templateTable->getFullLocalData($id);
        $form->setData($data);
    } catch (\Exception $e) {
        $this->error = _('Template not found');
    }
}
```

Expected localized data structure:

```php
[
    'subject' => [
        1 => 'Forgot password',
        2 => 'Password recovery',
    ],
]
```

Example controller flow:

```php
if ($this->request->isPost()) {
    $data = $this->request->getPost()->toArray();
    $form->setData($data);

    if (isset($data['submit']) && $form->isValid()) {
        $data = $form->getData();

        if ($id > 0) {
            $this->templateTable->setId($id);
            $this->templateTable->set($data);
        } else {
            $id = $this->templateTable->insert($data);
            return $this->redirect()->toUrl(URL . 'admin/' . $this->url . '/edit/' . $id);
        }
    }
}
```

For localized editing, use `CodeIT\Form\MultilanguageForm`.

## Form Classes And View Helpers

### `CodeIT\View\Helper\WrappedForm`

Renders a complete Laminas form in one line. It opens the form tag, renders all elements in definition order, and closes the form tag. Element rendering is delegated to `CodeIT\View\Helper\WrappedElement`.

Example:

```php
<?= $this->wrappedForm($form); ?>
```

Benefits:

- One line is enough to render a whole form.
- View templates do not need to change every time a field is added or removed.

Use manual element rendering when a form needs custom layout, columns, or extra HTML that does not belong to the form itself.

### `CodeIT\View\Helper\WrappedElement`

Renders one Laminas form element with the shared project markup.

Main responsibilities:

- Renders consistent HTML wrappers for form elements.
- Preserves element attributes.
- Renders labels.
- Renders validation errors when present.

Example:

```php
<?= $this->form()->openTag($form); ?>
<?= $this->wrappedElement($form->get('csrf')); ?>
<?= $this->wrappedElement($form->get('id')); ?>

<div class="user-wrap user-wrap-left">
    <div class="avatar-right-form-control">
        <?= $this->wrappedElement($form->get('firstname')); ?>
        <?= $this->wrappedElement($form->get('lastname')); ?>
        <?= $this->wrappedElement($form->get('email')); ?>

        <div class="birthdate-info">
            <?= $this->wrappedElement($form->get('birthmonth')); ?>
            <?= $this->wrappedElement($form->get('birthday')); ?>
            <?= $this->wrappedElement($form->get('birthyear')); ?>
        </div>
    </div>
</div>

<?= $this->wrappedElement($form->get('cancel')); ?>
<?= $this->wrappedElement($form->get('submit')); ?>
<?= $this->form()->closeTag($form); ?>
```

### `CodeIT\Form\Form`

Base form class built on Laminas `Laminas\Form\Form`.

Main benefit:

- Automatically installs the form input filter when `setData()` is called.

Important method:

- `getInpFilter()` - abstract method that every concrete subclass must implement. It should return the input filter used for validation.

Example:

```php
$form = new \Auth\Form\LoginForm();

if ($this->request->isPost()) {
    $data = $this->request->getPost();
    $form->setData($data);

    if ($form->isValid()) {
        $data = $form->getData();
        $user = $this->user->checkLogin($data['email'], $data['password']);
    }
}
```

### `CodeIT\Form\MultilanguageForm`

Base form for editing localized content. It extends `CodeIT\Form\Form`.

Main benefits:

- Overrides `setData()` and `getData()` to support fields grouped by language.
- Lets users work with localized fields as normal form fields.

Use it together with `CodeIT\Model\LocalizableTable` for localized admin forms.
