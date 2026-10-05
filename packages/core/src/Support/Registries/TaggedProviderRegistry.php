<?php

declare(strict_types=1);

namespace Capell\Core\Support\Registries;

use Illuminate\Container\RewindableGenerator;
use Illuminate\Contracts\Foundation\Application;

/**
 * @template TProvider of object
 */
class TaggedProviderRegistry
{
    /**
     * @param  iterable<mixed>  $providers
     * @param  class-string<TProvider>  $providerContract
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly string $providerContract,
    ) {}

    /**
     * @param  non-empty-string  $tag
     * @return iterable<mixed>
     */
    public static function tagged(Application $application, string $tag): iterable
    {
        // Even an initially empty tag must remain live after package installation.
        return new RewindableGenerator(
            static function () use ($application, $tag): iterable {
                yield from $application->tagged($tag);
            },
            static fn (): int => count(iterator_to_array((static function () use ($application, $tag): iterable {
                yield from $application->tagged($tag);
            })())),
        );
    }

    /** @return list<TProvider> */
    protected function providers(): array
    {
        $providerContract = $this->providerContract;
        $providers = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof $providerContract) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
