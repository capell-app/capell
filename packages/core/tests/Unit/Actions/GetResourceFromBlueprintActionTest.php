<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Contracts\AdminResourceResolver;

beforeEach(function (): void {
    $resolver = Mockery::mock(AdminResourceResolver::class);
    $resolver->shouldReceive('hasPageResource')->andReturnTrue();
    $resolver->shouldReceive('getPageResource')->andReturn(PageResource::class);

    app()->instance(AdminResourceResolver::class, $resolver);
});

it('maps type to resource', function (): void {
    $resource = GetResourceFromBlueprintAction::run();

    expect($resource)->toBeString()->toContain('Page');
});

it('throws when the page resource is not registered', function (): void {
    $resolver = Mockery::mock(AdminResourceResolver::class);
    $resolver->shouldReceive('hasPageResource')->with('default')->andReturnFalse();
    app()->instance(AdminResourceResolver::class, $resolver);

    expect(fn () => GetResourceFromBlueprintAction::run())
        ->toThrow(InvalidArgumentException::class, 'Page resource not found for name: default');
});
