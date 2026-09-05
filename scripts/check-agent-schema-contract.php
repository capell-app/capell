#!/usr/bin/env php
<?php

declare(strict_types=1);

use Capell\Core\Enums\PropertyType;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Properties\BuiltInPropertySets;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

/**
 * Validate the database-free Agent Schema artefact and verifier entry points.
 * Runtime completeness remains the job of capell:agent-schema:verify.
 */
$configuredRoot = getenv('CAPELL_AGENT_SCHEMA_ROOT');
$root = is_string($configuredRoot) && trim($configuredRoot) !== '' ? rtrim($configuredRoot, '/') : dirname(__DIR__);
$vocabularyPath = $root . '/packages/core/resources/agent-schema/schemaorg-terms.json';
$errors = [];
$terms = [];

if (! is_file($vocabularyPath)) {
    $errors[] = 'packages/core/resources/agent-schema/schemaorg-terms.json is missing.';
} else {
    $contents = file_get_contents($vocabularyPath);
    try {
        $vocabulary = json_decode($contents === false ? '' : $contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $vocabulary = null;
        $errors[] = 'The bundled schema.org vocabulary is invalid JSON: ' . $exception->getMessage();
    }

    if (is_array($vocabulary)) {
        $candidateTerms = $vocabulary['terms'] ?? null;
        $sortedTerms = is_array($candidateTerms) ? $candidateTerms : [];
        sort($sortedTerms, SORT_STRING);
        if (! is_string($vocabulary['source'] ?? null) || ! str_starts_with($vocabulary['source'], 'https://')) {
            $errors[] = 'The bundled schema.org vocabulary must record an HTTPS source.';
        }
        if (! is_string($vocabulary['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/i', $vocabulary['sha256']) !== 1) {
            $errors[] = 'The bundled schema.org vocabulary must record a SHA-256 digest.';
        }
        if (! is_array($candidateTerms) || ! array_is_list($candidateTerms) || count($candidateTerms) < 1000
            || array_values(array_unique($candidateTerms)) !== $candidateTerms
            || $sortedTerms !== $candidateTerms
            || array_diff(['Product', 'price', 'Event', 'Article'], $candidateTerms) !== []) {
            $errors[] = 'The bundled schema.org vocabulary must contain the complete sorted term list.';
        } else {
            $terms = $candidateTerms;
        }
    }
}

$autoloadPath = $root . '/vendor/autoload.php';
if (! is_file($autoloadPath)) {
    $errors[] = 'vendor/autoload.php is missing; manifest and built-in contract validation cannot run.';
} else {
    require_once $autoloadPath;

    if (! class_exists(ManifestValidator::class)) {
        $errors[] = 'ManifestValidator is unavailable; manifest normalisation cannot run.';
    } else {
        $manifestValidator = new ManifestValidator;

        foreach (glob($root . '/packages/*/capell.json') ?: [] as $manifestPath) {
            $relativeManifestPath = ltrim(str_replace($root, '', $manifestPath), '/');

            try {
                $packageDirectory = dirname($manifestPath);
                $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
                $composerPath = $packageDirectory . '/composer.json';
                $composer = is_file($composerPath)
                    ? json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR)
                    : null;

                $manifestValidator->validate(
                    $manifest,
                    $composer,
                    is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : null,
                    $relativeManifestPath,
                );
            } catch (Throwable $exception) {
                $errors[] = $relativeManifestPath . ' failed manifest validation: ' . $exception->getMessage();
            }
        }
    }

    try {
        // BuiltInPropertySets uses translation helpers for display names. A
        // lightweight translator keeps this source-contract check DB-free.
        if (function_exists('app') && class_exists(Translator::class)) {
            app()->instance('translator', new Translator(
                new ArrayLoader,
                'en',
            ));
        }

        $builtInSets = BuiltInPropertySets::all();
        foreach ($builtInSets as $setKey => $set) {
            if (! is_string($setKey) || ! is_array($set) || ! is_string($set['name'] ?? null)
                || ! is_array($set['definitions'] ?? null) || ! array_is_list($set['definitions'])) {
                $errors[] = 'Built-in property set contract is invalid for ' . (string) $setKey . '.';

                continue;
            }

            foreach ($set['definitions'] as $definition) {
                if (! is_array($definition)
                    || ! is_string($definition['key'] ?? null)
                    || ! $definition['type'] instanceof PropertyType
                    || ! is_string($definition['semantic'] ?? null)
                    || ! str_starts_with($definition['semantic'], 'schema:')
                    || ! in_array(substr($definition['semantic'], 7), $terms, true)) {
                    $errors[] = 'Built-in property set ' . $setKey . ' contains an invalid schema definition.';
                    break;
                }
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'Built-in property set contract could not be evaluated: ' . $exception->getMessage();
    }
}

foreach ([
    'packages/core/src/Actions/Properties/VerifyAgentSchemaAction.php',
    'packages/core/src/Console/Commands/AgentSchemaVerifyCommand.php',
    'packages/core/docs/agent-schema.md',
] as $relativePath) {
    if (! is_file($root . '/' . $relativePath)) {
        $errors[] = $relativePath . ' is missing.';
    }
}

$command = (string) @file_get_contents($root . '/packages/core/src/Console/Commands/AgentSchemaVerifyCommand.php');
if (! str_contains($command, 'capell:agent-schema:verify')) {
    $errors[] = 'AgentSchemaVerifyCommand no longer exposes capell:agent-schema:verify.';
}

if ($errors !== []) {
    fwrite(STDERR, "Agent Schema static contract is out of sync.\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, "Agent Schema static contract is aligned; runtime verification remains database-backed.\n");
