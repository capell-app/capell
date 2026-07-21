#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function capellStableApiSnapshot(string $root): array
{
    $catalog = json_decode((string) file_get_contents($root . '/docs/packages/extension-surface-catalog.json'), true, flags: JSON_THROW_ON_ERROR);
    $coreComposer = json_decode((string) file_get_contents($root . '/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $surfaces = [];
    $configKeys = [];

    foreach ($catalog['surfaces'] as $surface) {
        if (($surface['stability'] ?? null) !== 'stable') {
            continue;
        }

        $identifier = (string) $surface['identifier'];
        $surfaces[$surface['id']] = [
            'identifier' => $identifier,
            'signature' => capellStableApiSignature($identifier),
            'contractTestId' => $surface['contractTestId'],
        ];

        if (($surface['kind'] ?? null) === 'config') {
            $configKeys[] = $identifier;
        }
    }

    ksort($surfaces);
    sort($configKeys);
    $migrations = array_map('basename', glob($root . '/packages/core/database/migrations/*.php') ?: []);
    sort($migrations);

    return [
        'surfaces' => $surfaces,
        'manifestRequirements' => ['manifest-version', 'name', 'version', 'capellApiVersion', 'surfaces', 'providers'],
        'packageConstraints' => $coreComposer['require'] ?? [],
        'migrations' => $migrations,
        'configKeys' => $configKeys,
    ];
}

function capellStableApiSignature(string $identifier): string
{
    if (! class_exists($identifier) && ! interface_exists($identifier)) {
        return hash('sha256', $identifier);
    }

    $reflection = new ReflectionClass($identifier);
    $methods = [];
    $classFile = $reflection->getFileName();
    $isAction = str_contains($identifier, '\\Actions\\');
    $hasDependencyInjectionConstructor = $isAction || str_ends_with($reflection->getShortName(), 'Registry');

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getFileName() !== $classFile
            || ($isAction && $method->getName() !== 'handle')
            || ($hasDependencyInjectionConstructor && $method->isConstructor())) {
            continue;
        }

        $parameters = array_map(static fn (ReflectionParameter $parameter): string => sprintf(
            '%s%s:%s',
            $parameter->isOptional() ? '?' : '',
            $parameter->getName(),
            capellStableApiType($parameter->getType(), $method->getDeclaringClass()),
        ), $method->getParameters());
        $methods[] = $method->getName() . '(' . implode(',', $parameters) . '):' . capellStableApiType($method->getReturnType(), $method->getDeclaringClass());
    }

    sort($methods);

    return hash('sha256', implode('|', $methods));
}

function capellStableApiType(?ReflectionType $type, ReflectionClass $declaringClass): string
{
    if ($type === null) {
        return '';
    }

    $rendered = (string) $type;
    $parentClass = $declaringClass->getParentClass();
    $replacements = [
        'self' => $declaringClass->getName(),
        'parent' => $parentClass === false ? 'parent' : $parentClass->getName(),
    ];

    return preg_replace_callback(
        '/(?<![A-Za-z0-9_\\\\])(self|parent)(?![A-Za-z0-9_\\\\])/',
        static fn (array $matches): string => $replacements[$matches[1]],
        $rendered,
    ) ?? $rendered;
}

/**
 * @param  array<string, mixed>  $baseline
 * @param  array<string, mixed>  $current
 * @return list<string>
 */
function capellStableApiDrift(array $baseline, array $current): array
{
    $drift = [];

    $baselineSurfaces = is_array($baseline['surfaces'] ?? null) ? $baseline['surfaces'] : [];
    $currentSurfaces = is_array($current['surfaces'] ?? null) ? $current['surfaces'] : [];

    foreach (array_diff(array_keys($baselineSurfaces), array_keys($currentSurfaces)) as $removedId) {
        $drift[] = "removed class: {$removedId}";
    }

    foreach (array_intersect(array_keys($baselineSurfaces), array_keys($currentSurfaces)) as $id) {
        $baselineSurface = is_array($baselineSurfaces[$id] ?? null) ? $baselineSurfaces[$id] : [];
        $currentSurface = is_array($currentSurfaces[$id] ?? null) ? $currentSurfaces[$id] : [];

        if (($baselineSurface['signature'] ?? null) !== ($currentSurface['signature'] ?? null)) {
            $drift[] = "changed public signature: {$id}";
        }
    }

    foreach (['manifestRequirements', 'packageConstraints', 'migrations', 'configKeys'] as $section) {
        if (($baseline[$section] ?? null) !== ($current[$section] ?? null)) {
            $drift[] = $section;
        }
    }

    return $drift;
}

/**
 * @param  list<string>  $arguments
 */
function capellStableApiMain(array $arguments): void
{
    $root = dirname(__DIR__);
    $baselinePath = $root . '/docs/packages/stable-extension-api-baseline.json';
    $current = capellStableApiSnapshot($root);

    if (! in_array('--check', $arguments, true)) {
        file_put_contents($baselinePath, json_encode([
            'schemaVersion' => 1,
            'status' => 'active',
            ...$current,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        fwrite(STDOUT, 'Active stable extension API baseline generated.' . PHP_EOL);

        return;
    }

    $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
    $drift = capellStableApiDrift($baseline, $current);

    if ($drift === []) {
        fwrite(STDOUT, 'Stable extension API baseline is current.' . PHP_EOL);

        return;
    }

    $decisionPath = $root . '/docs/packages/stable-extension-api-decision.json';

    if (! is_file($decisionPath)) {
        throw new RuntimeException('Active stable API drift requires docs/packages/stable-extension-api-decision.json.');
    }

    fwrite(STDOUT, 'Stable API drift has an explicit compatibility decision: ' . implode(', ', $drift) . PHP_EOL);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];

    capellStableApiMain(is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : []);
}
