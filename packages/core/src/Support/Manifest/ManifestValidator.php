<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAdminResource;
use Capell\Core\Contracts\Extensions\RegistersExtensionAsset;
use Capell\Core\Contracts\Extensions\RegistersExtensionContentWidget;
use Capell\Core\Contracts\Extensions\RegistersExtensionFilamentWidget;
use Capell\Core\Contracts\Extensions\RegistersExtensionFrontendComponent;
use Capell\Core\Contracts\Extensions\RegistersExtensionPageType;
use Capell\Core\Contracts\Extensions\RegistersExtensionPermission;
use Capell\Core\Contracts\Extensions\RegistersExtensionRenderHook;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionSection;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;
use Capell\Core\Contracts\Extensions\RunsExtensionMigration;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\ExtensionManifestVersion;
use Capell\Core\Enums\ExtensionSurface;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\Validation\CommercialSectionValidator;
use Capell\Core\Support\Manifest\Validation\ManifestValidationRules;
use Capell\Core\Support\Manifest\Validation\MarketplaceSectionValidator;
use Capell\Core\Support\Manifest\Validation\PerformanceSectionValidator;
use Capell\Core\Support\Manifest\Validation\SecuritySectionValidator;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\ServiceProvider;

final class ManifestValidator
{
    /** @var list<string> */
    private const array VALID_KINDS = ['package', 'plugin', 'theme', 'integration', 'bundle'];

    /** @var list<string> */
    private const array VALID_VISIBILITIES = ['catalogue', 'support'];

    /** @var list<string> */
    private const array REQUIRED_PROVIDER_BUCKETS = ['metadata', 'install', 'runtime', 'admin', 'frontend'];

    /** @var list<string> */
    private const array VALID_PROVIDER_BUCKETS = ['metadata', 'install', 'runtime', 'auth', 'admin', 'frontend'];

    /** @var list<string> */
    private const array TRUSTED_PLATFORM_NAMESPACE_PREFIXES = [
        'Capell\\Admin\\',
        'Capell\\Core\\',
        'Capell\\Frontend\\',
        'Capell\\Installer\\',
        'Capell\\Marketplace\\',
    ];

    /** @var list<string> */
    private const array VALID_ROOT_FIELDS = [
        'manifest-version',
        'name',
        'slug',
        'displayName',
        'kind',
        'capellApiVersion',
        'version',
        'description',
        'product',
        'namespace',
        'surfaces',
        'dependencies',
        'providers',
        'contributes',
        'contributionTraceability',
        'database',
        'commands',
        'actions',
        'settings',
        'permissions',
        'capabilities',
        'security',
        'performance',
        'healthChecks',
        'commercial',
        'marketplace',
        'scopes',
        'extends',
        'themeKey',
        'runtime',
        'defaultSelected',
        'demo',
        'order',
        'installPath',
        'visibility',
        'documentationUrl',
    ];

    private readonly ManifestValidationRules $rules;

    /** @var list<ManifestSectionValidator> */
    private readonly array $sectionValidators;

