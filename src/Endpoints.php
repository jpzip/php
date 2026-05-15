<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * /meta.json の endpoints 部分。
 */
final readonly class Endpoints
{
    public function __construct(
        public string $group,
        public string $prefix,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            group: (string) ($data['group'] ?? ''),
            prefix: (string) ($data['prefix'] ?? ''),
        );
    }
}
