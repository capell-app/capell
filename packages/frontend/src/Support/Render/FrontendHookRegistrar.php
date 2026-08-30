<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;

/**
 * Ergonomic entry point for packages contributing render hooks.
 *
 * Packages should register through this registrar rather than calling
 * {@see RenderHookRegistry::register()} directly, so every hook carries an
 * owner, a stable key, and a cache-safety declaration. The stable key gives
 * the platform dedupe and diagnostics for free, removing the need for the
 * ad-hoc WeakMap guards packages currently hand-roll.
 */
final class FrontendHookRegistrar
{
    public function __construct(
        private readonly RenderHookRegistry $registry,
        private readonly RecordsExtensionContributionReceipt $receipts,
    ) {}

    public function contribute(
        RenderHookLocation $location,
        RenderHookExtensionInterface|string $extension,
        string $owner,
        string $key,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
        bool $cacheSafe = true,
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): void {
        $this->registry->contribute(new RenderHookContributionData(
            location: $location,
            extension: $extension,
            owner: $owner,
            key: $key,
            priority: $priority,
            scenario: $scenario,
            target: $target,
            cacheSafe: $cacheSafe,
            position: $position,
            source: $source,
        ));
        $this->receipts->recordContribution(ExtensionContributionType::RenderHook, $key, is_string($extension) ? $extension : $extension::class, self::class);
    }
}
