<?php

declare(strict_types=1);

use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Tests\Unit\Support\Settings\Fixtures\InvalidSchema;
use Capell\Core\Tests\Unit\Support\Settings\Fixtures\MockAdminSchema;
use Capell\Core\Tests\Unit\Support\Settings\Fixtures\MockAdminSchemaExtended;
use Capell\Core\Tests\Unit\Support\Settings\Fixtures\MockAdminSettings;
use Capell\Core\Tests\Unit\Support\Settings\Fixtures\MockAdminSettingsWithSchema;

uses()->group('core', 'settings');

it('registers a schema for a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class);

    expect($registry->hasGroup('admin'))->toBeTrue();
});

it('retrieves all schemas for a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');
    $registry->register('admin', MockAdminSchemaExtended::class, 'extended');

    $schemas = $registry->getSchemas('admin');

    expect($schemas)
        ->toHaveCount(2)
        ->toHaveKey('admin')
        ->toHaveKey('extended');
});

it('gets a specific schema by group and key', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');

    $schema = $registry->getSchema('admin', 'admin');

    expect($schema)->toBe(MockAdminSchema::class);
});

it('returns null for non-existent schema key', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');

    $schema = $registry->getSchema('admin', 'nonexistent');

    expect($schema)->toBeNull();
});

it('replaces a schema in a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');
    $registry->replace('admin', MockAdminSchemaExtended::class, 'admin');

    $schema = $registry->getSchema('admin', 'admin');

    expect($schema)->toBe(MockAdminSchemaExtended::class);
});

it('throws when replacing non-existent schema key', function (): void {
    $registry = new SettingsSchemaRegistry;
    expect(fn () => $registry->replace('admin', MockAdminSchema::class, 'nonexistent'))
        ->toThrow(InvalidArgumentException::class, 'Schema key nonexistent not found in group admin');
});

it('removes a schema from a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');

    expect($registry->hasGroup('admin'))->toBeTrue();

    $registry->remove('admin', 'admin');

    expect($registry->hasGroup('admin'))->toBeFalse();
});

it('removes group when last schema is removed', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');
    $registry->remove('admin', 'admin');

    $groups = $registry->getGroups();

    expect($groups)
        ->toBeArray()
        ->not->toContain('admin');
});

it('registers settings class for a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->registerSettingsClass('admin', MockAdminSettings::class);

    $settingsClass = $registry->getSettingsClass('admin');

    expect($settingsClass)->toBe(MockAdminSettings::class);
});

it('registers settings class schema when exposed by the settings class', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->registerSettingsClass('admin', MockAdminSettingsWithSchema::class);

    expect($registry->getSettingsClass('admin'))->toBe(MockAdminSettingsWithSchema::class)
        ->and($registry->getSchemas('admin'))->toBe([
            'MockAdminSchema' => MockAdminSchema::class,
        ]);
});

it('throws when registering invalid settings class', function (): void {
    $registry = new SettingsSchemaRegistry;
    expect(fn () => $registry->registerSettingsClass('admin', 'NonExistentClass'))
        ->toThrow(InvalidArgumentException::class, 'Settings class NonExistentClass does not exist');
});

it('throws when registering a settings class that does not implement the contract', function (): void {
    $registry = new SettingsSchemaRegistry;

    expect(fn () => $registry->registerSettingsClass('admin', InvalidSchema::class))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

it('throws when registering a settings class under the wrong group', function (): void {
    $registry = new SettingsSchemaRegistry;

    expect(fn () => $registry->registerSettingsClass('core', MockAdminSettings::class))
        ->toThrow(
            InvalidArgumentException::class,
            'Settings class Capell\Core\Tests\Unit\Support\Settings\Fixtures\MockAdminSettings belongs to group admin, cannot register under core',
        );
});

it('throws when registering invalid schema class', function (): void {
    $registry = new SettingsSchemaRegistry;
    expect(fn () => $registry->register('admin', InvalidSchema::class))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

it('removes all schemas from a group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');
    $registry->register('admin', MockAdminSchemaExtended::class, 'extended');
    $registry->registerSettingsClass('admin', MockAdminSettings::class);

    expect($registry->hasGroup('admin'))->toBeTrue();
    expect($registry->getSettingsClass('admin'))->toBe(MockAdminSettings::class);

    $registry->removeGroup('admin');

    expect($registry->hasGroup('admin'))->toBeFalse();
    expect($registry->getSettingsClass('admin'))->toBeNull();
});

it('returns all registered groups', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class);
    $registry->register('core', MockAdminSchema::class);
    $registry->register('frontend', MockAdminSchema::class);

    $groups = $registry->getGroups();

    expect($groups)
        ->toBeArray()
        ->toContain('admin')
        ->toContain('core')
        ->toContain('frontend')
        ->toHaveCount(3);
});

it('returns all schemas across groups', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'admin');
    $registry->register('core', MockAdminSchema::class, 'core');

    $all = $registry->all();

    expect($all)
        ->toBeArray()
        ->toHaveKey('admin')
        ->toHaveKey('core')
        ->and($all['admin'])->toHaveKey('admin')
        ->and($all['core'])->toHaveKey('core');
});

it('uses class basename as default key when not provided', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class);

    $schema = $registry->getSchema('admin', 'MockAdminSchema');

    expect($schema)
        ->toBe(MockAdminSchema::class)
        ->and($registry->getSchemas('admin'))
        ->toHaveKey('MockAdminSchema');
});

it('allows multiple schemas per group', function (): void {
    $registry = new SettingsSchemaRegistry;
    $registry->register('admin', MockAdminSchema::class, 'schema1');
    $registry->register('admin', MockAdminSchemaExtended::class, 'schema2');
    $registry->register('admin', MockAdminSchema::class, 'schema3');

    $schemas = $registry->getSchemas('admin');

    expect($schemas)
        ->toHaveCount(3)
        ->toHaveKey('schema1')
        ->toHaveKey('schema2')
        ->toHaveKey('schema3')
        ->and($schemas['schema1'])->toBe(MockAdminSchema::class)
        ->and($schemas['schema2'])->toBe(MockAdminSchemaExtended::class)
        ->and($schemas['schema3'])->toBe(MockAdminSchema::class);
});
