[![Latest Version on Packagist](https://img.shields.io/packagist/v/jpzip/jpzip.svg)](https://packagist.org/packages/jpzip/jpzip)
[![PHP Version Require](https://img.shields.io/packagist/php-v/jpzip/jpzip.svg)](https://packagist.org/packages/jpzip/jpzip)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Test](https://github.com/jpzip/php/actions/workflows/test.yml/badge.svg)](https://github.com/jpzip/php/actions/workflows/test.yml)

> PHP SDK for **jpzip** — a free, unlimited Japanese postal code (郵便番号) API.
> 日本の全郵便番号 120,677 件を CDN 配信 JSON から引く PHP SDK。

**English** | [日本語](./README.ja.md)

`jpzip/jpzip` looks up Japanese postal codes (郵便番号) from `jpzip.nadai.dev`,
a CDN-hosted dataset built from Japan Post's `KEN_ALL.csv` and `KEN_ALL_ROME.csv`
normalized to JSON. No registration, no rate limits, no API key.

- 🇯🇵 **Complete dataset** — 120,677 entries with kanji, kana, romaji, and government codes (JIS X 0401 / 総務省地方公共団体コード)
- ⚡️ **Fast** — L1 LRU + optional L2 persistent cache; `preload()` to serve lookups without per-request network round-trips
- 🛡️ **Resilient** — 3-attempt retry with exponential backoff on 5xx / network failures
- 🐘 **Modern PHP** — PHP 8.2+, strict types, readonly DTOs, named arguments throughout
- 🆓 **Free forever** — backed by Cloudflare Pages' free tier (no billing axis exists)
- 🔌 **Drop-in** — same API surface across [every jpzip SDK](#other-languages)

## Requirements

- PHP 8.2+
- `ext-json`
- `guzzlehttp/guzzle ^7.8` (transitively pulls `psr/http-message`, `guzzlehttp/promises`, `guzzlehttp/psr7`)

## Install

```bash
composer require jpzip/jpzip
```

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use function Jpzip\lookup;

$entry = lookup('2310017');
if ($entry === null) {
    echo "not found\n";
    return;
}

echo $entry->prefecture, ' ', $entry->city, ' ', $entry->towns[0]->town, "\n";
// Output: 神奈川県 横浜市中区 港町
```

Romaji and government codes are on the same entry:

```php
echo $entry->prefecture_roma, ' ', $entry->city_roma, ' ', $entry->towns[0]->roma, "\n";
// Output: Kanagawa Ken Yokohama Shi Naka Ku Minatocho

echo $entry->prefecture_code, ' ', $entry->city_code, "\n";
// Output: 14 14104
```

## Use Cases

### Zipcode lookup HTTP endpoint (Laravel)

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use function Jpzip\lookup;

final class ZipcodeController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $entry = lookup($code);
        if ($entry === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json($entry->toArray());
    }
}

// routes/web.php
// Route::get('/api/zipcode/{code}', [ZipcodeController::class, 'show']);
```

### Zipcode lookup HTTP endpoint (Slim 4)

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use function Jpzip\lookup;

$app = AppFactory::create();

$app->get('/api/zipcode/{code}', function (ServerRequestInterface $req, ResponseInterface $res, array $args) {
    $entry = lookup($args['code']);
    if ($entry === null) {
        return $res->withStatus(404);
    }
    $res->getBody()->write(json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE));

    return $res->withHeader('Content-Type', 'application/json');
});

$app->run();
```

### Batch validation

```php
use function Jpzip\lookupAll;

$all = lookupAll(); // entire dataset in memory (~37 MiB JSON)

foreach ($csvZipcodes as $zip) {
    if (!isset($all[$zip])) {
        error_log("invalid zipcode: {$zip}");
    }
}
```

### Serve lookups from cache (BYO L2 backend)

The dataset is partitioned into 948 three-digit prefix buckets. The default L1
(100 entries) keeps the hottest buckets; to cache the whole dataset, pair
`preload('all')` with an L2 cache or raise `memoryCacheSize` above 948.

```php
use Jpzip\Client;
use Jpzip\CacheInterface;

final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) {}

    public function get(string $key): ?string
    {
        $path = $this->dir . '/' . sha1($key);

        return is_file($path) ? file_get_contents($path) : null;
    }

    public function set(string $key, string $value): void
    {
        file_put_contents($this->dir . '/' . sha1($key), $value);
    }

    public function delete(string $key): void
    {
        @unlink($this->dir . '/' . sha1($key));
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

$client = new Client(
    memoryCacheSize: 1024,
    cache: new FileCache(sys_get_temp_dir() . '/jpzip'),
);
$client->preload('all');

// Subsequent lookups are served from L1/L2 without hitting the network.
$entry = $client->lookup('2310017');
```

## API Reference

### Functions (namespace `Jpzip`, share a default Client)

| Function | Description |
|---|---|
| `Jpzip\lookup(string $zipcode): ?ZipcodeEntry` | Look up a single 7-digit zipcode. Returns `null` if not found or malformed (no network call for malformed input). |
| `Jpzip\lookupGroup(string $prefix): array<string, ZipcodeEntry>` | Look up by 1-, 2-, or 3-digit prefix. 1-digit fetches `/g/{d}.json`; 3-digit fetches `/p/{ddd}.json`; 2-digit fans out into 10 parallel 3-digit fetches and merges. |
| `Jpzip\lookupAll(): array<string, ZipcodeEntry>` | Fetch entire dataset (120k entries, ~37 MiB) in parallel across `/g/0..9.json`. |
| `Jpzip\getMeta(): ?Meta` | Dataset version, generated-at, per-prefecture counts, spec version. Result is cached until `refresh()`. |
| `Jpzip\preload(string $scope): void` | Warm L1 (and L2 when configured) for `"all"` or a specific 1–3-digit prefix. |
| `Jpzip\isValidZipcode(string $s): bool` | Pure syntax check (`^\d{7}$`) — no network. |

### `Jpzip\Client` (advanced)

A configurable instance; required for L2 caching, custom HTTP client, alternate base URL, or multiple isolated caches:

```php
use Jpzip\Client;

$client = new Client(
    baseURL: 'https://jpzip.nadai.dev',
    httpClient: $myGuzzleClient,         // any GuzzleHttp\ClientInterface (testing, custom middleware, etc.)
    memoryCacheSize: 200,                 // L1 capacity in prefix buckets, default 100
    cache: $myCache,                      // optional L2 (CacheInterface)
    onSpecMismatch: function (string $expected, string $received): void {
        error_log("jpzip spec mismatch: SDK={$expected} server={$received}");
    },
);
```

`Client` exposes `lookup()` / `lookupGroup()` / `lookupAll()` / `getMeta()` / `preload()` plus:

| Method | Description |
|---|---|
| `$client->refresh()` | Wipe L1 (and L2 when configured) and forget the cached meta. |

When `getMeta()` observes that `/meta.json`'s `version` has changed since the last successful fetch, L1 and L2 are cleared automatically — call `getMeta()` periodically to pick up dataset rollovers.

### DTOs

All DTOs are `final readonly` classes with typed properties:

```php
final readonly class ZipcodeEntry
{
    public string $prefecture;       // 神奈川県
    public string $prefecture_kana;  // カナガワケン
    public string $prefecture_roma;  // Kanagawa Ken
    public string $prefecture_code;  // 14 (JIS X 0401)
    public string $city;             // 横浜市中区
    public string $city_kana;        // ヨコハマシナカク
    public string $city_roma;        // Yokohama Shi Naka Ku
    public string $city_code;        // 14104 (総務省地方公共団体コード)
    /** @var list<Town> */
    public array $towns;
}

final readonly class Town
{
    public string $town;   // 港町
    public string $kana;   // ミナトチョウ
    public string $roma;   // Minatocho
    public ?string $note;  // (rare) qualifier from KEN_ALL.csv
}
```

Each DTO exposes `toArray()` for JSON serialization and `::fromArray()` for deserialization.

### Errors

- `\InvalidArgumentException` — thrown by `lookupGroup()` / `preload()` when the prefix is not `"all"` or 1–3 digits.
- `\RuntimeException` — thrown for non-404 4xx HTTP responses, malformed JSON, and 5xx responses that fail all retries.
- Transient network failures and 5xx responses are retried up to 3 attempts (initial + 2 retries) with exponential backoff sleeps of 400ms and 800ms. 4xx responses other than 404 (which yields `null`) are returned immediately.

### `CacheInterface`

Bring your own L2 backend (file, APCu, Redis, Memcached, KV, etc.):

```php
namespace Jpzip;

interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value): void;
    public function delete(string $key): void;
    public function clear(): void;
}
```

Keys are the full prefix-bucket URLs (e.g. `https://jpzip.nadai.dev/p/231.json`); values are raw JSON bytes.

Adapters for PSR-16 (`Psr\SimpleCache\CacheInterface`) and PSR-6 (`Psr\Cache\CacheItemPoolInterface`) can be written as ~20-line wrappers around the contract above.

## Why jpzip/jpzip?

| | **jpzip/jpzip** | [sukohi/laravel-jp-postal-code][sukohi] | [edgecreativemedia/japanaddressing][edge] | [zipcloud API][zipcloud] |
|---|---|---|---|---|
| Romaji (`Yokohama Shi`) | ✅ | ❌ | ✅ | ❌ |
| Government codes (JIS / 総務省) | ✅ | ❌ | ❌ | ❌ |
| No manual CSV download / DB seed | ✅ | ❌ Manual `KEN_ALL.CSV` | ✅ Bundled | ✅ |
| Framework-agnostic | ✅ | ❌ Laravel-only | ✅ | ✅ |
| Monthly updates | ✅ Auto | ❌ Manual | ❌ Abandoned (2016) | ✅ |
| Offline after preload | ✅ | ✅ | ✅ | ❌ |
| Rate-limit-free | ✅ | ✅ | ✅ | ⚠️ Discouraged for bulk |
| L1 + pluggable L2 cache | ✅ | ❌ | ❌ | ❌ |
| Modern PHP (8.2+, readonly DTOs) | ✅ | ❌ | ❌ PHP 5.4 | n/a |

[sukohi]: https://packagist.org/packages/sukohi/laravel-jp-postal-code
[edge]: https://packagist.org/packages/edgecreativemedia/japanaddressing
[zipcloud]: http://zipcloud.ibsnet.co.jp/doc/api

## Other Languages

Same API surface across all SDKs:

[Go](https://github.com/jpzip/go) · [TypeScript](https://github.com/jpzip/js) · [Python](https://github.com/jpzip/python) · [Rust](https://github.com/jpzip/rust) · [Ruby](https://github.com/jpzip/ruby) · [Swift](https://github.com/jpzip/swift) · [Dart](https://github.com/jpzip/dart)

## Resources

- **Website** — https://jpzip.nadai.dev
- **Protocol spec** — [jpzip/spec](https://github.com/jpzip/spec)
- **Data ETL** — [jpzip/data](https://github.com/jpzip/data)
- **MCP server** — [jpzip/mcp](https://github.com/jpzip/mcp) — use jpzip from Claude / ChatGPT / Cursor

## Keywords

japanese postal code, japan zipcode, 郵便番号, KEN_ALL, KEN_ALL_ROME, address validation, address autocomplete, japan address api, postal code lookup php, php japanese address, laravel zipcode, slim zipcode, JIS X 0401, 総務省地方公共団体コード

## License

[MIT](./LICENSE)
