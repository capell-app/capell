<?php

declare(strict_types=1);

$rootPath = getenv('CAPELL_COMPOSER_PATH_CHECK_ROOT') ?: dirname(__DIR__);
$failures = [
    ...findComposerJsonFailures($rootPath . '/composer.json'),
    ...(shouldCheckComposerLock($rootPath) ? findComposerLockFailures($rootPath . '/composer.lock') : []),
];

if ($failures === []) {
    echo "Composer public files do not contain local path repositories.\n";

    exit(0);
}

fwrite(STDERR, "Local Composer path references are not allowed in composer.json or composer.lock.\n");
fwrite(STDERR, "Use composer.local.json and composer.local.lock for local path overlays.\n\n");

foreach ($failures as $failure) {
    fwrite(STDERR, "- {$failure}\n");
}

exit(1);

/**
 * @return list<string>
 */
function findComposerJsonFailures(string $composerFile): array
{
    if (! file_exists($composerFile)) {
        return [];
    }

    /**
     * @var array{
     *     repositories?: array<int, array<string, mixed>>,
     *     autoload?: array<string, mixed>,
     *     autoload-dev?: array<string, mixed>
     * } $composer
     */
    $composer = readJsonFile($composerFile);
    $failures = [];

    foreach (($composer['repositories'] ?? []) as $repositoryIndex => $repository) {
        $repositoryUrl = $repository['url'] ?? null;

        if (($repository['type'] ?? null) === 'path' && ! isAllowedAppPackagePath($repositoryUrl)) {
            $failures[] = "composer.json repositories[{$repositoryIndex}] uses type \"path\".";
        }

        if (is_string($repositoryUrl) && isDisallowedLocalPathReference($repositoryUrl)) {
            $failures[] = "composer.json repositories[{$repositoryIndex}] uses local URL \"{$repositoryUrl}\".";
        }
    }

    foreach (['autoload', 'autoload-dev'] as $autoloadSection) {
        $psr4Paths = $composer[$autoloadSection]['psr-4'] ?? [];

        if (! is_array($psr4Paths)) {
            continue;
        }

        foreach ($psr4Paths as $namespace => $paths) {
            foreach (normalizeComposerPaths($paths) as $path) {
                if (isLocalPathReferenceOutsideProject($path)) {
                    $failures[] = "composer.json {$autoloadSection}.psr-4[{$namespace}] uses local path \"{$path}\".";
                }
            }
        }
    }

    return $failures;
}

/**
 * @return list<string>
 */
function findComposerLockFailures(string $composerLockFile): array
{
    if (! file_exists($composerLockFile)) {
        return [];
    }

    /**
     * @var array{
     *     packages?: array<int, array<string, mixed>>,
     *     packages-dev?: array<int, array<string, mixed>>
     * } $lock
     */
    $lock = readJsonFile($composerLockFile);
    $failures = [];

    foreach (['packages', 'packages-dev'] as $packageGroup) {
        foreach (($lock[$packageGroup] ?? []) as $packageIndex => $package) {
            $packageName = is_string($package['name'] ?? null) ? $package['name'] : "{$packageGroup}[{$packageIndex}]";

            foreach (['source', 'dist'] as $referenceType) {
                $reference = $package[$referenceType] ?? null;

                if (! is_array($reference)) {
                    continue;
                }

                $referenceUrl = $reference['url'] ?? null;

                if (($reference['type'] ?? null) === 'path' && ! isAllowedAppPackagePath($referenceUrl)) {
                    $failures[] = "composer.lock {$packageName} {$referenceType} uses type \"path\".";
                }

                if (is_string($referenceUrl) && isDisallowedLocalPathReference($referenceUrl)) {
                    $failures[] = "composer.lock {$packageName} {$referenceType} uses local URL \"{$referenceUrl}\".";
                }
            }
        }
    }

    return $failures;
}

/**
 * @return array<string, mixed>
 */
function readJsonFile(string $file): array
{
    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException("Unable to read {$file}.");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Expected {$file} to decode to a JSON object.");
    }

    return $decoded;
}

function isDisallowedLocalPathReference(string $url): bool
{
    if (isAllowedAppPackagePath($url)) {
        return false;
    }

    return str_starts_with($url, './')
        || str_starts_with($url, '../')
        || str_starts_with($url, '/')
        || str_starts_with($url, 'file://');
}

function isAllowedAppPackagePath(mixed $url): bool
{
    return is_string($url)
        && ($url === './packages/*' || str_starts_with($url, './packages/'));
}

/**
 * @return list<string>
 */
function normalizeComposerPaths(mixed $paths): array
{
    if (is_string($paths)) {
        return [$paths];
    }

    if (! is_array($paths)) {
        return [];
    }

    return array_values(array_filter($paths, is_string(...)));
}

function isLocalPathReferenceOutsideProject(string $path): bool
{
    return str_starts_with($path, '../')
        || str_starts_with($path, '/')
        || str_starts_with($path, 'file://');
}

function shouldCheckComposerLock(string $rootPath): bool
{
    if (! file_exists($rootPath . '/composer.lock')) {
        return false;
    }

    $trackedCommand = sprintf(
        'git -C %s ls-files --error-unmatch composer.lock >/dev/null 2>&1',
        escapeshellarg($rootPath),
    );

    if (runCommandSucceeds($trackedCommand)) {
        return true;
    }

    $stagedCommand = sprintf(
        'git -C %s diff --cached --name-only -- composer.lock',
        escapeshellarg($rootPath),
    );

    $stagedFiles = shell_exec($stagedCommand);

    return is_string($stagedFiles) && trim($stagedFiles) !== '';
}

function runCommandSucceeds(string $command): bool
{
    exec($command, result_code: $exitCode);

    return $exitCode === 0;
}
