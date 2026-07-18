<?php

declare(strict_types=1);

namespace Capell\Core\Support\Registries;

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
