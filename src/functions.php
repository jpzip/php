<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * SDK が対応する jpzip プロトコルのバージョン。
 */
const SPEC_VERSION = '1.0';

/**
 * 本番 CDN の配信元。
 */
const DEFAULT_BASE_URL = 'https://jpzip.nadai.dev';

/**
 * 既定のクライアントを (遅延) 取得する。
 *
 * @internal
 */
function _default_client(): Client
{
    static $client = null;
    if ($client === null) {
        $client = new Client();
    }

    return $client;
}

/**
 * 単一の zipcode を引く (既定クライアント経由)。
 */
function lookup(string $zipcode): ?ZipcodeEntry
{
    return _default_client()->lookup($zipcode);
}

/**
 * prefix 配下のエントリ辞書を返す。
 *
 * @return array<string, ZipcodeEntry>
 */
function lookupGroup(string $prefix): array
{
    return _default_client()->lookupGroup($prefix);
}

/**
 * 全件辞書を返す。
 *
 * @return array<string, ZipcodeEntry>
 */
function lookupAll(): array
{
    return _default_client()->lookupAll();
}

/**
 * preload を行う。
 */
function preload(string $scope): void
{
    _default_client()->preload($scope);
}

/**
 * /meta.json を取得 (キャッシュ済みなら再利用)。
 */
function getMeta(): ?Meta
{
    return _default_client()->getMeta();
}

/**
 * 7 桁の数字かどうかだけを検証する (fetch なし)。
 */
function isValidZipcode(string $s): bool
{
    return preg_match('/^\d{7}$/', $s) === 1;
}
