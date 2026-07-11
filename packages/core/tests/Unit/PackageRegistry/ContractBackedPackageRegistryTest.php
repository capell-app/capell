<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\BuildExtensionContractRegistryAction;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Contracts\Extensions\DeletesExtensionData;
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
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;

it('maps manifest v3 metadata into package data without provider registration', function (): void {
    CapellCore::clearPackages();

    CapellCore::registerManifestPackage(contractRegistryManifest(
        name: 'vendor/editorial-tools',
        surfaces: ['admin', 'frontend'],
        overrides: [
            'slug' => 'editorial-tools',
            'displayName' => 'Editorial Tools',
            'kind' => 'theme',
            'version' => '1.2.3',
            'description' => 'Editorial workflow tools.',
            'product' => ['group' => 'Publishing Pro', 'tier' => 'premium', 'bundle' => 'publishing'],
            'dependencies' => [
                'requires' => ['capell-app/core'],
                'supports' => ['capell-app/admin'],
                'conflicts' => ['vendor/legacy-editor'],
            ],
            'contributes' => [
                [
                    'type' => 'dashboard-widget',
                    'class' => 'Vendor\\EditorialTools\\Widgets\\ReviewQueueWidget',
                    'surface' => 'admin',
                ],
                [
                    'type' => 'route',
                    'class' => 'Vendor\\EditorialTools\\Routes\\PreviewRoutes',
                    'surface' => 'frontend',
                ],
            ],
            'performance' => [
                'frontendRenderBudgetMs' => 14,
                'adminQueryBudget' => 18,
                'cacheTags' => ['editorial'],
                'cacheSafety' => [
                    'cacheable' => false,
                    'variesBy' => ['site', 'locale'],
                    'sensitiveOutput' => true,
                    'invalidationSources' => ['workspaces'],
                    'queueInvalidation' => true,
                ],
            ],
            'commercial' => [
                'proposedLicense' => 'paid',
                'requestedCertification' => 'first-party',
                'supportPolicy' => 'priority',
                'privateDocsRequested' => true,
            ],
            'marketplace' => [
                'hidden' => true,
            ],
            'themeKey' => 'editorial',
        ],
    ));

    $package = CapellCore::getPackage('vendor/editorial-tools');

    expect($package)->toBeInstanceOf(PackageData::class)
        ->and($package->name)->toBe('vendor/editorial-tools')
        ->and($package->type)->toBe(PackageTypeEnum::Theme)
        ->and($package->slug)->toBe('editorial-tools')
        ->and($package->getLabel())->toBe('Editorial Tools')
        ->and($package->version)->toBe('1.2.3')
        ->and($package->getDescription())->toBe('Editorial workflow tools.')
        ->and($package->getProductGroup())->toBe('Publishing Pro')
        ->and($package->getTier())->toBe('premium')
        ->and($package->getBundle())->toBe('publishing')
        ->and($package->getRequirements())->toBe(['capell-app/core'])
        ->and($package->getSupportingPackages())->toBe(['capell-app/admin'])
        ->and($package->conflicts)->toBe(['vendor/legacy-editor'])
        ->and($package->getThemeKey())->toBe('editorial')
        ->and($package->contributionCount)->toBe(2)
        ->and($package->performanceBudget?->frontendRenderBudgetMs)->toBe(14)
        ->and($package->performanceBudget?->adminQueryBudget)->toBe(18)
        ->and($package->proposedLicense)->toBe('paid')
        ->and($package->requestedCertificationStatus)->toBe('first-party')
        ->and($package->supportPolicy)->toBe('priority')
        ->and($package->privateDocsRequested)->toBeTrue()
        ->and($package->isHiddenFromMarketplace())->toBeTrue()
        ->and($package->effectiveMarketplaceStatus)->toBeNull();
});

