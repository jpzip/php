<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * ユーザーが差し込む L2 永続キャッシュの抽象インターフェース。
 *
 * 値は生 JSON バイト列 (string)。実装はファイル / KV / Redis など何でもよい。
 */
interface CacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function delete(string $key): void;

    public function clear(): void;
}
