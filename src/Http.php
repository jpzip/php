<?php

declare(strict_types=1);

namespace Jpzip;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle ベースの HTTP ヘルパ。
 *
 * - 5xx / ネットワークエラーは指数バックオフで最大 3 回までリトライ
 * - 404 は body=null status=404 で返す (リトライしない)
 *
 * @internal
 */
final class Http
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {
    }

    /**
     * @return array{0: ?string, 1: int} [body, status]
     */
    public function get(string $url): array
    {
        $lastException = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($attempt > 0) {
                // 200ms * 2^attempt: 400ms, 800ms。
                $delayMs = 200 * (1 << $attempt);
                usleep($delayMs * 1000);
            }
            try {
                $response = $this->client->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json'],
                    'http_errors' => false,
                ]);
            } catch (ConnectException | RequestException $e) {
                $lastException = $e;
                continue;
            } catch (TransferException $e) {
                $lastException = $e;
                continue;
            }
            $status = $response->getStatusCode();
            if ($status === 404) {
                return [null, 404];
            }
            if ($status >= 500) {
                $lastException = new \RuntimeException(sprintf('jpzip: %s returned %d', $url, $status));
                continue;
            }
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('jpzip: %s returned %d', $url, $status));
            }

            return [(string) $response->getBody(), $status];
        }
        throw $lastException ?? new \RuntimeException(sprintf('jpzip: %s failed after retries', $url));
    }

    /**
     * 複数 URL を並列 fetch する。
     *
     * @param list<string> $urls
     *
     * @return array<string, array{0: ?string, 1: int}> URL → [body, status]
     */
    public function getMany(array $urls, int $concurrency = 10): array
    {
        if ($urls === []) {
            return [];
        }
        // Guzzle Pool は内部リトライを行わないため、リトライは各 URL ごとに最大 3 周ループで実装する。
        $remaining = array_fill_keys($urls, true);
        $results = [];
        $errors = [];

        for ($attempt = 0; $attempt < 3 && $remaining !== []; ++$attempt) {
            if ($attempt > 0) {
                $delayMs = 200 * (1 << $attempt);
                usleep($delayMs * 1000);
            }
            $batch = array_keys($remaining);
            $requests = static function () use ($batch): \Generator {
                foreach ($batch as $url) {
                    yield new Request('GET', $url, ['Accept' => 'application/json']);
                }
            };
            $batchResults = [];
            $pool = new Pool($this->client, $requests(), [
                'concurrency' => $concurrency,
                'options' => ['http_errors' => false],
                'fulfilled' => static function (ResponseInterface $resp, int $index) use (&$batchResults, $batch): void {
                    $batchResults[$batch[$index]] = ['resp' => $resp, 'err' => null];
                },
                'rejected' => static function (mixed $reason, int $index) use (&$batchResults, $batch): void {
                    $batchResults[$batch[$index]] = ['resp' => null, 'err' => $reason];
                },
            ]);
            $promise = $pool->promise();
            if ($promise instanceof PromiseInterface) {
                $promise->wait();
            }
            foreach ($batch as $url) {
                if (!isset($batchResults[$url])) {
                    $errors[$url] = new \RuntimeException(sprintf('jpzip: %s did not complete', $url));
                    continue;
                }
                $entry = $batchResults[$url];
                if ($entry['resp'] === null) {
                    $errors[$url] = $entry['err'] instanceof \Throwable
                        ? $entry['err']
                        : new \RuntimeException(sprintf('jpzip: %s failed', $url));
                    continue;
                }
                /** @var ResponseInterface $resp */
                $resp = $entry['resp'];
                $status = $resp->getStatusCode();
                if ($status === 404) {
                    $results[$url] = [null, 404];
                    unset($remaining[$url], $errors[$url]);
                    continue;
                }
                if ($status >= 500) {
                    $errors[$url] = new \RuntimeException(sprintf('jpzip: %s returned %d', $url, $status));
                    continue;
                }
                if ($status >= 400) {
                    throw new \RuntimeException(sprintf('jpzip: %s returned %d', $url, $status));
                }
                $results[$url] = [(string) $resp->getBody(), $status];
                unset($remaining[$url], $errors[$url]);
            }
        }

        if ($remaining !== []) {
            // リトライ後も残っているものはエラー。
            $firstUrl = array_key_first($remaining);
            throw $errors[$firstUrl] ?? new \RuntimeException(sprintf('jpzip: %s failed after retries', $firstUrl));
        }

        return $results;
    }
}
