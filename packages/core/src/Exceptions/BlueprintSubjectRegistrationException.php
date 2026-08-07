<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Contracts\Blueprintable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when a package contributes an invalid blueprint subject descriptor.
 *
 * These are all programmer errors detectable at boot, not bad user input, hence
 * {@see LogicException}: a duplicate key, a model that cannot carry a blueprint,
 * a malformed key, or a registration attempted after the registry froze.
 */
final class BlueprintSubjectRegistrationException extends LogicException
{
    public static function frozen(string $key): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] cannot be registered after boot. Register subjects from bootInstalledPackage() via $this->surface()->blueprintSubject().',
            $key,
        ));
    }

    public static function malformedKey(string $key): self
    {
        return new self(sprintf(
            'Blueprint subject key [%s] must be lowercase kebab-case, optionally dot-namespaced (e.g. structured-content.collection).',
            $key,
        ));
    }

    public static function invalidGroup(string $key): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] groups must all be %s values.',
            $key,
            BlueprintGroupEnum::class,
        ));
    }

    public static function invalidModel(string $key, string $modelClass): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] model [%s] must extend [%s] and implement [%s].',
            $key,
            $modelClass,
            Model::class,
            Blueprintable::class,
        ));
    }

    public static function missingOwnerPackage(string $key): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] must name the package that owns it so orphaned rows can be attributed after an uninstall.',
            $key,
        ));
    }

    public static function invalidSeeder(string $key, string $seederClass): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] default schema seeder [%s] must expose a static run method.',
            $key,
            $seederClass,
        ));
    }

    public static function duplicateKey(string $key, string $ownerPackage): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] is already registered by [%s].',
            $key,
            $ownerPackage,
        ));
    }
}
