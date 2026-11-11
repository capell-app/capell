<?php

declare(strict_types=1);

use Capell\Frontend\Support\Cache\FragmentCache;
use Illuminate\Support\Facades\Artisan;

it('retires every cached fragment so the next request rebuilds it', function (): void {
    $fragments = resolve(FragmentCache::class);
    $renders = 0;
    $render = static function () use (&$renders): string {
        $renders++;

        return 'rendered';
    };

    $fragments->remember('release-switch-fragment', $render);
    $fragments->remember('release-switch-fragment', $render);

    expect($renders)->toBe(1);

    expect(Artisan::call('capell:flush-fragment-cache'))->toBe(0)
        ->and(Artisan::output())->toContain('Fragment cache flushed.');

    $fragments->remember('release-switch-fragment', $render);

    expect($renders)->toBe(2);
});
