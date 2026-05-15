<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * /meta.json 全体。
 */
final readonly class Meta
{
    /**
     * @param array<string, int> $by_pref
     */
    public function __construct(
        public string $version,
        public string $generated_at,
        public string $spec_version,
        public int $total_zipcodes,
        public int $prefix_count,
        public array $by_pref,
        public string $data_source,
        public Endpoints $endpoints,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $byPref = [];
        if (isset($data['by_pref']) && is_array($data['by_pref'])) {
            foreach ($data['by_pref'] as $k => $v) {
                $byPref[(string) $k] = (int) $v;
            }
        }
        $endpoints = isset($data['endpoints']) && is_array($data['endpoints'])
            ? Endpoints::fromArray($data['endpoints'])
            : new Endpoints('', '');

        return new self(
            version: (string) ($data['version'] ?? ''),
            generated_at: (string) ($data['generated_at'] ?? ''),
            spec_version: (string) ($data['spec_version'] ?? ''),
            total_zipcodes: (int) ($data['total_zipcodes'] ?? 0),
            prefix_count: (int) ($data['prefix_count'] ?? 0),
            by_pref: $byPref,
            data_source: (string) ($data['data_source'] ?? ''),
            endpoints: $endpoints,
        );
    }
}
