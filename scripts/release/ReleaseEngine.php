<?php

declare(strict_types=1);

/*
 * SHARED-CODE LOCKSTEP NOTICE
 *
 * This engine is the foundation (stable lockstep) half of a deliberately
 * duplicated pair. Its sibling is the companion (per-package maturity) engine
 * at capell-packages-4/scripts/release/ReleaseEngine.php.
 *
 * SHARED — byte-for-byte identical in both repos. Every shared region is
 * fenced by `// LOCKSTEP-BEGIN <name>` / `// LOCKSTEP-END <name>` markers, and
 * the fences are mechanically enforced by
 * capell-packages-4/tests/Feature/PublicReleaseVersionContractTest.php
 * ("every declared lockstep region is byte-identical across the core and
 * companion engines"). The regions are:
 *   command-runner              CommandRunner, ProcessCommandRunner,
 *                               ReleaseException, DependencyGraph
 *   maturity-vocabulary         PlanValidator::assertMaturity()
 *   declared-maturity           PlanValidator::assertDeclaredMaturity()
 *   manifest-and-version-rules  PlanValidator::validateManifest(),
 *                               isCanonicalVersion(), isLegacyConstraint()
 *   resume-decision             ResumeDecision
 *   publish                     ReleaseEngine::publish()
 *   verify                      ReleaseEngine::verify()
 *   bump                        ReleaseEngine::bump()
 *   release-definitions         ReleaseEngine::releaseDefinitions()
 *   push-command                ReleaseEngine::pushCommand()
 *   engine-helpers              assertExactSource(), required(),
 *                               sanitiseError(), optional(), writeState(),
 *                               assertCleanSource(), git()
 * (releaseDefinitions() and pushCommand() are fenced separately rather than
 * folded into engine-helpers because pint's ordered_class_elements sorts them
 * into opposite orders in the two repos; regions are matched by name, not
 * position, so their order may differ freely.)
 * A fix to any of the above must be applied identically in the sibling repo,
 * inside the same named region, or the test fails naming the drifted region.
 *
 * NOT SHARED — these intentionally diverge; do NOT sync them:
 *   PlanValidator::validate()   This repo adds two foundation-lockstep checks
 *                               the sibling must not have: every lockstep
 *                               package selected in inventory order, and one
 *                               lockstep version assigned to every package.
 *                               Copying the sibling's validate() over this one
 *                               would delete that enforcement.
 *   ReleaseEngine::plan()       This repo plans a single stable lockstep
 *                               version for every foundation package; the
 *                               sibling plans per-package versions with
 *                               beta/promote maturity bumps.
 *   ReleaseEngine::packageEntry()
 *                               This repo takes no $maturity parameter and
 *                               hardcodes stable; the sibling takes one.
 * This repo additionally has highestBumpType() and highestLedgerVersion(),
 * which have no counterpart in the sibling.
 */

namespace Capell\Release;

use RuntimeException;

// LOCKSTEP-BEGIN command-runner
interface CommandRunner
{
    /** @return array{output:string,exitCode:int,error?:string} */
    public function run(array $command, ?string $workingDirectory = null): array;
}

final class ProcessCommandRunner implements CommandRunner
{
    public function run(array $command, ?string $workingDirectory = null): array
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'capell-release-output-');
        $errorPath = tempnam(sys_get_temp_dir(), 'capell-release-error-');
        throw_if($outputPath === false || $errorPath === false, RuntimeException::class, 'Unable to allocate command output files.');

        try {
            $descriptor = [1 => ['file', $outputPath, 'w'], 2 => ['file', $errorPath, 'w']];
            $process = proc_open(array_map(strval(...), $command), $descriptor, $pipes, $workingDirectory);
            throw_unless(is_resource($process), RuntimeException::class, 'Unable to start command.');

            $exitCode = proc_close($process);
            $output = file_get_contents($outputPath);
            $error = file_get_contents($errorPath);

            return ['output' => trim((string) $output), 'exitCode' => $exitCode, 'error' => trim((string) $error)];
        } finally {
            @unlink($outputPath);
            @unlink($errorPath);
        }
    }
}

final class ReleaseException extends RuntimeException {}

final class DependencyGraph
{
    /** @param array<string,list<string>> $dependencies @return list<string> */
    public static function order(array $dependencies): array
    {
        $result = [];
        $visiting = [];
        $visited = [];
        $visit = function (string $package) use (&$visit, &$result, &$visiting, &$visited, $dependencies): void {
            throw_if(isset($visiting[$package]), ReleaseException::class, sprintf('Dependency cycle includes %s.', $package));

            if (isset($visited[$package])) {
                return;
            }

            $visiting[$package] = true;
            foreach ($dependencies[$package] ?? [] as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$package]);
            $visited[$package] = true;
            $result[] = $package;
        };
        foreach (array_keys($dependencies) as $package) {
            $visit($package);
        }

        return $result;
    }
}

// LOCKSTEP-END command-runner

final class PlanValidator
{
    // LOCKSTEP-BEGIN maturity-vocabulary
    public static function assertMaturity(mixed $maturity, string $context): string
    {
        throw_if($maturity === 'labs', ReleaseException::class, $context . ' declares maturity labs, which is not yet supported.');

        throw_unless(in_array($maturity, ['stable', 'beta'], true), ReleaseException::class, $context . ' must declare maturity stable or beta.');

        return $maturity;
    }

