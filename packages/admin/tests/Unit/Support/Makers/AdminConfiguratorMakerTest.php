<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Support\Makers\AdminConfiguratorMaker;
use Capell\Admin\Support\Makers\ConfiguratorSourceResolver;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Filesystem\Filesystem;

it('resolves a generated configurator source when no existing configurators are registered', function (): void {
    $resolver = new ConfiguratorSourceResolver;

    expect($resolver->candidates('UnregisteredType'))->toBe([[
        'key' => 'default',
        'class' => null,
        'path' => null,
        'sourcePackage' => 'generated',
    ]])
        ->and($resolver->resolve('UnregisteredType', 'missing')['key'])->toBe('default');
});

it('resolves registered configurator source candidates by key', function (): void {
    CapellAdmin::shouldReceive('getConfigurators')
        ->with('pages')
        ->andReturn(['default' => DefaultPageConfigurator::class]);

    $resolver = new ConfiguratorSourceResolver;

    $candidate = $resolver->resolve('pages', 'default');

    expect($candidate['key'])->toBe('Default')
        ->and($candidate['class'])->toBe(DefaultPageConfigurator::class)
        ->and($candidate['path'])->toBe(new ReflectionClass(DefaultPageConfigurator::class)->getFileName())
        ->and($candidate['sourcePackage'])->toBe('package');
});

it('resolves the explicit blank configurator source without copying an existing class', function (): void {
    CapellAdmin::shouldReceive('getConfigurators')
        ->with('pages')
        ->andReturn(['default' => DefaultPageConfigurator::class]);

    $candidate = (new ConfiguratorSourceResolver)->resolve('pages', ConfiguratorSourceResolver::BLANK_SOURCE_KEY);

    expect($candidate)->toBe([
        'key' => 'blank',
        'class' => null,
        'path' => null,
        'sourcePackage' => 'generated',
    ]);
});

it('previews a host app configurator from the generic stub', function (): void {
    $preview = new AdminConfiguratorMaker(new ConfiguratorSourceResolver)->preview(new MakerInputData(
        maker: 'admin.configurator',
        values: [
            'type' => 'pages',
            'name' => 'hero',
        ],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect($preview->maker)->toBe('admin.configurator')
        ->and($preview->files)->toHaveCount(1)
        ->and($file->path)->toEndWith('/app/Filament/Configurators/Pages/HeroConfigurator.php')
        ->and($file->contents)->toContain('namespace App\\Filament\\Configurators\\Pages;')
        ->and($file->contents)->toContain('class HeroConfigurator')
        ->and(firstDataItem($preview->commands))->toBe('php artisan capell:make admin.configurator --type=Pages --name=HeroConfigurator')
        ->and(firstDataItem($preview->notes))->toContain('capell:admin-cache-configurators');
});

it('writes a created configurator through the filesystem service without touching real files', function (): void {
    $targetPath = app_path('Filament/Configurators/Widgets/HeroConfigurator.php');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->with($targetPath)->andReturnFalse();
    $filesystem->shouldReceive('ensureDirectoryExists')->once()->with(dirname($targetPath))->andReturnNull();
    $filesystem->shouldReceive('put')
        ->once()
        ->with($targetPath, Mockery::on(fn (string $contents): bool => str_contains($contents, 'class HeroConfigurator')))
        ->andReturn(1024);

    app()->instance(Filesystem::class, $filesystem);

    $result = new AdminConfiguratorMaker(new ConfiguratorSourceResolver)->run(new MakerInputData(
        maker: 'admin.configurator',
        values: [
            'type' => 'widgets',
            'name' => 'hero',
            'source' => ConfiguratorSourceResolver::BLANK_SOURCE_KEY,
        ],
        dryRun: false,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($result->files));

    expect($result->successful)->toBeTrue()
        ->and($file->path)->toBe($targetPath)
        ->and($file->operation)->toBe('create')
        ->and($file->exists)->toBeTrue();
});
