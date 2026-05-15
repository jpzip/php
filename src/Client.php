<?php

declare(strict_types=1);

namespace Jpzip;

use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * jpzip SDK のエントリーポイント。
 */
final class Client
{
    private const ZIP_REGEX = '/^\d{7}$/';
    private const PREFIX_REGEX = '/^\d{1,3}$/';

    private readonly string $baseURL;
    private readonly Http $http;
    private readonly MemoryLRU $mem;
    private readonly ?CacheInterface $cache;
    private readonly ?Closure $onSpecMismatch;

    private bool $metaResolved = false;
    private ?Meta $metaCached = null;
    private string $knownVersion = '';

    /**
     * @param string|null              $baseURL          配信元 URL (省略時は jpzip.nadai.dev)
     * @param ClientInterface|null     $httpClient       Guzzle HTTP クライアント (テスト用)
     * @param int                      $memoryCacheSize  L1 (prefix 単位) の上限。デフォルト 100
     * @param CacheInterface|null      $cache            L2 永続キャッシュ (省略時は無効)
     * @param Closure|null             $onSpecMismatch   spec_version 不一致時に 1 度だけ呼ばれるコールバック
     *                                                   signature: function(string $expected, string $received): void
     */
    public function __construct(
        ?string $baseURL = null,
        ?ClientInterface $httpClient = null,
        int $memoryCacheSize = 100,
        ?CacheInterface $cache = null,
        ?Closure $onSpecMismatch = null,
    ) {
        $this->baseURL = rtrim($baseURL ?? DEFAULT_BASE_URL, '/');
        $client = $httpClient ?? new GuzzleClient([
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
        ]);
        $this->http = new Http($client);
        $this->mem = new MemoryLRU($memoryCacheSize);
        $this->cache = $cache;
        $this->onSpecMismatch = $onSpecMismatch;
    }

    /**
     * 単一の zipcode を引く。見つからない・形式不正なら null。
     */
    public function lookup(string $zipcode): ?ZipcodeEntry
    {
        if (preg_match(self::ZIP_REGEX, $zipcode) !== 1) {
            return null;
        }
        $dict = $this->fetchPrefixDict(substr($zipcode, 0, 3));
        if ($dict === null) {
            return null;
        }

        return $dict[$zipcode] ?? null;
    }

