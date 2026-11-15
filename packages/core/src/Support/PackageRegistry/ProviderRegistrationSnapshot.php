<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Closure;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;

/**
 * The container wiring and provider bookkeeping as they stood before a package's providers registered. Restoring it
 * undoes every binding, instance, alias, extender, tag and callback those providers added, and forgets the providers
 * themselves, so a package whose later provider fails is not left half-registered and its earlier providers never boot.
 */
final readonly class ProviderRegistrationSnapshot
{
    /** Container and application properties that provider registration writes to. */
    private const array PROPERTIES = [
        'bindings', 'instances', 'aliases', 'abstractAliases', 'extenders', 'tags', 'methodBindings',
        'reboundCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks', 'beforeResolvingCallbacks',
        'serviceProviders', 'loadedProviders', 'deferredServices',
        'bootingCallbacks', 'bootedCallbacks', 'terminatingCallbacks',
    ];

    /** @param  array<string, mixed>  $state */
    private function __construct(
        private Application $app,
        private array $state,
    ) {}

    /** Null when the container is not a full Laravel application (a test double), which has no provider state to restore. */
    public static function take(ApplicationContract $app): ?self
    {
        if (! $app instanceof Application) {
            return null;
        }

        $read = Closure::bind(function (array $properties): array {
            $state = [];

            foreach ($properties as $property) {
                $state[$property] = $this->{$property};
            }

            return $state;
        }, $app, Application::class);

        return new self($app, $read(self::PROPERTIES));
    }

    public function restore(): void
    {
        $write = Closure::bind(function (array $state): void {
            foreach ($state as $property => $value) {
                $this->{$property} = $value;
            }
        }, $this->app, Application::class);

        $write($this->state);
    }
}
