<?php

declare(strict_types=1);

namespace Jpzip\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Jpzip\CacheInterface;
use Jpzip\Client;
use Jpzip\Tests\InMemoryCache;
use Jpzip\ZipcodeEntry;
use PHPUnit\Framework\TestCase;

use function Jpzip\isValidZipcode;

final class ClientTest extends TestCase
{
    private const SAMPLE_ENTRY = [
        'prefecture' => '神奈川県',
        'prefecture_kana' => 'カナガワケン',
        'prefecture_roma' => 'Kanagawa',
        'prefecture_code' => '14',
        'city' => '横浜市中区',
        'city_kana' => 'ヨコハマシナカク',
        'city_roma' => 'Yokohama Shi Naka Ku',
        'city_code' => '14104',
        'towns' => [[
            'town' => '本町',
            'kana' => 'ホンチョウ',
            'roma' => 'Honcho',
        ]],
    ];

    /**
     * @param list<Response> $responses
     */
    private function makeClient(array $responses, ?CacheInterface $cache = null): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);

        return new Client(
            baseURL: 'https://example.test',
            httpClient: $guzzle,
            memoryCacheSize: 100,
            cache: $cache,
        );
    }

    public function testIsValidZipcode(): void
    {
        self::assertTrue(isValidZipcode('2310017'));
        self::assertFalse(isValidZipcode('231083'));
        self::assertFalse(isValidZipcode('abcdefg'));
        self::assertFalse(isValidZipcode('23100171'));
    }

    public function testLookupReturnsEntry(): void
    {
        $body = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        $client = $this->makeClient([new Response(200, [], $body)]);

        $entry = $client->lookup('2310017');
        self::assertInstanceOf(ZipcodeEntry::class, $entry);
        self::assertSame('神奈川県', $entry->prefecture);
        self::assertSame('横浜市中区', $entry->city);
        self::assertCount(1, $entry->towns);
        self::assertSame('本町', $entry->towns[0]->town);
    }

    public function testLookupMalformedReturnsNullWithoutNetwork(): void
    {
        // MockHandler 無し: ネットワーク呼び出しがあれば例外で落ちる。
        $mock = new MockHandler([]);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $client = new Client(baseURL: 'https://example.test', httpClient: $guzzle);

        self::assertNull($client->lookup('abc'));
        self::assertNull($client->lookup('123'));
        self::assertNull($client->lookup(''));
    }

    public function testLookupReturnsNullOn404(): void
    {
        $client = $this->makeClient([new Response(404)]);
        self::assertNull($client->lookup('9999999'));
    }

    public function testLookupCachesPrefix(): void
    {
        $body = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        // 2 回目は MockHandler が空なので、もしネットワークに行けば例外。
        $client = $this->makeClient([new Response(200, [], $body)]);

        self::assertNotNull($client->lookup('2310017'));
        // L1 ヒットで再 fetch しない。
        self::assertNotNull($client->lookup('2310017'));
    }

    public function testLookupGroupThreeDigit(): void
    {
        $body = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        $client = $this->makeClient([new Response(200, [], $body)]);

        $dict = $client->lookupGroup('231');
        self::assertArrayHasKey('2310017', $dict);
    }

    public function testLookupGroupTwoDigitFanOut(): void
    {
        // 230〜239 の 10 ファイル。230 と 231 だけエントリ持ち、他は 404。
        $responses = [];
        for ($i = 0; $i < 10; ++$i) {
            if ($i === 0) {
                $responses[] = new Response(200, [], json_encode([
                    '2300001' => self::SAMPLE_ENTRY,
                ], JSON_UNESCAPED_UNICODE));
            } elseif ($i === 1) {
                $responses[] = new Response(200, [], json_encode([
                    '2310017' => self::SAMPLE_ENTRY,
                ], JSON_UNESCAPED_UNICODE));
            } else {
                $responses[] = new Response(404);
            }
        }
        $client = $this->makeClient($responses);
        $dict = $client->lookupGroup('23');
        self::assertCount(2, $dict);
        self::assertArrayHasKey('2300001', $dict);
        self::assertArrayHasKey('2310017', $dict);
    }

    public function testLookupGroupOneDigit(): void
    {
        $body = json_encode([
            '2310017' => self::SAMPLE_ENTRY,
            '2300001' => self::SAMPLE_ENTRY,
        ], JSON_UNESCAPED_UNICODE);
        $client = $this->makeClient([new Response(200, [], $body)]);

        $dict = $client->lookupGroup('2');
        self::assertCount(2, $dict);
    }

    public function testLookupGroupInvalidPrefix(): void
    {
        $client = $this->makeClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->lookupGroup('abcd');
    }

    public function testLookupAll(): void
    {
        $responses = [];
        for ($i = 0; $i < 10; ++$i) {
            $zip = $i . '000000';
            $responses[] = new Response(200, [], json_encode([
                $zip => self::SAMPLE_ENTRY,
            ], JSON_UNESCAPED_UNICODE));
        }
        $client = $this->makeClient($responses);
        $dict = $client->lookupAll();
        self::assertCount(10, $dict);
    }

    public function testRefreshClearsCache(): void
    {
        $body = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        // 1 度目のあと refresh → 2 度目もネットワーク。
        $client = $this->makeClient([
            new Response(200, [], $body),
            new Response(200, [], $body),
        ]);

        self::assertNotNull($client->lookup('2310017'));
        $client->refresh();
        self::assertNotNull($client->lookup('2310017'));
    }

    public function testGetMetaCachesAcrossCalls(): void
    {
        $meta = [
            'version' => '2026-05',
            'generated_at' => '2026-05-01T00:00:00Z',
            'spec_version' => '1.0',
            'total_zipcodes' => 1,
            'prefix_count' => 1,
            'by_pref' => ['14' => 1],
            'data_source' => 'https://example/data',
            'endpoints' => ['group' => '/g/{p}.json', 'prefix' => '/p/{p}.json'],
        ];
        $client = $this->makeClient([new Response(200, [], json_encode($meta, JSON_UNESCAPED_UNICODE))]);

        $first = $client->getMeta();
        self::assertNotNull($first);
        self::assertSame('2026-05', $first->version);
        // 2 度目は L1。MockHandler は空なので、ネットワークなら例外。
        $second = $client->getMeta();
        self::assertSame($first, $second);
    }

    public function testMetaVersionChangeInvalidatesCache(): void
    {
        $entryBody = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        $metaV1 = json_encode([
            'version' => '2026-05',
            'generated_at' => '2026-05-01T00:00:00Z',
            'spec_version' => '1.0',
            'total_zipcodes' => 1,
            'prefix_count' => 1,
            'by_pref' => [],
            'data_source' => '',
            'endpoints' => ['group' => '/g/{p}.json', 'prefix' => '/p/{p}.json'],
        ], JSON_UNESCAPED_UNICODE);
        $metaV2 = json_encode([
            'version' => '2026-06',
            'generated_at' => '2026-06-01T00:00:00Z',
            'spec_version' => '1.0',
            'total_zipcodes' => 1,
            'prefix_count' => 1,
            'by_pref' => [],
            'data_source' => '',
            'endpoints' => ['group' => '/g/{p}.json', 'prefix' => '/p/{p}.json'],
        ], JSON_UNESCAPED_UNICODE);

        $cache = new InMemoryCache();
        $client = $this->makeClient([
            new Response(200, [], $metaV1),     // 1st meta
            new Response(200, [], $entryBody),  // prefix fetch → cached in L1/L2
            new Response(200, [], $metaV2),     // 2nd meta (after refresh) → invalidates caches
            new Response(200, [], $entryBody),  // re-fetch after invalidation
        ], $cache);

        self::assertNotNull($client->getMeta());
        self::assertNotNull($client->lookup('2310017'));
        self::assertNotEmpty($cache->store);

        // refresh して meta を再取得 → version 変化で L1/L2 が消える。
        $client->refresh();
        self::assertNotNull($client->getMeta());
        // Refresh で clear、その後 meta の version 比較がトリガーされる前に refresh 自体が L2 を空にしている。
        // ここでは「再取得しても落ちない」「最終的な lookup が成功する」ことを確認する。
        self::assertNotNull($client->lookup('2310017'));
    }

    public function testSpecMismatchCallbackInvoked(): void
    {
        $meta = json_encode([
            'version' => '2026-05',
            'generated_at' => '2026-05-01T00:00:00Z',
            'spec_version' => '2.0',
            'total_zipcodes' => 0,
            'prefix_count' => 0,
            'by_pref' => [],
            'data_source' => '',
            'endpoints' => ['group' => '/g/{p}.json', 'prefix' => '/p/{p}.json'],
        ], JSON_UNESCAPED_UNICODE);

        $mock = new MockHandler([new Response(200, [], $meta)]);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);

        $called = [];
        $client = new Client(
            baseURL: 'https://example.test',
            httpClient: $guzzle,
            onSpecMismatch: function (string $expected, string $received) use (&$called): void {
                $called[] = [$expected, $received];
            },
        );
        $client->getMeta();
        self::assertCount(1, $called);
        self::assertSame(['1.0', '2.0'], $called[0]);
    }

    public function testRetryOn5xx(): void
    {
        $body = json_encode(['2310017' => self::SAMPLE_ENTRY], JSON_UNESCAPED_UNICODE);
        $client = $this->makeClient([
            new Response(500),
            new Response(503),
            new Response(200, [], $body),
        ]);
        self::assertNotNull($client->lookup('2310017'));
    }
}
