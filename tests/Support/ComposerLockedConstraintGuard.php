<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Offline admission checks; registry freshness needs a separate release audit. */
final class ComposerLockedConstraintGuard
{
    public static function read(string $path): string
    {
        $contents = file_get_contents($path);

        throw_if($contents === false, RuntimeException::class, $path . ' must be readable.');

        return $contents;
    }

    /** @return array<string, string> */
    public static function versions(string $json, string $source): array
    {
        $lock = self::object($json, $source);
        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? null;

            throw_if(! is_array($packages) || ! array_is_list($packages), RuntimeException::class, sprintf('%s %s must be a list.', $source, $section));

            foreach ($packages as $package) {
                throw_if(! is_array($package) || ! is_string($package['name'] ?? null)
                    || ! is_string($package['version'] ?? null), RuntimeException::class, $source . ' packages must contain string names and versions.');

                $versions[$package['name']] = $package['version'];
            }
        }

        throw_if($versions === [], RuntimeException::class, $source . ' contains no locked packages.');

        return $versions;
    }

    /** @return array<string, string> */
    public static function committedVersions(string $repository): array
    {
        // A fetched main ref keeps concurrent sibling edits out of the evidence.
        $process = new Process(['git', '-C', $repository, 'show', 'origin/main:composer.lock']);
        $process->mustRun();

        return self::versions($process->getOutput(), $repository . ' origin/main:composer.lock');
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{manifest: string, section: string, dependency: string, constraint: string}>
     */
    public static function requirements(string $root, array $paths): array
    {
        $requirements = [];

        foreach ($paths as $path) {
            $manifest = self::object(self::read($root . '/' . $path), $path);

            foreach (['require', 'require-dev'] as $section) {
                $entries = $manifest[$section] ?? [];

                throw_unless(is_array($entries), RuntimeException::class, sprintf('%s %s must be an object.', $path, $section));

                foreach ($entries as $dependency => $constraint) {
                    throw_if(! is_string($dependency) || ! is_string($constraint), RuntimeException::class, sprintf('%s %s must map names to constraint strings.', $path, $section));

                    $requirements[] = [
                        'manifest' => $path,
                        'section' => $section,
                        'dependency' => $dependency,
                        'constraint' => $constraint,
                    ];
                }
            }
        }

        return $requirements;
    }

    /**
     * @param  array<string, array<string, string>>  $locks
     * @return array<string, array{version: string, source: string}>
     */
    public static function highest(array $locks): array
    {
        $highest = [];
        $parser = new VersionParser;

        foreach ($locks as $source => $versions) {
            foreach ($versions as $dependency => $version) {
                if (VersionParser::parseStability($version) !== 'stable') {
                    continue;
                }

                if (! isset($highest[$dependency])
                    || version_compare($parser->normalize($version), $parser->normalize($highest[$dependency]['version']), '>')) {
                    $highest[$dependency] = ['version' => $version, 'source' => $source];
                }
            }
        }

        throw_if($highest === [], RuntimeException::class, 'The locks contain no stable versions.');

        return $highest;
    }

    /**
     * @param  list<array{manifest: string, section: string, dependency: string, constraint: string}>  $requirements
     * @param  array<string, array{version: string, source: string}>  $versions
     * @param  array<string, string>  $holds  Audited explanations, never admission exemptions.
     * @return list<string>
     */
    public static function failures(array $requirements, array $versions, array $holds = []): array
    {
        $failures = [];

        foreach ($requirements as $requirement) {
            $known = $versions[$requirement['dependency']] ?? null;
            if ($known === null) {
                continue;
            }

            if (Semver::satisfies($known['version'], $requirement['constraint'])) {
                continue;
            }

            $failure = sprintf(
                '%s %s %s %s excludes %s from %s.',
                $requirement['manifest'],
                $requirement['section'],
                $requirement['dependency'],
                $requirement['constraint'],
                $known['version'],
                $known['source'],
            );

            if (isset($holds[$requirement['dependency']])) {
                $failure .= ' Audited hold in scripts/composer-major-exceptions.json: ' . $holds[$requirement['dependency']];
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    public static function releaseEnvironment(): bool
    {
        if (filter_var(getenv('CAPELL_RELEASE_ENVIRONMENT'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        // An explicitly selected release group must never pass by skipping missing siblings.
        $arguments = $_SERVER['argv'] ?? [];

        foreach ($arguments as $index => $argument) {
            $groups = str_starts_with((string) $argument, '--group=')
                ? substr((string) $argument, strlen('--group='))
                : ($argument === '--group' ? ($arguments[$index + 1] ?? '') : '');

            if (in_array('release-environment', explode(',', $groups), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function object(string $json, string $source): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        throw_if(! is_array($decoded) || array_is_list($decoded), RuntimeException::class, $source . ' must contain a JSON object.');

        $object = [];

        foreach ($decoded as $key => $value) {
            throw_unless(is_string($key), RuntimeException::class, $source . ' must contain string keys.');

            $object[$key] = $value;
        }

        return $object;
    }
}
