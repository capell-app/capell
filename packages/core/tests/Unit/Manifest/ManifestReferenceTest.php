<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\ManifestValidator;

/**
 * Authors learn which `capell.json` keys exist from the manifest reference and from editor
 * completion driven by the published schema. A key the validator accepts but neither lists
 * leaves authors guessing; a key the schema lists but the validator rejects breaks a manifest
 * that the editor said was fine.
 *
 * @return list<string>
 */
function manifestReferenceDocumentedKeys(): array
{
    $contents = (string) file_get_contents(dirname(__DIR__, 5) . '/docs/packages/manifest-reference.md');

    preg_match_all('/^\|\s*`([^`]+)`\s*\|/m', $contents, $matches);

    return array_values(array_unique($matches[1]));
}

/** @return list<string> */
function manifestSchemaRootKeys(): array
{
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return array_values(array_filter(
        array_keys($schema['properties']),
        is_string(...),
    ));
}

it('documents every top-level key the manifest validator accepts', function (): void {
    $undocumented = array_values(array_diff(ManifestValidator::VALID_ROOT_FIELDS, manifestReferenceDocumentedKeys()));

    expect($undocumented)->toBe([]);
});

it('documents no top-level key the manifest validator rejects', function (): void {
    $unknown = array_values(array_diff(manifestReferenceDocumentedKeys(), ManifestValidator::VALID_ROOT_FIELDS));

    expect($unknown)->toBe([]);
});

it('publishes exactly the top-level keys the manifest validator accepts in the JSON schema', function (): void {
    $validatorKeys = ManifestValidator::VALID_ROOT_FIELDS;
    $schemaKeys = manifestSchemaRootKeys();

    expect(array_values(array_diff($validatorKeys, $schemaKeys)))->toBe([])
        ->and(array_values(array_diff($schemaKeys, $validatorKeys)))->toBe([]);
});
