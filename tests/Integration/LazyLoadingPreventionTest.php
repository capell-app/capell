<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

it('prevents Eloquent lazy loading throughout the test suite', function (): void {
    Site::factory()->count(2)->create();
    $freshSite = Site::query()->get()->firstOrFail();

    expect(Model::preventsLazyLoading())->toBeTrue();
    expect(fn () => $freshSite->language)->toThrow(LazyLoadingViolationException::class);
});
