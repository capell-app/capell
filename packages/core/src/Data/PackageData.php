<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use BackedEnum;
use Capell\Core\Data\Manifest\ExtensionPerformanceBudgetData;
use Capell\Core\Data\Manifest\ExtensionProviderData;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Closure;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelData\Data;

class PackageData extends Data
{
    private ?ManifestData $manifestData = null;

    private bool $manifestLoaded = false;

    private ?CapellManifestData $capellManifestData = null;

    private bool $capellManifestLoaded = false;

    private ?Closure $descriptionResolver = null;

    public function __construct(
        public string $name,
        public PackageTypeEnum $type,
        /** @var list<PackageScopeEnum> */
        public array $scopes = [],
        /** @var class-string<ServiceProvider> */
        public ?string $serviceProviderClass = null,
        public ?string $path = null,
        public null|string|BackedEnum $icon = null,
        public ?string $shortName = null,
        public ?string $key = null,
        public ?string $description = null,
        public int $sort = 0,
        /** @var list<string> */
        public array $permissions = [],
        public ?string $installCommand = null,
        /** @var class-string|null */
        public ?string $installAction = null,
        /** @var list<string> */
        public array $installParams = [],
        public ?string $setupCommand = null,
        /** @var class-string|null */
        public ?string $setupAction = null,
        /** @var list<string> */
        public array $setupParams = [],
        public ?string $demoCommand = null,
        /** @var list<string> */
        public array $demoParams = [],
        public ?string $fakerCommand = null,
        /** @var list<string> */
        public array $fakerParams = [],
        public ?string $upgradeCommand = null,
        /* @param class-string<\Spatie\LaravelSettings\Settings>|null $setting */
        public ?string $setting = null,
        public ?string $version = null,
        public ?string $url = null,
        public ?string $author = null,
        public ?bool $installed = null,
        /** @var list<string>|null */
        public ?array $requirements = null,
        public ?string $productGroup = null,
        public ?string $tier = null,
        public ?string $bundle = null,
        public ?bool $core = null,
        public ?bool $defaultSelected = null,
        public ?bool $demo = null,
        public ?string $afterInstallCommand = null,
        /** @var class-string|null */
        public ?string $afterInstallAction = null,
        /** @var list<string> */
        public array $afterInstallParams = [],
        public ?string $kind = null,
        public ?string $themeKey = null,
        public ?string $extendsPackage = null,
        public ?string $previewImageUrl = null,
        /** @var list<string> */
        public array $supportingPackages = [],
        /** @var list<string> */
        public array $conflicts = [],
        public int $contributionCount = 0,
        public ?ExtensionPerformanceBudgetData $performanceBudget = null,
        public ?string $proposedLicense = null,
        public ?string $requestedCertificationStatus = null,
        public ?string $supportPolicy = null,
        public bool $privateDocsRequested = false,
        public bool $hiddenFromMarketplace = false,
        public ?string $effectiveMarketplaceStatus = null,
        public ?string $slug = null,
        public string $visibility = 'catalogue',
        public ?string $documentationUrl = null,
    ) {
        if ($this->key === null) {
            $this->key = strtolower((string) $this->shortName);
        }

        if ($this->author === null) {
            $parts = explode('/', $this->name);
            $this->author = count($parts) === 2 ? ucfirst(explode('-', $parts[0])[0]) : 'Unknown';
        }
    }

    public function getSort(): int
    {
        if ($this->sort !== 0) {
            return $this->sort;
        }

        $manifest = $this->manifest();

        if ($manifest instanceof ManifestData) {
            return $manifest->order;
        }

        $capellManifest = $this->capellManifest();

        return $capellManifest instanceof CapellManifestData ? $capellManifest->order : 0;
    }

    public function getShortName(): string
    {
        if ($this->shortName !== null) {
            return $this->shortName;
        }

        $manifest = $this->manifest();

        return $manifest instanceof ManifestData
            ? $manifest->label
            : str($this->name)->afterLast('/')->replace('-', ' ')->title()->toString();
    }

    public function setDescriptionResolver(Closure $resolver): static
    {
        $this->descriptionResolver = $resolver;

        return $this;
    }

    public function getDescription(): ?string
    {
        if ($this->descriptionResolver instanceof Closure) {
            return ($this->descriptionResolver)();
        }

        if ($this->description !== null) {
            return $this->description;
        }

        $manifest = $this->manifest();

        if ($manifest instanceof ManifestData && $manifest->description !== null) {
            return $manifest->description;
        }

        return $this->capellManifest()?->description;
    }

    public function getIcon(): null|string|BackedEnum
    {
        return $this->icon ?? $this->manifest()?->icon;
    }

    public function getUrl(): ?string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        $manifest = $this->manifest();

