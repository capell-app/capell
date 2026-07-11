<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionCommercialData extends Data
{
    public function __construct(
        public readonly ?string $proposedLicense = null,
        public readonly ?string $requestedCertification = null,
        public readonly ?string $supportPolicy = null,
        public readonly bool $privateDocsRequested = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            proposedLicense: self::nullableString($data['proposedLicense'] ?? null),
            requestedCertification: self::nullableString($data['requestedCertification'] ?? null),
            supportPolicy: self::nullableString($data['supportPolicy'] ?? null),
            privateDocsRequested: (bool) ($data['privateDocsRequested'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'proposedLicense' => $this->proposedLicense,
            'requestedCertification' => $this->requestedCertification,
            'supportPolicy' => $this->supportPolicy,
            'privateDocsRequested' => $this->privateDocsRequested,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
