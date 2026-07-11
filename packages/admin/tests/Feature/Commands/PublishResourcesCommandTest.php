<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Illuminate\Support\Facades\File;

afterEach(fn () => Mockery::close());

it('publishes resources interactively and by option', function (): void {
    $resourcePath = app_path('Filament/Resources/Pages');
    $testFile = $resourcePath . '/PageResource.php';

    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(PageResource::class, group: 'Page', name: 'PageResource'),
    );

    File::shouldReceive('exists')
        ->andReturn(false);

    File::shouldReceive('ensureDirectoryExists')
        ->andReturn(true);

    File::shouldReceive('put')
        ->withArgs(fn (string $path, mixed $content): bool => is_string($content) && str_contains($content, 'namespace App\\Filament\\Resources'))
        ->andReturn(true);

    artisanCommand('capell:admin-publish-resources', [
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    artisanCommand('capell:admin-publish-resources', [
        '--type' => 'Page',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    artisanCommand('capell:admin-publish-resources', [
        '--resource' => 'PageResource',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

it('returns failure for invalid resource', function (): void {
    File::shouldReceive('exists')->zeroOrMoreTimes()->andReturn(false);
    File::shouldReceive('ensureDirectoryExists')->never();
    File::shouldReceive('put')->never();

    artisanCommand('capell:admin-publish-resources', [
        '--resource' => 'NotAResource',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(1);
});

it('returns success but does nothing for invalid type', function (): void {
    File::shouldReceive('exists')->zeroOrMoreTimes()->andReturn(false);
    File::shouldReceive('ensureDirectoryExists')->never();
    File::shouldReceive('put')->never();

    artisanCommand('capell:admin-publish-resources', [
        '--type' => 'NotAType',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

it('reports skipped resources unless force overwrite is requested', function (): void {
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(PageResource::class, group: 'Page', name: 'PageResource'),
    );

    File::shouldReceive('exists')
        ->andReturn(true);

    File::shouldReceive('ensureDirectoryExists')
        ->zeroOrMoreTimes()
        ->andReturn(true);

    File::shouldReceive('put')->never();

    artisanCommand('capell:admin-publish-resources', [
        '--resource' => 'PageResource',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Skipped 1 file')
        ->expectsOutputToContain('Use --force to overwrite skipped files.')
        ->assertExitCode(0);
});

it('reports failed writes when the destination cannot be written', function (): void {
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(PageResource::class, group: 'Page', name: 'PageResource'),
    );

    File::shouldReceive('exists')
        ->andReturn(false);

    File::shouldReceive('ensureDirectoryExists')
        ->zeroOrMoreTimes()
        ->andReturn(true);

    File::shouldReceive('put')
        ->once()
        ->andReturn(false);

    artisanCommand('capell:admin-publish-resources', [
        '--resource' => PageResource::class,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Failed to publish resource')
        ->assertExitCode(0);
});