        return $manifest instanceof ManifestData && $manifest->url !== null
            ? $manifest->url
            : 'https://github.com/' . $this->name;
    }

    /**
     * The package's non-technical admin documentation URL, declared in the
     * manifest (`documentationUrl`) or composer.json (`support.docs`).
     */
    public function getDocumentationUrl(): ?string
    {
        if ($this->documentationUrl !== null) {
            return $this->documentationUrl;
        }

        return $this->capellManifest()?->documentationUrl;
    }

    /**
     * @return list<PackageScopeEnum>
     */
    public function getScopes(): array
    {
        if ($this->scopes !== []) {
            return $this->scopes;
        }

        $manifest = $this->manifest();

        if ($manifest instanceof ManifestData && $manifest->scopes !== []) {
            return array_values($manifest->scopes);
        }

        $capellManifest = $this->capellManifest();

        return $capellManifest instanceof CapellManifestData
            ? array_values(array_map(PackageScopeEnum::from(...), $capellManifest->scopes))
            : [];
    }

    /**
     * @return list<string>
     */
    public function getRequirements(): array
    {
        if ($this->requirements !== null) {
            return $this->requirements;
        }

        $manifest = $this->manifest();

        if ($manifest instanceof ManifestData) {
            return array_values($manifest->dependsOn);
        }

        $capellManifest = $this->capellManifest();

        return $capellManifest instanceof CapellManifestData ? array_values($capellManifest->requires) : [];
    }

    /**
     * @return list<string>
     */
    public function getSupportingPackages(): array
    {
        if ($this->supportingPackages !== []) {
            return $this->supportingPackages;
        }

        $capellManifest = $this->capellManifest();

        return $capellManifest instanceof CapellManifestData ? $capellManifest->supports : [];
    }

    /**
     * @return list<class-string>
     */
    public function getProviderClasses(?string $context = null): array
    {
        $providers = $this->capellManifest()?->providers;

        if (! $providers instanceof ExtensionProviderData) {
            return [];
        }

        if ($context !== null) {
            return array_values(array_filter(
                $providers[$context] ?? [],
                class_exists(...),
            ));
        }

        return array_values(array_filter(
            $providers->all(),
            class_exists(...),
        ));
    }

    public function getProductGroup(): string
    {
        if ($this->productGroup !== null) {
            return $this->productGroup;
        }

        $manifest = $this->capellManifest();

        return $manifest instanceof CapellManifestData ? $manifest->productGroup : 'Uncategorised';
    }

    public function getTier(): string
    {
        if ($this->tier !== null) {
            return $this->tier;
        }

        $manifest = $this->capellManifest();

        return $manifest instanceof CapellManifestData ? $manifest->tier : 'free';
    }

    public function getBundle(): ?string
    {
        if ($this->bundle !== null) {
            return $this->bundle;
        }

        return $this->capellManifest()?->bundle;
    }

    public function getKind(): string
    {
        if ($this->kind !== null) {
            return $this->kind;
        }

        $manifest = $this->capellManifest();

        return $manifest instanceof CapellManifestData ? $manifest->kind : $this->type->value;
    }

    public function getThemeKey(): ?string
    {
        $manifest = $this->capellManifest();

        if ($manifest instanceof CapellManifestData && $manifest->kind === 'theme') {
            return ThemeManifestKey::resolve($manifest);
        }

        if ($this->type !== PackageTypeEnum::Theme && $this->kind !== 'theme') {
            return null;
        }

        return $this->themeKey !== null && $this->themeKey !== ''
            ? $this->themeKey
            : ThemeManifestKey::fromPackageName($this->name);
    }

    public function getExtendsPackage(): ?string
    {
        return $this->extendsPackage ?? $this->capellManifest()?->extends;
    }

    public function getPreviewImageUrl(): ?string
    {
        return $this->previewImageUrl;
    }

    public function isCore(): bool
    {
        return TrustedCorePackages::contains($this->name);
    }

    public function isSupportPackage(): bool
    {
        return $this->visibility === 'support' || $this->capellManifest()?->visibility === 'support';
    }

    public function isVisibleInCatalogue(): bool
    {
        return ! $this->isSupportPackage();
    }

    public function isHiddenFromMarketplace(): bool
    {
        return $this->hiddenFromMarketplace || $this->capellManifest()?->marketplaceHidden === true;
    }

    public function isDemo(): bool
    {
        if ($this->demo === true) {
            return true;
        }

        $manifest = $this->manifest();

        if ($manifest instanceof ManifestData && $manifest->demo) {
            return true;
        }

        $capellManifest = $this->capellManifest();

        if ($capellManifest instanceof CapellManifestData && $capellManifest->demo) {
            return true;
        }

        return $this->getDemoCommand() !== null;
    }

    public function getInstallCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->installCommand, 'install');
    }

    public function getInstallAction(): ?string
    {
        return $this->actionWithManifestFallback($this->installAction, 'install');
    }

    /**
     * @return list<string>
     */
    public function getInstallParams(): array
    {
        return $this->paramsWithManifestFallback($this->installParams, 'install');
    }

    public function getSetupCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->setupCommand, 'setup');
    }

    public function getSetupAction(): ?string
    {
        return $this->actionWithManifestFallback($this->setupAction, 'setup');
    }

    /**
     * @return list<string>
     */
    public function getSetupParams(): array
    {
        return $this->paramsWithManifestFallback($this->setupParams, 'setup');
    }

    public function getAfterInstallCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->afterInstallCommand, 'afterInstall');
    }

    public function getAfterInstallAction(): ?string
    {
        return $this->actionWithManifestFallback($this->afterInstallAction, 'afterInstall');
    }

    /**
     * @return list<string>
     */
    public function getAfterInstallParams(): array
    {
        return $this->paramsWithManifestFallback($this->afterInstallParams, 'afterInstall');
    }

    public function getUpgradeCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->upgradeCommand, 'upgrade');
    }

    public function getDemoCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->demoCommand, 'demo');
    }

    /**
     * @return list<string>
     */
    public function getDemoParams(): array
    {
        return $this->paramsWithManifestFallback($this->demoParams, 'demo');
    }

    public function getDoctorCommand(): ?string
    {
        return $this->command('doctor');
    }

    public function getFakerCommand(): ?string
    {
        return $this->commandWithManifestFallback($this->fakerCommand, 'faker');
    }

    /**
     * @return list<string>
     */
    public function getFakerParams(): array
    {
        return $this->paramsWithManifestFallback($this->fakerParams, 'faker');
    }

    public function getLabel(): string
    {
        return $this->shortName
            ?? str($this->name)->afterLast('/')->replace('-', ' ')->title()->toString();
    }

    public function isInstalled(): bool
    {
        return CapellCore::isPackageInstalled($this->name);
    }

    public function setInstalled(bool $installed = true): void
    {
        $this->installed = $installed;
    }

    public function hasFrontendScope(): bool
    {
        return in_array(PackageScopeEnum::Frontend, $this->getScopes(), true);
    }

    public function hasBackendScope(): bool
    {
        return in_array(PackageScopeEnum::Backend, $this->getScopes(), true);
    }

    private function manifest(): ?ManifestData
    {
        if (! $this->manifestLoaded) {
            $this->manifestLoaded = true;

            if ($this->path !== null) {
                $manifestPath = $this->path . '/capell.json';
                $this->manifestData = is_file($manifestPath) ? ManifestData::fromFile($manifestPath) : null;
            }
        }

        return $this->manifestData;
    }

    private function capellManifest(): ?CapellManifestData
    {
        if (! $this->capellManifestLoaded) {
            $this->capellManifestLoaded = true;

            if ($this->path !== null) {
                $manifestPath = $this->path . '/capell.json';

                if (is_file($manifestPath)) {
                    $contents = file_get_contents($manifestPath);

                    if ($contents !== false) {
                        /** @var array<string, mixed>|null $decoded */
                        $decoded = json_decode($contents, true);

                        if (is_array($decoded) && ($decoded['manifest-version'] ?? null) === 3) {
                            $this->capellManifestData = CapellManifestData::fromArray($decoded, $this->path);
                        }
                    }
                }
            }
        }

        return $this->capellManifestData;
    }

    private function command(string $key): ?string
    {
        $command = $this->capellManifest()?->commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    private function action(string $key): ?string
    {
        $action = $this->capellManifest()?->actions[$key] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    /**
     * @return list<string>
     */
    private function commandParams(string $key): array
    {
        $params = $this->capellManifest()?->commands[$key] ?? [];

        return is_array($params) ? array_values($params) : [];
    }

    private function commandWithManifestFallback(?string $configuredCommand, string $key): ?string
    {
        if ($configuredCommand !== null) {
            return $configuredCommand;
        }

        return $this->legacyManifestCommand($key) ?? $this->command($key);
    }

    private function actionWithManifestFallback(?string $configuredAction, string $key): ?string
    {
        if ($configuredAction !== null) {
            return $configuredAction;
        }

        return $this->action($key);
    }

    /**
     * @param  list<string>  $configuredParams
     * @return list<string>
     */
    private function paramsWithManifestFallback(array $configuredParams, string $key): array
    {
        if ($configuredParams !== []) {
            return $configuredParams;
        }

        $legacyParams = $this->legacyManifestParams($key);

        return $legacyParams !== [] ? $legacyParams : $this->commandParams($key . 'Params');
    }

    private function legacyManifestCommand(string $key): ?string
    {
        $manifest = $this->manifest();

        if (! $manifest instanceof ManifestData) {
            return null;
        }

        return match ($key) {
            'install' => $manifest->installCommand,
            'setup' => $manifest->setupCommand,
            'afterInstall' => $manifest->afterInstallCommand,
            'upgrade' => $manifest->upgradeCommand,
            'demo' => $manifest->demoCommand,
            'faker' => $manifest->fakerCommand,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function legacyManifestParams(string $key): array
    {
        $manifest = $this->manifest();

        if (! $manifest instanceof ManifestData) {
            return [];
        }

        return array_values(match ($key) {
            'setup' => $manifest->setupParams,
            'afterInstall' => $manifest->afterInstallParams,
            'demo' => $manifest->demoParams,
            'faker' => $manifest->fakerParams,
            default => [],
        });
    }
}
