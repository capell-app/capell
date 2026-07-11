<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionSecurityData extends Data
{
    /**
     * @param  array<string, mixed>  $publicSurface
     * @param  array<string, mixed>  $sensitiveData
     * @param  array<string, bool>  $publicOutput
     * @param  array<string, mixed>  $externalHttpClients
     * @param  array<string, mixed>  $adminSurface
     */
    public function __construct(
        public readonly ?string $riskTier = null,
        public readonly array $publicSurface = [],
        public readonly array $sensitiveData = [],
        public readonly array $publicOutput = [],
        public readonly array $externalHttpClients = [],
        public readonly array $adminSurface = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            riskTier: self::nullableString($data['riskTier'] ?? null),
            publicSurface: self::arrayValue($data['publicSurface'] ?? []),
            sensitiveData: self::arrayValue($data['sensitiveData'] ?? []),
            publicOutput: self::booleanMap($data['publicOutput'] ?? []),
            externalHttpClients: self::arrayValue($data['externalHttpClients'] ?? []),
            adminSurface: self::arrayValue($data['adminSurface'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'riskTier' => $this->riskTier,
            'publicSurface' => $this->publicSurface,
            'sensitiveData' => $this->sensitiveData,
            'publicOutput' => $this->publicOutput,
            'externalHttpClients' => $this->externalHttpClients,
            'adminSurface' => $this->adminSurface,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<string, bool> */
    private static function booleanMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(static fn (mixed $item): bool => is_bool($item))
            ->all();
    }
}
