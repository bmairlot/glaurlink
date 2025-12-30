<?php
/**
 * @copyright Copyright (c) 2024 Bruno Mairlot
 * @license MIT
 */
namespace Ancalagon\Glaurlink;

use mysqli;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * Trait for models with composite primary keys
 *
 * Use this trait when a table has a composite primary key (multiple columns).
 * Note: Composite keys cannot use auto-increment, so all key values must be
 * set before saving.
 *
 * @example
 * ```php
 * class UserRole extends Model
 * {
 *     use CompositeKeyTrait;
 *
 *     protected static string $table = 'user_roles';
 *     protected static array $primaryKeys = ['user_id', 'role_id'];
 *
 *     public int $user_id;
 *     public int $role_id;
 *     public string $assigned_at;
 * }
 *
 * // Usage:
 * $userRole = new UserRole(['user_id' => 1, 'role_id' => 5]);
 * $userRole->assigned_at = date('Y-m-d H:i:s');
 * $userRole->save($dbh);
 *
 * // Find by composite key:
 * $found = UserRole::findByKey($dbh, ['user_id' => 1, 'role_id' => 5]);
 * ```
 */
trait CompositeKey
{
    /**
     * Array of column names that form the composite primary key
     * Must be defined in the using class
     *
     * @var array<string>
     */
    protected static array $primaryKeys = [];

