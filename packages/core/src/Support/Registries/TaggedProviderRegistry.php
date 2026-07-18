<?php

declare(strict_types=1);

namespace Capell\Core\Support\Registries;

use Illuminate\Contracts\Foundation\Application;

/**
 * @template TProvider of object
 */
class TaggedProviderRegistry
{
    /** @var iterable<mixed> */
    private readonly iterable $providers;

    /**
     * @param  non-empty-string  $tag
     * @param  class-string<TProvider>  $providerContract
     */
    public function __construct(
        Application $application,
        string $tag,
        private readonly string $providerContract,
    ) {
        $this->providers = $application->tagged($tag);
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
