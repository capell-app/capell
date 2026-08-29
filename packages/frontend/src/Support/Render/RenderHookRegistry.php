<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Frontend\Actions\Performance\RecordManifestRenderContributionAction;
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
use ReflectionFunction;

/**
 * @template T of RenderHookContext
 */
class RenderHookRegistry
{
    /** @var array<string, list<RenderHookEntryData>> */
    protected array $extensions = [];

    /** @var array<string, true> Stable keys already contributed, for dedupe. */
    protected array $contributedKeys = [];

    /** @var array<string, int> Occurrences for indistinguishable legacy registrations. */
    protected array $legacyReceiptOccurrences = [];

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
        $this->receipt($extension, $location->value);
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
        $this->receipt($view, $location->value);
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
        $this->receipt($blade, $location->value);
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
        $this->receipt($extension, $location->value);
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
        $this->receipt($extension, $location->value);
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
        $this->receipts()?->recordContribution(
            ExtensionContributionType::RenderHook,
            $contribution->key,
            is_string($contribution->extension) ? $contribution->extension : $contribution->extension::class,
            self::class,
            'frontend',
        );
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
            ->map(fn (RenderHookEntryData $entry): mixed => $this->renderAndRecordEntry($entry, $context))
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

    private function receipt(mixed $extension, string $location): void
    {
        $implementation = is_string($extension) ? $extension : (is_object($extension) ? $extension::class : get_debug_type($extension));
        $identity = $this->legacyExtensionIdentity($extension, $implementation);
        $baseKey = $location . ':' . $identity;
        $occurrence = ($this->legacyReceiptOccurrences[$baseKey] ?? 0) + 1;
        $this->legacyReceiptOccurrences[$baseKey] = $occurrence;
        $identity .= $occurrence > 1 ? ':duplicate-' . $occurrence : '';
        $this->receipts()?->recordContribution(
            ExtensionContributionType::RenderHook,
            'hook:' . $location . ':' . $identity,
            $implementation,
            self::class,
            'frontend',
        );
    }

    private function legacyExtensionIdentity(mixed $extension, string $implementation): string
    {
        if (! $extension instanceof \Closure) {
            return $implementation;
        }

        $reflection = new ReflectionFunction($extension);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if (! is_string($file) || ! is_file($file) || $start === false || $end === false) {
            return 'closure:' . $implementation;
        }

        $lines = file($file);
        if ($lines === false) {
            return 'closure:' . $implementation;
        }

        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        $tokens = token_get_all('<?php ' . $source);
        $closureIndex = null;
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $next = $index + 1;
                while ($next < count($tokens) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    $next++;
                }

                if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    continue;
                }
            }

            $closureIndex = $index;
            break;
        }

        if ($closureIndex === null) {
            return 'closure:' . $implementation;
        }

        $start = $closureIndex;
        $previous = $closureIndex - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            $previous--;
        }
        if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_STATIC) {
            $start = $previous;
        }

        $normalised = '';
        $started = false;
        $closureType = $tokens[$closureIndex][0];
        $depth = 0;

        foreach (array_slice($tokens, $start, null, true) as $token) {
            $value = is_array($token) ? $token[1] : $token;
            $type = is_array($token) ? $token[0] : null;

            if (! $started) {
                if ($type !== T_FUNCTION && $type !== T_FN && $type !== T_STATIC) {
                    continue;
                }

                if ($type === T_STATIC) {
                    $normalised .= $value;
                    continue;
                }

                $started = true;
            }

            if ($type !== null && in_array($type, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $normalised .= $value;
            if ($closureType === T_FN && $value === '=>') {
                $depth = 1;
            } elseif ($closureType === T_FUNCTION && $value === '{') {
                $depth = 1;
            } elseif ($started && $value === '{') {
                $depth++;
            } elseif ($started && $value === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($closureType === T_FN && $depth === 1 && in_array($value, [';', ','], true)) {
                break;
            }
        }

        return 'closure:' . hash('sha256', $reflection->getClosureScopeClass()?->getName() . '|' . $normalised);
    }

    private function receipts(): ?RecordsExtensionContributionReceipt
    {
        return app()->bound(RecordsExtensionContributionReceipt::class)
            ? resolve(RecordsExtensionContributionReceipt::class)
            : null;
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

    private function renderAndRecordEntry(RenderHookEntryData $entry, RenderHookContext $context): mixed
    {
        $startedAt = microtime(true);
        $result = $this->renderEntry($entry, $context);

        if ($entry->owner === null) {
            return $result;
        }

        RecordManifestRenderContributionAction::run(
            packageName: $entry->owner,
            contributionType: 'render-hook',
            contributionClass: is_object($entry->extension) ? $entry->extension::class : null,
            elapsedMilliseconds: (microtime(true) - $startedAt) * 1000,
            cacheSafe: $entry->cacheSafe,
        );

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