    // LOCKSTEP-END maturity-vocabulary

    // LOCKSTEP-BEGIN declared-maturity
    /**
     * The maturity declared in config/release-packages.json is authoritative: a
     * plan may never publish a package at a maturity its definition does not
     * declare. Without this, a plan predating a declaration flip publishes
     * immutable tags at the wrong stability and only fails downstream, once the
     * tags already exist.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<array-key,mixed>  $definitions
     */
    public static function assertDeclaredMaturity(array $plan, array $definitions): void
    {
        $declared = [];
        foreach ($definitions as $definition) {
            $declared[$definition['name']] = self::assertMaturity(
                $definition['maturity'] ?? 'stable',
                'Release package definition ' . $definition['name'],
            );
        }

        throw_if(array_column($plan['ledger'], 'name') !== array_keys($declared), ReleaseException::class, 'Plan ledger must exactly match declared release package inventory.');

        foreach ($plan['ledger'] as $entry) {
            $expected = $declared[$entry['name']];
            if ($entry['maturity'] === $expected) {
                continue;
            }

            $remedy = $expected === 'beta' ? 'beta' : 'promote';
            throw new ReleaseException(sprintf('Package %s declares maturity %s in config/release-packages.json but the plan resolves %s. Re-plan with --bump=%s=%s, or align the declaration.', $entry['name'], $expected, $entry['maturity'], $entry['name'], $remedy));
        }
    }

    // LOCKSTEP-END declared-maturity

    // LOCKSTEP-BEGIN version-transition
    /** @param array<string,mixed> $package */
    public static function assertVersionTransition(array $package): void
    {
        $type = $package['release_type'];
        if (! in_array($type, ['baseline', 'patch', 'minor', 'major', 'beta', 'promote'], true)) {
            throw new ReleaseException(sprintf('Invalid release transition for %s.', $package['name']));
        }

        if ($type === 'baseline') {
            $expected = $package['maturity'] === 'beta' ? '1.0.0-beta.1' : '1.0.0';
            if ($package['current_version'] !== null || $package['proposed_version'] !== $expected) {
                throw new ReleaseException(sprintf('Invalid release transition for %s.', $package['name']));
            }

            return;
        }

        if (! is_string($package['current_version'])) {
            throw new ReleaseException(sprintf('Invalid release transition for %s.', $package['name']));
        }

        $expected = ReleaseEngine::bump($package['current_version'], $type);
        $expectedMaturity = str_contains($expected, '-beta.') ? 'beta' : 'stable';
        if ($package['proposed_version'] !== $expected || $package['maturity'] !== $expectedMaturity) {
            throw new ReleaseException(sprintf('Invalid release transition for %s.', $package['name']));
        }
    }

    // LOCKSTEP-END version-transition

