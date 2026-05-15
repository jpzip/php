<?php

declare(strict_types=1);

namespace Jpzip;

/**
 * ZipcodeEntry.towns の 1 要素。
 */
final readonly class Town
{
    public function __construct(
        public string $town,
        public string $kana,
        public string $roma,
        public ?string $note = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            town: (string) ($data['town'] ?? ''),
            kana: (string) ($data['kana'] ?? ''),
            roma: (string) ($data['roma'] ?? ''),
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'town' => $this->town,
            'kana' => $this->kana,
            'roma' => $this->roma,
        ];
        if ($this->note !== null) {
            $out['note'] = $this->note;
        }

        return $out;
    }
}
