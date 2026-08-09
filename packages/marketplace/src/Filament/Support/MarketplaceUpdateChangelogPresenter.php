<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Core\Enums\ExtensionReleaseKindEnum;
use Capell\Marketplace\Data\ExtensionDetailData;

/**
 * What actually changed between the version this site is running and the one it
 * is being offered.
 *
 * An update confirmation that says only "a newer version exists" asks the
 * operator to consent to something they cannot see. ExtensionDetailData has
 * carried version history since it was written; this is what puts it in front of
 * the person deciding.
 */
final class MarketplaceUpdateChangelogPresenter
{
    public const int MAXIMUM_ENTRIES = 10;

    /**
     * Releases strictly newer than the installed version, newest first.
     *
     * @return list<array{version: string, kind: string, notes: string}>
     */
    public function entriesSince(ExtensionDetailData $detail, ?string $installedVersion): array
    {
        $entries = [];

        foreach ($detail->versionHistory as $release) {
            $version = $this->stringValue($release['version'] ?? $release['tag'] ?? $release['name'] ?? null);

            if ($version === null) {
                continue;
            }

            if ($installedVersion !== null
                && version_compare(ltrim($version, 'vV'), ltrim($installedVersion, 'vV'), '<=')) {
                continue;
            }

            $entries[$version] = [
                'version' => $version,
                'kind' => ExtensionReleaseKindEnum::between($installedVersion, $version)->value,
                'notes' => $this->stringValue(
                    $release['notes'] ?? $release['changelog'] ?? $release['body'] ?? $release['description'] ?? null,
                ) ?? '',
            ];
        }

        uasort(
            $entries,
            static fn (array $left, array $right): int => version_compare(
                ltrim((string) $right['version'], 'vV'),
                ltrim((string) $left['version'], 'vV'),
            ),
        );

        // Bounded, because a long-neglected extension can have dozens of
        // releases and a modal the operator has to scroll past is a modal they
        // stop reading.
        return array_slice(array_values($entries), 0, self::MAXIMUM_ENTRIES);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }
}
