<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Core\Contracts\SettingsContract;
use Illuminate\Support\Collection;
use ReflectionProperty;

final class AbstractPackageSettingsPageTestSettings implements SettingsContract
{
    /** @var array<string, mixed> */
    public static array $savedValues = [];

    /** @var array<string, array<string, mixed>> */
    public static array $persistedDefaultPayloads = [];

    public string $fallbackHeadline = 'Fallback package headline';

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values = []) {}

    public static function group(): string
    {
        return 'abstract-page-test';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function fill(array $values): void
    {
        $this->values = [
            ...$this->values,
            ...$values,
        ];
    }

    public function save(): void
    {
        self::$savedValues = $this->values;
    }

    public function refresh(): self
    {
        return $this;
    }

    public function settingsConfig(): object
    {
        return new class
        {
            public function getRepository(): object
            {
                return new class
                {
                    /**
                     * @return array<string, mixed>
                     */
                    public function getPropertiesInGroup(string $group): array
                    {
                        return [];
                    }

                    /**
                     * @param  array<string, mixed>  $defaults
                     */
                    public function updatePropertiesPayload(string $group, array $defaults): void
                    {
                        AbstractPackageSettingsPageTestSettings::$persistedDefaultPayloads[$group] = $defaults;
                    }
                };
            }

            public function getGroup(): string
            {
                return AbstractPackageSettingsPageTestSettings::group();
            }

            /**
             * @return Collection<string, ReflectionProperty>
             */
            public function getReflectedProperties(): Collection
            {
                return collect([
                    'fallbackHeadline' => new ReflectionProperty(
                        AbstractPackageSettingsPageTestSettings::class,
                        'fallbackHeadline',
                    ),
                ]);
            }

            public function getCast(string $name): null
            {
                return null;
            }

            public function isEncrypted(string $name): bool
            {
                return false;
            }
        };
    }
}
