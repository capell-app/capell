<?php

declare(strict_types=1);

use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('loads a site by ID', function (): void {
    $site = Site::factory()->createOne();

    $loader = resolve(SiteLoader::class);
    $loaded = $loader->loadById($site->id);

    expect($loaded)->toBeInstanceOf(Site::class)
        ->and($loaded->id)->toBe($site->id);
});

it('throws when site is not found', function (): void {
    $loader = resolve(SiteLoader::class);

    expect(fn () => $loader->loadById(999999))
        ->toThrow(ModelNotFoundException::class);
});
