<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;

/**
 * @param  array<string, mixed>  $manifestOverrides
 * @param  array<string, string>  $files
 */
function makeLintPackage(string $packageName, array $manifestOverrides = [], array $files = []): string
{
    $directory = sys_get_temp_dir() . '/capell-package-lint-' . bin2hex(random_bytes(6));
    $namespace = str($packageName)->after('/')->studly()->prepend('Vendor\\')->append('\\')->toString();

    mkdir($directory . '/src/Providers', 0755, true);

    file_put_contents($directory . '/composer.json', json_encode([
        'name' => $packageName,
        'autoload' => ['psr-4' => [$namespace => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($directory . '/src/Providers/PackageServiceProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}Providers;

use Illuminate\Support\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
}
PHP);

    foreach ($files as $relativePath => $contents) {
        $path = $directory . '/' . $relativePath;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }

    file_put_contents($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: $packageName,
            surfaces: ['admin'],
            namespace: rtrim($namespace, '\\'),
            providers: ['runtime' => [$namespace . 'Providers\\PackageServiceProvider']],
            overrides: $manifestOverrides,
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    return $directory;
}

it('passes a valid package through the shared extension audit contract', function (): void {
    $directory = makeLintPackage('vendor/valid');

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('Package lint passed.')
        ->assertExitCode(Command::SUCCESS);
});

it('fails an explicit directory containing no package manifests', function (): void {
    $directory = sys_get_temp_dir() . '/capell-empty-package-lint-' . bin2hex(random_bytes(6));
    mkdir($directory, 0755, true);

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('No capell.json manifests were found')
        ->expectsOutputToContain('immediate children are packages')
        ->assertExitCode(Command::FAILURE);
});

it('prints shared audit warnings without failing lint', function (): void {
    $directory = makeLintPackage('vendor/warning', ['capabilities' => ['vendor-specific-capability']]);

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('outside the typed package capability graph')
        ->assertExitCode(Command::SUCCESS);
});

it('fails invalid package versions with an actionable message', function (): void {
    $directory = makeLintPackage('vendor/versioned', ['version' => 'soon']);

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('valid Composer version')
        ->assertExitCode(Command::FAILURE);
});

it('fails when the manifest slug differs from the Composer package segment', function (): void {
    $directory = makeLintPackage('vendor/named', ['slug' => 'different']);

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('must match the package segment')
        ->assertExitCode(Command::FAILURE);
});

it('fails missing marketplace screenshot assets', function (): void {
    $directory = makeLintPackage('vendor/screenshots', [
        'marketplace' => [
            'summary' => 'Screenshots.',
            'screenshots' => [[
                'path' => 'resources/images/missing.webp',
                'alt' => 'Missing example',
                'caption' => 'Missing example',
            ]],
            'categories' => ['developer-tools'],
        ],
    ]);

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('existing file inside the package')
        ->assertExitCode(Command::FAILURE);
});

it('requires theme CSS to use the manifest theme key condition', function (): void {
    $directory = makeLintPackage(
        'vendor/example-theme',
        [
            'kind' => 'theme',
            'slug' => 'example-theme',
            'themeKey' => 'example',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/theme.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\ExampleTheme\Providers;

final class ThemeServiceProvider
{
    private const CSS_CONDITION = 'theme-css:wrong';
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('conditional Tailwind import')
        ->expectsOutputToContain('theme-css:example')
        ->assertExitCode(Command::FAILURE);
});

it('fails a theme registration that points at a missing CSS source', function (): void {
    $directory = makeLintPackage(
        'vendor/missing-css-theme',
        [
            'kind' => 'theme',
            'slug' => 'missing-css-theme',
            'themeKey' => 'missing-css',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/other.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\MissingCssTheme\Providers;

use Capell\Core\Data\VendorAssetData as Asset;
use Capell\Core\Enums\VendorAssetEnum as AssetType;
use Capell\Core\Facades\CapellCore as Assets;

final class ThemeServiceProvider
{
    private const string CSS_SOURCE = 'resources/css/missing.css';

    private const string CSS_CONDITION = 'theme-css:missing-css';

    public function boot(): void
    {
        CapellCore::registerVendorAsset(new VendorAssetData(
            type: VendorAssetEnum::TailwindImport,
            value: self::CSS_SOURCE,
            packageName: 'vendor/missing-css-theme',
            condition: self::CSS_CONDITION,
        ));
    }
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('existing package CSS source')
        ->assertExitCode(Command::FAILURE);
});

it('does not mistake unused theme constants for a Tailwind registration', function (): void {
    $directory = makeLintPackage(
        'vendor/unregistered-theme',
        [
            'kind' => 'theme',
            'slug' => 'unregistered-theme',
            'themeKey' => 'unregistered',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/theme.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\UnregisteredTheme\Providers;

final class ThemeServiceProvider
{
    private const string CSS_SOURCE = 'resources/css/theme.css';

    private const string CSS_CONDITION = 'theme-css:unregistered';
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('must be registered as a conditional Tailwind import')
        ->assertExitCode(Command::FAILURE);
});

it('does not mistake commented or string-contained code for a Tailwind registration', function (): void {
    $directory = makeLintPackage(
        'vendor/commented-theme',
        [
            'kind' => 'theme',
            'slug' => 'commented-theme',
            'themeKey' => 'commented',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/theme.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\CommentedTheme\Providers;

final class ThemeServiceProvider
{
    private const EXAMPLE = "CapellCore::registerVendorAsset(new VendorAssetData(type: VendorAssetEnum::TailwindImport, value: 'resources/css/theme.css', condition: 'theme-css:commented'));";

    // CapellCore::registerVendorAsset(new VendorAssetData(type: VendorAssetEnum::TailwindImport, value: 'resources/css/theme.css', condition: 'theme-css:commented'));
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('must be registered as a conditional Tailwind import')
        ->assertExitCode(Command::FAILURE);
});

it('accepts a registered CSS source nested under resources css', function (): void {
    $directory = makeLintPackage(
        'vendor/nested-theme',
        [
            'kind' => 'theme',
            'slug' => 'nested-theme',
            'themeKey' => 'nested',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/capell/themes/nested.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\NestedTheme\Providers;

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;

final class ThemeServiceProvider
{
    public function boot(): void
    {
        CapellCore::registerVendorAsset(new VendorAssetData(
            type: VendorAssetEnum::TailwindImport,
            value: 'resources/css/capell/themes/nested.css',
            packageName: 'vendor/nested-theme',
            condition: 'theme-css:nested',
        ));
    }
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('Package lint passed.')
        ->assertExitCode(Command::SUCCESS);
});

it('accepts Composer PSR-4 array mappings when locating theme registrations', function (): void {
    $directory = makeLintPackage(
        'vendor/array-theme',
        [
            'kind' => 'theme',
            'slug' => 'array-theme',
            'themeKey' => 'array',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/theme.css' => '@import "tailwindcss";',
            'generated/ThemeRegistration.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\ArrayTheme;

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;

final class ThemeRegistration
{
    private const string CSS_SOURCE = "resources/css/theme.css";

    private const string CSS_CONDITION = "theme-css:array";

    public function boot(): void
    {
        Assets::registerVendorAsset(new Asset(
            type: AssetType::TailwindImport,
            value: static::CSS_SOURCE,
            packageName: 'vendor/array-theme',
            condition: static::CSS_CONDITION,
        ));
    }
}
PHP,
        ],
    );
    $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer['autoload']['psr-4']['Vendor\\ArrayTheme\\'] = ['src/', 'generated/'];
    file_put_contents($directory . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('Package lint passed.')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when any matching theme registration references a missing source', function (): void {
    $directory = makeLintPackage(
        'vendor/partial-theme',
        [
            'kind' => 'theme',
            'slug' => 'partial-theme',
            'themeKey' => 'partial',
            'extends' => 'default',
            'capabilities' => ['frontend-rendering', 'frontend-assets'],
        ],
        [
            'resources/css/theme.css' => '@import "tailwindcss";',
            'src/Providers/ThemeServiceProvider.php' => <<<'PHP'
<?php

final class ThemeServiceProvider
{
    public function boot(): void
    {
        CapellCore::registerVendorAsset(new VendorAssetData(type: VendorAssetEnum::TailwindImport, value: 'resources/css/theme.css', condition: 'theme-css:partial'));
        CapellCore::registerVendorAsset(new VendorAssetData(type: VendorAssetEnum::TailwindImport, value: 'resources/css/missing.css', condition: 'theme-css:partial'));
    }
}
PHP,
        ],
    );

    artisanCommand('capell:package:lint', ['path' => $directory])
        ->expectsOutputToContain('resources/css/missing.css')
        ->assertExitCode(Command::FAILURE);
});
