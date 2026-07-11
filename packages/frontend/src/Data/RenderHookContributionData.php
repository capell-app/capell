<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;

/**
 * A keyed render-hook contribution.
 *
 * Unlike a bare {@see RenderHookRegistry::register()} call, a contribution
 * carries the metadata a long-term extension platform needs: the owning
 * package, a stable key for dedupe and diagnostics, and an explicit
 * cache-safety declaration. Two contributions sharing the same stable key are
 * collapsed to one, so a package that boots twice (or two providers that both
 * register the same hook) cannot double-render.
 */
final class RenderHookContributionData
{
    public readonly RenderHookRegistrationType $registrationType;

    public function __construct(
        public readonly RenderHookLocation $location,
        public readonly RenderHookExtensionInterface|string $extension,
        public readonly string $owner,
        public readonly string $key,
        public readonly int $priority = 10,
        public readonly ?string $scenario = null,
        public readonly ?string $target = null,
        public readonly bool $cacheSafe = true,
        ?RenderHookRegistrationType $registrationType = null,
    ) {
        $this->registrationType = $registrationType ?? $this->defaultRegistrationType($extension);
    }

    public static function view(
        RenderHookLocation $location,
        string $view,
        string $owner,
        string $key,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
        bool $cacheSafe = true,
    ): self {
        return new self(
            location: $location,
            extension: $view,
            owner: $owner,
            key: $key,
            priority: $priority,
            scenario: $scenario,
            target: $target,
            cacheSafe: $cacheSafe,
            registrationType: RenderHookRegistrationType::View,
        );
    }

    public static function inlineBlade(
        RenderHookLocation $location,
        string $blade,
        string $owner,
        string $key,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
        bool $cacheSafe = true,
    ): self {
        return new self(
            location: $location,
            extension: $blade,
            owner: $owner,
            key: $key,
            priority: $priority,
            scenario: $scenario,
            target: $target,
            cacheSafe: $cacheSafe,
            registrationType: RenderHookRegistrationType::InlineBlade,
        );
    }

    public static function extension(
        RenderHookLocation $location,
        RenderHookExtensionInterface $extension,
        string $owner,
        string $key,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
        bool $cacheSafe = true,
    ): self {
        return new self(
            location: $location,
            extension: $extension,
            owner: $owner,
            key: $key,
            priority: $priority,
            scenario: $scenario,
            target: $target,
            cacheSafe: $cacheSafe,
            registrationType: RenderHookRegistrationType::ExtensionClass,
        );
    }

    /**
     * Stable, location-scoped identity used for dedupe and diagnostics.
     */
    public function stableKey(): string
    {
        return $this->location->value . ':' . $this->owner . ':' . $this->key;
    }

    /**
     * @return array{owner: string, key: string, location: string, priority: int, scenario: string|null, target: string|null, cacheSafe: bool, registrationType: string}
     */
    public function toDiagnostics(): array
    {
        return [
            'owner' => $this->owner,
            'key' => $this->key,
            'location' => $this->location->value,
            'priority' => $this->priority,
            'scenario' => $this->scenario,
            'target' => $this->target,
            'cacheSafe' => $this->cacheSafe,
            'registrationType' => $this->registrationType->value,
        ];
    }

    private function defaultRegistrationType(RenderHookExtensionInterface|string $extension): RenderHookRegistrationType
    {
        return $extension instanceof RenderHookExtensionInterface
            ? RenderHookRegistrationType::ExtensionClass
            : RenderHookRegistrationType::LegacyString;
    }
}
