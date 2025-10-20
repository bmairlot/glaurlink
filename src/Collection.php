<?php

namespace Ancalagon\Glaurlink;

use ArrayAccess;
use Countable;
use Iterator;
use JsonSerializable;

/**
 * @template T of Model
 * @implements Iterator<int, T>
 * @implements ArrayAccess<int, T>
 */
class Collection implements Iterator, ArrayAccess, Countable, JsonSerializable
{
    private int $position;

    /**
     * @param array<int, T> $items
     */
    public function __construct(private array $items = [])
    {
        $this->position = 0;
    }

    /**
     * @return T
     */
    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @return T
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * @param T $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array<int, T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(fn($item) => $item->jsonSerialize(), $this->items);
    }
}
