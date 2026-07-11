<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class InstallInputData extends Data
{
    public function __construct(
        public readonly string $siteUrl,
        /** @var array<string> */
        public readonly array $packages,
        /** @var array<string> */
        public readonly array $languages,
        public readonly bool $demoContent,
        /** @var array<string> */
        public readonly array $cachesToClear,
        public readonly bool $generateSitemap,
        public readonly bool $generateStaticSite,
        /** @var array<string>|null */
        public readonly ?array $demoSites = null,
        /** @var array<string>|null */
        public readonly ?array $demoLanguages = null,
        /** @var array{css: string, js: string}|null */
        public readonly ?array $assets = null,
        public readonly ?int $userId = null,
        public readonly ?NewUserData $newUser = null,
        public readonly bool $seedDefaultData = true,
        public readonly bool $installFilamentPanel = false,
        /** @var array<string> */
        public readonly array $extraPackages = [],
        public readonly bool $integrateAdminPanel = true,
        public readonly ?string $adminPanel = null,
        /** @var array<int, array{in: string, for: string}> */
        public readonly array $adminDiscoverSchemas = [],
        public readonly bool $adminAddColors = true,
        public readonly bool $adminAddWidgets = true,
        public readonly bool $adminAddNavigation = true,
        public readonly bool $rebuildResources = false,
        public readonly bool $freshInstall = false,
        public readonly bool $installWelcomeRoute = false,
        public readonly bool $installDeveloperTooling = false,
        public readonly bool $configureBoostDeveloperTooling = false,
        /** @var array<NewUserData> */
        public readonly array $additionalUsers = [],
        public readonly ?string $selectedThemeKey = null,
        public readonly bool $seedDatabase = false,
    ) {}
}
