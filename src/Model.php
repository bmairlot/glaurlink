<?php
/**
 * @copyright Copyright (c) 2024 Bruno Mairlot
 * @license MIT
 */
namespace Ancalagon\Glaurlink;

use JsonSerializable;
use mysqli;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use TypeError;

/**
 * @phpstan-consistent-constructor
 */
abstract class Model implements JsonSerializable
{
    protected static string $table;
    protected static string $primaryKey = 'id';
    protected static bool $autoIncrement = true;
    protected static array $fillable = [];
    protected static array $generated = [];
    protected static array $attributes = [];

    /**
     * @throws Exception
     */
    public function __construct(array $attributes = [])
    {
        // Initialize properties with their default values
        $this->initializeProperties();
        $this->fill($attributes);
    }

    /**
     * @throws Exception
     */
    public static function create(array $attributes = []): static
    {
        $instance = new static();
        $instance->fill($attributes);
        return $instance;
    }

    /**
     * Check if this is a new (unsaved) record
     */
    public function isNew(): bool
    {
        $pk = static::$primaryKey;
        $prop = new ReflectionProperty($this, $pk);
        if (!$prop->isInitialized($this)) {
            return true;
        }
        return $this->$pk === null;
    }

    /**
     * Get the primary key value
     */
    public function getKey(): mixed
    {
        $pk = static::$primaryKey;
        $prop = new ReflectionProperty($this, $pk);
        if (!$prop->isInitialized($this)) {
            return null;
        }
        return $this->$pk;
    }

    /**
     * Set the primary key value
     */
    protected function setKey(mixed $value): void
    {
        $pk = static::$primaryKey;
        $this->$pk = $value;
    }

    /**
     * Get the primary key column name
     */
    public static function getKeyName(): string|array
    {
        return static::$primaryKey;
    }

    /**
     * Check if the model uses auto-increment
     */
    public static function isAutoIncrement(): bool
    {
        return static::$autoIncrement;
    }

    /**
     * Build WHERE clause for primary key
     *
     * @return array{clause: string, params: array, types: string}
     */
    protected function buildPrimaryKeyWhere(): array
    {
        $pk = static::$primaryKey;
        $value = $this->$pk;

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return [
            'clause' => "`$pk` = ?",
            'params' => [$value],
            'types' => is_int($value) ? 'i' : 's'
        ];
    }

    /**
     * Initialize class properties based on reflection
     */
    protected function initializeProperties(): void
    {
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name = $property->getName();
            if ($property->isStatic()) {
                continue;
            }

            if ($property->hasDefaultValue()) {
                static::$attributes[$name] = $property->getDefaultValue();
            } else {
                static::$attributes[$name] = null;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            // Only fill if it's a defined property or in fillable array
            if (array_key_exists($key, static::$attributes) || in_array($key, static::$fillable)) {
                $this->setAttribute($key, $value);
            }
        }
    }

    /**
     * @throws Exception
     */
    protected function setAttribute($key, $value): void
    {
        // Check if the property exists and has type hints
        if (property_exists($this, $key)) {
            $reflection = new ReflectionProperty($this, $key);
            if ($reflection->hasType()) {
                $type = $reflection->getType();

                // Convert string to enum if a property type is an enum
                if ($type instanceof ReflectionNamedType && enum_exists($type->getName())) {
                    $enumClass = $type->getName();
                    if (is_string($value) && !($value instanceof $enumClass)) {
                        // Try to convert string to enum
                        $enumValue = $enumClass::from($value);
                        if ($enumValue !== null) {
                            $value = $enumValue;
                        } else {
                            throw new TypeError("Invalid enum value '$value' for property $key of type " . $type->getName());
                        }
                    }
                }

                // Validate type
                if (!$this->validateType($value, $type)) {
                    throw new TypeError("Cannot assign " . gettype($value) . " to property $key of type " . $type->getName());
                }
            }

            $this->$key = $value;
        } else {
            throw new Exception("Property $key does not exist in " . get_class($this));
        }
    }

