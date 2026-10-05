<?php

declare(strict_types=1);

use Capell\Admin\Tests\Support\ResponsiveVisibilityGuard;
use Illuminate\Support\Facades\File;

it('keeps package admin views and PHP class producers free of bare hidden with breakpoint display utilities', function (): void {
    // Unlayered vendor CSS can force the bare hidden class to stay hidden at every width.
    expect(ResponsiveVisibilityGuard::scan(dirname(__DIR__, 4)))->toBe([]);
});

it('detects conflicting responsive visibility across display types and breakpoints', function (string $classes): void {
    expect(ResponsiveVisibilityGuard::hasConflict($classes))->toBeTrue();
})->with([
    'hidden sm:block',
    'md:flex hidden',
    "hidden\nlg:inline",
    'hidden xl:inline-flex',
    'hidden 2xl:grid',
    'hidden sm:inline-grid',
    'hidden md:inline-block',
    'hidden lg:table-cell',
    'hidden xl:table-row',
    'hidden 2xl:table-header-group',
    'hidden sm:inline-table',
    'hidden md:contents',
    'hidden lg:flow-root',
    'hidden xl:list-item',
]);

it('detects the conflict in Blade attributes class arrays and PHP rendered classes', function (string $source): void {
    expect(ResponsiveVisibilityGuard::violations($source))->not->toBeEmpty();
})->with([
    '<div class="hidden lg:block"></div>',
    "<div\n class=\"hidden\n md:flex\"></div>",
    '<x-dropdown :class="$sidebar ? \'flex\' : \'hidden lg:flex\'" />',
    "@class(['hidden' => ! \$sidebar, 'lg:flex' => ! \$sidebar])",
    '<?php $classes = "hidden xl:inline-flex";',
    "<?php \$attributes = ['class' => 'hidden 2xl:table-cell'];",
    "<?php \$attributes = ['class' => ['hidden', 'lg:flex']];",
]);

it('allows variant visibility and unrelated hidden tokens', function (string $source): void {
    expect(ResponsiveVisibilityGuard::violations($source))->toBe([]);
})->with([
    '<div class="block max-lg:hidden"></div>',
    '<div class="flex max-lg:hidden"></div>',
    '<div class="flex lg:hidden"></div>',
    '<div class="overflow-hidden lg:block"></div>',
    '<div class="hidden lg:flex-col"></div>',
    '<div class="hidden"></div><div class="lg:block"></div>',
    '<input type="hidden" class="lg:block">',
]);

it('discovers nested admin views and PHP producers across packages while excluding public frontend views', function (): void {
    $root = sys_get_temp_dir() . '/responsive-visibility-' . bin2hex(random_bytes(8));
    $files = [
        'packages/admin/resources/views/nested/probe.blade.php' => '<div class="hidden lg:block"></div>',
        'packages/marketplace/src/Filament/Probe.php' => '<?php $classes = "hidden md:flex";',
        'packages/frontend/resources/views/filament/probe.blade.php' => '<div class="hidden sm:inline"></div>',
        'packages/frontend/resources/views/components/probe.blade.php' => '<div class="hidden md:block"></div>',
    ];

    try {
        foreach ($files as $path => $source) {
            File::ensureDirectoryExists(dirname($root . '/' . $path));
            File::put($root . '/' . $path, $source);
        }

        $violations = ResponsiveVisibilityGuard::scan($root);

        expect($violations)->toHaveCount(3)
            ->and(implode("\n", $violations))
            ->toContain('packages/admin/resources/views/nested/probe.blade.php')
            ->toContain('packages/marketplace/src/Filament/Probe.php')
            ->toContain('packages/frontend/resources/views/filament/probe.blade.php')
            ->not->toContain('packages/frontend/resources/views/components/probe.blade.php');
    } finally {
        File::deleteDirectory($root);
    }
});