    /**
     * DIVERGES from the sibling companion engine by design: this method adds
     * two lockstep-only checks the sibling must not have. Do not sync it. See
     * the notice above.
     *
     * @param  array<string,mixed>  $plan
     */
    public function validate(array $plan): void
    {
        foreach (['schema_version', 'source', 'inventory', 'ledger', 'packages', 'dependency_order'] as $field) {
            throw_unless(array_key_exists($field, $plan), ReleaseException::class, sprintf('Plan is missing %s.', $field));
        }

        throw_if(! is_int($plan['schema_version']) || $plan['schema_version'] !== 1 || ! is_array($plan['source']) || ! is_array($plan['packages']) || ! is_array($plan['dependency_order']), ReleaseException::class, 'Plan schema types are invalid.');

        throw_unless(preg_match('/^[a-f0-9]{40}$/', $plan['source']['commit'] ?? ''), ReleaseException::class, 'Source commit must be an exact SHA.');

        $names = array_column($plan['packages'], 'name');
        $inventoryNames = array_column($plan['inventory'], 'name');
        $ledgerNames = array_column($plan['ledger'], 'name');
        $externalLedger = $plan['external_ledger'] ?? [];
        throw_if(! is_array($externalLedger) || array_intersect($ledgerNames, array_column($externalLedger, 'name')) !== [], ReleaseException::class, 'External ledger must be disjoint from local inventory.');

        $combinedLedger = [...$externalLedger, ...$plan['ledger']];
        $combinedNames = array_column($combinedLedger, 'name');
        throw_if($inventoryNames !== $ledgerNames || count(array_unique($ledgerNames)) !== count($ledgerNames), ReleaseException::class, 'Plan ledger must exactly cover inventory in stable order.');

        foreach ($combinedLedger as $entry) {
            foreach (['name', 'path', 'repository', 'version', 'previous_version', 'source_commit', 'subtree_hash', 'direct_capell_dependencies', 'resolved_minimum_versions', 'maturity'] as $field) {
                throw_unless(array_key_exists($field, $entry), ReleaseException::class, sprintf('Ledger entry is missing %s.', $field));
            }

            $maturity = self::assertMaturity($entry['maturity'], 'Ledger entry ' . $entry['name']);
            if (! preg_match('/^[a-f0-9]{40}$/', $entry['source_commit']) || ! preg_match('/^[a-f0-9]{40}$/', $entry['subtree_hash']) || ! self::isCanonicalVersion($entry['version'], $maturity)) {
                throw new ReleaseException(sprintf('Ledger entry %s is invalid.', $entry['name']));
            }
        }

        foreach ($plan['inventory'] as $index => $inventory) {
            $ledger = $plan['ledger'][$index];
            foreach (['name', 'path', 'repository', 'version'] as $field) {
                throw_if(($inventory[$field] ?? null) !== $ledger[$field], ReleaseException::class, sprintf('Inventory and ledger disagree on %s.', $field));
            }
        }

        $ledgerGraph = [];
        foreach ($combinedLedger as $entry) {
            $dependencies = $entry['direct_capell_dependencies'];
            if (count($dependencies) !== count(array_unique($dependencies)) || array_diff($dependencies, $combinedNames) !== [] || in_array($entry['name'], $dependencies, true)) {
                throw new ReleaseException(sprintf('Ledger entry %s has an unknown, duplicate, or self dependency.', $entry['name']));
            }

            if (array_diff(array_keys($entry['resolved_minimum_versions']), $dependencies) !== [] || array_diff($dependencies, array_keys($entry['resolved_minimum_versions'])) !== []) {
                throw new ReleaseException(sprintf('Ledger entry %s must resolve every direct dependency exactly once.', $entry['name']));
            }

            foreach ($entry['resolved_minimum_versions'] as $dependency => $minimum) {
                $referenced = current(array_filter($combinedLedger, fn (array $candidate): bool => $candidate['name'] === $dependency));
                if ($referenced === false || $minimum !== $referenced['version']) {
                    throw new ReleaseException(sprintf('Ledger entry %s has an incompatible minimum for %s.', $entry['name'], $dependency));
                }

                if (($entry['maturity'] ?? 'stable') === 'stable' && ($referenced['maturity'] ?? 'stable') === 'beta') {
                    throw new ReleaseException(sprintf('Stable package %s may not depend on beta package %s.', $entry['name'], $dependency));
                }
            }

            $ledgerGraph[$entry['name']] = $dependencies;
        }

        DependencyGraph::order($ledgerGraph);
        throw_if($names === [] || count(array_unique($names)) !== count($names), ReleaseException::class, 'Plan packages must be non-empty and unique.');

        $graph = [];
        $sourceTags = [];
        foreach ($plan['packages'] as $package) {
            foreach (['name', 'path', 'split_repository', 'current_version', 'proposed_version', 'source_commit', 'source_tag', 'subtree_hash', 'direct_capell_dependencies', 'resolved_minimum_versions', 'reason', 'release_type', 'publication_state', 'tag_sha', 'maturity'] as $field) {
                if (! array_key_exists($field, $package)) {
                    throw new ReleaseException(sprintf('Package %s is missing %s.', $package['name'], $field));
                }
            }

            $packageMaturity = self::assertMaturity($package['maturity'], 'Package ' . $package['name']);
            if (! self::isCanonicalVersion($package['proposed_version'], $packageMaturity)) {
                throw new ReleaseException(sprintf('Invalid proposed version for %s.', $package['name']));
            }

            $expectedSourceTag = basename($package['path']) . '/v' . $package['proposed_version'];
            if ($package['source_tag'] !== $expectedSourceTag || in_array($package['source_tag'], $sourceTags, true)) {
                throw new ReleaseException(sprintf('Invalid or duplicate source tag for %s.', $package['name']));
            }

            $sourceTags[] = $package['source_tag'];
            if (($package['current_version'] === null && $package['release_type'] !== 'baseline') || ($package['current_version'] !== null && ! self::isCanonicalVersion($package['current_version'], 'any'))) {
                throw new ReleaseException(sprintf('Invalid current version for %s.', $package['name']));
            }

            self::assertVersionTransition($package);
            $ledger = current(array_filter($plan['ledger'], fn (array $entry): bool => $entry['name'] === $package['name']));
            if ($ledger === false) {
                throw new ReleaseException(sprintf('Selected package %s is absent from ledger.', $package['name']));
            }

            $matches = $package['path'] === $ledger['path'] && $package['split_repository'] === $ledger['repository']
                && $package['proposed_version'] === $ledger['version'] && $package['current_version'] === $ledger['previous_version']
                && $package['source_commit'] === $ledger['source_commit'] && $package['subtree_hash'] === $ledger['subtree_hash']
                && $package['direct_capell_dependencies'] === $ledger['direct_capell_dependencies']
                && $package['resolved_minimum_versions'] === $ledger['resolved_minimum_versions']
                && $package['maturity'] === $ledger['maturity'];
            if (! $matches) {
                throw new ReleaseException(sprintf('Selected package %s disagrees with ledger.', $package['name']));
            }

            $graph[$package['name']] = array_values(array_intersect($package['direct_capell_dependencies'], $names));
            if (($package['source_commit'] ?? null) !== $plan['source']['commit'] || ! preg_match('/^[a-f0-9]{40}$/', $package['subtree_hash'] ?? '')) {
                throw new ReleaseException(sprintf('Package %s has a drifting source or invalid tree hash.', $package['name']));
            }

            foreach ($package['direct_capell_dependencies'] as $dependency) {
                if (! in_array($dependency, $combinedNames, true) || $dependency === $package['name']) {
                    throw new ReleaseException(sprintf('Package %s has an unknown dependency.', $package['name']));
                }
            }

            foreach ($package['resolved_minimum_versions'] as $dependency => $version) {
                $dependencyPackage = current(array_filter($plan['packages'], fn (array $candidate): bool => $candidate['name'] === $dependency));
                $ledgerPackage = current(array_filter($combinedLedger, fn (array $candidate): bool => $candidate['name'] === $dependency));
                $expected = $dependencyPackage === false ? ($ledgerPackage['version'] ?? null) : $dependencyPackage['proposed_version'];
                if (! in_array($dependency, $package['direct_capell_dependencies'], true) || $expected === null || $version !== $expected) {
                    throw new ReleaseException(sprintf('Incompatible planned requirement for %s.', $package['name']));
                }
            }

            if (array_keys($package['resolved_minimum_versions']) !== $package['direct_capell_dependencies']) {
                throw new ReleaseException(sprintf('Package %s must resolve every direct dependency exactly once.', $package['name']));
            }
        }

        throw_if($names !== $inventoryNames, ReleaseException::class, 'A foundation release must select every lockstep package in inventory order.');

        throw_if(count(array_unique(array_column($plan['packages'], 'proposed_version'))) !== 1, ReleaseException::class, 'A foundation release must assign one lockstep version to every package.');

        throw_if(DependencyGraph::order($graph) !== $plan['dependency_order'], ReleaseException::class, 'Dependency order is invalid.');
    }

