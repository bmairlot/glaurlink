<?php

namespace Ancalagon\Glaurlink;

use mysqli;
use Throwable;

/**
 * Lightweight file-based migrations for Glaurlink using mysqli.
 *
 * Migration files live in the consuming application's project, not in this library.
 * By default we look into "database/migrations" under the current working directory.
 *
 * File format (PHP): a file returning an array with 'up' and 'down' keys, each being either
 * a string (single SQL statement) or an array of SQL statements.
 * Example:
 *   <?php
 *   return [
 *       'up' => [
 *           "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB;",
 *           "ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER name;",
 *       ],
 *       'down' => [
 *           "DROP TABLE IF EXISTS users;",
 *       ],
 *   ];
 */
class Migration
{
    public const DEFAULT_MIGRATIONS_PATH = 'database/migrations';
    public const APPLIED_SUBDIR = 'applied';

    private const TABLE = 'glaurlink_migrations';

    /**
     * Apply all pending migrations found in the migrations directory.
     *
     * @param mysqli $dbh Active mysqli connection
     * @param string|null $migrationsPath Optional base path to migrations directory
     * @param bool $moveApplied If true, move files to an "applied" subdirectory after applying
     * @param bool $verbose If true, print status messages to stdout
     * @throws Exception
     */
    public static function migrate(mysqli $dbh, ?string $migrationsPath = null, bool $moveApplied = true,bool $verbose = true): void
    {
        self::ensureTable($dbh);
        $dir = self::resolveMigrationsPath($migrationsPath);

        $all = self::listMigrationFiles($dir);
        if (empty($all)) {
            // Nothing to do
            return;
        }

        $applied = self::getAppliedMap($dbh); // name => batch
        $pending = array_values(array_filter($all, fn(string $file) => !array_key_exists(basename($file), $applied)));
        if (empty($pending)) {
            return;
        }

        $nextBatch = self::getLastBatch($dbh) + 1;

        foreach ($pending as $file) {
            $def = self::loadMigrationDefinition($file);
            $stmts = self::normalizeStatements($def['up'] ?? null);
            if (empty($stmts)) {
                throw new Exception("Migration '" . basename($file) . "' has no 'up' statements.");
            }

            self::executeStatementsInTransaction($dbh, $stmts);

            // Record application
            $stmt = $dbh->prepare("INSERT INTO `" . self::TABLE . "` (`name`, `batch`, `applied_at`) VALUES (?, ?, NOW())");
            if (!$stmt) {
                throw new Exception("Failed to prepare insert into migrations: " . $dbh->error);
            }
            $name = basename($file);
            $stmt->bind_param('si', $name, $nextBatch);
            if (!$stmt->execute()) {
                throw new Exception("Failed to record migration: " . $stmt->error);
            }
            $stmt->close();

            if ($moveApplied) {
                self::moveToAppliedDir($file, $dir);
            }
            if($verbose){
                echo "Applied migration successfully: $name\n";
            }
        }
    }

