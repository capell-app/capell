<?php

declare(strict_types=1);

use Capell\Core\Concerns\HasCache;
use Capell\Core\Concerns\HasComponents;
use Capell\Core\Concerns\HasModelInterceptors;
use Capell\Core\Concerns\HasPackages;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\CapellCoreManager;

/**
 * @return array<string, array<string, string>>
 */
function capellCoreFacadeContract(): array
{
    return [
        HasPackages::class => [
            'arePackageRequirementsInstalled' => '(array $requirements): bool',
            'canInstallPackage' => '(string $name): bool',
            'canUninstallPackage' => '(string $name): bool',
            'clearExtensionCache' => '(): void',
            'clearPackages' => '(): void',
            'forcePackageInstalled' => '(string $name, bool $installed = true): void',
            'getDependentInstalledPackages' => '(string $name): Illuminate\\Support\\Collection',
            'getInstalledExtensionNames' => '(): array',
            'getInstalledPackages' => '(): Illuminate\\Support\\Collection',
            'getMissingRequirements' => '(string $name): array',
            'getPackage' => '(string $name): Capell\\Core\\Data\\PackageData',
            'getPackageRequirements' => '(string $name): array',
            'getPackages' => '(bool $withoutCore = true, bool $sortByDependencies = false): Illuminate\\Support\\Collection',
            'getPackagesGroupedByProductGroup' => '(?string $tier = null, bool $withoutCore = true): Illuminate\\Support\\Collection',
            'hasPackage' => '(string $name): bool',
            'isPackageAvailable' => '(string $name): bool',
            'isPackageEnabled' => '(string $name): bool',
            'isPackageInstalled' => '(string $name): bool',
            'markPackageDisabled' => '(string $name): void',
            'markPackageFailed' => '(string $name, string $message): void',
            'markPackageInstalled' => '(string $name): void',
            'markPackageInstalling' => '(string $name): void',
            'markPackageUninstalled' => '(string $name): void',
            'registerManifestPackage' => '(Capell\\Core\\Support\\Manifest\\CapellManifestData $manifest, ?string $version = null): static',
            'registerPackage' => '(string $name, ' . PackageTypeEnum::class . ' $type = ' . PackageTypeEnum::class . '::Plugin, ?string $serviceProviderClass = null, ?string $path = null, ?string $version = null, ?string $setting = null, array $permissions = [], Closure|string|null $description = null, ?string $setupCommand = null, array $setupParams = [], ?string $installCommand = null, array $installParams = [], bool $defaultSelected = false): static',
        ],
        HasCache::class => [
            'cacheExists' => '(string $key): bool',
            'flushCache' => '(): void',
            'flushLocalCache' => '(): void',
            'getFromCache' => '(string $key): mixed',
            'incrementCacheKey' => '(string $key): int',
            'invalidateCachePattern' => '(string $pattern): void',
            'registerCacheInvalidationPattern' => '(string $pattern): void',
            'rememberCache' => '(BackedEnum|string $key, Closure $callback, Closure|DateTimeInterface|DateInterval|int|null $ttl = null): mixed',
            'removeCacheKey' => '(string $key): void',
            'setToCache' => '(string $key, mixed $value, Closure|DateTimeInterface|DateInterval|int|null $ttl = null): void',
        ],
        HasModelInterceptors::class => [
            'createModel' => '(string $model, BackedEnum|array|string $key, callable $persist, string $interceptorInterface): object',
            'createOrUpdateModel' => '(string $model, BackedEnum|array|string $key, callable $persist, string $interceptorInterface): object',
            'getInterceptorsForModelAndKey' => '(string $model, BackedEnum|array|string|null $key): array',
            'mergeModelInterceptorData' => '(array $defaults, array $data): array',
            'registerModelInterceptor' => '(string $model, string $interceptorClass, BackedEnum|array|string|null $key = null, int $priority = 0): void',
            'replaceModelInterceptor' => '(string $model, string $oldInterceptorClass, string $newInterceptorClass, BackedEnum|array|string|null $key = null, int $priority = 0): void',
            'unregisterModelInterceptor' => '(string $model, string $interceptorClass, BackedEnum|array|string|null $key = null): void',
        ],
        HasComponents::class => [
            'cacheComponents' => '(): void',
            'clearCachedComponents' => '(): void',
            'discoverComponents' => '(string $in, ?string $for = null): static',
            'getComponent' => '(BackedEnum|string $type, string $name): string',
            'getComponentCachePath' => '(): string',
            'getComponentTypeFromDirectory' => 'static (string $directory): string',
            'getComponents' => '(BackedEnum|string|null $type = null): array',
            'getCoreComponents' => '(BackedEnum|string|null $type = null): array',
            'getDiscoverableComponents' => '(): array',
            'hasCachedComponents' => '(): bool',
            'hasComponent' => '(BackedEnum|string $type, string $name): bool',
            'registerComponent' => '(BackedEnum|string $type, BackedEnum|string $name, string $component): static',
            'registerComponents' => '(BackedEnum|string $type, array $components): static',
            'registerDiscoverableComponents' => '(string $in, ?string $for = null): static',
            'restoreCachedComponents' => '(): void',
        ],
    ];
}

function capellCoreFacadeMethodSignature(ReflectionMethod $method): string
{
    $parameters = array_map(
        static function (ReflectionParameter $parameter): string {
            $signature = sprintf('%s $%s', (string) $parameter->getType(), $parameter->getName());

            if (! $parameter->isDefaultValueAvailable()) {
                return $signature;
            }

            $default = $parameter->getDefaultValue();
            $formattedDefault = match (true) {
                $default instanceof UnitEnum => $default::class . '::' . $default->name,
                $default === null => 'null',
                $default === true => 'true',
                $default === false => 'false',
                $default === [] => '[]',
                is_int($default) => (string) $default,
                default => throw new LogicException(sprintf('Unsupported default value on %s.', $parameter->getName())),
            };

            return $signature . ' = ' . $formattedDefault;
        },
        $method->getParameters(),
    );

    return sprintf(
        '%s(%s): %s',
        $method->isStatic() ? 'static ' : '',
        implode(', ', $parameters),
        (string) $method->getReturnType(),
    );
}

it('pins the extension-facing package, cache, interceptor, and component facade contract', function (): void {
    $facadeRoot = CapellCore::getFacadeRoot();

    expect($facadeRoot)->toBeInstanceOf(CapellCoreManager::class);

    foreach (capellCoreFacadeContract() as $concern => $expectedContract) {
        $methods = new ReflectionClass($concern)->getMethods(ReflectionMethod::IS_PUBLIC);
        $actualContract = [];

        foreach ($methods as $method) {
            expect(method_exists($facadeRoot, $method->getName()))->toBeTrue();

            $facadeMethod = new ReflectionMethod($facadeRoot, $method->getName());
            $actualContract[$method->getName()] = capellCoreFacadeMethodSignature($facadeMethod);
        }

        ksort($actualContract);

        expect($actualContract)->toBe($expectedContract);
    }
});

it('keeps zero-argument manager construction and facade swapping compatible', function (): void {
    $reflection = new ReflectionClass(CapellCoreManager::class);
    $originalManager = CapellCore::getFacadeRoot();
    $replacementManager = new CapellCoreManager;

    expect($reflection->getConstructor())->toBeNull();

    CapellCore::swap($replacementManager);

    try {
        expect(CapellCore::getFacadeRoot())->toBe($replacementManager);
    } finally {
        CapellCore::swap($originalManager);
    }
});
