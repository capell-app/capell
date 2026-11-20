<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionSurface;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestValidator;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

use function PHPUnit\Framework\assertInstanceOf;

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

/** @return array<string, mixed> */
function manifestReferenceSchema(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
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
    $schema = manifestReferenceSchema();

    expect(array_keys($schema['properties']))
        ->toEqualCanonicalizing(ManifestValidator::VALID_ROOT_FIELDS);
});

it('publishes exactly the top-level keys CapellManifestData reads', function (): void {
    $file = new ReflectionClass(CapellManifestData::class)->getFileName();
    expect($file)->toBeString();

    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file));
    $finder = new NodeFinder;
    $factory = $finder->findFirst(
        $nodes ?? [],
        static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === 'fromArray',
    );
    assertInstanceOf(ClassMethod::class, $factory);

    $readKeys = [];
    foreach ($finder->findInstanceOf($factory, ArrayDimFetch::class) as $access) {
        if ($access->var instanceof Variable && $access->var->name === 'data') {
            // A dynamic index needs an explicit inventory of the keys it can read.
            assertInstanceOf(String_::class, $access->dim);
            $readKeys[] = $access->dim->value;
        }
    }

    // $schema is an editor pointer the validator accepts and hydration deliberately ignores.
    $editorOnlyKeys = ['$schema'];

    expect(array_values(array_diff(array_keys(manifestReferenceSchema()['properties']), $editorOnlyKeys)))
        ->toEqualCanonicalizing(array_values(array_unique($readKeys)));
});

it('publishes every surface the manifest validator accepts in the JSON schema', function (): void {
    expect(data_get(manifestReferenceSchema(), 'properties.surfaces.items.enum'))->toEqualCanonicalizing(ExtensionSurface::values());
});
