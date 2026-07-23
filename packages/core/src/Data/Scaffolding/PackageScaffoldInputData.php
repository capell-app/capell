<?php

declare(strict_types=1);

namespace Capell\Core\Data\Scaffolding;

use Capell\Core\Enums\PackageScaffoldProfile;

final readonly class PackageScaffoldInputData
{
    public function __construct(
        public string $packageName,
        public string $namespace,
        public string $slug,
        public string $displayName,
        public string $tier,
        public string $targetPath,
        public PackageScaffoldProfile $profile,
    ) {}

    /**
     * @return array<string, string>
     */
    public function stubReplacements(): array
    {
        return [
            '{{ packageName }}' => $this->packageName,
            '{{ slug }}' => $this->slug,
            '{{ displayName }}' => $this->displayName,
            '{{ tier }}' => $this->tier,
            '{{ namespace }}' => $this->namespace,
            '{{ escapedNamespace }}' => str_replace('\\', '\\\\', $this->namespace),
            '{{ escapedRuntimeProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\Providers\\PackageServiceProvider'),
            '{{ escapedMetadataProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\Providers\\MetadataServiceProvider'),
            '{{ escapedInstallProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\Providers\\InstallServiceProvider'),
            '{{ escapedAdminProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\Providers\\AdminServiceProvider'),
            '{{ escapedFrontendProvider }}' => str_replace('\\', '\\\\', $this->namespace . '\\Providers\\FrontendServiceProvider'),
            '{{ packageClass }}' => class_basename($this->namespace),
            '{{ settingsGroup }}' => str_replace('-', '_', $this->slug),
            '{{ widgetKey }}' => str_replace('/', '.', $this->packageName),
        ];
    }
}
