<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * CDN から配信される 1 つの郵便番号エントリ。
 */
final readonly class ZipcodeEntry
{
    /**
     * @param list<Town> $towns
     */
    public function __construct(
        public string $prefecture,
        public string $prefecture_kana,
        public string $prefecture_roma,
        public string $prefecture_code,
        public string $city,
        public string $city_kana,
        public string $city_roma,
        public string $city_code,
        public array $towns,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $towns = [];
        if (isset($data['towns']) && is_array($data['towns'])) {
            foreach ($data['towns'] as $t) {
                if (is_array($t)) {
                    $towns[] = Town::fromArray($t);
                }
            }
        }

        return new self(
            prefecture: (string) ($data['prefecture'] ?? ''),
            prefecture_kana: (string) ($data['prefecture_kana'] ?? ''),
            prefecture_roma: (string) ($data['prefecture_roma'] ?? ''),
            prefecture_code: (string) ($data['prefecture_code'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            city_kana: (string) ($data['city_kana'] ?? ''),
            city_roma: (string) ($data['city_roma'] ?? ''),
            city_code: (string) ($data['city_code'] ?? ''),
            towns: $towns,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prefecture' => $this->prefecture,
            'prefecture_kana' => $this->prefecture_kana,
            'prefecture_roma' => $this->prefecture_roma,
            'prefecture_code' => $this->prefecture_code,
            'city' => $this->city,
            'city_kana' => $this->city_kana,
            'city_roma' => $this->city_roma,
            'city_code' => $this->city_code,
            'towns' => array_map(static fn (Town $t): array => $t->toArray(), $this->towns),
        ];
    }
}
