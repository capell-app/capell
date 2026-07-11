<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionCacheSafetyData extends Data
{
    /**
     * @param  list<string>  $variesBy
     * @param  list<array<string, mixed>>  $invalidationSources
     */
    public function __construct(
        public readonly bool $cacheable,
        public readonly array $variesBy,
        public readonly bool $sensitiveOutput,
        public readonly array $invalidationSources,
        public readonly bool $queueInvalidation,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            cacheable: (bool) $data['cacheable'],
            variesBy: self::stringList($data['variesBy']),
            sensitiveOutput: (bool) $data['sensitiveOutput'],
            invalidationSources: array_values(array_filter(
                is_array($data['invalidationSources']) ? $data['invalidationSources'] : [],
                is_array(...),
            )),
            queueInvalidation: (bool) $data['queueInvalidation'],
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'cacheable' => $this->cacheable,
            'variesBy' => $this->variesBy,
            'sensitiveOutput' => $this->sensitiveOutput,
            'invalidationSources' => $this->invalidationSources,
            'queueInvalidation' => $this->queueInvalidation,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
