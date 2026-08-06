<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Exceptions\BlueprintSubjectRegistrationException;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Database\Eloquent\Model;

/**
 * The set of models that can carry an operator-editable blueprint.
 *
 * Blueprints own Capell's schema-snapshot, drift-alert and interceptor
 * machinery. Before this registry that machinery was closed to Page, Site and
 * Theme; a package wanting operator-editable schemas for its own model had to
 * fork it. Registering a subject here opts a model into all of it unchanged.
 *
 * **What a package provides:** one
 * {@see BlueprintSubjectDescriptorData} per model, from
 * `bootInstalledPackage()`:
 *
 * ```php
 * $this->surface()->blueprintSubject(new BlueprintSubjectDescriptorData(
 *     key: 'structured-content.collection',
 *     label: 'Collection',
 *     modelClass: Collection::class,
 *     ownerPackage: 'capell-app/structured-content-library',
 *     groups: [BlueprintGroupEnum::Default],
 *     defaultSchemaSeeder: CreateDefaultCollectionBlueprintAction::class,
 * ));
 * ```
 *
 * The package must also declare an `blueprint-subject` contribution in its
 * `capell.json`; `capell:extension-audit` cross-checks the declaration against
 * what actually registered.
 *
 * **When it is read:** on every blueprint type cast, `Blueprint::type()` scope,
 * and admin subject list. Resolution is fail-closed — see
 * {@see self::descriptor()} versus {@see self::descriptorOrNull()}.
 *
 * **Lifecycle:** registration is boot-only. The container freezes the registry
 * on `booted`, so a request-time registration cannot leak across Octane
 * requests; late registration throws
 * {@see BlueprintSubjectRegistrationException}.
 *
 * @see BlueprintSubjectDescriptorData The descriptor contract itself.
 * @see PackageSurfaceRegistrar::blueprintSubject() The registration entry point.
 */
final class BlueprintSubjectRegistry
{
    /** @var array<string, BlueprintSubjectDescriptorData> */
    private array $subjects = [];

    private bool $frozen = false;

    /**
     * Register a subject, validating everything a later reader will assume.
     *
     * @throws BlueprintSubjectRegistrationException when the registry is frozen,
     *                                               the key is malformed or already
     *                                               taken, the model cannot carry a
     *                                               blueprint, the owner package is
     *                                               missing, or the seeder is not
     *                                               runnable.
     */
    public function register(BlueprintSubjectDescriptorData $subject): self
    {
        if ($this->frozen) {
            throw BlueprintSubjectRegistrationException::frozen($subject->key);
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $subject->key)) {
            throw BlueprintSubjectRegistrationException::malformedKey($subject->key);
        }

        if (! is_a($subject->modelClass, Model::class, true)
            || ! is_a($subject->modelClass, Blueprintable::class, true)) {
            throw BlueprintSubjectRegistrationException::invalidModel($subject->key, $subject->modelClass);
        }

        if ($subject->ownerPackage === '') {
            throw BlueprintSubjectRegistrationException::missingOwnerPackage($subject->key);
        }

        foreach ($subject->groups as $group) {
            if (! $group instanceof BlueprintGroupEnum) {
                throw BlueprintSubjectRegistrationException::invalidGroup($subject->key);
            }
        }

        if ($subject->defaultSchemaSeeder !== null
            && (! class_exists($subject->defaultSchemaSeeder)
                || ! is_callable([$subject->defaultSchemaSeeder, 'run']))) {
            throw BlueprintSubjectRegistrationException::invalidSeeder($subject->key, $subject->defaultSchemaSeeder);
        }

        if (isset($this->subjects[$subject->key])) {
            throw BlueprintSubjectRegistrationException::duplicateKey(
                $subject->key,
                $this->subjects[$subject->key]->ownerPackage,
            );
        }

        $this->subjects[$subject->key] = $subject;

        return $this;
    }

    /**
     * Resolve a subject, failing closed when it is not registered.
     *
     * Use this on write paths and anywhere a wrong answer is worse than an
     * error. Read paths that must tolerate an uninstalled package should use
     * {@see self::descriptorOrNull()} instead.
     *
     * @throws UnknownBlueprintSubjectException when no package registered the key.
     */
    public function descriptor(BlueprintSubjectEnum|string $subject): BlueprintSubjectDescriptorData
    {
        return $this->descriptorOrNull($subject)
            ?? throw UnknownBlueprintSubjectException::forKey($this->key($subject), $this->keys());
    }

    /**
     * Resolve a subject, returning null when it is not registered.
     *
     * This is the orphan-tolerant read path: when a package is uninstalled its
     * blueprint rows survive, and listing surfaces must still render them as an
     * unavailable subject rather than crashing.
     */
    public function descriptorOrNull(BlueprintSubjectEnum|string $subject): ?BlueprintSubjectDescriptorData
    {
        return $this->subjects[$this->key($subject)] ?? null;
    }

    public function has(BlueprintSubjectEnum|string $subject): bool
    {
        return isset($this->subjects[$this->key($subject)]);
    }

    /** @return array<string, BlueprintSubjectDescriptorData> */
    public function all(): array
    {
        return $this->subjects;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->subjects);
    }

    /**
     * Subjects contributed by a single package, for uninstall attribution.
     *
     * @return array<string, BlueprintSubjectDescriptorData>
     */
    public function ownedBy(string $ownerPackage): array
    {
        return array_filter(
            $this->subjects,
            static fn (BlueprintSubjectDescriptorData $subject): bool => $subject->ownerPackage === $ownerPackage,
        );
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function key(BlueprintSubjectEnum|string $subject): string
    {
        return $subject instanceof BlueprintSubjectEnum ? $subject->getKey() : trim($subject);
    }
}
