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
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\ExtensionManifestVersion;
use Capell\Core\Enums\ExtensionSurface;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
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
    private const array COMMERCIAL_PROPOSAL_FIELDS = [
        'proposedLicense',
        'requestedCertification',
        'supportPolicy',
        'privateDocsRequested',
    ];

    /** @var list<string> */
    private const array SECURITY_FIELDS = [
        'riskTier',
        'publicSurface',
        'sensitiveData',
        'publicOutput',
        'externalHttpClients',
        'adminSurface',
    ];

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

        $this->validateRequiredStrings($data, [
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
        $this->validateStringList($data, 'surfaces');
        $this->validateStringList($data, 'settings', required: false);
        $this->validateStringList($data, 'permissions', required: false);
        $this->validateStringList($data, 'capabilities', required: false);

        foreach ($data['surfaces'] as $surface) {
            if (! in_array($surface, ExtensionSurface::values(), strict: true)) {
                throw InvalidManifestException::invalidContext((string) $surface);
            }
        }

        $namespacePrefixes = $this->composerNamespacePrefixes($composerJson);
        $isTrustedCore = TrustedCorePackages::contains((string) $data['name']);

        $this->validateClassList($data['providers'], $namespacePrefixes, $isTrustedCore, ServiceProvider::class);
        $this->validateContributions($data, $namespacePrefixes, $isTrustedCore);
        $this->validateSecurity($data);
        $this->validatePerformance($data);
        $this->validateHealthChecks($data, $namespacePrefixes, $isTrustedCore);
        $this->validateCommercial($data);
        $this->validateMarketplace($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    private function validateRequiredStrings(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw InvalidManifestException::missingField($field);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateProduct(array $data): void
    {
        if (! is_array($data['product'] ?? null)) {
            throw InvalidManifestException::missingField('product');
        }

        $this->validateRequiredStrings($data['product'], ['group', 'tier']);
    }

    /** @param array<string, mixed> $data */
    private function validateDependencies(array $data): void
    {
        if (! is_array($data['dependencies'] ?? null)) {
            throw InvalidManifestException::missingField('dependencies');
        }

        foreach (['requires', 'supports', 'conflicts'] as $field) {
            $this->validateStringList($data['dependencies'], $field);
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
            $this->validateStringList(
                $data['providers'],
                $bucket,
                required: in_array($bucket, self::REQUIRED_PROVIDER_BUCKETS, true),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateStringList(array $data, string $field, bool $required = true): void
    {
        if (! array_key_exists($field, $data)) {
            if ($required) {
                throw InvalidManifestException::missingField($field);
            }

            return;
        }

        if (! is_array($data[$field]) || ! array_is_list($data[$field])) {
            throw InvalidManifestException::invalidField($field, 'must be a list');
        }

        foreach ($data[$field] as $value) {
            if (! is_string($value) || $value === '') {
                throw InvalidManifestException::invalidField($field, 'must contain non-empty strings');
            }
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

    /** @param array<string, mixed> $data */
    private function validateSecurity(array $data): void
    {
        if (! array_key_exists('security', $data)) {
            return;
        }

        if (! is_array($data['security'])) {
            throw InvalidManifestException::invalidField('security', 'must be an object');
        }

        $security = $data['security'];

        foreach (array_keys($security) as $field) {
            if (! in_array($field, self::SECURITY_FIELDS, true)) {
                throw InvalidManifestException::invalidField('security.' . $field, 'is not part of manifest v3 security metadata');
            }
        }

        if (array_key_exists('riskTier', $security) && (! is_string($security['riskTier']) || $security['riskTier'] === '')) {
            throw InvalidManifestException::invalidField('security.riskTier', 'must be a non-empty string');
        }

        $this->validateSecurityObject($security, 'publicSurface');
        $this->validateSecurityObject($security, 'sensitiveData');
        $this->validateSecurityObject($security, 'publicOutput');
        $this->validateSecurityObject($security, 'externalHttpClients');
        $this->validateSecurityObject($security, 'adminSurface');

        if (is_array($security['publicSurface'] ?? null)) {
            $publicSurface = $security['publicSurface'];

            foreach (['routeNames', 'csrfExemptRoutes', 'signedRoutes', 'tokenizedRoutes', 'webhookRoutes', 'throttledRoutes'] as $field) {
                $this->validateStringList($publicSurface, $field, required: false);
            }

            if (array_key_exists('auth', $publicSurface) && (! is_string($publicSurface['auth']) || $publicSurface['auth'] === '')) {
                throw InvalidManifestException::invalidField('security.publicSurface.auth', 'must be a non-empty string');
            }
        }

        if (is_array($security['sensitiveData'] ?? null)) {
            foreach (['encryptedFields', 'hashedTokenFields', 'redactedOutputClasses', 'plaintextJustifications'] as $field) {
                $this->validateStringList($security['sensitiveData'], $field, required: false);
            }
        }

        if (is_array($security['publicOutput'] ?? null)) {
            foreach (['cacheSafe', 'forbidAuthoringSurface', 'forbidSecrets', 'forbidPublicBladeQueries'] as $field) {
                if (array_key_exists($field, $security['publicOutput']) && ! is_bool($security['publicOutput'][$field])) {
                    throw InvalidManifestException::invalidField('security.publicOutput.' . $field, 'must be a boolean');
                }
            }
        }

        if (is_array($security['externalHttpClients'] ?? null)) {
            foreach (['requiresTimeouts', 'requiresSecretRedaction'] as $field) {
                if (array_key_exists($field, $security['externalHttpClients']) && ! is_bool($security['externalHttpClients'][$field])) {
                    throw InvalidManifestException::invalidField('security.externalHttpClients.' . $field, 'must be a boolean');
                }
            }

            $this->validateStringList($security['externalHttpClients'], 'clients', required: false);
        }

        if (is_array($security['adminSurface'] ?? null)) {
            if (array_key_exists('authorization', $security['adminSurface']) && (! is_string($security['adminSurface']['authorization']) || $security['adminSurface']['authorization'] === '')) {
                throw InvalidManifestException::invalidField('security.adminSurface.authorization', 'must be a non-empty string');
            }

            $this->validateStringList($security['adminSurface'], 'permissions', required: false);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateSecurityObject(array $data, string $field): void
    {
        if (array_key_exists($field, $data) && ! is_array($data[$field])) {
            throw InvalidManifestException::invalidField('security.' . $field, 'must be an object');
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePerformance(array $data): void
    {
        if (! is_array($data['performance'] ?? null)) {
            throw InvalidManifestException::missingField('performance');
        }

        $performance = $data['performance'];
        $this->validateStringList($performance, 'cacheTags');

        if (! is_array($performance['cacheSafety'] ?? null)) {
            throw InvalidManifestException::missingField('cacheSafety');
        }

        $cacheSafety = $performance['cacheSafety'];

        foreach (['cacheable', 'sensitiveOutput', 'queueInvalidation'] as $field) {
            if (! array_key_exists($field, $cacheSafety) || ! is_bool($cacheSafety[$field])) {
                throw InvalidManifestException::invalidField('cacheSafety.' . $field, 'must be explicit boolean metadata');
            }
        }

        $this->validateStringList($cacheSafety, 'variesBy');

        if (! array_key_exists('invalidationSources', $cacheSafety) || ! is_array($cacheSafety['invalidationSources']) || ! array_is_list($cacheSafety['invalidationSources'])) {
            throw InvalidManifestException::invalidField('cacheSafety.invalidationSources', 'must be a list');
        }

        foreach ($cacheSafety['invalidationSources'] as $index => $source) {
            if (! is_array($source)) {
                throw InvalidManifestException::invalidField('cacheSafety.invalidationSources.' . $index, 'must be an object');
            }

            $this->validateRequiredStrings($source, ['model']);
            $this->validateStringList($source, 'events');
        }

        if ($cacheSafety['cacheable'] && $cacheSafety['invalidationSources'] === []) {
            throw InvalidManifestException::invalidField('cacheSafety.invalidationSources', 'cacheable frontend surfaces need invalidation metadata');
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

            $this->validateRequiredStrings($healthCheck, ['key', 'label', 'class']);
            $this->validateClass((string) $healthCheck['class'], $namespacePrefixes, $isTrustedCore, ChecksExtensionHealth::class);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateCommercial(array $data): void
    {
        if (! is_array($data['commercial'] ?? null)) {
            throw InvalidManifestException::missingField('commercial');
        }

        foreach (array_keys($data['commercial']) as $field) {
            if (! in_array($field, self::COMMERCIAL_PROPOSAL_FIELDS, true)) {
                throw InvalidManifestException::invalidField('commercial.' . $field, 'commercial manifest data is author proposal metadata only');
            }
        }

        if (! array_key_exists('privateDocsRequested', $data['commercial']) || ! is_bool($data['commercial']['privateDocsRequested'])) {
            throw InvalidManifestException::invalidField('commercial.privateDocsRequested', 'must be explicit boolean proposal metadata');
        }
    }

    /** @param array<string, mixed> $data */
    private function validateMarketplace(array $data): void
    {
        if (! is_array($data['marketplace'] ?? null)) {
            throw InvalidManifestException::missingField('marketplace');
        }

        $marketplace = $data['marketplace'];
        $this->validateRequiredStrings($marketplace, ['summary']);
        $this->validateStringList($marketplace, 'categories');

        if (array_key_exists('hidden', $marketplace) && ! is_bool($marketplace['hidden'])) {
            throw InvalidManifestException::invalidField('marketplace.hidden', 'must be a boolean');
        }

        if (! is_array($marketplace['screenshots'] ?? null) || ! array_is_list($marketplace['screenshots'])) {
            throw InvalidManifestException::missingField('marketplace.screenshots');
        }

        foreach ($marketplace['screenshots'] as $index => $screenshot) {
            if (! is_array($screenshot)) {
                throw InvalidManifestException::invalidField('marketplace.screenshots.' . $index, 'must be an object');
            }

            $this->validateRequiredStrings($screenshot, ['path', 'alt', 'caption']);

            foreach (['alt', 'caption'] as $field) {
                if (mb_strlen(trim((string) $screenshot[$field])) < 12) {
                    throw InvalidManifestException::invalidField(sprintf('marketplace.screenshots.%d.%s', $index, $field), 'must be usable descriptive text');
                }
            }
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
