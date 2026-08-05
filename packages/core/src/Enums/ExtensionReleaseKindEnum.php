<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How big the step between two versions is.
 *
 * A separate type from the auto-update policy so that "what kind of release is
 * this" and "what kind of release may we take" are answered by different things
 * and can be tested against each other.
 */
enum ExtensionReleaseKindEnum: string implements HasLabel
{
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    /**
     * Anything that is not a clean step forward: an unparseable version, a
     * downgrade, or two versions that are the same. Never eligible for an
     * automatic update, because there is nothing here to reason about.
     */
    case Unknown = 'unknown';

    public static function between(?string $currentVersion, ?string $latestVersion): self
    {
        if ($currentVersion === null || $latestVersion === null) {
            return self::Unknown;
        }

        $current = self::numericSegments($currentVersion);
        $latest = self::numericSegments($latestVersion);

        if ($current === null || $latest === null) {
            return self::Unknown;
        }

        if (version_compare(implode('.', $latest), implode('.', $current), '<=')) {
            return self::Unknown;
        }

        return match (true) {
            $latest[0] !== $current[0] => self::Major,
            $latest[1] !== $current[1] => self::Minor,
            default => self::Patch,
        };
    }

    public function getLabel(): string
    {
        return (string) __('capell-core::extensions.release_kinds.' . $this->value);
    }

    /**
     * Major, minor and patch as integers, or null when the string is not a
     * version this can reason about.
     *
     * Pre-release and build metadata are dropped rather than rejected: a site on
     * 2.3.0 offered 2.3.1-beta.1 is still being offered a patch, and refusing to
     * classify it would silently exclude it from every policy.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function numericSegments(string $version): ?array
    {
        $normalized = ltrim(trim($version), 'vV');
        $core = preg_split('/[-+]/', $normalized)[0] ?? '';
        $segments = explode('.', $core);

        if (! ctype_digit($segments[0])) {
            return null;
        }

        return [
            (int) $segments[0],
            ctype_digit($segments[1] ?? '') ? (int) $segments[1] : 0,
            ctype_digit($segments[2] ?? '') ? (int) $segments[2] : 0,
        ];
    }
}
