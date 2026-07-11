<?php

declare(strict_types=1);

use Capell\Core\Support\Makers\MakerSafety;

it('allows php writes in local environments by default', function (): void {
    app()->instance('env', 'local');
    config()->set('capell.diagnostics.php_writes', 'local_only');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeTrue();
});

it('blocks php writes in production by default', function (): void {
    app()->instance('env', 'production');
    config()->set('capell.diagnostics.php_writes', 'local_only');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeFalse();
});

it('allows production writes only with explicit config override', function (): void {
    app()->instance('env', 'production');
    config()->set('capell.diagnostics.php_writes', 'enabled');
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->current()->phpWritesAllowed)->toBeTrue();
});

it('rejects paths outside allowed roots', function (): void {
    config()->set('capell.diagnostics.allowed_roots', [app_path('Filament/Schemas')]);

    expect(resolve(MakerSafety::class)->pathIsAllowed(base_path('.env')))->toBeFalse();
    expect(resolve(MakerSafety::class)->pathIsAllowed(app_path('Filament/Schemas/Pages/HeroSchema.php')))->toBeTrue();
});