    public function __construct()
    {
        $this->rules = new ManifestValidationRules;
        $this->sectionValidators = [
            new SecuritySectionValidator($this->rules),
            new PerformanceSectionValidator($this->rules),
            new CommercialSectionValidator,
            new MarketplaceSectionValidator($this->rules),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $composerJson
     */
    public function validate(
        array $data,
        ?array $composerJson = null,
        ?string $packageName = null,
        ?string $discoverySource = null,
    ): void {
        if (($data['manifest-version'] ?? null) !== ExtensionManifestVersion::V3->value) {
            throw InvalidManifestException::missingField('manifest-version 3');
        }

        if (array_key_exists('capell-version', $data)) {
            throw InvalidManifestException::invalidField('capell-version', 'manifest v3 uses capellApiVersion');
        }

        foreach (array_keys($data) as $field) {
            if (! in_array($field, self::VALID_ROOT_FIELDS, strict: true)) {
                throw InvalidManifestException::invalidField($field, 'is not part of manifest v3');
            }
        }

        $this->rules->requiredStrings($data, [
            'name',
            'slug',
            'displayName',
            'kind',
            'capellApiVersion',
            'version',
        ]);

        if (! in_array($packageName, [null, '', $data['name']], true)) {
            throw InvalidManifestException::packageNameMismatch(
                composerName: $packageName,
                manifestName: (string) $data['name'],
                source: $discoverySource ?? 'unknown source',
            );
        }

        if (! in_array($data['kind'], self::VALID_KINDS, strict: true)) {
            throw InvalidManifestException::invalidKind((string) $data['kind']);
        }

        $visibility = (string) ($data['visibility'] ?? 'catalogue');
        if (! in_array($visibility, self::VALID_VISIBILITIES, strict: true)) {
            throw InvalidManifestException::invalidField('visibility', 'must be one of: ' . implode(', ', self::VALID_VISIBILITIES));
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', (string) $data['slug'])) {
            throw InvalidManifestException::invalidField('slug', 'must use lowercase letters, numbers, and hyphens');
        }

        $this->validateProduct($data);
        $this->validateDependencies($data);
        $this->validateProviders($data);
        $this->rules->stringList($data, 'surfaces');
        $this->rules->stringList($data, 'settings', required: false);
        $this->rules->stringList($data, 'permissions', required: false);
        $this->rules->stringList($data, 'capabilities', required: false);

        foreach ($data['surfaces'] as $surface) {
            if (! in_array($surface, ExtensionSurface::values(), strict: true)) {
                throw InvalidManifestException::invalidContext((string) $surface);
            }
        }

        $namespacePrefixes = $this->composerNamespacePrefixes($composerJson);
        $isTrustedCore = TrustedCorePackages::contains((string) $data['name']);

        $this->validateClassList($data['providers'], $namespacePrefixes, $isTrustedCore, ServiceProvider::class);
        $this->validateContributions($data, $namespacePrefixes, $isTrustedCore);
        $this->sectionValidators[0]->validate($data);
        $this->sectionValidators[1]->validate($data);
        $this->validateHealthChecks($data, $namespacePrefixes, $isTrustedCore);
        $this->sectionValidators[2]->validate($data);
        $this->sectionValidators[3]->validate($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    /** @param array<string, mixed> $data */
    private function validateProduct(array $data): void
    {
        if (! is_array($data['product'] ?? null)) {
            throw InvalidManifestException::missingField('product');
        }

        $this->rules->requiredStrings($data['product'], ['group', 'tier']);
    }

    /** @param array<string, mixed> $data */
    private function validateDependencies(array $data): void
    {
        if (! is_array($data['dependencies'] ?? null)) {
            throw InvalidManifestException::missingField('dependencies');
        }

        foreach (['requires', 'supports', 'conflicts'] as $field) {
            $this->rules->stringList($data['dependencies'], $field);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateProviders(array $data): void
    {
        if (! is_array($data['providers'] ?? null)) {
            throw InvalidManifestException::missingField('providers');
        }

        $buckets = array_keys($data['providers']);

        $unexpected = array_values(array_diff($buckets, self::VALID_PROVIDER_BUCKETS));
        $missing = array_values(array_diff(self::REQUIRED_PROVIDER_BUCKETS, $buckets));

        if ($unexpected !== [] || $missing !== []) {
            $context = $unexpected[0] ?? $missing[0] ?? 'providers';

            throw InvalidManifestException::invalidContext((string) $context);
        }

        foreach (self::VALID_PROVIDER_BUCKETS as $bucket) {
            $this->rules->stringList(
                $data['providers'],
                $bucket,
                required: in_array($bucket, self::REQUIRED_PROVIDER_BUCKETS, true),
            );
        }
    }

    /**
     * @param  array<string, list<class-string>>  $providers
     * @param  list<string>  $namespacePrefixes
     */
    private function validateClassList(
        array $providers,
        array $namespacePrefixes,
        bool $isTrustedCore,
        string $expectedType,
    ): void {
        foreach ($providers as $classes) {
            foreach ($classes as $class) {
                $this->validateClass($class, $namespacePrefixes, $isTrustedCore, $expectedType);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $namespacePrefixes
     */
    private function validateContributions(array $data, array $namespacePrefixes, bool $isTrustedCore): void
    {
        if (! is_array($data['contributes'] ?? null) || ! array_is_list($data['contributes'])) {
            throw InvalidManifestException::missingField('contributes');
        }

        $contentWidgetKeys = [];

        foreach ($data['contributes'] as $index => $contribution) {
            if (! is_array($contribution)) {
                throw InvalidManifestException::invalidField('contributes.' . $index, 'must be an object');
            }

            $type = ExtensionContributionType::tryFrom((string) ($contribution['type'] ?? ''));

            if (! $type instanceof ExtensionContributionType) {
                throw InvalidManifestException::invalidField(sprintf('contributes.%d.type', $index), (string) ($contribution['type'] ?? 'missing'));
            }

            $class = $contribution['class'] ?? null;

            if (! is_string($class) || $class === '') {
                throw InvalidManifestException::missingField(sprintf('contributes.%d.class', $index));
            }

            $this->validateClass($class, $namespacePrefixes, $isTrustedCore, $this->expectedContributionContract($type));

            if ($type === ExtensionContributionType::ContentWidget) {
                $key = $contribution['key'] ?? null;
                $packageVendor = str((string) $data['name'])->before('/')->toString();

                if (! is_string($key) || $key === '') {
                    throw InvalidManifestException::missingField(sprintf('contributes.%d.key', $index));
                }

                if (! preg_match('/^[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9.-]*$/', $key)
                    || ! str_starts_with($key, $packageVendor . '.')) {
                    throw InvalidManifestException::invalidField(
                        sprintf('contributes.%d.key', $index),
                        sprintf('must be package-prefixed with "%s."', $packageVendor),
                    );
                }

                if (in_array($key, $contentWidgetKeys, true)) {
                    throw InvalidManifestException::invalidField(
                        sprintf('contributes.%d.key', $index),
                        sprintf('duplicates content widget key "%s"', $key),
                    );
                }

                $contentWidgetKeys[] = $key;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $namespacePrefixes
     */
    private function validateHealthChecks(array $data, array $namespacePrefixes, bool $isTrustedCore): void
    {
        if (! is_array($data['healthChecks'] ?? null) || ! array_is_list($data['healthChecks'])) {
            throw InvalidManifestException::missingField('healthChecks');
        }

        foreach ($data['healthChecks'] as $index => $healthCheck) {
            if (! is_array($healthCheck)) {
                throw InvalidManifestException::invalidField('healthChecks.' . $index, 'must be an object');
            }

            $this->rules->requiredStrings($healthCheck, ['key', 'label', 'class']);
            $this->validateClass((string) $healthCheck['class'], $namespacePrefixes, $isTrustedCore, ChecksExtensionHealth::class);
        }
    }

    /**
     * @param  array<string, mixed>|null  $composerJson
     * @return list<string>
     */
    private function composerNamespacePrefixes(?array $composerJson): array
    {
        $composerJson ??= [];

        $autoload = is_array($composerJson['autoload']['psr-4'] ?? null) ? $composerJson['autoload']['psr-4'] : [];
        $autoloadDev = is_array($composerJson['autoload-dev']['psr-4'] ?? null) ? $composerJson['autoload-dev']['psr-4'] : [];
        $prefixes = array_keys([...$autoload, ...$autoloadDev]);

        return array_values(array_unique(array_filter(
            array_map(static fn (string $prefix): string => rtrim($prefix, '\\') . '\\', $prefixes),
            static fn (string $prefix): bool => $prefix !== '\\',
        )));
    }

    /**
     * @param  list<string>  $namespacePrefixes
     */
    private function validateClass(string $class, array $namespacePrefixes, bool $isTrustedCore, string $expectedType): void
    {
        if ($namespacePrefixes === [] || ! $this->classIsInNamespace($class, $namespacePrefixes)) {
            throw InvalidManifestException::invalidField('class', $class . ' is outside the package Composer PSR-4 namespace');
        }

        if (! $isTrustedCore && $this->classIsInNamespace($class, self::TRUSTED_PLATFORM_NAMESPACE_PREFIXES)) {
            throw InvalidManifestException::invalidField('class', $class . ' spoofs a trusted Capell platform namespace');
        }

        if (! class_exists($class) && ! interface_exists($class)) {
            throw InvalidManifestException::invalidField('class', $class . ' cannot be resolved');
        }

        if (! is_a($class, $expectedType, true)) {
            throw InvalidManifestException::invalidField('class', sprintf('%s must implement or extend %s', $class, $expectedType));
        }
    }

    /**
     * @param  list<string>  $namespacePrefixes
     */
    private function classIsInNamespace(string $class, array $namespacePrefixes): bool
    {
        return array_any($namespacePrefixes, fn (string $namespacePrefix): bool => str_starts_with($class, $namespacePrefix));
    }

    private function expectedContributionContract(ExtensionContributionType $type): string
    {
        return match ($type) {
            ExtensionContributionType::AdminResource => RegistersExtensionAdminResource::class,
            ExtensionContributionType::Section => RegistersExtensionSection::class,
            ExtensionContributionType::PageType, ExtensionContributionType::PageVariation => RegistersExtensionPageType::class,
            ExtensionContributionType::DashboardFilamentWidget, ExtensionContributionType::OverviewStat => RegistersExtensionFilamentWidget::class,
            ExtensionContributionType::Permission => RegistersExtensionPermission::class,
            ExtensionContributionType::Route => RegistersExtensionRoute::class,
            ExtensionContributionType::Setting => RegistersExtensionSetting::class,
            ExtensionContributionType::FrontendComponent => RegistersExtensionFrontendComponent::class,
            ExtensionContributionType::ContentWidget => RegistersExtensionContentWidget::class,
            ExtensionContributionType::RenderHook => RegistersExtensionRenderHook::class,
            ExtensionContributionType::Asset => RegistersExtensionAsset::class,
            ExtensionContributionType::Migration => RunsExtensionMigration::class,
            ExtensionContributionType::ScheduledJob => RunsScheduledExtensionJob::class,
            ExtensionContributionType::HealthCheck => ChecksExtensionHealth::class,
            ExtensionContributionType::ContentGraph => ContentGraphExtractor::class,
            ExtensionContributionType::WorkflowAttention => ContributesWorkflowAttention::class,
            default => ExtensionContribution::class,
        };
    }
}
