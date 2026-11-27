<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Actions\Packages\BuildPackageCapabilityGraphAction;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Diagnostics\AgentDoctorReportData;
use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** Gather the orientation report behind `capell:agent:doctor`. */
final class BuildAgentDoctorReportAction
{
    use AsFake;
    use AsObject;

    private const string LaravelMcpPackage = 'laravel/mcp';

    /** Content-building contributions an agent can place on a page. */
    private const array WidgetContributionTypes = [
        ExtensionContributionType::ContentWidget,
        ExtensionContributionType::BuilderBlock,
        ExtensionContributionType::Section,
    ];

    /** Orientation commands, listed only when the command is registered in this application. */
    private const array OrientationCommands = [
        'capell:make' => 'Preview and run a registered maker; add --list to see them all',
        'capell:site-spec-export' => 'Export the site as a SiteSpec to read or edit',
        'capell:site-spec-import' => 'Import a SiteSpec; add --diff to preview the changes first',
        'capell:themes:validate' => 'Validate theme definitions and see how to fix them',
        'capell:agent-schema:verify' => 'Verify the public agent schema for published pages',
        'capell:extension-install' => 'Install a Composer-required extension into Capell',
        'capell:doctor' => 'Check the installation is healthy',
    ];

    /**
     * @param  bool  $agentBridge  An installed Capell package declares the `agent-bridge` capability in its manifest.
     * @return array{laravelMcp: bool, agentBridge: bool, status: string, nextStep: ?string}
     */
    public static function mcpStatus(bool $laravelMcp, bool $agentBridge): array
    {
        if ($agentBridge && $laravelMcp) {
            return ['laravelMcp' => true, 'agentBridge' => true, 'status' => 'ready', 'nextStep' => null];
        }

        if ($agentBridge) {
            // The bridge is already installed into Capell; the missing piece is the MCP library.
            return [
                'laravelMcp' => false,
                'agentBridge' => true,
                'status' => 'missing-laravel-mcp',
                'nextStep' => 'composer require ' . self::LaravelMcpPackage,
            ];
        }

        return [
            'laravelMcp' => $laravelMcp,
            'agentBridge' => false,
            'status' => 'missing',
            'nextStep' => 'Install the Agent Bridge extension with Composer, then run php artisan capell:extension-install for it',
        ];
    }

    public function handle(): AgentDoctorReportData
    {
        return new AgentDoctorReportData(
            versions: [
                'capell' => CapellCore::getInstalledPrettyVersion('capell-app/core'),
                'laravel' => Application::VERSION,
                'php' => PHP_VERSION,
            ],
            packages: $this->packages(),
            themes: $this->themes(),
            pageTypes: $this->pageTypes(),
            widgets: $this->widgets(),
            makers: $this->makers(),
            mcp: $this->mcp(),
            commands: $this->commands(),
        );
    }

    /** @return list<array{name: string, version: ?string, installed: bool, type: string, tier: ?string}> */
    private function packages(): array
    {
        return array_values(CapellCore::getPackages(withoutCore: false)
            ->map(fn (PackageData $package): array => [
                'name' => $package->name,
                'version' => $package->version,
                'installed' => CapellCore::isPackageInstalled($package->name),
                'type' => $package->type->value,
                'tier' => $package->tier,
            ])
            ->sortBy('name')
            ->values()
            ->all());
    }

    /** @return list<array{key: string, name: string, extends: ?string, package: string, runtime: string, installed: bool}> */
    private function themes(): array
    {
        $installedKeys = $this->installedThemeKeys();

        return array_values(collect(resolve(ThemeRegistry::class)->definitions())
            ->map(fn (ThemeDefinitionData $theme): array => [
                'key' => $theme->key,
                'name' => $theme->name,
                'extends' => $theme->extends,
                'package' => $theme->package,
                'runtime' => $theme->runtime->value,
                'installed' => in_array($theme->key, $installedKeys, true),
            ])
            ->sortBy('key')
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function installedThemeKeys(): array
    {
        try {
            if (! Schema::hasTable(new Theme()->getTable())) {
                return [];
            }

            return array_values(Theme::query()->pluck('key')->filter()->map(strval(...))->all());
        } catch (Throwable) {
            // No database yet (for example before the first install); report themes as not installed.
            return [];
        }
    }

    /** @return list<array{name: string, label: string, model: ?string}> */
    private function pageTypes(): array
    {
        return array_values(CapellCore::getPageTypes()
            ->map(fn (PageTypeData $type): array => [
                'name' => $type->name,
                'label' => $type->getLabel(),
                'model' => $type->model,
            ])
            ->sortBy('name')
            ->values()
            ->all());
    }

    /** @return list<array{type: string, key: string, package: string}> */
    private function widgets(): array
    {
        if (! app()->bound(ExtensionContributionReceiptRegistry::class)) {
            return [];
        }

        return array_values(collect(resolve(ExtensionContributionReceiptRegistry::class)->all())
            ->filter(fn (ExtensionContributionReceiptData $receipt): bool => in_array($receipt->type, self::WidgetContributionTypes, true))
            ->map(fn (ExtensionContributionReceiptData $receipt): array => [
                'type' => $receipt->type->value,
                'key' => $receipt->key,
                'package' => $receipt->ownerPackage,
            ])
            ->sortBy(['type', 'key'])
            ->values()
            ->all());
    }

    /** @return list<array{key: string, label: string, description: string, command: string, writesPhp: bool, writesDatabase: bool}> */
    private function makers(): array
    {
        return array_values(resolve(MakerRegistryInterface::class)->all()
            ->map(function (Maker $maker): array {
                $definition = $maker->definition();

                return [
                    'key' => $definition->key,
                    'label' => $definition->label,
                    'description' => $definition->description,
                    'command' => sprintf('php artisan capell:make %s --name=<Name> --dry-run', $definition->key),
                    'writesPhp' => $definition->supportsPhpWrites,
                    'writesDatabase' => $definition->supportsDatabaseWrites,
                ];
            })
            ->values()
            ->all());
    }

    /** @return array{laravelMcp: bool, agentBridge: bool, status: string, nextStep: ?string} */
    private function mcp(): array
    {
        // Find the bridge by the capability its manifest declares, never by its Composer name.
        return self::mcpStatus(
            InstalledVersions::isInstalled(self::LaravelMcpPackage),
            BuildPackageCapabilityGraphAction::run()->packagesWith(PackageCapability::AgentBridge) !== [],
        );
    }

    /** @return list<array{command: string, purpose: string}> */
    private function commands(): array
    {
        $registered = array_keys(resolve(Kernel::class)->all());

        return array_values(collect(self::OrientationCommands)
            ->filter(fn (string $purpose, string $command): bool => in_array($command, $registered, true))
            ->map(fn (string $purpose, string $command): array => ['command' => 'php artisan ' . $command, 'purpose' => $purpose])
            ->values()
            ->all());
    }
}
