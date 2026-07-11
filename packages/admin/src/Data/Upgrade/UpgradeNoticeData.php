<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Upgrade;

use Spatie\LaravelData\Data;

final class UpgradeNoticeData extends Data
{
    /**
     * @param  array<int, string>  $composerNames
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $noticeId,
        public readonly string $type,
        public readonly string $severity,
        public readonly array $composerNames,
        public readonly string $installedVersion,
        public readonly string $recommendedVersion,
        public readonly ?int $versionsBehind,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $installedVersion = self::installedVersion($payload);
        $recommendedVersion = self::recommendedVersion($payload);

        return new self(
            noticeId: self::noticeId($payload),
            type: self::noticeType($payload),
            severity: self::severity($payload),
            composerNames: self::composerNames($payload),
            installedVersion: $installedVersion,
            recommendedVersion: $recommendedVersion,
            versionsBehind: self::versionsBehind($installedVersion, $recommendedVersion),
            payload: $payload,
        );
    }

    public function isHighRiskSecurity(): bool
    {
        return $this->type === 'security' && in_array($this->severity, ['critical', 'high'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function noticeId(array $payload): string
    {
        $noticeId = $payload['notice_id'] ?? $payload['id'] ?? '';

        return is_string($noticeId) ? $noticeId : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function noticeType(array $payload): string
    {
        $type = $payload['update_type'] ?? $payload['release_type'] ?? $payload['type'] ?? 'feature';

        if (! is_string($type)) {
            return 'feature';
        }

        return $type === 'bug' ? 'bugfix' : $type;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function severity(array $payload): string
    {
        $severity = $payload['severity'] ?? 'low';

        return is_string($severity) && $severity !== '' ? $severity : 'low';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private static function composerNames(array $payload): array
    {
        return collect([
            $payload['composer_name'] ?? null,
            $payload['package'] ?? null,
        ])
            ->merge(collect(self::affectedPackages($payload))
                ->map(fn (array $package): mixed => $package['composer_name'] ?? null))
            ->filter(fn (mixed $composerName): bool => is_string($composerName) && $composerName !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function installedVersion(array $payload): string
    {
        $installedVersion = $payload['installed_version'] ?? $payload['current_version'] ?? null;

        if (is_string($installedVersion) && $installedVersion !== '') {
            return $installedVersion;
        }

        $affectedPackage = collect(self::affectedPackages($payload))
            ->first(fn (array $package): bool => is_string($package['installed_version'] ?? null) && $package['installed_version'] !== '');

        return is_array($affectedPackage) ? (string) $affectedPackage['installed_version'] : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function recommendedVersion(array $payload): string
    {
        $recommendedVersion = $payload['recommended_version'] ?? $payload['latest_version'] ?? null;

        if (is_string($recommendedVersion) && $recommendedVersion !== '') {
            return $recommendedVersion;
        }

        $affectedPackage = collect(self::affectedPackages($payload))
            ->first(fn (array $package): bool => is_string($package['fixed_version'] ?? null) && $package['fixed_version'] !== '');

        return is_array($affectedPackage) ? (string) $affectedPackage['fixed_version'] : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private static function affectedPackages(array $payload): array
    {
        $packages = $payload['affected_packages'] ?? [];

        return is_array($packages)
            ? array_values(array_filter($packages, is_array(...)))
            : [];
    }

    private static function versionsBehind(string $installedVersion, string $recommendedVersion): ?int
    {
        $installedComparableVersion = self::comparableVersion($installedVersion);
        $recommendedComparableVersion = self::comparableVersion($recommendedVersion);

        if ($installedComparableVersion === null || $recommendedComparableVersion === null) {
            return null;
        }

        if (version_compare($recommendedComparableVersion, $installedComparableVersion) <= 0) {
            return 0;
        }

        $installedParts = array_map(intval(...), explode('.', $installedComparableVersion));
        $recommendedParts = array_map(intval(...), explode('.', $recommendedComparableVersion));

        if ($installedParts[0] !== $recommendedParts[0]) {
            return max(1, ($recommendedParts[0] - $installedParts[0]) * 10);
        }

        if (($installedParts[1] ?? 0) !== ($recommendedParts[1] ?? 0)) {
            return max(1, ($recommendedParts[1] ?? 0) - ($installedParts[1] ?? 0));
        }

        return max(1, ($recommendedParts[2] ?? 0) - ($installedParts[2] ?? 0));
    }

    private static function comparableVersion(string $version): ?string
    {
        if (preg_match('/v?(\d+(?:\.\d+){0,2})/i', $version, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $matches[1]);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