    /**
     * Check if this is a new (unsaved) record
     *
     * For composite keys, a record is considered new if ANY of the
     * primary key components is null.
     */
    public function isNew(): bool
    {
        foreach (static::$primaryKeys as $key) {
            if ($this->$key === null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the composite primary key values as an associative array
     *
     * @return array<string, mixed>
     */
    public function getKey(): array
    {
        $values = [];
        foreach (static::$primaryKeys as $key) {
            $values[$key] = $this->$key;
        }
        return $values;
    }

    /**
     * Set multiple primary key values at once
     *
     * @param array<string, mixed> $values
     */
    protected function setKey(mixed $values): void
    {
        if (!is_array($values)) {
            throw new RuntimeException("Composite key requires an array of values");
        }
        foreach ($values as $key => $value) {
            if (in_array($key, static::$primaryKeys, true)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get the primary key column names
     *
     * @return array<string>
     */
    public static function getKeyName(): array
    {
        return static::$primaryKeys;
    }

    /**
     * Composite keys never use auto-increment
     */
    public static function isAutoIncrement(): bool
    {
        return false;
    }

    /**
     * Build WHERE clause for composite primary key
     *
     * @return array{clause: string, params: array, types: string}
     */
    protected function buildPrimaryKeyWhere(): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        foreach (static::$primaryKeys as $key) {
            $value = $this->$key;

            if ($value === null) {
                throw new RuntimeException("Cannot build WHERE clause: primary key component '$key' is null");
            }

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $conditions[] = "`$key` = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
        }

        return [
            'clause' => implode(' AND ', $conditions),
            'params' => $params,
            'types' => $types
        ];
    }

    /**
     * Find a record by its composite primary key
     *
     * @param mysqli $dbh Database connection
     * @param array<string, mixed> $keys Associative array of key column => value
     * @return static|null
     * @throws Exception
     */
    public static function findByKey(mysqli $dbh, mixed $keys): ?static
    {
        if (!is_array($keys)) {
            throw new RuntimeException("Composite key requires an array of key => value pairs");
        }

        // Validate that all key components are provided
        foreach (static::$primaryKeys as $keyName) {
            if (!array_key_exists($keyName, $keys)) {
                throw new RuntimeException("Missing primary key component: $keyName");
            }
        }

        // Only use the primary key columns for the find
        $conditions = array_intersect_key($keys, array_flip(static::$primaryKeys));

        return static::find($dbh, $conditions);
    }

    /**
     * Save the record (insert or update)
     *
     * @throws Exception
     */
    public function save(mysqli $dbh): bool
    {
        // We want to get properties of only the _Object class and not the effective one
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $isNew = $this->isNew();

        // For composite keys, we need to determine if this is truly a new record
        // by checking if a record with these keys already exists
        if (!$isNew) {
            $existing = static::findByKey($dbh, $this->getKey());
            $isNew = ($existing === null);
        }

        $updates = [];
        $params = [];
        $types = '';

        foreach ($properties as $prop) {
            $name = $prop->getName();

            // For UPDATE, skip primary key columns in the SET clause
            if (!$isNew && in_array($name, static::$primaryKeys, true)) {
                continue;
            }

            $value = $prop->getValue($this);
            $value = $this->prepareValueForDatabase($value, $prop->getType());

            $updates[] = "`$name` = ?";
            $params[] = $value;

            // Determine parameter type for bind_param
            if (is_int($value) || $prop->getType()?->getName() === 'bool') {
                $types .= 'i';
            } elseif (is_double($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        if ($isNew) {
            // INSERT operation
            $sql = "INSERT INTO " . static::$table . " SET " . implode(', ', $updates);
        } else {
            // UPDATE operation
            $pkWhere = $this->buildPrimaryKeyWhere();
            $sql = "UPDATE " . static::$table . " SET " . implode(', ', $updates) . " 
                   WHERE " . $pkWhere['clause'];
            $params = array_merge($params, $pkWhere['params']);
            $types .= $pkWhere['types'];
        }

        // Prepare and execute the statement
        $stmt = $dbh->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        // Bind parameters dynamically
        if (!empty($params)) {
            $bind_names[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind_names[] = &$params[$i];
            }
            call_user_func_array(array($stmt, 'bind_param'), $bind_names);
        }

        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }

        $stmt->close();
        return true;
    }

    /**
     * Insert a new record
     *
     * @throws Exception
     */
    public function insert(mysqli $dbh): bool
    {
        // Validate that all primary key components are set
        foreach (static::$primaryKeys as $key) {
            if ($this->$key === null) {
                throw new RuntimeException("Cannot insert: primary key component '$key' is null. Composite keys require all values to be set.");
            }
        }

        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $columns = [];
        $values = [];
        $params = [];
        $types = '';

        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($this);

            // Prepare value for database (handles enum conversion)
            $value = $this->prepareValueForDatabase($value, $property->getType());

            $columns[] = "`$name`";
            $values[] = "?";
            $params[] = $value;

            // Determine type for bind_param
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType) {
                $types .= match ($type->getName()) {
                    'int', 'bool' => 'i',
                    'float' => 'd',
                    default => 's',
                };
            } else {
                $types .= 's';
            }
        }

        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            static::$table,
            implode(", ", $columns),
            implode(", ", $values)
        );

        $stmt = $dbh->prepare($query);
        if ($stmt === false) {
            throw new RuntimeException("Failed to prepare statement: " . $dbh->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Delete the current record from the database
     *
     * @throws Exception
     */
    public function delete(mysqli $dbh): bool
    {
        if ($this->isNew()) {
            throw new Exception("Cannot delete a record that hasn't been saved (missing primary key values)");
        }

        $pkWhere = $this->buildPrimaryKeyWhere();
        $sql = "DELETE FROM " . static::$table . " WHERE " . $pkWhere['clause'] . " LIMIT 1";

        $stmt = $dbh->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        $stmt->bind_param($pkWhere['types'], ...$pkWhere['params']);

        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Reset all primary key values to indicate the record no longer exists
            foreach (static::$primaryKeys as $key) {
                $this->$key = null;
            }
        }

        return $success;
    }

    /**
     * Refresh the model from the database
     *
     * @throws Exception
     */
    public function refresh(mysqli $dbh): bool
    {
        if ($this->isNew()) {
            return false;
        }

        $fresh = static::findByKey($dbh, $this->getKey());
        if ($fresh === null) {
            return false;
        }

        // Copy all properties from fresh instance
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $prop) {
            $name = $prop->getName();
            if (!$prop->isStatic()) {
                $this->$name = $fresh->$name;
            }
        }

        return true;
    }
}
