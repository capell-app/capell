<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Enums\ExtensionContributionType;
use Closure;

final class ExtensionContributionReceiptRegistry implements RecordsExtensionContributionReceipt
{
    /** @var list<ExtensionContributionReceiptData> */
    private array $receipts = [];

    /** @var list<ExtensionContributionReceiptContext> */
    private array $contexts = [];

    /** @var array<class-string, list<ExtensionContributionReceiptContext>> */
    private array $providerContexts = [];

    /** @var array<string, array<string, true>> */
    private array $loadedContexts = [];

    /** @var array<string, list<ExtensionContributionReceiptContext>> */
    private array $namespaceContexts = [];

    public function rememberProviderContext(string $provider, ExtensionContributionReceiptContext $context): void
    {
        $this->providerContexts[$provider] ??= [];
        foreach ($this->providerContexts[$provider] as $existing) {
            if ($existing == $context) {
                $this->loadedContexts[$context->ownerPackage][$context->providerBucket] = true;

                return;
            }
        }
        $this->providerContexts[$provider][] = $context;
        $this->loadedContexts[$context->ownerPackage][$context->providerBucket] = true;
    }

    public function providerContext(string $provider): ?ExtensionContributionReceiptContext
    {
        return $this->providerContexts[$provider][0] ?? null;
    }

    /** @return list<ExtensionContributionReceiptContext> */
    public function providerContexts(string $provider): array
    {
        return $this->providerContexts[$provider] ?? [];
    }

    public function rememberNamespaceContext(string $namespace, ExtensionContributionReceiptContext $context): void
    {
        $namespace = rtrim($namespace, '\\') . '\\';
        if ($namespace !== '\\') {
            $this->namespaceContexts[$namespace] ??= [];
            foreach ($this->namespaceContexts[$namespace] as $existing) {
                if ($existing == $context) {
                    return;
                }
            }

            $this->namespaceContexts[$namespace][] = $context;
        }
    }

    /**
     * @template TReturn
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withContext(ExtensionContributionReceiptContext $context, Closure $callback): mixed
    {
        return $this->withContexts([$context], $callback);
    }

    /**
     * @template TReturn
     * @param  list<ExtensionContributionReceiptContext>  $contexts
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withContexts(array $contexts, Closure $callback): mixed
    {
        $previous = $this->contexts;
        $this->contexts = $contexts;

        try {
            return $callback();
        } finally {
            $this->contexts = $previous;
        }
    }

    public function record(ExtensionContributionReceiptData $receipt): void
    {
        foreach ($this->receipts as $existing) {
            if ($existing->toArray() === $receipt->toArray()) {
                return;
            }
        }

        $this->receipts[] = $receipt;
    }

    public function recordFromContext(
        ExtensionContributionType $type,
        string $key,
        string $implementation,
        ?string $sourceClass = null,
        ?string $providerBucket = null,
    ): void {
        $contexts = $this->contexts !== [] ? $this->contexts : $this->bootProviderContexts();
        $contexts = $this->contextsForBucket($contexts, $providerBucket);
        $contexts = $contexts !== [] ? $contexts : [$this->fallbackContext($sourceClass ?? self::class)];

        $this->recordForContexts($type, $key, $implementation, $sourceClass, $contexts);
    }

    public function recordFromSourceContext(
        ExtensionContributionType $type,
        string $key,
        string $implementation,
        string $sourceClass,
        ?string $providerBucket = null,
    ): void {
        $mappedContexts = $this->contextForClass($sourceClass, $providerBucket);
        $contexts = $mappedContexts ?? [];
        if ($mappedContexts === null) {
            $contexts = $this->contexts !== [] ? $this->contexts : $this->bootProviderContexts();
        }
        $contexts = $this->contextsForBucket($contexts, $providerBucket);
        $contexts = $contexts !== [] ? $contexts : [$this->fallbackContext($sourceClass)];

        $this->recordForContexts($type, $key, $implementation, $sourceClass, $contexts);
    }

    /** @param list<ExtensionContributionReceiptContext> $contexts */
    private function recordForContexts(
        ExtensionContributionType $type,
        string $key,
        string $implementation,
        ?string $sourceClass,
        array $contexts,
    ): void {
        $context = array_values($contexts)[0];

        $this->record(new ExtensionContributionReceiptData(
            ownerPackage: $context->ownerPackage,
            providerBucket: $context->providerBucket,
            type: $type,
            key: $key,
            implementation: $implementation,
            sourceClass: $sourceClass ?? $context->sourceClass,
            foundationBuiltIn: $context->foundationBuiltIn,
        ));
    }

    /** @param list<ExtensionContributionReceiptContext> $contexts */
    private function contextsForBucket(array $contexts, ?string $providerBucket): array
    {
        if ($providerBucket === null || $contexts === []) {
            return $contexts;
        }

        $matching = array_values(array_filter(
            $contexts,
            static function (ExtensionContributionReceiptContext $context) use ($providerBucket): bool {
                return $context->providerBucket === $providerBucket;
            },
        ));

        return $matching !== [] ? $matching : $contexts;
    }

    /** @return list<ExtensionContributionReceiptData> */
    public function all(): array
    {
        return $this->receipts;
    }

    /** @return list<ExtensionContributionReceiptData> */
    public function forPackage(string $package): array
    {
        return array_values(array_filter(
            $this->receipts,
            static fn (ExtensionContributionReceiptData $receipt): bool => $receipt->ownerPackage === $package,
        ));
    }

    /** @return list<string> */
    public function loadedBuckets(string $package): array
    {
        return array_keys($this->loadedContexts[$package] ?? []);
    }

    public function clear(): void
    {
        $this->receipts = [];
        $this->contexts = [];
        $this->providerContexts = [];
        $this->loadedContexts = [];
        $this->namespaceContexts = [];
    }

    /** @return list<ExtensionContributionReceiptContext> */
    private function bootProviderContexts(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object !== null && isset($this->providerContexts[$object::class])) {
                return $this->providerContexts[$object::class];
            }

            $class = $frame['class'] ?? null;
            if (is_string($class) && isset($this->providerContexts[$class])) {
                return $this->providerContexts[$class];
            }
        }

        return [];
    }

    /** @return list<ExtensionContributionReceiptContext>|null */
    private function contextForClass(string $class, ?string $providerBucket = null): ?array
    {
        $matchedNamespace = null;
        foreach ($this->namespaceContexts as $namespace => $contexts) {
            if (str_starts_with($class, $namespace)) {
                if ($matchedNamespace === null || strlen($namespace) > strlen($matchedNamespace)) {
                    $matchedNamespace = $namespace;
                }
            }
        }

        if ($matchedNamespace === null) {
            return null;
        }

        $contexts = $this->namespaceContexts[$matchedNamespace];

        return $this->contextsForBucket($contexts, $providerBucket);
    }

    private function fallbackContext(string $sourceClass): ExtensionContributionReceiptContext
    {
        foreach ([
            'Capell\\Core\\' => ['capell-app/core', 'runtime'],
            'Capell\\Admin\\' => ['capell-app/admin', 'admin'],
            'Capell\\Frontend\\' => ['capell-app/frontend', 'frontend'],
            'Capell\\Installer\\' => ['capell-app/installer', 'install'],
            'Capell\\Marketplace\\' => ['capell-app/marketplace', 'admin'],
        ] as $namespace => [$package, $bucket]) {
            if (str_starts_with($sourceClass, $namespace)) {
                return ExtensionContributionReceiptContext::foundation($package, $bucket, $sourceClass);
            }
        }

        return ExtensionContributionReceiptContext::forPackage('unknown', 'unknown', $sourceClass);
    }
}
