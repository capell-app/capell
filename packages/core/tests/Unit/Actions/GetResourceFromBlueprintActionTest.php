<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Contracts\AdminResourceResolver;

it('returns the registered page resource and reports missing registrations', function (bool $registered): void {
    $resolver = new readonly class($registered) implements AdminResourceResolver
    {
        public function __construct(private bool $registered) {}

        #[Override]
        public function hasPageResource(string $name = 'default'): bool
        {
            return $this->registered && $name === 'default';
        }

        #[Override]
        public function getPageResource(string $name = 'default'): ?string
        {
            return $this->hasPageResource($name) ? PageResource::class : null;
        }
    };
    app()->instance(AdminResourceResolver::class, $resolver);

    if ($registered) {
        expect(GetResourceFromBlueprintAction::run())->toBe(PageResource::class);
    } else {
        expect(fn (): string => GetResourceFromBlueprintAction::run())
            ->toThrow(InvalidArgumentException::class, 'Page resource not found for name: default');
    }
})->with([true, false]);
