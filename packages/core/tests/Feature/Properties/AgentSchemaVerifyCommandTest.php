<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\SyncBuiltInPropertySetsAction;
use Capell\Core\Actions\Properties\VerifyAgentSchemaAction;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;

it('verifies the installed built-in vocabulary without modifying definitions', function (): void {
    SyncBuiltInPropertySetsAction::run();
    $before = PropertyDefinition::query()->get()->toArray();

    $report = VerifyAgentSchemaAction::run();

    expect($report->passed())->toBeTrue()
        ->and(PropertyDefinition::query()->get()->toArray())->toBe($before);
    artisanCommand('capell:agent-schema:verify', ['--json' => true])->assertSuccessful();
});

it('fails verification for an unknown semantic and names the invalid term', function (): void {
    SyncBuiltInPropertySetsAction::run();
    PropertyDefinition::query()->where('key', 'sku')->update(['semantic' => 'schema:NotARealProperty']);

    $report = VerifyAgentSchemaAction::run();

    expect($report->passed())->toBeFalse()
        ->and(collect($report->failures)->where('check', 'semantic')->first()['problem'])
        ->toContain('schema:NotARealProperty');
    artisanCommand('capell:agent-schema:verify')->assertFailed();
});

it('reports uninstalled property-set owners without deleting their definitions', function (): void {
    SyncBuiltInPropertySetsAction::run();
    $set = PropertySet::factory()->create(['key' => 'orphan.example', 'owner_package' => 'example/removed']);
    $definition = PropertyDefinition::factory()->create(['property_set_id' => $set->id]);

    $report = VerifyAgentSchemaAction::run();

    expect(collect($report->failures)->where('check', 'owner')->first()['subject'])->toBe('orphan.example')
        ->and($definition->fresh())->not->toBeNull();
});

it('reports built-in drift rather than silently resynchronising it', function (): void {
    SyncBuiltInPropertySetsAction::run();
    $definition = PropertyDefinition::query()->where('key', 'sku')->firstOrFail();
    $definition->delete();

    $report = VerifyAgentSchemaAction::run();

    expect($report->passed())->toBeFalse()
        ->and(collect($report->failures)->where('check', 'builtin')->first()['subject'])->toBe('commerce.product.sku')
        ->and(PropertyDefinition::query()->find($definition->id))->toBeNull();
});

it('rejects invalid site options instead of checking a different site', function (): void {
    artisanCommand('capell:agent-schema:verify', ['--site' => 'not-a-site'])->assertExitCode(2);
});
