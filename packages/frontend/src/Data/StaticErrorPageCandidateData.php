<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\StaticErrorPageResolutionReason;
use Override;
use Spatie\LaravelData\Data;

/**
 * One manifest entry as the resolver saw it, with the predicate that rejected
 * it (or null when it matched) and both sides of that comparison.
 */
final class StaticErrorPageCandidateData extends Data
{
    public function __construct(
        public readonly int $index,
        public readonly string $scheme,
        public readonly string $domain,
        public readonly string $status,
        public readonly string $path,
        public readonly string $file,
        public readonly ?StaticErrorPageResolutionReason $rejectedBy = null,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
        public readonly bool $selected = false,
        public readonly ?string $resolvedPath = null,
        public readonly ?bool $fileExists = null,
    ) {}

    public function matched(): bool
    {
        return ! $this->rejectedBy instanceof StaticErrorPageResolutionReason;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'scheme' => $this->scheme,
            'domain' => $this->domain,
            'status' => $this->status,
            'path' => $this->path,
            'file' => $this->file,
            'rejectedBy' => $this->rejectedBy?->value,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'selected' => $this->selected,
            'resolvedPath' => $this->resolvedPath,
            'fileExists' => $this->fileExists,
        ];
    }
}
