# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] - 2026-05-15

### Fixed

- `Model::collection()` now emits `` `col` IS NULL `` for null condition values,
  matching the behavior of `find()` and `count()`. Previously, passing
  `['col' => null]` produced `` `col` = ? `` bound to NULL, which never matched
  in SQL.

### Changed

- Bumped `phpstan/phpstan` dev dependency from `^1.10` to `^2`. PHPStan 1.x
  could not parse PHP 8.4's `new Foo()->method()` syntax used throughout the
  codebase; analysis now runs to completion.
- Annotated `Model` with `@phpstan-consistent-constructor` to formalize the
  constructor signature as part of the framework contract for subclasses.