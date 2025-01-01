<?php

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

abstract class Model implements JsonSerializable
{
    protected static string $table;
    protected static array $fillable = [];
    protected array $attributes = [];


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
     * Initialize class properties based on reflection
     */
    protected function initializeProperties(): void
    {

        // We want to get properties of only the _Object class and not the effective one
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $name = $property->getName();
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            // Get default value if it exists
            $defaultValue = $property->hasDefaultValue() ? $property->getDefaultValue() : null;

            // Initialize the property in attributes
            $this->attributes[$name] = $defaultValue;

            // If the property has a type, we can enforce it
            if ($property->hasType()) {
                $type = $property->getType();
                if (!$type->allowsNull() && $defaultValue === null) {
                    throw new RuntimeException("Non-nullable property $name must have a default value");
                }
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
            if (array_key_exists($key, $this->attributes) || in_array($key, static::$fillable)) {
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

                // Validate type
                if (!$this->validateType($value, $type)) {
                    throw new TypeError("Cannot assign " . gettype($value) . " to property $key of type " . $type->getName());
                }
            }

            $this->$key = $value;
        } else {
            throw new Exception("Property $key does not exist in " . gettype($this));
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
                    case 'union':
                        error_log("Found union type");
                    default:
                        return $value instanceof $typeName;
                }
                break;
            case "ReflectionUnionType":
                $typeArr = $type->getTypes();
                foreach ($typeArr as $subType) {
                    $val = $this->validateType($value, $subType);
                    if ($val === true) {
                        return true;
                    }
                }
                return false;
                break;
            case "ReflectionIntersectionType":
                throw new TypeError("Intersection Type ($class) should not be used to implement a column (yet)");
                break;
            default:
                throw new TypeError("Unsupported Reflection Type ($class)");
        }


    }

    protected function prepareValueForDatabase($value, ReflectionProperty $property): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();
        if (!$type) {
            return $value;
        }

        // Handle named types
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
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
                    return $this->prepareValueForDatabase($value, $property);
                }
            }
        }

        return $value;
    }

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
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
                $values[] = $value;
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
            // Create types string for bind_param
            $types = str_repeat('s', count($values)); // Default all to string

            // Determine proper type for each value
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types = 'i';
                } elseif (is_float($value)) {
                    $types = 'd';
                } elseif (is_bool($value)) {
                    $types = 'i';
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
     * @throws Exception
     */
    public function save(mysqli $dbh): bool
    {
        // We want to get properties of only the _Object class and not the effective one
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $fields = [];
        $values = [];
        $updates = [];
        $params = [];
        $types = '';

        foreach ($properties as $prop) {

            $name = $prop->getName();
            $value = $prop->getValue($this);
            $value = $this->prepareValueForDatabase($value, $prop);


            $fields[] = $name;
            $values[] = '?';
            $updates[] = "$name = ?";
            $params[] = $value;

            // Determine parameter type for bind_param
            if (is_int($value) || $name === 'id' || $prop->getType()?->getName() === 'bool') {
                $types .= 'i';
            } elseif (is_double($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            error_log("Types : $types" . PHP_EOL);
        }
        // Determine if this is an insert or update operation
        if ($this->id === null) {
            // INSERT operation
//            $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $fields) . ")
            //                 VALUES (" . implode(', ', $values) . ")";
            $sql = "INSERT INTO " . static::$table . " SET " . implode(', ', $updates);
        } else {
            // UPDATE operation
            // Remove id from the updates array
            //array_shift($updates);

            $sql = "UPDATE " . static::$table . " SET " . implode(', ', $updates) . " 
                   WHERE id = ?";
            // Add id as the last parameter for WHERE clause
            $params[] = $this->id;
            $types .= 'i';
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
            error_log(var_export($bind_names, true));
            call_user_func_array(array($stmt, 'bind_param'), $bind_names);
        }

        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }

        // If this was an insert, update the id property
        if ($this->id === null) {
            $this->id = $stmt->insert_id;
        }

        $stmt->close();
        return true;
    }

    public function insert(mysqli $dbh): bool
    {
        // We want to get properties of only the _Object class and not the effective one
        $reflection = new ReflectionClass($this)->getParentClass();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $columns = [];
        $values = [];
        $params = [];
        $types = '';

        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($this);
            // Skip ID if it's null (auto-increment)
            if ($name === 'id' && $value === null) {
                continue;
            }

            // Add to our arrays
            $columns[] = "`$name`";
            $values[] = "?";
            $params[] = $value;

            // Determine type for bind_param
            $type = $property->getType();
            if ($type) {
                $types .= match ($type->getName()) {
                    'int', 'bool' => 'i',
                    'float' => 'd',
                    default => 's',
                };
            } else {
                $types .= 's'; // Default to string if no type
            }
        }

        // Build query
        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            static::$table,
            implode(", ", $columns),
            implode(", ", $values)
        );

        // Prepare statement
        $stmt = $dbh->prepare($query);
        if ($stmt === false) {
            throw new RuntimeException("Failed to prepare statement: " . $dbh->error);
        }


        // Bind parameters
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        // Execute
        $success = $stmt->execute();

        if ($success) {
            // Set the ID if it was auto-generated
            if ($dbh->insert_id) {
                $this->id = $dbh->insert_id;
            }
        }
        return $success;
    }


    /**
     * Specify data which should be serialized to JSON
     * @return array data which can be serialized by json_encode()
     */
    public function jsonSerialize(): array
    {
        foreach (array_keys($this->attributes) as $attribute) {
            $this->attributes[$attribute] = $this->$attribute;
        };
        return $this->attributes;
    }


}