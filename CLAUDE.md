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

## Key Implementation Details

**Type System**: Reflection-based type detection with automatic coercion for primitives, union types, and nullable types.

**Enum Support**: Native PHP backed enums with automatic string↔enum conversion via tryFrom(). JSON serialization outputs enum values.

**Query Building**: Table/column names are backtick-wrapped. Type strings ('i', 'd', 's') are generated dynamically. NULL conditions use "IS NULL" instead of parameterized values.

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
