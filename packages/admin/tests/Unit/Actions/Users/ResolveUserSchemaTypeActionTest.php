<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ResolveUserSchemaTypeAction;

it('resolves the first configured schema type matching the user roles', function (): void {
    config()->set('capell-admin.user_resource.default_schema_type', 'default');
    config()->set('capell-admin.user_resource.role_schema_types', [
        'client' => 'client',
        'editor' => 'editorial',
    ]);

    expect(ResolveUserSchemaTypeAction::run(['editor', 'client']))->toBe('client');
});

it('falls back to the configured default schema type', function (): void {
    config()->set('capell-admin.user_resource.default_schema_type', 'standard');
    config()->set('capell-admin.user_resource.role_schema_types', [
        'client' => 'client',
    ]);

    expect(ResolveUserSchemaTypeAction::run(['subscriber']))->toBe('standard');
});

it('falls back safely when config is malformed', function (): void {
    config()->set('capell-admin.user_resource.default_schema_type', 123);
    config()->set('capell-admin.user_resource.role_schema_types', 'broken');

    expect(ResolveUserSchemaTypeAction::run(['client']))->toBe('default');
});

it('falls back safely when configured role schema entries are malformed', function (): void {
    config()->set('capell-admin.user_resource.default_schema_type', 'standard');
    config()->set('capell-admin.user_resource.role_schema_types', [
        'client' => 123,
        'editor' => 'editorial',
    ]);

    expect(ResolveUserSchemaTypeAction::run(['editor']))->toBe('standard');
});