it('returns contributions from the precomputed contract registry indexes', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/editorial-tools' => contractRegistryManifest(
            name: 'vendor/editorial-tools',
            surfaces: ['admin'],
            overrides: [
                'contributes' => [
                    [
                        'type' => 'dashboard-widget',
                        'class' => 'Vendor\\EditorialTools\\Widgets\\ReviewQueueWidget',
                        'surface' => 'admin',
                    ],
                    [
                        'type' => 'route',
                        'class' => 'Vendor\\EditorialTools\\Routes\\PreviewRoutes',
                        'surface' => 'frontend',
                    ],
                    [
                        'type' => 'content-widget',
                        'class' => 'Vendor\\EditorialTools\\Widgets\\ArticleCard',
                    ],
                ],
            ],
        ),
        'vendor/content-tools' => contractRegistryManifest(
            name: 'vendor/content-tools',
            surfaces: ['admin'],
            overrides: [
                'contributes' => [
                    [
                        'type' => 'dashboard-widget',
                        'class' => 'Vendor\\ContentTools\\Widgets\\DraftsWidget',
                        'surface' => 'admin',
                    ],
                ],
            ],
        ),
    ]);

    $contractRegistry = BuildExtensionContractRegistryAction::run($registry->all());

    expect($contractRegistry['byType'][ExtensionContributionType::DashboardFilamentWidget->value])->toHaveCount(2)
        ->and($contractRegistry['byType'][ExtensionContributionType::ContentWidget->value])->toHaveCount(1)
        ->and($contractRegistry['byPackage']['vendor/editorial-tools'])->toHaveCount(3)
        ->and($contractRegistry['bySurface']['frontend'])->toHaveCount(2)
        ->and(collect($contractRegistry['bySurface']['frontend'])->pluck('class')->all())->toContain('Vendor\\EditorialTools\\Widgets\\ArticleCard')
        ->and($contractRegistry['byClass']['Vendor\\EditorialTools\\Routes\\PreviewRoutes'])->toBeInstanceOf(ExtensionContributionData::class);

    expect($registry->contributionsForType(ExtensionContributionType::DashboardFilamentWidget))->toHaveCount(2)
        ->and($registry->contributionsForType(ExtensionContributionType::ContentWidget))->toHaveCount(1)
        ->and($registry->contributionsForPackage('vendor/editorial-tools'))->toHaveCount(3)
        ->and($registry->contributionsForSurface('frontend'))->toHaveCount(2)
        ->and(collect($registry->contributionsForSurface('frontend'))->pluck('class')->all())->toContain('Vendor\\EditorialTools\\Widgets\\ArticleCard')
        ->and($registry->contributionForClass('Vendor\\EditorialTools\\Routes\\PreviewRoutes')?->type)->toBe(ExtensionContributionType::Route)
        ->and($registry->contributionForClass('Vendor\\Missing\\MissingWidget'))->toBeNull();

    $reflection = new ReflectionProperty($registry, 'contractRegistry');

    expect($reflection->getValue($registry))->toHaveKeys(['byType', 'byPackage', 'bySurface', 'byClass']);
});

it('has typed contribution contracts for every manifest runtime contribution surface', function (): void {
    $contracts = [
        RegistersExtensionFilamentWidget::class,
        RegistersExtensionSection::class,
        RegistersExtensionPageType::class,
        RegistersExtensionAdminResource::class,
        RegistersExtensionPermission::class,
        RegistersExtensionRoute::class,
        RegistersExtensionSetting::class,
        RegistersExtensionAsset::class,
        RegistersExtensionContentWidget::class,
        RunsExtensionMigration::class,
        RunsScheduledExtensionJob::class,
        ChecksExtensionHealth::class,
        ContributesWorkflowAttention::class,
        DeletesExtensionData::class,
        RegistersExtensionRenderHook::class,
        RegistersExtensionFrontendComponent::class,
    ];

    foreach ($contracts as $contract) {
        expect(interface_exists($contract))->toBeTrue()
            ->and(is_subclass_of($contract, ExtensionContribution::class))->toBeTrue();
    }
});

function contractRegistryManifest(string $name, array $surfaces = ['admin'], array $overrides = []): CapellManifestData
{
    return CapellManifestData::fromArray(capellManifestV3Array(
        name: $name,
        surfaces: $surfaces,
        overrides: $overrides,
    ));
}
