<?php

declare(strict_types=1);

namespace Capell\Core\Data\Marketplace;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionHealthReportData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $extensions
     * @param  array<int, array<string, mixed>>  $packages
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array<string, mixed>  $licenceState
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ?string $siteId = null,
        public readonly ?string $installId = null,
        public readonly ?string $appUrl = null,
        public readonly ?string $capellVersion = null,
        public readonly ?string $laravelVersion = null,
        public readonly ?string $phpVersion = null,
        public readonly ?string $environment = null,
        public readonly ?string $generatedAt = null,
        public readonly array $extensions = [],
        public readonly array $packages = [],
        public readonly array $alerts = [],
        public readonly array $licenceState = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            siteId: self::optionalString($payload['site_id'] ?? null),
            installId: self::optionalString($payload['install_id'] ?? null),
            appUrl: self::optionalString($payload['app_url'] ?? null),
            capellVersion: self::optionalString($payload['capell_version'] ?? null),
            laravelVersion: self::optionalString($payload['laravel_version'] ?? null),
            phpVersion: self::optionalString($payload['php_version'] ?? null),
            environment: self::optionalString($payload['environment'] ?? null),
            generatedAt: self::optionalString($payload['generated_at'] ?? null),
            extensions: self::listOfArrays($payload['extensions'] ?? []),
            packages: self::listOfArrays($payload['packages'] ?? []),
            alerts: self::listOfArrays($payload['alerts'] ?? []),
            licenceState: is_array($payload['licence_state'] ?? null) ? $payload['licence_state'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'site_id' => $this->siteId,
            'install_id' => $this->installId,
            'app_url' => $this->appUrl,
            'capell_version' => $this->capellVersion,
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'environment' => $this->environment,
            'generated_at' => $this->generatedAt,
            'extensions' => $this->extensions,
            'packages' => $this->packages,
            'alerts' => $this->alerts,
            'licence_state' => $this->licenceState,
            'metadata' => $this->metadata,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listOfArrays(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }
}
