<?php

declare(strict_types=1);

namespace Capell\Core\Support\Capabilities;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Enums\CapabilityContract;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Closure;
use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * Resolves request-style capability contracts by interface. A providing package
 * registers its implementation while booting; consumers always get an object
 * that implements the contract, the null implementation when nothing provides it.
 */
/**
 * @extends AbstractKeyedRegistry<class-string|Closure(Container): object, class-string>
 */
final class CapabilityRegistry extends AbstractKeyedRegistry
{
    /** @var array<class-string, class-string> */
    private const array NULL_IMPLEMENTATIONS = [
        FormCatalogue::class => NullFormCatalogue::class,
    ];

    /**
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @param  class-string<TContract>|Closure(Container): TContract  $provider
     */
    public function provide(string $contract, string|Closure $provider): void
    {
        $capability = $this->capability($contract);

        throw_if(
            $this->hasItem($contract),
            InvalidArgumentException::class,
            sprintf('The [%s] capability already has a provider; only one package may provide it.', $capability->value),
        );

        if (is_string($provider) && ! is_a($provider, $contract, true)) {
            throw new InvalidArgumentException(sprintf('[%s] does not implement %s.', $provider, $contract));
        }

        $this->setItem($contract, $provider);
    }

    /**
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @return TContract
     */
    public function get(string $contract): object
    {
        $this->capability($contract);
        $provider = $this->getItem($contract) ?? self::NULL_IMPLEMENTATIONS[$contract];
        // Resolved through the current container on every call, so a long-lived worker never hands out a boot-time instance.
        $container = Container::getInstance();
        $resolved = $provider instanceof Closure ? $provider($container) : $container->make($provider);

        throw_unless($resolved instanceof $contract, InvalidArgumentException::class, sprintf('The provider for %s returned an object that does not implement it.', $contract));

        return $resolved;
    }

    /**
     * @param  class-string  $contract
     */
    public function isProvided(string $contract): bool
    {
        $this->capability($contract);

        return $this->hasItem($contract);
    }

    /**
     * @return list<CapabilityContract>
     */
    public function provided(): array
    {
        return array_values(array_filter(
            CapabilityContract::cases(),
            fn (CapabilityContract $capability): bool => $capability->contract() !== null && $this->hasItem($capability->contract()),
        ));
    }

    private function capability(string $contract): CapabilityContract
    {
        $capability = CapabilityContract::forContract($contract);

        throw_unless(
            $capability instanceof CapabilityContract && isset(self::NULL_IMPLEMENTATIONS[$contract]),
            InvalidArgumentException::class,
            sprintf('%s is not a Core capability contract.', $contract),
        );

        return $capability;
    }
}
