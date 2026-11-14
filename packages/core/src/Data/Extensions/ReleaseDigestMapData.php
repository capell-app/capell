<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Composer\Semver\VersionParser;
use Throwable;

/**
 * The per-version release digests a Marketplace publishes for one package.
 * Composer may resolve any version that satisfies the constraints, so the
 * digest is selected for the version actually installed, never for the
 * version the listing happened to describe.
 */
final readonly class ReleaseDigestMapData
{
    public const string INVALID = 'invalid';

    /**
     * @param  array<string, array{content_sha256: string, dist_sha: string|null}>  $digests  Keyed by the published version spelling.
     */
    public function __construct(public array $digests) {}

    /** Returns null when the payload carries no map at all. */
    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            return new self([]);
        }

        $digests = [];

        foreach ($payload as $version => $entry) {
            $version = trim((string) $version);

            if ($version === '') {
                continue;
            }

            $content = is_array($entry) ? ($entry['release_content_sha256'] ?? $entry['content_sha256'] ?? null) : null;
            $dist = is_array($entry) ? ($entry['release_checksum'] ?? $entry['dist_sha'] ?? null) : null;

            $digests[$version] = [
                'content_sha256' => is_string($content) && preg_match('/\A[a-f0-9]{64}\z/i', $content) === 1 ? strtolower($content) : self::INVALID,
                'dist_sha' => $dist === null ? null : (is_string($dist) && preg_match('/\A[a-f0-9]{40}\z/i', $dist) === 1 ? strtolower($dist) : self::INVALID),
            ];
        }

        return new self($digests);
    }

    /** @return array<string, array{release_content_sha256: string, release_checksum?: string}> */
    public function toPayload(): array
    {
        $payload = [];

        foreach ($this->digests as $version => $digest) {
            $payload[$version] = array_filter([
                'release_content_sha256' => $digest['content_sha256'],
                'release_checksum' => $digest['dist_sha'],
            ], static fn (?string $value): bool => $value !== null);
        }

        return $payload;
    }

    /**
     * The digest for the installed version, matched with Composer's version
     * normalisation. Null when no entry, or more than one disagreeing entry,
     * describes that version.
     *
     * @return array{content_sha256: string, dist_sha: string|null}|null
     */
    public function forInstalledVersion(?string $installedVersion): ?array
    {
        $installed = $this->normalise($installedVersion);

        if ($installed === null) {
            return null;
        }

        $matches = [];

        foreach ($this->digests as $version => $digest) {
            if ($this->normalise($version) === $installed) {
                $matches[serialize($digest)] = $digest;
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function normalise(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }

        try {
            return (new VersionParser)->normalize(trim($version));
        } catch (Throwable) {
            return null;
        }
    }
}
