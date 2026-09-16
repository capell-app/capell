<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Support\Extensions\ExtensionPosition;
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
        public readonly ?ExtensionPosition $position = null,
        public readonly string $source = self::class,
        public readonly bool $fragment = false,
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
            position: $contribution->position,
            source: $contribution->source,
            fragment: $contribution->fragment,
        );
    }

    /**
     * Stable, location-scoped identity used to re-render captured fragments.
     */
    public function stableKey(): string
    {
        if ($this->owner === null || $this->key === null) {
            return '';
        }

        return $this->location->value . ':' . $this->owner . ':' . $this->key;
    }

    /**
     * @return array{owner: string|null, key: string|null, priority: int, scenario: string|null, target: string|null, cacheSafe: bool, fragment: bool, registrationType: string}
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
            'fragment' => $this->fragment,
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
