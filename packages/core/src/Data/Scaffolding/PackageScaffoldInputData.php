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
        /** The package that declares the scaffold-full-profile role; the full profile builds on it. */
        public ?string $profilePackage = null,
        public ?string $projectRoot = null,
    ) {}

    /**
     * The commands that take a freshly scaffolded package from files on disk to an installed
     * extension, in the order to run them.
     *
     * @return array<int, string>
     */
    public function nextSteps(): array
    {
        $directory = $this->packageDirectory();
        $argument = preg_match('/\A[A-Za-z0-9._\/-]+\z/', $directory) === 1 ? $directory : escapeshellarg($directory);

        return [
            sprintf('composer config repositories.%s path %s', $this->slug, $argument),
            sprintf('composer require %s:@dev', $this->packageName),
            sprintf('php artisan capell:extension-install %s', $this->packageName),
            sprintf('cd %s && composer install && composer test', $argument),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function stubReplacements(): array
    {
        return [
            '{{ packageName }}' => $this->packageName,
            '{{ slug }}' => $this->slug,
            '{{ displayName }}' => $this->displayName,
            '{{ jsonDisplayName }}' => $this->jsonStringValue($this->displayName),
            '{{ phpDisplayName }}' => $this->phpStringValue($this->displayName),
            '{{ tier }}' => $this->tier,
            '{{ proposedLicense }}' => $this->tier === 'premium' ? 'paid' : 'free',
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
            '{{ profilePackage }}' => $this->profilePackage ?? '',
        ];
    }

    /** The package directory as the host project should refer to it: relative to the project root when inside it. */
    private function packageDirectory(): string
    {
        $target = rtrim(str_replace('\\', '/', $this->targetPath), '/');

        if ($this->projectRoot === null) {
            return $target;
        }

        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/');

        return str_starts_with($target, $root . '/') ? substr($target, strlen($root) + 1) : $target;
    }

    private function jsonStringValue(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return substr($encoded, 1, -1);
    }

    private function phpStringValue(string $value): string
    {
        return var_export($value, true);
    }
}