    /**
     * 1〜3 桁の prefix 配下のエントリ辞書を返す。
     *
     * @return array<string, ZipcodeEntry>
     */
    public function lookupGroup(string $prefix): array
    {
        if (preg_match(self::PREFIX_REGEX, $prefix) !== 1) {
            throw new \InvalidArgumentException(sprintf('jpzip: prefix must be 1-3 digits, got %s', var_export($prefix, true)));
        }
        $len = strlen($prefix);
        if ($len === 3) {
            return $this->fetchPrefixDict($prefix) ?? [];
        }
        if ($len === 1) {
            return $this->fetchGroupDict($prefix);
        }
        // len === 2: prefix0 〜 prefix9 を並列 fetch。
        $urls = [];
        for ($i = 0; $i < 10; ++$i) {
            $urls[] = $this->prefixURL($prefix . (string) $i);
        }
        $responses = $this->http->getMany($urls, 10);
        $out = [];
        foreach ($urls as $url) {
            $res = $responses[$url] ?? null;
            if ($res === null) {
                continue;
            }
            [$body, $status] = $res;
            if ($status === 404 || $body === null) {
                continue;
            }
            $dict = $this->decodeDict($body, $url);
            $this->mem->set($url, $dict);
            $this->writeL2($url, $body);
            foreach ($dict as $k => $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * 全件辞書を返す。/g/0.json 〜 /g/9.json を並列 fetch して merge する。
     *
     * @return array<string, ZipcodeEntry>
     */
    public function lookupAll(): array
    {
        $urls = [];
        for ($i = 0; $i < 10; ++$i) {
            $urls[] = $this->baseURL . '/g/' . $i . '.json';
        }
        $responses = $this->http->getMany($urls, 10);
        $out = [];
        foreach ($urls as $url) {
            $res = $responses[$url] ?? null;
            if ($res === null) {
                continue;
            }
            [$body, $status] = $res;
            if ($status === 404 || $body === null) {
                continue;
            }
            foreach ($this->decodeDict($body, $url) as $k => $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * 指定 scope を SDK 内キャッシュに展開する。
     *
     * scope は "all" または 1〜3 桁の数字。
     */
    public function preload(string $scope): void
    {
        if ($scope === 'all') {
            $all = $this->lookupAll();
            // 3 桁 prefix にバケットして L1 / L2 に投入。
            $buckets = [];
            foreach ($all as $zip => $entry) {
                $p = substr($zip, 0, 3);
                $buckets[$p] ??= [];
                $buckets[$p][$zip] = $entry;
            }
            foreach ($buckets as $p => $b) {
                $url = $this->prefixURL($p);
                $this->mem->set($url, $b);
                $payload = [];
                foreach ($b as $k => $v) {
                    $payload[$k] = $v->toArray();
                }
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    $this->writeL2($url, $encoded);
                }
            }

            return;
        }
        if (preg_match(self::PREFIX_REGEX, $scope) !== 1) {
            throw new \InvalidArgumentException(sprintf('jpzip: scope must be "all" or 1-3 digits, got %s', var_export($scope, true)));
        }
        $this->lookupGroup($scope);
    }

    /**
     * /meta.json を取得 (キャッシュ済みなら再利用)。
     */
    public function getMeta(): ?Meta
    {
        if ($this->metaResolved) {
            return $this->metaCached;
        }
        [$body, $status] = $this->http->get($this->baseURL . '/meta.json');
        if ($status === 404 || $body === null) {
            $this->metaResolved = true;
            $this->metaCached = null;

            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('jpzip: parse meta: invalid JSON');
        }
        $meta = Meta::fromArray($decoded);
        if ($meta->spec_version !== SPEC_VERSION && $this->onSpecMismatch !== null) {
            ($this->onSpecMismatch)(SPEC_VERSION, $meta->spec_version);
        }
        if ($this->knownVersion !== '' && $this->knownVersion !== $meta->version) {
            $this->mem->clear();
            $this->cache?->clear();
        }
        $this->knownVersion = $meta->version;
        $this->metaCached = $meta;
        $this->metaResolved = true;

        return $meta;
    }

    /**
     * L1 / L2 とメタキャッシュをクリアする。
     */
    public function refresh(): void
    {
        $this->mem->clear();
        $this->metaCached = null;
        $this->metaResolved = false;
        $this->knownVersion = '';
        $this->cache?->clear();
    }

    /* ------------------------------ internals ------------------------------- */

    private function prefixURL(string $prefix3): string
    {
        return $this->baseURL . '/p/' . $prefix3 . '.json';
    }

    /**
     * /p/{prefix3}.json を L1 → L2 → 網 の順で解決する。
     *
     * @return array<string, ZipcodeEntry>|null
     */
    private function fetchPrefixDict(string $prefix3): ?array
    {
        $url = $this->prefixURL($prefix3);
        $hit = $this->mem->get($url);
        if ($hit !== null) {
            return $hit;
        }
        $fromL2 = $this->readL2($url);
        if ($fromL2 !== null) {
            $this->mem->set($url, $fromL2);

            return $fromL2;
        }
        [$body, $status] = $this->http->get($url);
        if ($status === 404 || $body === null) {
            return null;
        }
        $dict = $this->decodeDict($body, $url);
        $this->mem->set($url, $dict);
        $this->writeL2($url, $body);

        return $dict;
    }

    /**
     * @return array<string, ZipcodeEntry>
     */
    private function fetchGroupDict(string $prefix1): array
    {
        $url = $this->baseURL . '/g/' . $prefix1 . '.json';
        [$body, $status] = $this->http->get($url);
        if ($status === 404 || $body === null) {
            return [];
        }

        return $this->decodeDict($body, $url);
    }

    /**
     * @return array<string, ZipcodeEntry>
     */
    private function decodeDict(string $body, string $url): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('jpzip: parse %s: invalid JSON', $url));
        }
        $out = [];
        foreach ($decoded as $zip => $raw) {
            if (is_array($raw)) {
                $out[(string) $zip] = ZipcodeEntry::fromArray($raw);
            }
        }

        return $out;
    }

    /**
     * @return array<string, ZipcodeEntry>|null
     */
    private function readL2(string $url): ?array
    {
        if ($this->cache === null) {
            return null;
        }
        $raw = $this->cache->get($url);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // corrupt cache — drop it
            $this->cache->delete($url);

            return null;
        }
        $out = [];
        foreach ($decoded as $zip => $entry) {
            if (is_array($entry)) {
                $out[(string) $zip] = ZipcodeEntry::fromArray($entry);
            }
        }

        return $out;
    }

    private function writeL2(string $url, string $rawJson): void
    {
        $this->cache?->set($url, $rawJson);
    }
}
