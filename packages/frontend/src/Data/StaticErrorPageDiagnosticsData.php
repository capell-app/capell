<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\StaticErrorPageResolutionReason;
use Override;
use Spatie\LaravelData\Data;

/**
 * The full, read-only account of a static error page resolution attempt.
 */
final class StaticErrorPageDiagnosticsData extends Data
{
    /** @param list<StaticErrorPageCandidateData> $candidates */
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $path,
        public readonly string $status,
        public readonly bool $storeBound,
        public readonly string $manifestPath,
        public readonly bool $manifestExists,
        public readonly array $candidates,
        public readonly bool $resolved,
        public readonly ?StaticErrorPageResolutionReason $reason = null,
        public readonly ?string $resolvedFilePath = null,
        public readonly ?bool $resolvedFileExists = null,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'path' => $this->path,
            'status' => $this->status,
            'storeBound' => $this->storeBound,
            'manifestPath' => $this->manifestPath,
            'manifestExists' => $this->manifestExists,
            'candidates' => array_map(
                static fn (StaticErrorPageCandidateData $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
            'resolved' => $this->resolved,
            'reason' => $this->reason?->value,
            'resolvedFilePath' => $this->resolvedFilePath,
            'resolvedFileExists' => $this->resolvedFileExists,
        ];
    }
}
