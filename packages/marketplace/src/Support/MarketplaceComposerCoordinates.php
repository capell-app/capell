<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

final class MarketplaceComposerCoordinates
{
    public static function assertValid(string $composerName, string $versionConstraint): void
    {
        if (strlen($composerName) > 255
            || preg_match('{\A[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z}', $composerName) !== 1) {
            throw new UnexpectedValueException(__('capell-marketplace::marketplace.errors.invalid_composer_coordinates'));
        }

        // Composer accepts aliases; remote responses must not redefine package versions.
        if ($versionConstraint === '' || strlen($versionConstraint) > 512
            || trim($versionConstraint) !== $versionConstraint
            || preg_match('/[\x00-\x1f\x7f]|\s+as\s+/i', $versionConstraint) === 1) {
            throw new UnexpectedValueException(__('capell-marketplace::marketplace.errors.invalid_composer_coordinates'));
        }

        try {
            new VersionParser()->parseConstraints($versionConstraint);
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new UnexpectedValueException(__('capell-marketplace::marketplace.errors.invalid_composer_coordinates'), $unexpectedValueException->getCode(), previous: $unexpectedValueException);
        }
    }

    public static function assertUpgrade(string $composerName, string $versionConstraint, string $currentVersion): void
    {
        self::assertValid($composerName, $versionConstraint);
        $parser = new VersionParser;

        if (! Intervals::isSubsetOf($parser->parseConstraints($versionConstraint), $parser->parseConstraints('>' . $parser->normalize($currentVersion)))) {
            throw new UnexpectedValueException(__('capell-marketplace::marketplace.errors.unsafe_update_constraint'));
        }
    }
}
