<?php

declare(strict_types=1);

$repositoryRoot = getenv('CAPELL_SUPPORT_CONTRACT_ROOT') ?: dirname(__DIR__);
$expectedPhpConstraint = '^8.4';
$expectedLaravelConstraint = '^12.41.1|^13.0';
$errors = [];

foreach (composerManifestPaths($repositoryRoot) as $composerPath) {
    $manifest = readJsonFile($composerPath);
    $packageName = is_string($manifest['name'] ?? null) ? $manifest['name'] : $composerPath;
    $requires = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];

    if (($requires['php'] ?? null) !== $expectedPhpConstraint) {
        $errors[] = sprintf('%s must require php %s.', $packageName, $expectedPhpConstraint);
    }

    $frameworkConstraintFound = false;
    foreach (['laravel/framework', 'illuminate/database', 'illuminate/support'] as $dependency) {
        if (! array_key_exists($dependency, $requires)) {
            continue;
        }

        $frameworkConstraintFound = true;
        if ($requires[$dependency] !== $expectedLaravelConstraint) {
            $errors[] = sprintf('%s must require %s %s.', $packageName, $dependency, $expectedLaravelConstraint);
        }
    }

    if (! $frameworkConstraintFound && ($requires['capell-app/core'] ?? null) !== 'self.version') {
        $errors[] = sprintf('%s must declare the Laravel support contract directly or depend on capell-app/core self.version.', $packageName);
    }
}

$errors = array_merge($errors, documentationSupportContractErrors($repositoryRoot));
$errors = array_merge($errors, dockerSupportContractErrors($repositoryRoot));
$errors = array_merge($errors, workflowSupportContractErrors($repositoryRoot));

if ($errors !== []) {
    fwrite(STDERR, "Capell support contract is out of sync.\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }

    exit(1);
}

fwrite(STDOUT, "Capell support contract is aligned for PHP 8.4+ and Laravel 12.41.1+/13.x.\n");

/**
 * @return list<string>
 */
function composerManifestPaths(string $repositoryRoot): array
{
    $paths = [$repositoryRoot . '/composer.json'];
    $packagePaths = glob($repositoryRoot . '/packages/*/composer.json') ?: [];

    sort($packagePaths);

    return array_values(array_filter([...$paths, ...$packagePaths], is_file(...)));
}

/**
 * @return array<string, mixed>
 */
function readJsonFile(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$path}.\n");

        exit(1);
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        fwrite(STDERR, "{$path} does not contain a JSON object.\n");

        exit(1);
    }

    return $decoded;
}

/**
 * @return list<string>
 */
function documentationSupportContractErrors(string $repositoryRoot): array
{
    $errors = [];

    foreach ([
        'docs/getting-started/install.md',
        'docs/getting-started/quickstart.md',
    ] as $relativePath) {
        $path = $repositoryRoot . '/' . $relativePath;

        if (! is_file($path)) {
            $errors[] = "{$relativePath} is missing.";

            continue;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/\|\s*PHP\s*\|\s*8\.4\+\s*\|/', $contents) !== 1) {
            $errors[] = "{$relativePath} must document PHP 8.4+.";
        }

        if (preg_match('/\|\s*Laravel\s*\|\s*12\.41\.1\+\s+or\s+13\.x\s*\|/', $contents) !== 1) {
            $errors[] = "{$relativePath} must document Laravel 12.41.1+ or 13.x.";
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function dockerSupportContractErrors(string $repositoryRoot): array
{
    $dockerfilePath = $repositoryRoot . '/.docker/Dockerfile';

    if (! is_file($dockerfilePath)) {
        return ['.docker/Dockerfile is missing.'];
    }

    $contents = (string) file_get_contents($dockerfilePath);
    $errors = [];

    if (! str_contains($contents, 'php8.4')) {
        $errors[] = '.docker/Dockerfile must install PHP 8.4 packages.';
    }

    if (! str_contains($contents, '/etc/php/8.4')) {
        $errors[] = '.docker/Dockerfile must configure PHP 8.4.';
    }

    $phpConfigurationPath = $repositoryRoot . '/.docker/php/php.ini';

    if (! is_file($phpConfigurationPath)) {
        $errors[] = '.docker/php/php.ini is missing.';

        return $errors;
    }

    return $errors;
}

/**
 * @return list<string>
 */
function workflowSupportContractErrors(string $repositoryRoot): array
{
    $errors = [];

    foreach ([
        '.github/workflows/test-fast-pr.yml',
        '.github/workflows/test-full.yml',
    ] as $relativePath) {
        $path = $repositoryRoot . '/' . $relativePath;

        if (! is_file($path)) {
            $errors[] = "{$relativePath} is missing.";

            continue;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/php:\s*8\.4/', $contents) !== 1) {
            $errors[] = "{$relativePath} must include PHP 8.4 in its matrix.";
        }

        if (preg_match('/laravel:\s*12\.\*/', $contents) !== 1 || preg_match('/laravel:\s*13\.\*/', $contents) !== 1) {
            $errors[] = "{$relativePath} must include Laravel 12.* and 13.* in its matrix.";
        }
    }

    return $errors;
}
