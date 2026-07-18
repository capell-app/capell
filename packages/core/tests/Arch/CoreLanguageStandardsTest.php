<?php

declare(strict_types=1);
use Illuminate\Support\Collection;

it('uses strict types throughout core production code', function (): void {
    $nonStrictFiles = corePhpFiles()
        ->reject(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);'))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->values()
        ->all();

    expect($nonStrictFiles)->toBe([]);
});

it('uses string backing for every core enum', function (): void {
    $invalidEnums = corePhpFiles(__DIR__ . '/../../src/Enums')
        ->filter(function (SplFileInfo $file): bool {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/^enum\s+\w+/m', $contents) !== 1) {
                return false;
            }

            if (in_array($file->getFilename(), ['ExtensionManifestVersion.php', 'RedirectStatusCodeEnum.php'], true)) {
                return false;
            }

            return preg_match('/^enum\s+\w+\s*:\s*string\b/m', $contents) !== 1;
        })
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->values()
        ->all();

    expect($invalidEnums)->toBe([]);
});

it('types every core class constant', function (): void {
    $untypedConstants = [];

    foreach (corePhpFiles() as $file) {
        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents)) {
            continue;
        }

        if (preg_match_all('/^\s*(?:public |protected |private )?const\s+[A-Z][A-Z0-9_]*\s*=/m', $contents, $matches) > 0) {
            $untypedConstants[] = $file->getPathname();
        }
    }

    expect($untypedConstants)->toBe([]);
});

it('makes core actions data and support classes final by default', function (): void {
    $extensionPoints = [
        'Actions/GetComponentViewPathAction.php',
        'Actions/Install/ClearCachesAction.php',
        'Actions/Install/RunInstallAction.php',
        'Actions/InstallPackageAction.php',
        'Actions/RequirePackageAction.php',
        'Data/PackageData.php',
        'Support/CapellCoreManager.php',
        'Support/Dataset/DatasetPublisher.php',
        'Support/Install/DeveloperToolingInstallationState.php',
        'Support/Makers/MakerSafety.php',
        'Support/Media/CustomPathGenerator.php',
        'Support/Plugins/PluginPackagesFetcher.php',
        'Support/Settings/SettingsSchemaRegistry.php',
        'Support/Subscriber/SubscriberRegistry.php',
    ];
    $sourcePath = realpath(__DIR__ . '/../../src');

    expect($sourcePath)->toBeString();

    $nonFinalClasses = collect([
        ...corePhpFiles($sourcePath . '/Actions'),
        ...corePhpFiles($sourcePath . '/Data'),
        ...corePhpFiles($sourcePath . '/Support'),
    ])->filter(function (SplFileInfo $file): bool {
        $contents = (string) file_get_contents($file->getPathname());

        return preg_match('/^class\s+\w+/m', $contents) === 1
            && preg_match('/\buse\s+AsFake\s*;/', $contents) !== 1;
    })->map(fn (SplFileInfo $file): string => str_replace($sourcePath . '/', '', $file->getPathname()))
        ->reject(fn (string $path): bool => in_array($path, $extensionPoints, true))
        ->values()
        ->all();

    expect($nonFinalClasses)->toBe([]);
});

/**
 * @return Collection<int, SplFileInfo>
 */
function corePhpFiles(string $path = __DIR__ . '/../../src'): Collection
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );

    return collect(iterator_to_array($files, false))
        ->filter(fn (mixed $file): bool => $file instanceof SplFileInfo && $file->getExtension() === 'php')
        ->values();
}
