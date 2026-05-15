<?php

declare(strict_types=1);

namespace Jpzip\Tests;

use Jpzip\CacheInterface;

final class InMemoryCache implements CacheInterface
{
    /** @var array<string, string> */
    public array $store = [];

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
