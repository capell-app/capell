<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\ThemeSourcesPatch;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-theme-sources-patch-test-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('adds Capell sources to the custom Filament Vite theme configured on the panel provider', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $customThemePath = base_path('resources/css/filament/admin/custom.css');
    $originalProviderContent = file_exists($providerPath) ? file_get_contents($providerPath) : null;
    $originalCustomThemeContent = file_exists($customThemePath) ? file_get_contents($customThemePath) : null;

    writeThemeSourcesFile($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->viteTheme('resources/css/filament/admin/custom.css');
    }
}
PHP);

    writeThemeSourcesFile($customThemePath, <<<'CSS'
@import 'tailwindcss';
CSS);

    try {
        $patch = new ThemeSourcesPatch;

        expect($patch->probe())->toBe(PatchStatus::Applicable);

        $patch->apply();

        $updatedContent = (string) file_get_contents($customThemePath);

        expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($updatedContent)->toContain("@source '../../../../vendor/capell-app/admin/resources/views/**/*.blade.php';")
            ->and($updatedContent)->toContain("@source '../../../../vendor/capell-app/installer/resources/views/**/*.blade.php';");
    } finally {
        restoreThemeSourcesFile($providerPath, $originalProviderContent);
        restoreThemeSourcesFile($customThemePath, $originalCustomThemeContent);
    }
});

it('resolves array based Filament Vite theme configuration before patching sources', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $customThemePath = base_path('resources/css/filament/admin/array-theme.css');

    writeThemeSourcesFile($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->viteTheme(['resources/css/filament/admin/array-theme.css']);
    }
}
PHP);

    writeThemeSourcesFile($customThemePath, <<<'CSS'
@import 'tailwindcss';
CSS);

    $patch = new ThemeSourcesPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect(file_get_contents($customThemePath))
        ->toContain("@source '../../../../vendor/capell-app/admin/resources/views/**/*.blade.php';")
        ->toContain("@source '../../../../storage/capell/tailwind-classes.txt';");
});

it('falls back to the default theme file when the panel provider cannot be parsed or has no Vite theme', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $defaultThemePath = base_path('resources/css/filament/admin/theme.css');

    writeThemeSourcesFile($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

class AdminPanelProvider
{
    public function panel(
PHP);
    writeThemeSourcesFile($defaultThemePath, <<<'CSS'
@import 'tailwindcss';
CSS);

    $patch = new ThemeSourcesPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect(file_get_contents($defaultThemePath))
        ->toContain("@source '../../../../vendor/capell-app/installer/resources/views/**/*.blade.php';");

    writeThemeSourcesFile($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

class AdminPanelProvider
{
}
PHP);
    writeThemeSourcesFile($defaultThemePath, "@import 'tailwindcss';\n");

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect(file_get_contents($defaultThemePath))
        ->toContain("@source '../../../../vendor/capell-app/marketplace/resources/views/**/*.blade.php';");
});

it('does not rewrite a theme file that already contains all required sources', function (): void {
    $themePath = base_path('resources/css/filament/admin/theme.css');

    writeThemeSourcesFile($themePath, <<<'CSS'
@import 'tailwindcss';
@source '../../../../vendor/capell-app/admin/resources/views/**/*.blade.php';
@source '../../../../vendor/capell-app/installer/resources/views/**/*.blade.php';
@source '../../../../vendor/capell-app/marketplace/resources/views/**/*.blade.php';
@source '../../../../storage/capell/tailwind-classes.txt';
@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
CSS);

    $patch = new ThemeSourcesPatch;

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied);

    expect(function () use ($patch): void {
        $patch->apply();
    })
        ->toThrow(RuntimeException::class, 'Cannot apply patch when status is: already_applied');
});

function restoreThemeSourcesFile(string $path, ?string $originalContent): void
{
    if (is_string($originalContent)) {
        writeThemeSourcesFile($path, $originalContent);

        return;
    }

    if (file_exists($path)) {
        unlink($path);
    }
}

function writeThemeSourcesFile(string $path, string $content): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $content);
}
