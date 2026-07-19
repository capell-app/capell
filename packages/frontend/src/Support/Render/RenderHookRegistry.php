<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Data\RenderHookEntryData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use LogicException;

/**
 * @template T of RenderHookContext
 */
class RenderHookRegistry
{
    /** @var array<string, list<RenderHookEntryData>> */
    protected array $extensions = [];

    /** @var array<string, true> Stable keys already contributed, for dedupe. */
    protected array $contributedKeys = [];

    public function __construct(
        private readonly ?Container $container = null,
    ) {}

    /**
     * Register an extension for a location, optionally scoped to a scenario and/or target (e.g., Blade file/component).
     */
    public function register(
        RenderHookLocation $location,
        callable|RenderHookExtensionInterface|string $extension,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): void {
        $this->addEntry(RenderHookEntryData::legacy(
            location: $location,
            extension: $extension,
            priority: $priority,
            scenario: $scenario,
            target: $target,
        ));
    }

    public function registerView(
        RenderHookLocation $location,
        string $view,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): void {
        $this->addEntry(new RenderHookEntryData(
            location: $location,
            extension: $view,
            registrationType: RenderHookRegistrationType::View,
            priority: $priority,
            scenario: $scenario,
            target: $target,
        ));
    }

    public function registerInlineBlade(
        RenderHookLocation $location,
        string $blade,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): void {
        $this->addEntry(new RenderHookEntryData(
            location: $location,
            extension: $blade,
            registrationType: RenderHookRegistrationType::InlineBlade,
            priority: $priority,
            scenario: $scenario,
            target: $target,
        ));
    }

    public function registerCallable(
        RenderHookLocation $location,
        callable $extension,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): void {
        $this->addEntry(new RenderHookEntryData(
            location: $location,
            extension: $extension,
            registrationType: RenderHookRegistrationType::Callable,
            priority: $priority,
            scenario: $scenario,
            target: $target,
        ));
    }

    public function registerExtension(
        RenderHookLocation $location,
        RenderHookExtensionInterface $extension,
        int $priority = 10,
        ?string $scenario = null,
        ?string $target = null,
    ): void {
        $this->addEntry(new RenderHookEntryData(
            location: $location,
            extension: $extension,
            registrationType: RenderHookRegistrationType::ExtensionClass,
            priority: $priority,
            scenario: $scenario,
            target: $target,
        ));
    }

    /**
     * Register a keyed contribution. Contributions sharing a stable key are
     * deduplicated, so repeated boots cannot double-render the same hook.
     */
    public function contribute(RenderHookContributionData $contribution): void
    {
        $stableKey = $contribution->stableKey();

        if (isset($this->contributedKeys[$stableKey])) {
            return;
        }

        $this->contributedKeys[$stableKey] = true;

        $this->addEntry(RenderHookEntryData::contribution($contribution));
    }

    /**
     * Diagnostics metadata for every keyed contribution, grouped by location.
     *
     * @return array<string, list<array{owner: string|null, key: string|null, priority: int, scenario: string|null, target: string|null, cacheSafe: bool, registrationType: string}>>
     */
    public function contributions(): array
    {
        $contributions = [];

        foreach ($this->extensions as $location => $entries) {
            foreach ($entries as $entry) {
                if ($entry->owner === null && $entry->key === null) {
                    continue;
                }

                $contributions[$location][] = $entry->toDiagnostics();
            }
        }

        return $contributions;
    }

    /**
     * Diagnostics metadata for all registered hooks, including unkeyed legacy registrations.
     *
     * @return array<string, list<array{owner: string|null, key: string|null, priority: int, scenario: string|null, target: string|null, cacheSafe: bool, registrationType: string}>>
     */
    public function diagnostics(): array
    {
        $diagnostics = [];

        foreach ($this->extensions as $location => $entries) {
            foreach ($entries as $entry) {
                $diagnostics[$location][] = $entry->toDiagnostics();
            }
        }

        return $diagnostics;
    }

    /**
     * Render all extensions for a location and item context, optionally filtered by scenario and/or target.
     */
    public function renderAll(
        RenderHookLocation $location,
        mixed $item = null,
        ?string $scenario = null,
        ?string $target = null,
    ): string {

        $key = $location->value;
        if (! isset($this->extensions[$key])) {
            return '';
        }

        $context = new RenderHookContext($location->value, $item);
        $extensions = collect($this->extensions[$key])
            ->filter(function (RenderHookEntryData $entry) use ($scenario, $target): bool {
                if ($entry->scenario !== null && $entry->scenario !== $scenario) {
                    return false;
                }

                if ($entry->target !== null && $entry->target !== $target) {
                    return false;
                }

                return true;
            })
            ->sortBy(fn (RenderHookEntryData $entry): int => $entry->priority);

        return $extensions
            ->map(fn (RenderHookEntryData $entry): mixed => $this->renderEntry($entry, $context))
            ->implode('');
    }

    /**
     * Get all extensions registered for a location, rendering any View objects to string.
     */
    public function get(RenderHookLocation $location): array
    {
        $key = $location->value;
        if (! isset($this->extensions[$key])) {
            return [];
        }

        return array_map(function (RenderHookEntryData $entry) {
            if ($entry->extension instanceof View) {
                return $entry->extension->render();
            }

            return $entry->extension;
        }, $this->extensions[$key]);
    }

    private function addEntry(RenderHookEntryData $entry): void
    {
        $key = $entry->location->value;
        $this->extensions[$key][] = $entry;
    }

    private function renderEntry(RenderHookEntryData $entry, RenderHookContext $context): mixed
    {
        $result = match ($entry->registrationType) {
            RenderHookRegistrationType::View => view((string) $entry->extension, ['context' => $context]),
            RenderHookRegistrationType::InlineBlade,
            RenderHookRegistrationType::LegacyString => Blade::render((string) $entry->extension, ['context' => $context]),
            RenderHookRegistrationType::Callable => ($entry->extension)($context),
            RenderHookRegistrationType::ExtensionClass => $this->resolveExtension($entry->extension)->render($context),
        };

        if ($result instanceof View) {
            return $result->render();
        }

        return $result;
    }

    private function resolveExtension(mixed $extension): RenderHookExtensionInterface
    {
        if ($extension instanceof RenderHookExtensionInterface) {
            return $extension;
        }

        throw_unless(is_string($extension), LogicException::class, 'Render hook extension class must be a class-string.');

        $resolved = ($this->container ?? app())->make($extension);

        throw_unless($resolved instanceof RenderHookExtensionInterface, LogicException::class, 'Resolved render hook extension must implement RenderHookExtensionInterface.');

        return $resolved;
    }
}
