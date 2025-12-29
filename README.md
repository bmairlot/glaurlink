# Glaurlink

A lightweight, zero-dependency ORM for PHP 8.4+ and MariaDB/MySQL.

## Features

- **Minimal Dependencies** — Only requires PHP core extensions (mysqli, ctype)
- **Type-Safe Models** — Automatic type validation using PHP reflection
- **Enum Support** — Native PHP backed enum integration for database columns
- **Simple CRUD** — Intuitive `find()`, `save()`, `insert()`, `collection()`, and `count()` methods
- **Search & Pagination** — Built-in support for LIKE queries, ordering, and pagination
- **Collections** — Type-safe, iterable collections implementing Iterator, ArrayAccess, and Countable
- **JSON Serialization** — Models and collections are JSON-serializable out of the box
- **Lightweight Migrations** — File-based migrations with transaction support and rollback capability

## Requirements

- PHP 8.4 or higher
- mysqli extension
- ctype extension
- MariaDB or MySQL database

## Installation

```bash
composer require ancalagon/glaurlink
```

## Quick Start

### Defining a Model

Create a model by extending the base `Model` class:

```php
<?php

use Ancalagon\Glaurlink\Model;

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = ['name', 'email', 'is_active'];

    public ?int $id = null;
    public string $name;
    public string $email;
    public bool $is_active = false;
}
```

### Basic Operations

```php
<?php

$dbh = new mysqli('localhost', 'user', 'password', 'database');

// Create and save a new record
$user = new User([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
$user->save($dbh);

// Find a single record
$user = User::find($dbh, ['id' => 1]);
$user = User::find($dbh, ['email' => 'john@example.com']);

// Update a record
$user->name = 'Jane Doe';
$user->save($dbh);

// Get a collection of records
$activeUsers = User::collection($dbh, conditions: ['is_active' => true]);

// Count records
$count = User::count($dbh, ['is_active' => true]);
```

### Working with Collections

The `collection()` method returns a `Collection` object with full iteration support:

```php
<?php

// Fetch with conditions, ordering, and pagination
$users = User::collection(
    $dbh,
    conditions: ['is_active' => true],
    orderBy: ['name' => 'ASC'],
    limit: 10,
    offset: 0
);

// Search across multiple columns
$users = User::collection(
    $dbh,
    searchTerm: 'john',
    searchColumns: ['name', 'email']
);

// Iterate over results
foreach ($users as $user) {
    echo $user->name . "\n";
}

// Array-like access
$firstUser = $users[0];
$totalCount = count($users);

// JSON serialization
echo json_encode($users);
```

### Using Enums

Glaurlink supports PHP backed enums for type-safe column values:

```php
<?php

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

class User extends Model
{
    protected static string $table = 'users';

    public ?int $id = null;
    public string $name;
    public UserStatus $status = UserStatus::Pending;
}

// Enums are automatically converted when reading from/writing to the database
$user = new User(['name' => 'John', 'status' => UserStatus::Active]);
$user->save($dbh);

// Find by enum value
$activeUsers = User::collection($dbh, conditions: ['status' => UserStatus::Active]);
```

## Migrations

Glaurlink includes a lightweight migration system for managing database schema changes.

### Migration File Location

By default, migrations are loaded from `database/migrations` relative to your project root. You can customize this in your `composer.json`:

```json
{
    "extra": {
        "glaurlink": {
            "migrations_path": "database/migrations"
        }
    }
}
```

### Creating a Migration

Create a PHP file in your migrations directory. Files are applied in lexicographical order, so prefix with a timestamp:

**`database/migrations/20251229120000_create_users_table.php`**

```php
<?php

return [
    'up' => [
        "CREATE TABLE users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            status ENUM('active', 'inactive', 'pending') NOT NULL DEFAULT 'pending',
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        "DROP TABLE IF EXISTS users;",
    ],
];
```

### Running Migrations

```php
<?php

use Ancalagon\Glaurlink\Migration;

$dbh = new mysqli('localhost', 'user', 'password', 'database');

// Apply all pending migrations
Migration::migrate($dbh);

// Apply from a specific directory
Migration::migrate($dbh, __DIR__ . '/database/migrations');

// Roll back the last batch
Migration::rollback($dbh);

// Roll back multiple batches
Migration::rollback($dbh, steps: 2);
```

### Migration Behavior

- Each migration runs within a transaction — on error, changes are rolled back
- Applied migrations are tracked in a `glaurlink_migrations` table
- Migrations applied together share the same batch number
- Rollbacks are performed in reverse order by batch
- Optionally, applied migration files can be moved to an `applied/` subdirectory

## API Reference

### Model Methods

| Method | Description |
|--------|-------------|
| `__construct(array $attributes = [])` | Create a new model instance |
| `static create(array $attributes = [])` | Factory method to create a new instance |
| `fill(array $attributes)` | Mass-assign attributes |
| `save(mysqli $dbh)` | Insert or update the record |
| `insert(mysqli $dbh)` | Explicitly insert a new record |
| `static find(mysqli $dbh, array $attributes)` | Find a single record by conditions |
| `static collection(mysqli $dbh, ...)` | Fetch multiple records with filtering |
| `static count(mysqli $dbh, array $conditions = [])` | Count matching records |
| `jsonSerialize()` | Get array representation for JSON encoding |

### Collection Methods

| Method | Description |
|--------|-------------|
| `count()` | Get the number of items |
| `toArray()` | Convert to a plain array |
| `jsonSerialize()` | Get array representation for JSON encoding |
| Array access | `$collection[0]`, `isset($collection[0])` |
| Iteration | `foreach ($collection as $item)` |

### Migration Methods

| Method | Description |
|--------|-------------|
| `static migrate(mysqli $dbh, ...)` | Apply pending migrations |
| `static rollback(mysqli $dbh, ...)` | Roll back migrations by batch |

## License

MIT License — see [LICENSE](LICENSE) for details.

## Author

Bruno Mairlot