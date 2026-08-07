<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Actions\PublishOutboundEventAction;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Events\OutboundEventPublished;
use Capell\Core\Exceptions\OutboundEventRegistrationException;
use Capell\Core\Exceptions\UnknownOutboundEventException;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Spatie\LaravelData\Data;

/**
 * The set of events a package may publish through
 * {@see PublishOutboundEventAction}.
 *
 * Core performs no HTTP: a package declares the events it can emit here, and
 * publishing an event is a cheap no-op until something (typically the
 * `webhooks` package) binds a listener to
 * {@see OutboundEventPublished}. Registering a definition
 * does not by itself send anything anywhere.
 *
 * **What a package provides:** one {@see OutboundEventDefinitionData} per
 * event, from `bootInstalledPackage()`:
 *
 * ```php
 * $this->surface()->outboundEvent(new OutboundEventDefinitionData(
 *     name: 'vendor-package.event-name',
 *     version: 1,
 *     payloadClass: ArticlePublishedData::class,
 *     description: 'An article was published.',
 *     ownerPackage: 'vendor/package',
 * ));
 * ```
 *
 * The package must also declare an `outbound-event` contribution in its
 * `capell.json`; `capell:extension-audit` cross-checks the declaration against
 * what actually registered.
 *
 * **Lifecycle:** registration is boot-only. The container freezes the registry
 * on `booted`, so a request-time registration cannot leak across Octane
 * requests; late registration throws
 * {@see OutboundEventRegistrationException}.
 *
 * @see OutboundEventDefinitionData The definition contract itself.
 * @see PackageSurfaceRegistrar::outboundEvent() The registration entry point.
 */
final class OutboundEventRegistry
{
    /** @var array<string, OutboundEventDefinitionData> */
    private array $definitions = [];

    private bool $frozen = false;

    /**
     * Register an event definition, validating everything a later publish
     * will assume.
     *
     * @throws OutboundEventRegistrationException when the registry is frozen,
     *                                            the name is malformed, the
     *                                            version is not a positive
     *                                            integer, the description or
     *                                            owner package is missing, the
     *                                            payload class does not extend
     *                                            {@see Data}, or the name is
     *                                            already taken.
     */
    public function register(OutboundEventDefinitionData $definition): self
    {
        if ($this->frozen) {
            throw OutboundEventRegistrationException::frozen($definition->name);
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/', $definition->name)) {
            throw OutboundEventRegistrationException::malformedName($definition->name);
        }

        if ($definition->version < 1) {
            throw OutboundEventRegistrationException::invalidVersion($definition->name);
        }

        if ($definition->description === '' || $definition->ownerPackage === '') {
            throw OutboundEventRegistrationException::missingDescriptionOrOwnerPackage($definition->name);
        }

        if (! is_a($definition->payloadClass, Data::class, true)) {
            throw OutboundEventRegistrationException::invalidPayloadClass($definition->name, $definition->payloadClass);
        }

        if (isset($this->definitions[$definition->name])) {
            throw OutboundEventRegistrationException::duplicateName($definition->name);
        }

        $this->definitions[$definition->name] = $definition;

        return $this;
    }

    /**
     * Resolve an event definition, failing closed when it is not registered.
     *
     * @throws UnknownOutboundEventException when no package registered the name.
     */
    public function definition(string $name): OutboundEventDefinitionData
    {
        return $this->definitions[$name] ?? throw UnknownOutboundEventException::forName($name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** @return array<string, OutboundEventDefinitionData> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
