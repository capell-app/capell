<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Override;
use Spatie\LaravelData\Data;

/**
 * Which source wins a piece of error-page copy, and why the rest lost.
 */
final class ErrorPageCopyDiagnosticsData extends Data
{
    /**
     * @param  array<string, list<ErrorPageCopySourceData>>  $fields  keyed by
     *                                                                copy field ("headline", "description")
     * @param  array<string, ?string>  $winners  resolved value per field
     */
    public function __construct(
        public readonly string $host,
        public readonly string $status,
        public readonly string $fallbackManifestPath,
        public readonly bool $fallbackManifestExists,
        /**
         * When the fallback manifest was last written. A public 404 regenerates
         * it mid-request, so a value pinned before the request may already be
         * gone by the time anything asserts on it.
         */
        public readonly ?string $fallbackManifestWrittenAt = null,
        public readonly ?string $viewPath = null,
        public readonly array $fields = [],
        public readonly array $winners = [],
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'status' => $this->status,
            'fallbackManifestPath' => $this->fallbackManifestPath,
            'fallbackManifestExists' => $this->fallbackManifestExists,
            'fallbackManifestWrittenAt' => $this->fallbackManifestWrittenAt,
            'viewPath' => $this->viewPath,
            'fields' => array_map(
                static fn (array $sources): array => array_map(
                    static fn (ErrorPageCopySourceData $source): array => $source->toArray(),
                    $sources,
                ),
                $this->fields,
            ),
            'winners' => $this->winners,
        ];
    }
}
