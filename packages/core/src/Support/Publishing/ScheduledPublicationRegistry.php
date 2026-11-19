<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

use Capell\Core\Contracts\Publishing\SchedulesPublication;
use Capell\Core\Exceptions\ScheduledPublicationRegistrationException;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * The models, other than Page, whose `visible_from` and `visible_until` the
 * scheduled-publication sweep watches. A package registers its model class while
 * booting; the container freezes the registry on `booted`, so a request-time
 * registration cannot leak across Octane requests, and package installation
 * reopens it through {@see self::duringPackageInstallation()}.
 *
 * @experimental
 *
 * @extends AbstractKeyedRegistry<class-string<Model&SchedulesPublication>, class-string<Model&SchedulesPublication>>
 */
final class ScheduledPublicationRegistry extends AbstractKeyedRegistry
{
    private bool $frozen = false;

    private int $installationDepth = 0;

    /**
     * @param  class-string  $modelClass
     *
     * @throws ScheduledPublicationRegistrationException when the registry is frozen or the class is not a model implementing SchedulesPublication
     */
    public function register(string $modelClass): void
    {
        if ($this->frozen && $this->installationDepth === 0) {
            throw ScheduledPublicationRegistrationException::frozen($modelClass);
        }

        if (! is_a($modelClass, Model::class, true) || ! is_a($modelClass, SchedulesPublication::class, true)) {
            throw ScheduledPublicationRegistrationException::invalidModel($modelClass);
        }

        $this->setItem($modelClass, $modelClass);
    }

    /**
     * @return list<class-string<Model&SchedulesPublication>>
     */
    public function models(): array
    {
        return array_values($this->allItems());
    }

    public function freeze(): void
    {
        $this->frozen = true;
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
}
