<?php

declare(strict_types=1);

use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder;

function makeThemeViewRegistrar(FileViewFinder $viewFinder): ThemeViewRegistrar
{
    return new ThemeViewRegistrar($viewFinder, ['/frontend/views']);
}

it('registers paths under capell namespace, most-specific first', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/specific/views', '/default/views'], 'my-theme');

    $hints = $viewFinder->getHints();
    expect($hints)->toHaveKey('capell');
    // Most-specific path must be first in the hints array
    expect($hints['capell'])->toBe(['/specific/views', '/default/views', '/frontend/views']);
});

it('replaces previously registered paths when the theme changes', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/first/views'], 'first');
    $registrar->register(['/second/views'], 'second');

    expect($viewFinder->getHints()['capell'] ?? [])->toBe(['/second/views', '/frontend/views']);
});

it('second call with same paths is a no-op (duplicate guard)', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/theme/views'], 'default');

    $countAfterFirst = count($viewFinder->getHints()['capell'] ?? []);

    $registrar->register(['/theme/views'], 'default');
    $countAfterSecond = count($viewFinder->getHints()['capell'] ?? []);

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('does not add app-level override path when directory does not exist', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/theme/views'], 'non-existent-test-theme-xyz');

    $hints = $viewFinder->getHints()['capell'] ?? [];
    expect($hints)->not()->toContain(resource_path('themes/non-existent-test-theme-xyz'));
});

it('registers empty paths array without error', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register([], 'default');

    expect($viewFinder->getHints()['capell'] ?? [])->toBe(['/frontend/views']);
});

it('clears stale theme paths by replacing missing theme chains with frontend fallback', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/previous/theme/views'], 'previous');
    $registrar->register([], 'missing');

    expect($viewFinder->getHints()['capell'] ?? [])->toBe(['/frontend/views']);
});

it('flushes octane state back to the frontend fallback namespace', function (): void {
    $filesystem = new Filesystem;
    $viewFinder = new FileViewFinder($filesystem, []);
    $registrar = makeThemeViewRegistrar($viewFinder);

    $registrar->register(['/theme/views'], 'theme');
    $registrar->flushOctaneState();

    expect($viewFinder->getHints()['capell'] ?? [])->toBe(['/frontend/views']);

    $registrar->register(['/theme/views'], 'theme');

    expect($viewFinder->getHints()['capell'] ?? [])->toBe(['/theme/views', '/frontend/views']);
});

it('does not reuse a located view after the active theme changes in one process', function (): void {
    $root = sys_get_temp_dir() . '/capell-theme-view-' . bin2hex(random_bytes(6));
    $first = $root . '/first';
    $second = $root . '/second';
    mkdir($first, 0777, true);
    mkdir($second, 0777, true);
    file_put_contents($first . '/widget.blade.php', 'first');
    file_put_contents($second . '/widget.blade.php', 'second');

    try {
        $viewFinder = new FileViewFinder(new Filesystem, []);
        $registrar = new ThemeViewRegistrar($viewFinder, []);
        $registrar->register([$first], 'first');
        expect($viewFinder->find('capell::widget'))->toBe($first . '/widget.blade.php');

        $registrar->register([$second], 'second');
        expect($viewFinder->find('capell::widget'))->toBe($second . '/widget.blade.php');
    } finally {
        @unlink($first . '/widget.blade.php');
        @unlink($second . '/widget.blade.php');
        @rmdir($first);
        @rmdir($second);
        @rmdir($root);
    }
});
