<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintBlockSchema;
use Capell\Core\Support\BlueprintBlockTypeRegistry;
use Opis\JsonSchema\Validator;

function validatesBlueprintBlockPayload(array $payload, array $schema): bool
{
    $validator = new Validator;
    $dataObject = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    $schemaObject = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

    return $validator->validate($dataObject, $schemaObject)->isValid();
}

it('describes the block payloads registered for a blueprint', function (): void {
    resolve(BlueprintBlockTypeRegistry::class)->register('hero');
    $blueprint = Blueprint::factory()->make(['name' => 'Landing page']);
    $schema = BlueprintBlockSchema::for($blueprint);

    expect($schema['items']['additionalProperties'])->toBeFalse()
        ->and($schema['items']['properties']['type']['enum'])->toBe(['content', 'hero'])
        ->and($schema['items']['properties']['data']['description'])->toContain('schema version 2');
});

it('validates canonical nested content and flat rich content payloads', function (): void {
    $schema = BlueprintBlockSchema::for(Blueprint::factory()->make());

    expect(validatesBlueprintBlockPayload([
        ['type' => 'content', 'data' => ['content' => ['type' => 'doc', 'content' => []]]],
        ['type' => 'content', 'content' => '<p>Hello</p>'],
    ], $schema))->toBeTrue();
});

it('rejects extra envelope keys and unregistered block types', function (): void {
    $schema = BlueprintBlockSchema::for(Blueprint::factory()->make());

    expect(validatesBlueprintBlockPayload([
        ['type' => 'content', 'script' => 'alert(1)'],
    ], $schema))->toBeFalse()
        ->and(validatesBlueprintBlockPayload([
            ['type' => 'not-registered', 'data' => []],
        ], $schema))->toBeFalse();
});