    // LOCKSTEP-BEGIN manifest-and-version-rules
    /** @param array<string,mixed> $manifest */
    public function validateManifest(array $manifest): void
    {
        throw_if(array_key_exists('version', $manifest), ReleaseException::class, 'Composer manifests must not declare self.version.');

        foreach (($manifest['require'] ?? []) as $name => $constraint) {
            throw_if(str_starts_with((string) $name, 'capell-app/') && $this->isLegacyConstraint((string) $constraint), ReleaseException::class, sprintf('Legacy production constraint %s:%s.', $name, $constraint));
        }
    }

    private static function isCanonicalVersion(mixed $version, string $maturity = 'stable'): bool
    {
        if (! is_string($version)) {
            return false;
        }

        return match ($maturity) {
            'beta' => (bool) preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)-beta\.[1-9]\d*$/', $version),
            'any' => self::isCanonicalVersion($version, 'stable') || self::isCanonicalVersion($version, 'beta'),
            default => (bool) preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/', $version),
        };
    }

    private function isLegacyConstraint(string $constraint): bool
    {
        if (preg_match('/(?:\*|\bdev-|\.x-dev|\b0\.0(?:\.|\b))/i', $constraint)) {
            return true;
        }

        preg_match_all('/(?<![0-9.])(\d+)(?:\.\d+)?/', $constraint, $matches);
        foreach ($matches[1] as $major) {
            if ((int) $major < 1 || ((int) $major < 5 && (int) $major !== 1 && ! str_contains($constraint, '<' . $major))) {
                return true;
            }
        }

        return (bool) preg_match('/(?:\^|~|>=?|=)?\s*[234](?:\D|$)/', $constraint);
    }

    // LOCKSTEP-END manifest-and-version-rules
}

// LOCKSTEP-BEGIN resume-decision
final class ResumeDecision
{
    public static function forTag(?string $existingSha, string $expectedSha): string
    {
        if ($existingSha === null) {
            return 'publish';
        }

        if (hash_equals($expectedSha, $existingSha)) {
            return 'resume';
        }

        throw new ReleaseException('Existing immutable tag does not match the planned split SHA.');
    }
}

// LOCKSTEP-END resume-decision

final class ReleaseEngine
{
    public function __construct(private readonly string $root, private readonly CommandRunner $runner = new ProcessCommandRunner) {}

