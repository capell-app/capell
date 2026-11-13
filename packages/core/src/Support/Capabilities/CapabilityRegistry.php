<?php

declare(strict_types=1);

namespace Capell\Core\Support\Capabilities;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Enums\CapabilityContract;
use Capell\Core\Exceptions\CapabilityRegistrationException;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Throwable;
use WeakMap;

/**
 * Resolves request-style capability contracts by interface. A providing package
 * registers its implementation class while booting; the container freezes the
 * registry on `booted`, so a request-time registration cannot leak across Octane
 * requests, and package installation reopens it through
 * {@see self::duringPackageInstallation()}. Consumers always get an object that
 * implements the contract: the null implementation when nothing provides it, or
 * when the provider fails to resolve, which is reported rather than thrown so a
 * broken optional package cannot take a consumer's page down.
 *
 * @extends AbstractKeyedRegistry<class-string, class-string>
 */
final class CapabilityRegistry extends AbstractKeyedRegistry
{
    /** @var array<class-string, class-string> */
    private const array NULL_IMPLEMENTATIONS = [
        FormCatalogue::class => NullFormCatalogue::class,
    ];

    private bool $frozen = false;

    private int $installationDepth = 0;

    /**
     * Providers already reported as failing, per request object, so a broken
     * provider is reported once per request rather than on every resolution.
     *
     * @var WeakMap<object, array<class-string, true>>|null
     */
    private ?WeakMap $reportedFailures = null;

    /**
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @param  class-string<TContract>  $provider
     *
     * @throws CapabilityRegistrationException when the registry is frozen or another provider is registered
     * @throws InvalidArgumentException when the contract is not a capability or the provider does not implement it
     */
    public function provide(string $contract, string $provider): void
    {
        $capability = $this->capability($contract);

        if (! is_a($provider, $contract, true)) {
            throw new InvalidArgumentException(sprintf('[%s] does not implement %s.', $provider, $contract));
        }

        if ($this->frozen && $this->installationDepth === 0) {
            throw CapabilityRegistrationException::frozen($capability);
        }

        $existing = $this->getItem($contract);

        if ($existing === $provider) {
            // A package re-booted by the install lifecycle registers the same provider again.
            return;
        }

        if ($existing !== null) {
            throw CapabilityRegistrationException::alreadyProvided($capability, $existing, $provider);
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
        $provider = $this->getItem($contract);
        $null = self::NULL_IMPLEMENTATIONS[$contract];

        if ($provider === null) {
            return $this->resolve($null, $contract);
        }

        try {
            return $this->resolve($provider, $contract);
        } catch (Throwable $throwable) {
            $this->reportOnce($provider, $throwable);

            return $this->resolve($null, $contract);
        }
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

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Reopen the registry for the package-install lifecycle.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function duringPackageInstallation(callable $callback): mixed
    {
        $this->installationDepth++;

        try {
            return $callback();
        } finally {
            $this->installationDepth--;
        }
    }

    /**
     * Resolved through the current container on every call, so a long-lived worker
     * never hands out a boot-time instance.
     *
     * @template TContract of object
     *
     * @param  class-string  $implementation
     * @param  class-string<TContract>  $contract
     * @return TContract
     */
    private function resolve(string $implementation, string $contract): object
    {
        $resolved = Container::getInstance()->make($implementation);

        if (! $resolved instanceof $contract) {
            throw CapabilityRegistrationException::wrongType($implementation, $contract);
        }

        return $resolved;
    }

    /**
     * @param  class-string  $provider
     */
    private function reportOnce(string $provider, Throwable $throwable): void
    {
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;

        if (! is_object($request)) {
            report($throwable);

            return;
        }

        $this->reportedFailures ??= new WeakMap;
        $reported = $this->reportedFailures[$request] ?? [];

        if (isset($reported[$provider])) {
            return;
        }

        $reported[$provider] = true;
        $this->reportedFailures[$request] = $reported;
        report($throwable);
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
