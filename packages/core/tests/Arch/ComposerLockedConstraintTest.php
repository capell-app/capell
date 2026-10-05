<?php

declare(strict_types=1);

use Capell\Tests\Support\ComposerLockedConstraintGuard;
use Capell\Tests\Support\SiblingRepositoryLocator;

// Cross-repository evidence runs with --group=release-environment or
// CAPELL_RELEASE_ENVIRONMENT=1; fast suites do not need sibling checkouts.
pest()->group('release-environment');

/** @return list<array{manifest: string, section: string, dependency: string, constraint: string}> */
function coreLockedConstraintRequirements(string $root): array
{
    $paths = ['composer.json'];

    foreach (glob($root . '/packages/*/composer.json') ?: [] as $path) {
        $paths[] = substr($path, strlen($root) + 1);
    }

    return ComposerLockedConstraintGuard::requirements($root, $paths);
}

/** @return array<string, string> */
function coreLockedConstraintHolds(string $root): array
{
    $entries = json_decode(ComposerLockedConstraintGuard::read($root . '/scripts/composer-major-exceptions.json'), true, flags: JSON_THROW_ON_ERROR);

    throw_if(! is_array($entries) || ! array_is_list($entries), RuntimeException::class, 'scripts/composer-major-exceptions.json must contain a list of audited holds.');

    $holds = [];

    foreach ($entries as $entry) {
        throw_if(! is_array($entry) || ! is_string($entry['name'] ?? null)
            || ! is_int($entry['heldMajor'] ?? null) || $entry['heldMajor'] < 0
            || ! is_string($entry['reason'] ?? null) || trim($entry['reason']) === ''
            || ! is_string($entry['owner'] ?? null) || trim($entry['owner']) === '', RuntimeException::class, 'Every audited hold requires a name, major, reason and owner.');

        $holds[$entry['name']] = sprintf('major %d; %s Owner: %s.', $entry['heldMajor'], $entry['reason'], $entry['owner']);
    }

    return $holds;
}

it('admits the root locked version in all six foundation manifests', function (): void {
    $root = dirname(__DIR__, 4);
    $versions = ComposerLockedConstraintGuard::versions(ComposerLockedConstraintGuard::read($root . '/composer.lock'), 'Core composer.lock');
    $known = array_map(static fn (string $version): array => ['version' => $version, 'source' => 'Core composer.lock'], $versions);
    $failures = ComposerLockedConstraintGuard::failures(coreLockedConstraintRequirements($root), $known, coreLockedConstraintHolds($root));

    expect($failures)->toBe([], implode("\n", $failures));
});

it('admits the highest known stable version across Core Packages and App locks', function (): void {
    if (! ComposerLockedConstraintGuard::releaseEnvironment()) {
        $this->markTestSkipped('Cross-repository lock admission requires --group=release-environment or CAPELL_RELEASE_ENVIRONMENT=1.');
    }

    $root = dirname(__DIR__, 4);
    $locks = ['Core composer.lock' => ComposerLockedConstraintGuard::versions(ComposerLockedConstraintGuard::read($root . '/composer.lock'), 'Core composer.lock')];

    foreach (['Packages' => 'capell-packages-4', 'App' => 'capell-app'] as $source => $repository) {
        $path = SiblingRepositoryLocator::path($root, $repository);

        if ($path === null) {
            $message = sprintf('Unable to locate sibling %s checkout. Set ', $source) . ($source === 'Packages' ? 'CAPELL_PACKAGES_REPO_PATH' : 'CAPELL_APPLICATION_ROOT') . ' to evaluate the committed origin/main lock.';

            throw new RuntimeException($message);
        }

        $locks[$source . ' origin/main:composer.lock'] = ComposerLockedConstraintGuard::committedVersions($path);
    }

    $failures = ComposerLockedConstraintGuard::failures(coreLockedConstraintRequirements($root), ComposerLockedConstraintGuard::highest($locks), coreLockedConstraintHolds($root));

    expect($failures)->toBe([], implode("\n", $failures));
});
