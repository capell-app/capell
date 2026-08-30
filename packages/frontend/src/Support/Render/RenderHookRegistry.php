<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use BackedEnum;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Exceptions\ExtensionContributionConflictException;
use Capell\Core\Support\Extensions\ExtensionOrderResolver;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Capell\Frontend\Actions\Performance\RecordManifestRenderContributionAction;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Data\RenderHookEntryData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use LogicException;
use ReflectionFunction;
use UnitEnum;

/**
 * @template T of RenderHookContext
 */
class RenderHookRegistry
{
    /** @var array<string, list<RenderHookEntryData>> */
    protected array $extensions = [];

    /** @var array<string, true> Stable keys already contributed, for dedupe. */
    protected array $contributedKeys = [];

    private bool $frozen = false;

    public function __construct(
        private readonly ?Container $container = null,
        private readonly ?ExtensionOrderResolver $orderResolver = null,
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
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner, $contribution->source);
        }

        $stableKey = $contribution->stableKey();

        foreach ($this->extensions[$contribution->location->value] ?? [] as $existing) {
            if ($existing->key !== $contribution->key) {
                continue;
            }

            if ($existing->owner === $contribution->owner
                && $existing->registrationType === $contribution->registrationType
                && $this->extensionsMatch($existing->extension, $contribution->extension)
                && $existing->priority === $contribution->priority
                && $existing->scenario === $contribution->scenario
                && $existing->target === $contribution->target
                && $existing->cacheSafe === $contribution->cacheSafe
                && $this->positionKey($existing->position) === $this->positionKey($contribution->position)) {
                return;
            }

            throw ExtensionContributionConflictException::duplicate(
                $contribution->key,
                (string) $existing->owner,
                $existing->source,
                $contribution->owner,
                $contribution->source,
            );
        }

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
            ->values();
        $extensions = collect(($this->orderResolver ?? new ExtensionOrderResolver)->resolve(
            $extensions->all(),
            static fn (RenderHookEntryData $entry, int $index): string => $entry->key ?? '__legacy:' . $index,
            static fn (RenderHookEntryData $entry): ExtensionPosition => $entry->position ?? ExtensionPosition::priority($entry->priority),
        ));

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

        $ordered = ($this->orderResolver ?? new ExtensionOrderResolver)->resolve(
            $this->extensions[$key],
            static fn (RenderHookEntryData $entry, int $index): string => $entry->key ?? '__legacy:' . $index,
            static fn (RenderHookEntryData $entry): ExtensionPosition => $entry->position ?? ExtensionPosition::priority($entry->priority),
        );

        return array_map(function (RenderHookEntryData $entry) {
            if ($entry->extension instanceof View) {
                return $entry->extension->render();
            }

            return $entry->extension;
        }, $ordered);
    }

    public function replaceContribution(RenderHookContributionData $contribution): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner, $contribution->source);
        }

        foreach ($this->extensions[$contribution->location->value] ?? [] as $index => $existing) {
            if ($existing->key !== $contribution->key) {
                continue;
            }

            array_splice(
                $this->extensions[$contribution->location->value],
                $index,
                1,
                [RenderHookEntryData::contribution($contribution)],
            );
            $this->contributedKeys[$contribution->stableKey()] = true;

            return;
        }

        throw new LogicException(sprintf('Cannot replace missing render hook key [%s].', $contribution->key));
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @return list<ExtensionOrderDiagnosticData> */
    public function orderingDiagnostics(RenderHookLocation $location): array
    {
        $key = $location->value;
        $resolver = $this->orderResolver ?? new ExtensionOrderResolver;
        $resolver->resolve(
            $this->extensions[$key] ?? [],
            static fn (RenderHookEntryData $entry, int $index): string => $entry->key ?? '__legacy:' . $index,
            static fn (RenderHookEntryData $entry): ExtensionPosition => $entry->position ?? ExtensionPosition::priority($entry->priority),
        );

        return $resolver->diagnostics();
    }

    private function addEntry(RenderHookEntryData $entry): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($entry->owner ?? 'unknown', self::class);
        }

        $key = $entry->location->value;
        $this->extensions[$key][] = $entry;
    }

    private function positionKey(?ExtensionPosition $position): string
    {
        if (! $position instanceof ExtensionPosition) {
            return '';
        }

        return implode(':', [$position->kind, (string) $position->priority, $position->anchor ?? '']);
    }

    private function extensionsMatch(mixed $existing, mixed $incoming): bool
    {
        if (! is_object($existing) || ! is_object($incoming)) {
            return $existing === $incoming;
        }

        return $this->canonicalValue($existing) === $this->canonicalValue($incoming);
    }

    /**
     * Build a value-based identity for extension objects without invoking
     * application code. Opaque values retain object identity so uncertain
     * payloads remain collisions instead of being silently deduplicated.
     *
     * @param  array<int, int>  $references
     */
    private function canonicalValue(mixed $value, array &$references = []): mixed
    {
        if ($value instanceof UnitEnum) {
            return [
                'enum' => $value::class,
                'value' => $value instanceof BackedEnum ? $value->value : $value->name,
            ];
        }

        if ($value instanceof Closure) {
            return ['opaque-closure' => spl_object_id($value)];
        }

        if (is_object($value)) {
            $objectId = spl_object_id($value);

            if (isset($references[$objectId])) {
                return ['reference' => $references[$objectId]];
            }

            $references[$objectId] = count($references);
            $properties = [];

            foreach (get_mangled_object_vars($value) as $property => $item) {
                $properties[$property] = $this->canonicalValue($item, $references);
            }

            ksort($properties);

            return [
                'object' => $value::class,
                'properties' => $properties,
            ];
        }

        if (is_array($value)) {
            $canonical = [];

            foreach ($value as $key => $item) {
                $canonical[$key] = $this->canonicalValue($item, $references);
            }

            return $canonical;
        }

        if (is_resource($value)) {
            return [
                'opaque-resource' => get_resource_type($value),
                'identity' => get_resource_id($value),
            ];
        }

        return $value;
    }

    private function receipt(mixed $extension, string $location): void
    {
        $implementation = is_string($extension) ? $extension : (get_debug_type($extension));
        $identity = $this->legacyExtensionIdentity($extension, $implementation);
        $this->receipts()?->recordContribution(
            ExtensionContributionType::RenderHook,
            'legacy-hook:' . hash('sha256', $location . ':' . $identity),
            $implementation,
            self::class,
            'frontend',
        );
    }

    private function legacyExtensionIdentity(mixed $extension, string $implementation): string
    {
        if (! $extension instanceof Closure) {
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
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_FUNCTION, T_FN], true)) {
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
                if (! in_array($type, [T_FUNCTION, T_FN, T_STATIC], true)) {
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
            } elseif ($value === '{') {
                $depth++;
            } elseif ($value === '}') {
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
