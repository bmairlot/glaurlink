# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Glaurlink is a lightweight, zero-dependency ORM for PHP 8.4+ with MariaDB/MySQL support. The core philosophy is minimal dependencies—only PHP core extensions (mysqli, ctype) are used in production.

## Development Commands

```bash
# Run all tests
vendor/bin/phpunit

# Run a single test
vendor/bin/phpunit --filter testMethodName

# Static analysis
vendor/bin/phpstan analyse src/

# Code style check
vendor/bin/phpcs src/
```

## Architecture

The ORM consists of 5 classes in `src/`:

- **Model** - Base Active Record class providing CRUD operations (find, collection, count, save, insert, delete, deleteWhere). Uses PHP Reflection for type-safe property binding and prepared statements for all queries.

- **Collection** - Generic, type-safe collection implementing Iterator, ArrayAccess, Countable, and JsonSerializable. Query results return Collection objects, not arrays.

- **Migration** - File-based migration system with transaction support and batch tracking. Migrations live in `database/migrations/` by default.

- **CompositeKey** - Trait for models with multi-column primary keys. Never auto-increments; all key values must be set before save.

- **Exception** - Custom exception class for ORM errors.

## Recommended Model Layout (Two-Class Pattern)

Downstream projects this repository is tested against split each model across two files. Glaurlink does not enforce or detect this layout — it is a usage convention.

- A **base class** in a `Core/` subdirectory, named with a leading underscore on both the file (`_User.php`) and the class (`_User`). It is `abstract`, extends `Ancalagon\Glaurlink\Model`, and holds the schema: `$table`, `$primKeyArr`, `$generated`, and every typed property declaration that mirrors a database column (including property hooks for validation or normalization).
- A **public concrete class** in the parent directory (`User.php`, class `User`) extending the base class. It contains custom finders (e.g. `User::getFromSub($dbh, $sub)`), business methods, and computed accessors that are not backing-store columns. It does not declare column fields.

Application code instantiates and queries the public subclass: `new User()`, `User::find($dbh, ...)`.

### Why split

- **Regenerability.** The base class is shaped so it can be regenerated from the database schema — by a script, a prompt, or by hand — without destroying custom methods, which live in the public subclass.
- **Separation of concerns.** "What the table looks like" lives in one file; "what we do with it" lives in another.
- **Reduced merge friction.** Schema changes touch `Core/_*.php`; behavior changes touch the public subclass. The two rarely conflict.

### Recommendation, not a rule

Glaurlink uses Reflection on whatever class is passed to `find()` / `collection()` and does not care about file layout or class naming. The 3-level class hierarchy described under Key Implementation Details is a separate, technical requirement — it dictates *where* typed properties are declared, not what files those classes live in or how they are named. The two-class split is purely about file organization; adopt it if it fits your project, ignore it otherwise.

### Example

```php
// model/manager/Core/_User.php
namespace Ancalagon\Model\manager\Core;

use Ancalagon\Glaurlink\Model;

abstract class _User extends Model
{
    protected static string $table = 'user';
    protected static array $primKeyArr = ['id'];
    protected static array $generated = ['created_at', 'updated_at'];

    public ?int $id = null;
    public string $email = '';
    public bool $verified = false;
    // ... other typed property declarations matching the schema
}
```

```php
// model/manager/User.php
namespace Ancalagon\Model\manager;

use Ancalagon\Model\manager\Core\_User;

class User extends _User
{
    static function getFromSub($dbh, string $sub): ?self
    {
        return self::find($dbh, ['sub' => $sub]);
    }
}
```

The public class is what application code instantiates and queries; the base class is what the regenerator owns.

### Guidance for AI coding assistants

When adding or modifying a column on a model that follows this layout:

- Edit the base class under `Core/_*.php`, adding the typed property in the same style as sibling fields.
- Leave the public subclass untouched unless behavior (a method, a finder) is also being added.
- Treat `Core/_*.php` as code-it-writes, not as an off-limits generated artifact. The "do not hand-edit" guidance some projects attach to these files applies to ad-hoc human edits — not to structured prompts whose purpose is precisely to update the schema-mirroring class.

## Key Implementation Details

**Type System**: Reflection-based type detection with automatic coercion for primitives, union types, and nullable types.

**Enum Support**: Native PHP backed enums with automatic string↔enum conversion via tryFrom(). JSON serialization outputs enum values.

**Query Building**: Table/column names are backtick-wrapped. Type strings ('i', 'd', 's') are generated dynamically. NULL conditions use "IS NULL" instead of parameterized values.

**DB-managed columns**: `protected static array $generated = [...]` lists columns filled by the server (DEFAULT, ON UPDATE, generated). Null-valued `$generated` properties are omitted from INSERT/UPDATE SQL. Explicit non-null values are always emitted. `save($dbh, rehydrate: true)` re-reads the row after write to populate server-computed values (opt-in, no extra query by default).

**3-level class hierarchy**: The ORM uses `new ReflectionClass($this)->getParentClass()` to discover public properties representing DB columns. This means model classes must follow a 3-level pattern: `Model` → abstract schema class (declares properties) → concrete class. A concrete class extending Model directly will have no properties discovered. Test models must follow this pattern too.

**Column skip logic**: `shouldPersistProperty()` on Model centralizes all decisions about omitting columns from INSERT/UPDATE: uninitialized properties, auto-increment PK when null, `$generated` columns when null, and PK columns on UPDATE. The CompositeKey trait overrides only `isPrimaryKeyColumn()`.

## Development Constraints (from .junie/guidelines.md)

**Required:**
- Zero external dependencies in production code
- Use PHP 8.4+ features (typed properties, match expressions)
- Raw SQL with mysqli prepared statements
- Tests for all new features

**Forbidden:**
- External ORM libraries (Doctrine, Eloquent, Propel)
- Query builder libraries
- Unnecessary abstractions

## Testing

Tests require a MariaDB/MySQL server. Credentials are in `tests/.env` (not committed). The bootstrap (`tests/bootstrap.php`) creates a `test_<random>` database at startup and drops it on shutdown. If the database already exists, the suite aborts immediately.

Test models must use the 3-level hierarchy (see above): abstract schema class with properties, then an empty concrete class extending it.

Tables are created as real tables (not TEMPORARY) in `setUp()` and dropped in `tearDown()`.
