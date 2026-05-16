[![Latest Version on Packagist](https://img.shields.io/packagist/v/jpzip/jpzip.svg)](https://packagist.org/packages/jpzip/jpzip)
[![PHP Version Require](https://img.shields.io/packagist/php-v/jpzip/jpzip.svg)](https://packagist.org/packages/jpzip/jpzip)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Test](https://github.com/jpzip/php/actions/workflows/test.yml/badge.svg)](https://github.com/jpzip/php/actions/workflows/test.yml)

> **jpzip** の PHP SDK — 無料・無制限の日本郵便番号 API。
> 日本郵便の `KEN_ALL.csv` / `KEN_ALL_ROME.csv` を JSON 正規化し CDN 配信。

[English](./README.md) | **日本語**

`jpzip/jpzip` は `jpzip.nadai.dev` から日本の郵便番号 120,677 件を引く PHP SDK です。
登録不要、レート制限なし、API キー不要。

- 🇯🇵 **全件収録** — 漢字・カナ・ローマ字・自治体コード(JIS X 0401 / 総務省地方公共団体コード)
- ⚡️ **高速** — L1 LRU + 任意の L2 永続キャッシュ。`preload()` でネットワーク往復なしのルックアップが可能
- 🛡️ **堅牢** — 5xx / ネットワーク失敗時は指数バックオフで最大 3 回リトライ
- 🐘 **モダン PHP** — PHP 8.2+、`strict_types`、`readonly` DTO、名前付き引数
- 🆓 **永久無料** — Cloudflare Pages 無料枠で運用(課金軸が存在しない)
- 🔌 **同一 API** — [全 jpzip SDK](#他言語版) で API が揃う

## 必要環境

- PHP 8.2+
- `ext-json`
- `guzzlehttp/guzzle ^7.8`(間接的に `psr/http-message` / `guzzlehttp/promises` / `guzzlehttp/psr7`)

## インストール

```bash
composer require jpzip/jpzip
```

## クイックスタート

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use function Jpzip\lookup;

$entry = lookup('2310017');
if ($entry === null) {
    echo "見つかりません\n";
    return;
}

echo $entry->prefecture, ' ', $entry->city, ' ', $entry->towns[0]->town, "\n";
// 出力: 神奈川県 横浜市中区 港町
```

ローマ字・自治体コードも同じエントリに含まれます:

```php
echo $entry->prefecture_roma, ' ', $entry->city_roma, ' ', $entry->towns[0]->roma, "\n";
// 出力: Kanagawa Ken Yokohama Shi Naka Ku Minatocho

echo $entry->prefecture_code, ' ', $entry->city_code, "\n";
// 出力: 14 14104
```

## ユースケース

### 郵便番号ルックアップ HTTP エンドポイント (Laravel)

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

### 郵便番号ルックアップ HTTP エンドポイント (Slim 4)

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

### CSV のバッチ検証

```php
use function Jpzip\lookupAll;

$all = lookupAll(); // 全件をメモリに展開(JSON 約 37 MiB)

foreach ($csvZipcodes as $zip) {
    if (!isset($all[$zip])) {
        error_log("不正な郵便番号: {$zip}");
    }
}
```

### キャッシュからの提供(任意の L2 バックエンド)

データは 948 個の 3 桁 prefix バケットに分割されています。デフォルト L1(100 件)は
ホットなバケットを保持しますが、全件を常駐させるには L2 を併用するか
`memoryCacheSize` を 948 超に設定してください。

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

// 以降の lookup は L1/L2 で完結し、ネットワークにアクセスしない。
$entry = $client->lookup('2310017');
```

## API リファレンス

### 関数(名前空間 `Jpzip`、内部の default Client を共有)

| 関数 | 説明 |
|---|---|
| `Jpzip\lookup(string $zipcode): ?ZipcodeEntry` | 7 桁の郵便番号で 1 件引く。見つからない / 不正な入力は `null`(不正入力時はネットワーク不使用)。 |
| `Jpzip\lookupGroup(string $prefix): array<string, ZipcodeEntry>` | 1〜3 桁の prefix で引く。1 桁は `/g/{d}.json` を 1 回、3 桁は `/p/{ddd}.json` を 1 回、2 桁は 10 並列 fetch して結合。 |
| `Jpzip\lookupAll(): array<string, ZipcodeEntry>` | `/g/0..9.json` を並列取得して全件(120k 件、約 37 MiB)を返す。 |
| `Jpzip\getMeta(): ?Meta` | データバージョン・生成日時・都道府県別件数・spec version。`refresh()` までは結果をキャッシュ。 |
| `Jpzip\preload(string $scope): void` | `"all"` または特定 1〜3 桁 prefix で L1(L2 設定時は L2 も)を温める。 |
| `Jpzip\isValidZipcode(string $s): bool` | 純粋な書式チェック(`^\d{7}$`)。ネットワーク不使用。 |

### `Jpzip\Client`(高度な用途)

設定可能なインスタンス。L2 キャッシュ、HTTP クライアント差し替え、配信元変更、複数の独立キャッシュが必要な場合に使用:

```php
use Jpzip\Client;

$client = new Client(
    baseURL: 'https://jpzip.nadai.dev',
    httpClient: $myGuzzleClient,         // 任意の GuzzleHttp\ClientInterface(テスト / カスタムミドルウェア等)
    memoryCacheSize: 200,                 // L1 容量(prefix バケット数)、デフォルト 100
    cache: $myCache,                      // L2(任意・CacheInterface 実装)
    onSpecMismatch: function (string $expected, string $received): void {
        error_log("jpzip spec 不一致: SDK={$expected} server={$received}");
    },
);
```

`Client` は `lookup()` / `lookupGroup()` / `lookupAll()` / `getMeta()` / `preload()` に加えて:

| メソッド | 説明 |
|---|---|
| `$client->refresh()` | L1(L2 設定時は L2 も)を消し、キャッシュ済み meta を破棄。 |

`getMeta()` が `/meta.json` の `version` 変更を検知すると L1/L2 が自動クリアされます。データ切り替えに追従するには `getMeta()` を定期的に呼んでください。

### DTO

全 DTO は `final readonly` クラスで、プロパティは型付き:

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
    public ?string $note;  // (まれ) KEN_ALL.csv 由来の補足
}
```

各 DTO は JSON 化用の `toArray()` と復元用の `::fromArray()` を提供します。

### エラー

- `\InvalidArgumentException` — `lookupGroup()` / `preload()` の prefix が `"all"` でも 1〜3 桁の数字でもない場合。
- `\RuntimeException` — 404 以外の 4xx、JSON パース失敗、リトライしても解消しない 5xx。
- ネットワーク失敗と 5xx は最大 3 回試行(初回 + リトライ 2 回)、指数バックオフのスリープは 400ms / 800ms。404 以外の 4xx は即座にエラー返却(404 は `null`)。

### `CacheInterface`

任意の L2 バックエンド(ファイル / APCu / Redis / Memcached / KV など)を渡せます:

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

キーは prefix バケットの完全 URL(例: `https://jpzip.nadai.dev/p/231.json`)、値は生 JSON バイト列。

PSR-16(`Psr\SimpleCache\CacheInterface`)/ PSR-6(`Psr\Cache\CacheItemPoolInterface`)向けアダプタは 20 行程度のラッパーで実装可能です。

## なぜ jpzip/jpzip か

| | **jpzip/jpzip** | [sukohi/laravel-jp-postal-code][sukohi] | [edgecreativemedia/japanaddressing][edge] | [zipcloud API][zipcloud] |
|---|---|---|---|---|
| ローマ字(`Yokohama Shi`) | ✅ | ❌ | ✅ | ❌ |
| 自治体コード(JIS / 総務省) | ✅ | ❌ | ❌ | ❌ |
| CSV 手動 DL / DB seed 不要 | ✅ | ❌ `KEN_ALL.CSV` 手動配置 | ✅ 同梱 | ✅ |
| フレームワーク非依存 | ✅ | ❌ Laravel 専用 | ✅ | ✅ |
| 月次更新 | ✅ 自動 | ❌ 手動 | ❌ 開発停止 (2016) | ✅ |
| Preload 後オフライン | ✅ | ✅ | ✅ | ❌ |
| レート制限なし | ✅ | ✅ | ✅ | ⚠️ 大量アクセス非推奨 |
| L1 + 差し替え可能な L2 | ✅ | ❌ | ❌ | ❌ |
| モダン PHP(8.2+、readonly DTO) | ✅ | ❌ | ❌ PHP 5.4 | n/a |

[sukohi]: https://packagist.org/packages/sukohi/laravel-jp-postal-code
[edge]: https://packagist.org/packages/edgecreativemedia/japanaddressing
[zipcloud]: http://zipcloud.ibsnet.co.jp/doc/api

## 他言語版

全 SDK で同一の API を提供しています:

[Go](https://github.com/jpzip/go) · [TypeScript](https://github.com/jpzip/js) · [Python](https://github.com/jpzip/python) · [Rust](https://github.com/jpzip/rust) · [Ruby](https://github.com/jpzip/ruby) · [Swift](https://github.com/jpzip/swift) · [Dart](https://github.com/jpzip/dart)

## 関連リソース

- **Web サイト** — https://jpzip.nadai.dev
- **プロトコル仕様** — [jpzip/spec](https://github.com/jpzip/spec)
- **データ ETL** — [jpzip/data](https://github.com/jpzip/data)
- **MCP サーバー** — [jpzip/mcp](https://github.com/jpzip/mcp) — Claude / ChatGPT / Cursor から jpzip を呼ぶ

## キーワード

日本郵便番号, 郵便番号, KEN_ALL, KEN_ALL_ROME, 住所検索, 住所自動補完, 住所バリデーション, japanese postal code, japan zipcode, php japanese address, laravel zipcode, slim zipcode, JIS X 0401, 総務省地方公共団体コード

## ライセンス

[MIT](./LICENSE)
