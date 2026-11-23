<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Support\MarketplaceComposerCoordinates;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use UnexpectedValueException;

final class AssertMarketplaceUpdateConstraintAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceInstalledPackageVersionResolver $installedVersions) {}

    public static function assertStrictConstraint(string $constraint): void
    {
        if (! config('capell.extensions.verification.strict', true)) {
            return;
        }

        $unsafe = trim($constraint) === ''
            || preg_match('/\*|@dev|\bdev-|\-dev\b|\b(?:x|X)\b|\s+as\s+/i', $constraint) === 1;

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            // Require a bounded numeric constraint in every alternative. This
            // deliberately rejects unfamiliar branch syntax rather than guessing.
            if (preg_match('/(?:\^|~|<=?|(?<![\w.])=?)(?:v?\d)/i', $alternative) !== 1
                || (preg_match('/(?:\^|~|<=?)\s*v?\d|^\s*v?\d+(?:\.\d+)*(?:-[0-9A-Za-z.]+)?\s*$|\s+-\s+v?\d/i', $alternative) !== 1)) {
                $unsafe = true;
            }
        }

        if ($unsafe) {
            throw ValidationException::withMessages([
                'version_constraint' => __('capell-marketplace::marketplace.errors.strict_update_constraint'),
            ]);
        }
    }

    /**
     * An unattended update installs the version that was approved and nothing
     * else. A caret or range lets Composer resolve a later release than the one
     * whose digest the Marketplace advertised, and nobody is there to notice.
     *
     * Not skippable by the `verification.strict` setting: that setting relaxes
     * how bounded an operator-supervised constraint must be, never whether an
     * unattended one is pinned.
     *
     * @param  string|null  $approvedVersion  When given, the constraint must pin this version.
     */
    public static function assertExactVersion(string $constraint, ?string $approvedVersion = null): void
    {
        $parser = new VersionParser;

        try {
            $parsed = $parser->parseConstraints($constraint);
            $pinned = $parsed instanceof Constraint
                && $parsed->getOperator() === '=='
                && VersionParser::parseStability($constraint) !== 'dev'
                && ! str_starts_with($constraint, 'dev-');
            $matchesApproved = $approvedVersion === null
                || (string) $parsed === (string) $parser->parseConstraints($approvedVersion);
        } catch (UnexpectedValueException) {
            $pinned = false;
            $matchesApproved = false;
        }

        if (! $pinned || ! $matchesApproved) {
            throw ValidationException::withMessages([
                'version_constraint' => __('capell-marketplace::marketplace.errors.unattended_update_not_pinned'),
            ]);
        }
    }

    /** Call while holding the release's Composer operation lock. */
    public function handle(string $composerName, string $versionConstraint): void
    {
        self::assertStrictConstraint($versionConstraint);
        $currentVersion = $this->installedVersions->prettyVersion($composerName);

        if ($currentVersion === null || $currentVersion === '') {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.not_installed', ['package' => $composerName]),
            ]);
        }

        MarketplaceComposerCoordinates::assertUpgrade($composerName, $versionConstraint, $currentVersion);
    }
}