    // LOCKSTEP-BEGIN bump
    public static function bump(string $version, string $type): string
    {
        $isBeta = (bool) preg_match('/^(\d+)\.(\d+)\.(\d+)-beta\.([1-9]\d*)$/', $version, $beta);
        if ($type === 'promote') {
            throw_unless($isBeta, ReleaseException::class, sprintf('Cannot promote stable version %s.', $version));

            return sprintf('%s.%s.%s', $beta[1], $beta[2], $beta[3]);
        }

        if ($type === 'beta' && $isBeta) {
            return sprintf('%s.%s.%s-beta.', $beta[1], $beta[2], $beta[3]) . ((int) $beta[4] + 1);
        }

        throw_if($isBeta, ReleaseException::class, sprintf('Cannot %s bump prerelease version %s; promote the beta first.', $type, $version));

        [$major,$minor,$patch] = array_map(intval(...), explode('.', $version));

        return match ($type) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'beta' => $major . '.' . ($minor + 1) . '.0-beta.1',
            default => sprintf('%d.%d.', $major, $minor) . ($patch + 1),
        };
    }

    // LOCKSTEP-END bump

    /**
     * DIVERGES from the sibling companion engine by design. Do not sync.
     *
     * @return array<string,mixed>
     */
    public function plan(string $version, ?array $previous = null, array $bumps = [], array $externalLedger = []): array
    {
        throw_if($previous === null && $version !== '1.0.0', ReleaseException::class, 'The baseline version must be exactly 1.0.0.');

        $this->assertCleanSource();
        $commit = $this->git(['rev-parse', 'HEAD']);
        $definitions = json_decode((string) file_get_contents($this->root . '/config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = [];
        $candidates = [];
        $graph = [];
        $knownNames = array_column($definitions, 'name');
        $dependencyNames = [...$knownNames, ...array_column($externalLedger, 'name')];
        foreach ($bumps as $name => $type) {
            throw_if(in_array($type, ['beta', 'promote'], true), ReleaseException::class, 'Foundation packages release in stable lockstep.');

            throw_if(! in_array($name, $knownNames, true) || ! in_array($type, ['patch', 'minor', 'major'], true), ReleaseException::class, sprintf('Invalid or unknown bump %s=%s.', $name, $type));
        }

        foreach ($definitions as $definition) {
            $manifest = json_decode((string) file_get_contents($this->root . '/' . $definition['path'] . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            (new PlanValidator)->validateManifest($manifest);
            $dependencies = array_values(array_filter(array_keys($manifest['require'] ?? []), fn (string $name): bool => str_starts_with($name, 'capell-app/')));
            foreach ($dependencies as $dependency) {
                throw_unless(in_array($dependency, $dependencyNames, true), ReleaseException::class, sprintf('Unknown inventory dependency %s.', $dependency));

                if (in_array($dependency, $knownNames, true) && ($manifest['require'][$dependency] ?? null) !== 'self.version') {
                    throw new ReleaseException(sprintf('Lockstep foundation dependency %s->%s must use self.version.', $definition['name'], $dependency));
                }
            }

            $graph[$definition['name']] = $dependencies;
            $old = $previous === null ? null : current(array_filter($previous['ledger'], fn (array $item): bool => $item['name'] === $definition['name']));
            $tree = $this->git(['rev-parse', sprintf('%s:%s', $commit, $definition['path'])]);
            $candidates[$definition['name']] = ['definition' => $definition, 'dependencies' => $dependencies, 'old' => $old, 'tree' => $tree];
        }

        $hasChanges = $previous === null || $bumps !== [] || array_filter(
            $candidates,
            fn (array $candidate): bool => ! is_array($candidate['old']) || $candidate['old']['subtree_hash'] !== $candidate['tree'],
        ) !== [];

        if ($hasChanges) {
            $type = $previous === null ? 'baseline' : $this->highestBumpType($bumps);
            $proposed = $previous === null
                ? '1.0.0'
                : self::bump($this->highestLedgerVersion($previous['ledger']), $type);
            $reason = $previous === null
                ? 'Initial lockstep foundation release.'
                : sprintf('Lockstep %s foundation release.', $type);

            foreach ($candidates as $candidate) {
                $packages[] = $this->packageEntry($candidate['definition'], $candidate['dependencies'], $candidate['old'], $candidate['tree'], $commit, $proposed, $reason, $type);
            }
        }

        foreach ($packages as &$package) {
            foreach ($package['direct_capell_dependencies'] as $dependency) {
                $planned = current(array_filter($packages, fn (array $item): bool => $item['name'] === $dependency));
                $priorLedger = $previous === null ? $externalLedger : [...$externalLedger, ...$previous['ledger']];
                $prior = current(array_filter($priorLedger, fn (array $item): bool => $item['name'] === $dependency));
                $package['resolved_minimum_versions'][$dependency] = $planned['proposed_version'] ?? $prior['version'];
            }
        }

        unset($package);
        $selectedGraph = array_intersect_key($graph, array_flip(array_column($packages, 'name')));
        foreach ($selectedGraph as &$deps) {
            $deps = array_values(array_intersect($deps, array_keys($selectedGraph)));
        } unset($deps);
        $inventory = array_map(function (array $definition) use ($packages, $previous): array {
            $planned = current(array_filter($packages, fn (array $item): bool => $item['name'] === $definition['name']));
            $prior = current(array_filter($previous['ledger'] ?? [], fn (array $item): bool => $item['name'] === $definition['name']));

            return ['name' => $definition['name'], 'path' => $definition['path'], 'repository' => $definition['repository'], 'version' => $planned['proposed_version'] ?? $prior['version'] ?? $prior['proposed_version'] ?? null];
        }, $definitions);
        $ledger = array_map(function (array $definition) use ($packages, $previous, $candidates): array {
            $planned = current(array_filter($packages, fn (array $item): bool => $item['name'] === $definition['name']));
            $prior = $previous === null ? false : current(array_filter($previous['ledger'], fn (array $item): bool => $item['name'] === $definition['name']));
            $candidate = $candidates[$definition['name']];

            return ['name' => $definition['name'], 'path' => $definition['path'], 'repository' => $definition['repository'], 'version' => $planned['proposed_version'] ?? $prior['version'], 'previous_version' => $planned['current_version'] ?? $prior['previous_version'] ?? null, 'source_commit' => $planned['source_commit'] ?? $prior['source_commit'] ?? $previous['source']['commit'] ?? null, 'subtree_hash' => $candidate['tree'], 'direct_capell_dependencies' => $candidate['dependencies'], 'resolved_minimum_versions' => $planned['resolved_minimum_versions'] ?? $prior['resolved_minimum_versions'], 'maturity' => 'stable'];
        }, $definitions);
        $plan = ['schema_version' => 1, 'source' => ['repository' => $this->git(['config', '--get', 'remote.origin.url']), 'commit' => $commit, 'ref' => 'refs/commits/' . $commit], 'inventory' => $inventory, 'external_ledger' => $externalLedger, 'ledger' => $ledger, 'packages' => $packages, 'dependency_order' => DependencyGraph::order($selectedGraph)];
        (new PlanValidator)->validate($plan);

        return $plan;
    }

    // LOCKSTEP-BEGIN publish
    /** @param array<string,mixed> $plan */
    public function publish(array $plan, string $planPath): void
    {
        (new PlanValidator)->validate($plan);
        $planHash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $statePath = $planPath . '.state.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : ['plan_sha256' => $planHash, 'source_commit' => $plan['source']['commit'], 'packages' => []];
        throw_if(($state['plan_sha256'] ?? null) !== $planHash || ($state['source_commit'] ?? null) !== $plan['source']['commit'], ReleaseException::class, 'Existing release state belongs to a different plan or source commit.');

        $this->assertExactSource($plan);
        $preflightScript = null;
        if (($state['preflight']['plan_sha256'] ?? null) !== $planHash) {
            $preflightScript = getenv('RELEASE_PREFLIGHT_SCRIPT');
            throw_if(! is_string($preflightScript) || $preflightScript === '' || ! is_file($preflightScript), ReleaseException::class, 'RELEASE_PREFLIGHT_SCRIPT must name a repository-owned preflight script.');
        }

        $releases = [];
        foreach ($plan['dependency_order'] as $name) {
            $package = current(array_filter($plan['packages'], fn (array $item): bool => $item['name'] === $name));
            $tag = 'v' . $package['proposed_version'];
            $repository = $package['split_repository'];
            $main = $this->optional(['gh', 'api', sprintf('repos/%s/git/ref/heads/main', $repository), '--jq', '.object.sha']);
            $record = $state['packages'][$name] ?? null;
            $recordedSplit = is_array($record) ? ($record['split_sha'] ?? null) : null;
            $recordedState = is_array($record) ? ($record['state'] ?? null) : null;
            $resumeRecordedMain = in_array($recordedState, ['main_pushed', 'published'], true);

            if ($resumeRecordedMain) {
                throw_if(! is_string($recordedSplit) || preg_match('/^[a-f0-9]{40}$/', $recordedSplit) !== 1 || ($record['tag'] ?? null) !== $tag, ReleaseException::class, sprintf('Recorded main push state for %s is incomplete.', $name));

                throw_if(! is_string($main) || ! hash_equals($recordedSplit, $main), ReleaseException::class, sprintf('Remote main drift after recorded push for %s.', $name));

                $this->required(['git', 'fetch', '--no-tags', sprintf('https://github.com/%s.git', $repository), 'refs/heads/main'], $this->root);
                $splitSha = $recordedSplit;
            } elseif (is_string($main) && preg_match('/^[a-f0-9]{40}$/', $main)) {
                $this->required(['git', 'fetch', '--no-tags', sprintf('https://github.com/%s.git', $repository), 'refs/heads/main'], $this->root);
                $parent = $this->git(['rev-parse', 'FETCH_HEAD']);
                $parentTree = $this->git(['rev-parse', $parent . '^{tree}']);
                $splitSha = hash_equals($package['subtree_hash'], $parentTree)
                    ? $parent
                    : $this->git(['commit-tree', $package['subtree_hash'], '-p', $parent, '-m', 'Release ' . $tag]);
            } else {
                $splitSha = $this->git(['subtree', 'split', '--prefix=' . $package['path'], $plan['source']['commit']]);
            }

            $splitTree = $this->git(['rev-parse', $splitSha . '^{tree}']);
            throw_unless(hash_equals($package['subtree_hash'], $splitTree), ReleaseException::class, sprintf('Split tree mismatch for %s.', $name));

            $existing = $this->optional(['gh', 'api', sprintf('repos/%s/git/ref/tags/%s', $repository, $tag), '--jq', '.object.sha']);
            $peeled = $existing === null ? null : $this->optional(['gh', 'api', sprintf('repos/%s/git/tags/%s', $repository, $existing), '--jq', '.object.sha']) ?? $existing;
            $decision = ResumeDecision::forTag($peeled, $splitSha);
            $sourceTag = $package['source_tag'];
            $localSourceTagSha = $this->optional(['git', 'rev-parse', '-q', '--verify', 'refs/tags/' . $sourceTag . '^{commit}']);
            $localSourceTagSha = $localSourceTagSha === '' ? null : $localSourceTagSha;
            $sourceLine = $this->optional(['git', 'ls-remote', '--tags', 'origin', 'refs/tags/' . $sourceTag]);
            $sourceTagSha = $sourceLine === null || $sourceLine === '' ? null : strtok($sourceLine, "\t ");
            throw_if(($localSourceTagSha !== null && $localSourceTagSha !== $plan['source']['commit']) || ($sourceTagSha !== null && $sourceTagSha !== $plan['source']['commit']), ReleaseException::class, sprintf('Existing source tag %s does not match the planned source commit.', $sourceTag));

            if ($sourceTagSha !== null) {
                $record = $state['packages'][$name] ?? null;
                throw_if(($state['preflight']['state'] ?? null) !== 'passed' || ($state['preflight']['plan_sha256'] ?? null) !== $planHash || ($record['source_tag_sha'] ?? null) !== $sourceTagSha, ReleaseException::class, sprintf("Existing source tag %s is not backed by this plan's passed preflight state.", $sourceTag));
            }

            if ($decision === 'resume') {
                $record = $state['packages'][$name] ?? null;
                throw_if(($state['preflight']['state'] ?? null) !== 'passed' || ($state['preflight']['plan_sha256'] ?? null) !== $planHash
                    || ($record['split_sha'] ?? null) !== $splitSha || ($record['tag'] ?? null) !== $tag, ReleaseException::class, sprintf("Existing matching tag for %s is not backed by this plan's passed preflight state.", $name));
            }

            $maturity = PlanValidator::assertMaturity($package['maturity'], 'Package ' . $name);
            $releases[] = ['name' => $name, 'repository' => $repository, 'tag' => $tag, 'splitSha' => $splitSha, 'decision' => $decision, 'sourceTag' => $sourceTag, 'sourceTagSha' => $sourceTagSha, 'localSourceTagSha' => $localSourceTagSha, 'maturity' => $maturity];
        }

        foreach ($releases as $release) {
            ['name' => $name,'repository' => $repository,'tag' => $tag,'splitSha' => $splitSha] = $release;
            $token = getenv('GH_TOKEN');
            throw_if(! is_string($token) || $token === '', ReleaseException::class, 'GH_TOKEN is required.');

            $main = $this->optional(['gh', 'api', sprintf('repos/%s/git/ref/heads/main', $repository), '--jq', '.object.sha']);
            if ($main !== $splitSha) {
                $lease = is_string($main) && preg_match('/^[a-f0-9]{40}$/', $main) ? $main : null;
                $this->required($this->pushCommand($repository, $splitSha . ':refs/heads/main', $lease), $this->root);
            }

            $state['packages'][$name] = array_merge($state['packages'][$name] ?? [], ['state' => 'main_pushed', 'split_sha' => $splitSha, 'tag' => $tag]);
            $this->writeState($planPath, $state);
        }

        if (($state['preflight']['plan_sha256'] ?? null) !== $planHash) {
            $this->required([PHP_BINARY, $preflightScript, $planPath, $statePath], $this->root);
            $state['preflight'] = ['state' => 'passed', 'plan_sha256' => $planHash];
            $this->writeState($planPath, $state);
        }

        foreach ($releases as $release) {
            ['name' => $name,'repository' => $repository,'tag' => $tag,'splitSha' => $splitSha,'decision' => $decision,'sourceTag' => $sourceTag,'sourceTagSha' => $sourceTagSha,'localSourceTagSha' => $localSourceTagSha,'maturity' => $maturity] = $release;
            if ($sourceTagSha === null) {
                if ($localSourceTagSha === null) {
                    $this->required(['git', 'tag', $sourceTag, $plan['source']['commit']], $this->root);
                }

                $this->required(['git', 'push', 'origin', 'refs/tags/' . $sourceTag . ':refs/tags/' . $sourceTag], $this->root);
            }

            if ($decision === 'publish') {
                $this->required($this->pushCommand($repository, sprintf('%s:refs/tags/%s', $splitSha, $tag)), $this->root);
            }

            if ($this->optional(['gh', 'release', 'view', $tag, '--repo', $repository, '--json', 'tagName']) === null) {
                $this->required(['gh', 'release', 'create', $tag, '--repo', $repository, '--verify-tag', '--generate-notes', ...($maturity === 'beta' ? ['--prerelease'] : [])]);
            }

            $tagSha = $this->required(['gh', 'api', sprintf('repos/%s/git/ref/tags/%s', $repository, $tag), '--jq', '.object.sha']);
            $state['packages'][$name] = ['state' => 'published', 'split_sha' => $splitSha, 'tag' => $tag, 'tag_sha' => $tagSha, 'source_tag' => $sourceTag, 'source_tag_sha' => $plan['source']['commit'], 'maturity' => $maturity];
            $this->writeState($planPath, $state);
        }
    }

    // LOCKSTEP-END publish

    // LOCKSTEP-BEGIN verify
    public function verify(array $plan, string $planPath): void
    {
        (new PlanValidator)->validate($plan);
        $this->assertExactSource($plan);
        $statePath = $planPath . '.state.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : [];
        foreach ($plan['packages'] as $package) {
            $tag = 'v' . $package['proposed_version'];
            $repository = $package['split_repository'];
            $tagSha = $this->required(['gh', 'api', sprintf('repos/%s/git/ref/tags/%s', $repository, $tag), '--jq', '.object.sha']);
            if (($state['packages'][$package['name']]['tag_sha'] ?? null) !== $tagSha) {
                throw new ReleaseException(sprintf('Remote tag drift for %s.', $package['name']));
            }

            $isPrerelease = $this->required(['gh', 'release', 'view', $tag, '--repo', $repository, '--json', 'isPrerelease', '--jq', '.isPrerelease']);
            $expectedPrerelease = $package['maturity'] === 'beta' ? 'true' : 'false';
            if ($isPrerelease !== $expectedPrerelease) {
                throw new ReleaseException(sprintf('Remote release stability drift for %s.', $package['name']));
            }

            $main = $this->required(['gh', 'api', sprintf('repos/%s/git/ref/heads/main', $repository), '--jq', '.object.sha']);
            if ($main !== $state['packages'][$package['name']]['split_sha']) {
                throw new ReleaseException(sprintf('Remote main drift for %s.', $package['name']));
            }

            $sourceLine = $this->required(['git', 'ls-remote', '--tags', 'origin', 'refs/tags/' . $package['source_tag']]);
            if (! str_starts_with($sourceLine, $plan['source']['commit'])) {
                throw new ReleaseException(sprintf('Source tag drift for %s.', $package['name']));
            }
        }
    }

    // LOCKSTEP-END verify

    /** DIVERGES from the sibling companion engine by design. Do not sync. */
    private function packageEntry(array $definition, array $dependencies, array|false|null $old, string $tree, string $commit, string $proposed, string $reason, string $type): array
    {
        return ['name' => $definition['name'], 'path' => $definition['path'], 'split_repository' => $definition['repository'], 'current_version' => $old['version'] ?? null, 'proposed_version' => $proposed, 'source_commit' => $commit, 'source_ref' => 'refs/commits/' . $commit, 'source_tag' => basename($definition['path']) . '/v' . $proposed, 'subtree_hash' => $tree, 'direct_capell_dependencies' => $dependencies, 'resolved_minimum_versions' => [], 'reason' => $reason, 'release_type' => $type, 'publication_state' => 'pending', 'tag_sha' => null, 'maturity' => 'stable'];
    }

    /** This repo only; no counterpart in the sibling companion engine.
     *
     * @param  array<string, string>  $bumps
     */
    private function highestBumpType(array $bumps): string
    {
        $weights = ['patch' => 1, 'minor' => 2, 'major' => 3];
        $types = array_values($bumps);
        usort($types, fn (string $left, string $right): int => $weights[$right] <=> $weights[$left]);

        return $types[0] ?? 'patch';
    }

    /** @param list<array<string, mixed>> $ledger */
    private function highestLedgerVersion(array $ledger): string
    {
        $versions = array_column($ledger, 'version');
        usort($versions, fn (string $left, string $right): int => version_compare($right, $left));

        return $versions[0];
    }

    // LOCKSTEP-BEGIN push-command
    private function pushCommand(string $repository, string $refspec, ?string $lease = null): array
    {
        return ['git', 'push', ...($lease === null ? [] : ['--force-with-lease=refs/heads/main:' . $lease]), sprintf('https://github.com/%s.git', $repository), $refspec];
    }

    // LOCKSTEP-END release-definitions

    // LOCKSTEP-BEGIN engine-helpers
    private function assertExactSource(array $plan): void
    {
        $this->assertCleanSource();
        throw_if($this->git(['rev-parse', 'HEAD']) !== $plan['source']['commit'], ReleaseException::class, 'Current checkout does not match planned source commit.');

        foreach ($plan['packages'] as $package) {
            if ($this->git(['rev-parse', $plan['source']['commit'] . ':' . $package['path']]) !== $package['subtree_hash']) {
                throw new ReleaseException(sprintf('Source tree drift for %s.', $package['name']));
            }
        }
    }

    private function required(array $command, ?string $cwd = null): string
    {
        $result = $this->runner->run($command, $cwd);
        if ($result['exitCode'] !== 0) {
            $error = $command[0] === 'git' ? $this->sanitiseError((string) ($result['error'] ?? '')) : '';
            $description = (string) ($command[0] ?? 'unknown');
            throw new ReleaseException('Command failed: ' . $description . ($error === '' ? '' : ': ' . $error));
        }

        return $result['output'];
    }

    private function sanitiseError(string $error): string
    {
        $token = getenv('GH_TOKEN');
        if (is_string($token) && $token !== '') {
            $error = str_replace($token, '[redacted]', $error);
        }

        return trim((string) preg_replace('/(?:authorization:\s*)?bearer\s+\S+/i', '[redacted]', $error));
    }

    private function optional(array $command): ?string
    {
        $result = $this->runner->run($command, $this->root);

        return $result['exitCode'] === 0 ? $result['output'] : null;
    }

    private function writeState(string $planPath, array $state): void
    {
        $path = $planPath . '.state.json';
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        throw_if($handle === false, ReleaseException::class, 'Unable to create release state file.');

        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        fflush($handle);
        if (function_exists('fsync')) {
            fsync($handle);
        }

        fclose($handle);
        rename($temporary, $path);
    }

    private function assertCleanSource(): void
    {
        throw_if($this->git(['status', '--porcelain']) !== '', ReleaseException::class, 'Refusing to plan from a dirty source tree.');
    }

    private function git(array $arguments): string
    {
        $result = $this->runner->run(['git', ...$arguments], $this->root);
        if ($result['exitCode'] !== 0) {
            throw new ReleaseException('Git command failed: ' . ($result['error'] ?? ''));
        }

        return $result['output'];
    }

    // LOCKSTEP-END engine-helpers
}