    /**
     * Roll back the last migration batch(es).
     *
     * @param mysqli $dbh
     * @param int $steps Number of batches to roll back (default 1)
     * @param string|null $migrationsPath Optional base path to migrations directory
     * @param bool $moveBack If true, move files back from applied/ to base directory after rollback
     * @throws Exception
     */
    public static function rollback(mysqli $dbh, int $steps = 1, ?string $migrationsPath = null, bool $moveBack = false): void
    {
        if ($steps < 1) {
            return;
        }
        self::ensureTable($dbh);
        $dir = self::resolveMigrationsPath($migrationsPath);
        $appliedDir = self::appliedDir($dir);

        $currentBatch = self::getLastBatch($dbh);
        if ($currentBatch === 0) {
            return; // nothing applied
        }

        for ($i = 0; $i < $steps && $currentBatch > 0; $i++, $currentBatch--) {
            $names = self::getMigrationsForBatch($dbh, $currentBatch); // in apply order (by id)
            if (empty($names)) {
                continue;
            }
            // Rollback in reverse order
            $names = array_reverse($names);
            foreach ($names as $name) {
                $file = self::locateMigrationFile($name, $dir, $appliedDir);
                if ($file === null) {
                    throw new Exception("Migration file not found for rollback: $name");
                }
                $def = self::loadMigrationDefinition($file);
                $stmts = self::normalizeStatements($def['down'] ?? null);
                if (empty($stmts)) {
                    throw new Exception("Migration '$name' has no 'down' statements.");
                }
                self::executeStatementsInTransaction($dbh, $stmts);

                // Remove from table
                $stmt = $dbh->prepare("DELETE FROM `" . self::TABLE . "` WHERE `name` = ? LIMIT 1");
                if (!$stmt) {
                    throw new Exception("Failed to prepare delete from migrations: " . $dbh->error);
                }
                $stmt->bind_param('s', $name);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete migration record: " . $stmt->error);
                }
                $stmt->close();

                if ($moveBack && str_starts_with($file, $appliedDir)) {
                    // Move back to base dir
                    @mkdir($dir, 0777, true);
                    $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($file);
                    if (!@rename($file, $target)) {
                        // ignore move errors, but keep behavior predictable
                    }
                }
            }
        }
    }

    // --------------- Internals ---------------

    /**
     * Ensure the migration tracking table exists.
     * @throws Exception
     */
    private static function ensureTable(mysqli $dbh): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dbh->query($sql)) {
            throw new Exception("Failed to ensure migrations table: " . $dbh->error);
        }
    }

    private static function getAppliedMap(mysqli $dbh): array
    {
        $res = $dbh->query("SELECT `name`, `batch` FROM `" . self::TABLE . "` ORDER BY `id` ASC");
        if (!$res) {
            throw new Exception("Failed to read applied migrations: " . $dbh->error);
        }
        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[$row['name']] = (int)$row['batch'];
        }
        $res->free();
        return $map;
    }

    private static function getMigrationsForBatch(mysqli $dbh, int $batch): array
    {
        $stmt = $dbh->prepare("SELECT `name` FROM `" . self::TABLE . "` WHERE `batch` = ? ORDER BY `id` ASC");
        if (!$stmt) {
            throw new Exception("Failed to prepare batch query: " . $dbh->error);
        }
        $stmt->bind_param('i', $batch);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute batch query: " . $stmt->error);
        }
        $result = $stmt->get_result();
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[] = $row['name'];
        }
        $stmt->close();
        return $names;
    }

    private static function getLastBatch(mysqli $dbh): int
    {
        $res = $dbh->query("SELECT COALESCE(MAX(`batch`), 0) AS b FROM `" . self::TABLE . "`");
        if (!$res) {
            throw new Exception("Failed to get last batch: " . $dbh->error);
        }
        $row = $res->fetch_assoc();
        $res->free();
        return (int)$row['b'];
    }

    public static function resolveMigrationsPath(?string $override=null): string
    {
        if ($override !== null && $override !== '') {
            return rtrim(self::toAbsolutePath($override), DIRECTORY_SEPARATOR);
        }
        $root = dirname( __FILE__,5);
        $composerFile = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $path = null;
        if (is_file($composerFile)) {
            $json = json_decode((string)file_get_contents($composerFile), true);
            if (is_array($json) && isset($json['extra']['glaurlink']['migrations_path'])) {
                $path = (string)$json['extra']['glaurlink']['migrations_path'];
            }
        }
        if (!$path) {
            $path = self::DEFAULT_MIGRATIONS_PATH;
        }
        return rtrim(self::toAbsolutePath($root . DIRECTORY_SEPARATOR . $path), DIRECTORY_SEPARATOR);
    }

    private static function toAbsolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('~^[A-Za-z]:\\\\~', $path) === 1) {
            // absolute (linux/mac or windows)
            return $path;
        }
        $root = getcwd() ?: '.';
        return $root . DIRECTORY_SEPARATOR . $path;
    }

    private static function appliedDir(string $baseDir): string
    {
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::APPLIED_SUBDIR;
    }

    private static function listMigrationFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private static function locateMigrationFile(string $name, string $baseDir, string $appliedDir): ?string
    {
        $candidates = [
            rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name,
            rtrim($appliedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name,
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * @throws Exception
     */
    private static function loadMigrationDefinition(string $file): array
    {
        /** @var mixed $def */
        $def = (static function (string $__file) {
            return require $__file;
        })($file);

        if (!is_array($def)) {
            throw new Exception("Migration file must return an array: " . basename($file));
        }
        if (!array_key_exists('up', $def)) {
            throw new Exception("Migration file missing 'up' key: " . basename($file));
        }
        if (!array_key_exists('down', $def)) {
            throw new Exception("Migration file missing 'down' key: " . basename($file));
        }
        return $def;
    }

    /**
     * @param string|array<int,string>|null $value
     * @return array<int,string>
     */
    private static function normalizeStatements(string|array|null $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            return [trim($value)];
        }
        $out = [];
        foreach ($value as $stmt) {
            if (!is_string($stmt)) { continue; }
            $trim = trim($stmt);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }
        return $out;
    }

    /**
     * @param mysqli $dbh
     * @param array<int,string> $statements
     * @return void
     * @throws Exception
     * @throws Throwable
     */
    private static function executeStatementsInTransaction(mysqli $dbh, array $statements): void
    {
        if (empty($statements)) { return; }
        if (!$dbh->begin_transaction()) {
            throw new Exception("Failed to begin transaction: " . $dbh->error);
        }
        try {
            foreach ($statements as $sql) {
                if (!$dbh->query($sql)) {
                    throw new Exception("SQL error during migration: " . $dbh->error . " | SQL: " . $sql);
                }
            }
            if (!$dbh->commit()) {
                throw new Exception("Failed to commit transaction: " . $dbh->error);
            }else {
                if ($verbose) {

                }
            }
        } catch (Throwable $e) {
            $dbh->rollback();
            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), previous: $e);
        }
    }

    private static function moveToAppliedDir(string $file, string $baseDir): void
    {
        $appliedDir = self::appliedDir($baseDir);
        @mkdir($appliedDir, 0777, true);
        $target = rtrim($appliedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($file);
        @rename($file, $target);
    }
}
