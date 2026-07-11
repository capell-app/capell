<?php

declare(strict_types=1);

use Capell\Admin\Support\Makers\FilamentWidgetMaker;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturn(false);

    app()->instance(Filesystem::class, $filesystem);
});

it('previews a custom filament widget class', function (): void {
    $preview = resolve(FilamentWidgetMaker::class)->preview(new MakerInputData(
        maker: 'admin.filament-widget',
        values: ['name' => 'Hero Banner'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));

    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe(app_path('Filament/Widgets/HeroBannerWidget.php'));
    expect($file->contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('implements FilamentWidget')
        ->toContain('getWidgetName')
        ->toContain("return 'hero-banner';")
        ->toContain("Block::make('hero-banner')")
        ->toContain("__('capell-admin::widget.hero-banner')")
        ->toContain("__('capell-admin::form.heading')");
});

it('includes widget discovery and cache refresh guidance', function (): void {
    $preview = resolve(FilamentWidgetMaker::class)->preview(new MakerInputData(
        maker: 'admin.filament-widget',
        values: ['name' => 'Callout'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));

    expect(firstDataItem($preview->commands))->toBe('php artisan capell:make admin.filament-widget --name="Callout"')
        ->and($preview->notes->all())->toContain(
            'Host-app widgets are discovered from App\\Filament\\Widgets.',
            'Run php artisan capell:admin-cache-widgets after creating new widgets.',
        );
});