    protected function validateType($value, ReflectionType $type): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }

        $class = get_class($type);
        switch ($class) {
            case "ReflectionNamedType":
                $typeName = $type->getName();

                // Handle enum types
                if (enum_exists($typeName)) {
                    // Value can be an enum instance or a valid string value
                    if ($value instanceof $typeName) {
                        return true;
                    }
                    if (is_string($value)) {
                        return $typeName::tryFrom($value) !== null;
                    }
                    return false;
                }

                switch ($typeName) {
                    case 'int':
                        return is_int($value) || (is_string($value) && ctype_digit($value));
                    case 'float':
                        return is_float($value) || is_int($value) || (is_string($value) && is_numeric($value));
                    case 'string':
                        return is_string($value);
                    case 'bool':
                        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
                    case 'array':
                        return is_array($value);
                    default:
                        return $value instanceof $typeName;
                }

            case "ReflectionUnionType":
                $typeArr = $type->getTypes();
                foreach ($typeArr as $subType) {
                    $val = $this->validateType($value, $subType);
                    if ($val === true) {
                        return true;
                    }
                }
                return false;

            case "ReflectionIntersectionType":
                throw new TypeError("Intersection Type ($class) should not be used to implement a column (yet)");

            default:
                throw new TypeError("Unsupported Reflection Type ($class)");
        }
    }

    protected function prepareValueForDatabase($value, ReflectionType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!$type) {
            return $value;
        }

        // Handle named types
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            // Handle enum types - convert to their backing value
            if (enum_exists($typeName) && $value instanceof \BackedEnum) {
                return $value->value;
            }

            return match ($typeName) {
                'bool', 'int' => (int)$value,
                'float' => (float)$value,
                'string' => (string)$value,
                default => $value
            };
        }

        // Handle union types
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                if ($this->validateType($value, $subType)) {
                    return $this->prepareValueForDatabase($value, $subType);
                }
            }
        }

        return $value;
    }

    public function __get(string $name)
    {
        return static::$attributes[$name] ?? null;
    }

    /**
     * @throws Exception
     */
    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * @param mysqli|null $dbh
     * @param array $attributes
     * @return self|null
     * @throws Exception
     */
    public static function find(?mysqli $dbh, array $attributes): ?static
    {
        if (is_null($dbh)) {
            throw new Exception("Database handler cannot be null");
        }

        // Build WHERE clause
        $conditions = [];
        $values = [];

        foreach ($attributes as $column => $value) {
            if ($value === null) {
                $conditions[] = "`$column` IS NULL";
            } else {
                $conditions[] = "`$column` = ?";
                // Convert enums to their backing value for the query
                if ($value instanceof \BackedEnum) {
                    $values[] = $value->value;
                } else {
                    $values[] = $value;
                }
            }
        }

        $whereClause = implode(' AND ', $conditions);

        // Prepare the query
        $query = "SELECT * FROM " . static::$table . " WHERE $whereClause LIMIT 1";

        $stmt = $dbh->prepare($query);

        if ($stmt === false) {
            throw new RuntimeException("Failed to prepare statement: " . $dbh->error);
        }

        // Bind parameters if we have any
        if (!empty($values)) {
            // Create a type string for bind_param
            $types = '';

            // Determine the proper type for each value
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } elseif (is_bool($value)) {
                    $types .= 'i';
                } else {
                    $types .= 's';
                }
            }
            $stmt->bind_param($types, ...$values);
        }

        // Execute the query
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            return new static($row);
        }
        return null;
    }

    /**
     * Find a record by its primary key
     *
     * @param mysqli $dbh Database connection
     * @param mixed $id Primary key value
     * @return static|null
     * @throws Exception
     */
    public static function findByKey(mysqli $dbh, mixed $id): ?static
    {
        return static::find($dbh, [static::$primaryKey => $id]);
    }

    /**
     * Fetch all records from the table and returns an array of object
     * @param mysqli $dbh Database connection
     * @param string|null $searchTerm Optional search term
     * @param array $searchColumns Columns to search in (used with searchTerm)
     * @param array $conditions Optional WHERE conditions
     * @param array $orderBy Optional ORDER BY conditions
     * @param ?int $limit Optional LIMIT
     * @param ?int $offset Optional OFFSET
     * @return Collection Array of model instances
     * @throws Exception
     * @internal This method is meant to be called by child classes only
     */
    public static function collection(
        mysqli $dbh,
        ?string $searchTerm = null,
        array $searchColumns = [],
        array $conditions = [],
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null
    ): Collection {
        $query = "SELECT * FROM " . static::$table;
        $params = [];
        $types = '';
        $whereParts = [];

        // Add exact match conditions (AND)
        if (!empty($conditions)) {
            $andClause = [];
            foreach ($conditions as $column => $value) {
                if ($value === null) {
                    $andClause[] = "`$column` IS NULL";
                    continue;
                }

                $andClause[] = "`$column` = ?";

                // Convert enums to their backing value
                if ($value instanceof \BackedEnum) {
                    $params[] = $value->value;
                } else {
                    $params[] = $value;
                }

                $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            }
            $whereParts[] = implode(' AND ', $andClause);
        }

        // Add search across multiple columns (OR with LIKE)
        if (!empty($searchTerm) && !empty($searchColumns)) {
            $searchClause = [];
            $searchValue = "%$searchTerm%";
            foreach ($searchColumns as $column) {
                $searchClause[] = "`$column` LIKE ?";
                $params[] = $searchValue;
                $types .= 's';
            }
            $whereParts[] = '(' . implode(' OR ', $searchClause) . ')';
        }

        if (!empty($whereParts)) {
            $query .= " WHERE " . implode(' AND ', $whereParts);
        }

        // Add ORDER BY if specified
        if (!empty($orderBy)) {
            $orderClauses = [];
            foreach ($orderBy as $column => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderClauses[] = "`$column` $direction";
            }
            $query .= " ORDER BY " . implode(', ', $orderClauses);
        }

        // Add LIMIT and OFFSET if specified
        if ($limit !== null) {
            $query .= " LIMIT ?";
            $params[] = $limit;
            $types .= 'i';

            if ($offset !== null) {
                $query .= " OFFSET ?";
                $params[] = $offset;
                $types .= 'i';
            }
        }

        $stmt = $dbh->prepare($query);
        if ($stmt === false) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        // Bind parameters if we have any
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = new static($row);
        }

        return new Collection($records);
    }

    /**
     * Count records based on conditions
     * @param mysqli $dbh Database connection
     * @param array $conditions Optional WHERE conditions
     * @return int Number of matching records
     * @throws Exception
     */
    public static function count(mysqli $dbh, array $conditions = []): int
    {
        $query = "SELECT COUNT(*) as count FROM " . static::$table;
        $params = [];
        $types = '';

        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $column => $value) {
                if ($value === null) {
                    $whereClause[] = "`$column` IS NULL";
                } else {
                    $whereClause[] = "`$column` = ?";

                    // Convert enums to their backing value
                    if ($value instanceof \BackedEnum) {
                        $params[] = $value->value;
                    } else {
                        $params[] = $value;
                    }

                    $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
                }
            }
            $query .= " WHERE " . implode(' AND ', $whereClause);
        }

        $stmt = $dbh->prepare($query);
        if ($stmt === false) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    protected function isPrimaryKeyColumn(string $name): bool
    {
        return $name === static::$primaryKey;
    }

    protected function shouldPersistProperty(ReflectionProperty $prop, bool $isNew): bool
    {
        $name = $prop->getName();

        if (!$prop->isInitialized($this)) {
            return false;
        }

        $value = $prop->getValue($this);

        if (!$isNew && $this->isPrimaryKeyColumn($name)) {
            return false;
        }

        if ($isNew
            && static::$autoIncrement
            && $this->isPrimaryKeyColumn($name)
            && $value === null
        ) {
            return false;
        }

        if ($value === null && in_array($name, static::$generated, true)) {
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function save(mysqli $dbh, bool $rehydrate = false): bool
    {
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $isNew = $this->isNew();

        $updates = [];
        $params = [];
        $types = '';

        foreach ($properties as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            if (!$this->shouldPersistProperty($prop, $isNew)) {
                continue;
            }

            $value = $this->prepareValueForDatabase($prop->getValue($this), $prop->getType());

            $updates[] = '`' . $prop->getName() . '` = ?';
            $params[] = $value;

            if (is_int($value) || $prop->getType()?->getName() === 'bool') {
                $types .= 'i';
            } elseif (is_double($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        if ($isNew) {
            $sql = "INSERT INTO " . static::$table . " SET " . implode(', ', $updates);
        } else {
            $pkWhere = $this->buildPrimaryKeyWhere();
            $sql = "UPDATE " . static::$table . " SET " . implode(', ', $updates)
                 . " WHERE " . $pkWhere['clause'];
            $params = array_merge($params, $pkWhere['params']);
            $types .= $pkWhere['types'];
        }

        $stmt = $dbh->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }

        if ($isNew && static::$autoIncrement && $this->getKey() === null && $dbh->insert_id) {
            $this->setKey($dbh->insert_id);
        }

        $stmt->close();

        if ($rehydrate) {
            $this->rehydrateFromDatabase($dbh);
        }

        return true;
    }

    public function insert(mysqli $dbh, bool $rehydrate = false): bool
    {
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $columns = [];
        $values = [];
        $params = [];
        $types = '';

        foreach ($properties as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            if (!$this->shouldPersistProperty($prop, true)) {
                continue;
            }

            $value = $this->prepareValueForDatabase($prop->getValue($this), $prop->getType());

            $columns[] = '`' . $prop->getName() . '`';
            $values[] = '?';
            $params[] = $value;

            $type = $prop->getType();
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

        if ($success && static::$autoIncrement && $this->getKey() === null && $dbh->insert_id) {
            $this->setKey($dbh->insert_id);
        }

        $stmt->close();

        if ($success && $rehydrate) {
            $this->rehydrateFromDatabase($dbh);
        }

        return $success;
    }

    /**
     * Delete the current record from the database
     *
     * @param mysqli $dbh Database connection
     * @return bool True on success
     * @throws Exception
     */
    public function delete(mysqli $dbh): bool
    {
        if ($this->isNew()) {
            throw new Exception("Cannot delete a record that hasn't been saved");
        }

        $pkWhere = $this->buildPrimaryKeyWhere();
        $sql = "DELETE FROM " . static::$table . " WHERE " . $pkWhere['clause'] . " LIMIT 1";

        $stmt = $dbh->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        if (!empty($pkWhere['params'])) {
            $stmt->bind_param($pkWhere['types'], ...$pkWhere['params']);
        }

        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Reset primary key to indicate the record no longer exists
            $this->setKey(null);
        }

        return $success;
    }

    /**
     * Delete records from the database based on attribute conditions
     *
     * @param mysqli $dbh Database connection
     * @param array $attributes Attribute-value pairs to match
     * @return int Number of deleted records
     * @throws Exception
     */
    public static function deleteWhere(mysqli $dbh, array $attributes): int
    {
        if (empty($attributes)) {
            throw new Exception("Attributes array cannot be empty for delete operation");
        }

        // Build WHERE clause
        $conditions = [];
        $params = [];
        $types = '';

        foreach ($attributes as $column => $value) {
            if ($value === null) {
                $conditions[] = "`$column` IS NULL";
            } else {
                $conditions[] = "`$column` = ?";

                // Convert enums to their backing value
                if ($value instanceof \BackedEnum) {
                    $params[] = $value->value;
                } else {
                    $params[] = $value;
                }

                // Determine parameter type
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }
        }

        $whereClause = implode(' AND ', $conditions);
        $sql = "DELETE FROM " . static::$table . " WHERE " . $whereClause;

        $stmt = $dbh->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $dbh->error);
        }

        // Bind parameters if we have any
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $success = $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if (!$success) {
            throw new Exception("Failed to execute delete statement: " . $dbh->error);
        }

        return $affectedRows;
    }

    /**
     * Refresh the model from the database
     *
     * @param mysqli $dbh Database connection
     * @return bool True if record was found and refreshed
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

        // Copy all properties from a fresh instance
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

    protected function rehydrateFromDatabase(mysqli $dbh): void
    {
        $this->refresh($dbh);
    }

    /**
     * Check if a record exists in the database with the given conditions
     *
     * @param mysqli $dbh Database connection
     * @param array $conditions WHERE conditions
     * @return bool
     * @throws Exception
     */
    public static function exists(mysqli $dbh, array $conditions): bool
    {
        return static::count($dbh, $conditions) > 0;
    }

    /**
     * Specify data which should be serialized to JSON
     * @return array data which can be serialized by json_encode()
     */
    public function jsonSerialize(): array
    {
        $reflection = new ReflectionClass($this)->getParentClass();
        $attributes = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            if (!$prop->isInitialized($this)) {
                continue;
            }
            $name = $prop->getName();
            $value = $this->$name;
            if ($value instanceof \BackedEnum) {
                $attributes[$name] = $value->value;
            } else {
                $attributes[$name] = $value;
            }
        }
        return $attributes;
    }
}