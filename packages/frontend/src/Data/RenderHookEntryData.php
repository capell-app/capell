<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;

final class RenderHookEntryData
{
    public function __construct(
        public readonly RenderHookLocation $location,
        public readonly mixed $extension,
        public readonly RenderHookRegistrationType $registrationType,
        public readonly int $priority = 10,
        public readonly ?string $scenario = null,
        public readonly ?string $target = null,
        public readonly ?string $owner = null,
        public readonly ?string $key = null,
        public readonly bool $cacheSafe = true,
    ) {}

    public static function legacy(
        RenderHookLocation $location,
        callable|RenderHookExtensionInterface|string $extension,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): self {
        return new self(
            location: $location,
            extension: $extension,
            registrationType: self::legacyRegistrationType($extension),
            priority: $priority,
            scenario: $scenario,
            target: $target,
        );
    }

    public static function contribution(RenderHookContributionData $contribution): self
    {
        return new self(
            location: $contribution->location,
            extension: $contribution->extension,
            registrationType: $contribution->registrationType,
            priority: $contribution->priority,
            scenario: $contribution->scenario,
            target: $contribution->target,
            owner: $contribution->owner,
            key: $contribution->key,
            cacheSafe: $contribution->cacheSafe,
        );
    }

    /**
     * @return array{owner: string|null, key: string|null, priority: int, scenario: string|null, target: string|null, cacheSafe: bool, registrationType: string}
     */
    public function toDiagnostics(): array
    {
        return [
            'owner' => $this->owner,
            'key' => $this->key,
            'priority' => $this->priority,
            'scenario' => $this->scenario,
            'target' => $this->target,
            'cacheSafe' => $this->cacheSafe,
            'registrationType' => $this->registrationType->value,
        ];
    }

    private static function legacyRegistrationType(callable|RenderHookExtensionInterface|string $extension): RenderHookRegistrationType
    {
        if ($extension instanceof RenderHookExtensionInterface) {
            return RenderHookRegistrationType::ExtensionClass;
        }

        if (is_callable($extension)) {
            return RenderHookRegistrationType::Callable;
        }

        return RenderHookRegistrationType::LegacyString;
    }
}
