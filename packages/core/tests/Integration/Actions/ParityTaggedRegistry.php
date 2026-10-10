<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Illuminate\Foundation\Application;

/** @extends TaggedProviderRegistry<ParityContribution> */
final class ParityTaggedRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(self::tagged($application, 'runtime.parity.contributors'), ParityContribution::class);
    }

    /** @return list<class-string> */
    public function all(): array
    {
        return array_map(static fn (object $provider): string => $provider::class, $this->providers());
    }
}
