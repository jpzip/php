# jpzip — PHP SDK

> 日本の郵便番号を CDN 配信の JSON データから引く PHP SDK。

- 配信ドメイン: `https://jpzip.nadai.dev`
- プロトコル仕様: [`jpzip/spec`](https://github.com/jpzip/spec)
- データ ETL: [`jpzip/data`](https://github.com/jpzip/data)

```sh
composer require jpzip/jpzip
```

要件: PHP 8.2+ / `ext-json` / `guzzlehttp/guzzle ^7.8`

## 使い方

### 関数 API

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use function Jpzip\lookup;
use function Jpzip\lookupGroup;
use function Jpzip\lookupAll;
use function Jpzip\getMeta;
use function Jpzip\isValidZipcode;

$entry = lookup('2310017');
// $entry === null なら見つからなかった
echo $entry?->prefecture; // "神奈川県"
echo $entry?->city;       // "横浜市中区"

$dict = lookupGroup('23');  // 2 桁は 10 並列 fetch
$all  = lookupAll();
$meta = getMeta();

isValidZipcode('2310017'); // true
```

### クライアント API (L2 キャッシュ・複数インスタンス用)

```php
<?php

use Jpzip\Client;

$client = new Client(
    baseURL: 'https://jpzip.nadai.dev',
    memoryCacheSize: 200,
    cache: $myCache, // CacheInterface を実装
    onSpecMismatch: function (string $expected, string $received): void {
        error_log("jpzip spec mismatch: expected={$expected}, received={$received}");
    },
);

$client->preload('all');
$entry = $client->lookup('2310017');
```

## Cache インターフェース

```php
<?php

namespace Jpzip;

interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value): void;
    public function delete(string $key): void;
    public function clear(): void;
}
```

ファイル / KV / Redis 等の任意の実装を渡せる。値は生 JSON 文字列。

## 入力検証

`lookup()` は `^\d{7}$` にマッチしない入力には fetch せず `null` を返す。

## バージョン整合性

`getMeta()` で `spec_version` が異なる場合、コンストラクタの `onSpecMismatch` で渡したコールバックが呼ばれる。データバージョンが変わったら L1/L2 を自動 invalidate する。

## キャッシュ階層

| 層 | 目的 | デフォルト |
|---|---|---|
| **L1: メモリ内 LRU** | 同一プロセス内の重複 fetch 抑制 | **常時 ON** (内部実装、prefix 単位、容量 100) |
| **L2: 永続キャッシュ** | preload / オフライン / 起動高速化 | **デフォルト OFF**、`CacheInterface` を実装したオブジェクトを渡すと有効 |
| **L3: HTTP キャッシュ** | OS / プロキシレベル | SDK の制御外 |

## ライセンス

[MIT](./LICENSE)
