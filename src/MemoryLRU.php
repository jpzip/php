<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * L1 のメモリ内 LRU。prefix 単位の dict を保持する。
 *
 * @internal
 */
final class MemoryLRU
{
    private int $capacity;

    /**
     * @var array<string, array<string, ZipcodeEntry>>
     */
    private array $items = [];

    public function __construct(int $capacity = 100)
    {
        $this->capacity = max(1, $capacity);
    }

    /**
     * @return array<string, ZipcodeEntry>|null
     */
    public function get(string $key): ?array
    {
        if (!array_key_exists($key, $this->items)) {
            return null;
        }
        $value = $this->items[$key];
        // LRU: 末尾 (= MRU) に移す。
        unset($this->items[$key]);
        $this->items[$key] = $value;

        return $value;
    }

    /**
     * @param array<string, ZipcodeEntry> $value
     */
    public function set(string $key, array $value): void
    {
        if (array_key_exists($key, $this->items)) {
            unset($this->items[$key]);
        } elseif (count($this->items) >= $this->capacity) {
            // 先頭 (= LRU) を破棄。
            $oldestKey = array_key_first($this->items);
            if ($oldestKey !== null) {
                unset($this->items[$oldestKey]);
            }
        }
        $this->items[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function size(): int
    {
        return count($this->items);
    }
}
