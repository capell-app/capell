<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Contracts\Blueprintable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class BlueprintSubjectRegistry
{
    /** @var array<string, BlueprintSubjectDescriptorData> */
    private array $subjects = [];

    private bool $frozen = false;

    public function register(BlueprintSubjectDescriptorData $subject): self
    {
        if ($this->frozen) {
            throw new InvalidArgumentException('Blueprint subject registration is frozen.');
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $subject->key)) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint subject key [%s] must be lowercase kebab-case.',
                $subject->key,
            ));
        }

        if (! is_a($subject->modelClass, Model::class, true)
            || ! is_a($subject->modelClass, Blueprintable::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint subject model [%s] must extend [%s] and implement [%s].',
                $subject->modelClass,
                Model::class,
                Blueprintable::class,
            ));
        }

        if ($subject->ownerPackage === '') {
            throw new InvalidArgumentException('Blueprint subject owner package cannot be empty.');
        }

        foreach ($subject->groups as $group) {
            if (! $group instanceof BlueprintGroupEnum) {
                throw new InvalidArgumentException('Blueprint subject groups must be BlueprintGroupEnum values.');
            }
        }

        if ($subject->defaultSchemaSeeder !== null
            && (! class_exists($subject->defaultSchemaSeeder)
                || ! is_callable([$subject->defaultSchemaSeeder, 'run']))) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint subject seeder [%s] must expose a static run method.',
                $subject->defaultSchemaSeeder,
            ));
        }

        if (isset($this->subjects[$subject->key])) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint subject [%s] is already registered.',
                $subject->key,
            ));
        }

        $this->subjects[$subject->key] = $subject;

        return $this;
    }

    public function descriptor(BlueprintSubjectEnum|string $subject): BlueprintSubjectDescriptorData
    {
        $key = $subject instanceof BlueprintSubjectEnum ? $subject->getKey() : trim($subject);

        return $this->subjects[$key]
            ?? throw new InvalidArgumentException(sprintf(
                'Blueprint subject [%s] is not registered. Registered subjects: [%s].',
                $key,
                implode(', ', array_keys($this->subjects)),
            ));
    }

    public function has(BlueprintSubjectEnum|string $subject): bool
    {
        $key = $subject instanceof BlueprintSubjectEnum ? $subject->getKey() : trim($subject);

        return isset($this->subjects[$key]);
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

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
