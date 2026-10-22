<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Filesystem\Filesystem;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function extensionAssetFixturePath(): string
{
    $path = resolve(CapellPackageRegistry::class)->all()['capell-app/asset-test']->installPath;
    assert(is_string($path));

    return $path;
}

function extensionAssetUrl(string $path, string $package = 'capell-app/asset-test'): string
{
    return route('capell-admin.extension-asset', ['package' => $package, 'path' => $path]);
}

beforeEach(function (): void {
    $path = sys_get_temp_dir() . '/capell-extension-asset-' . bin2hex(random_bytes(8)) . '/package';
    $files = new Filesystem;
    $files->makeDirectory($path . '/docs/assets/marketplace', recursive: true);
    $files->makeDirectory($path . '/src');
    $files->makeDirectory($path . '/config');
    $files->makeDirectory($path . '/.private');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', strict: true);
    assert(is_string($png));
    foreach (['docs/screenshot.png', 'docs/undeclared.png', '.private/secret.png', 'docs/assets/marketplace/extension-card.jpg'] as $image) {
        $files->put($path . '/' . $image, $png);
    }

    foreach (['README.md', 'src/Secret.php', 'composer.json', '.env', '.env.local', 'config/private.php', 'docs/fake.png', 'docs/vector.svg'] as $file) {
        $files->put($path . '/' . $file, 'private package contents');
    }

    $files->put(dirname($path) . '/outside.png', $png);
    symlink(dirname($path) . '/outside.png', $path . '/docs/escaped.png');
    symlink($path . '/.private/secret.png', $path . '/docs/hidden.png');
    $files->put($path . '/README-image.md', $png);
    symlink($path . '/README-image.md', $path . '/docs/markdown.png');

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/asset-test',
        surfaces: ['admin'],
        overrides: ['marketplace' => ['screenshots' => array_map(
            static fn (string $path): array => ['path' => $path, 'alt' => 'Test image'],
            ['docs/screenshot.png', 'README.md', 'src/Secret.php', 'composer.json', '.env', '.env.local', 'config/private.php', 'docs/fake.png', 'docs/vector.svg', '.private/secret.png', 'docs/escaped.png', 'docs/hidden.png', 'docs/markdown.png', 'docs'],
        )]],
    ), $path);
    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(dirname(extensionAssetFixturePath()));
});

it('serves declared screenshots and the fixed marketplace card to an admin as images', function (string $path): void {
    $this->actingAsAdmin()->get(extensionAssetUrl($path))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with(['docs/screenshot.png', 'docs/assets/marketplace/extension-card.jpg']);

it('denies private and unsafe package targets even when declared as screenshots', function (string $path): void {
    $this->actingAsAdmin()->get(extensionAssetUrl($path))->assertNotFound();
})->with([
    'readme' => 'README.md',
    'source' => 'src/Secret.php',
    'composer manifest' => 'composer.json',
    'environment' => '.env',
    'local environment' => '.env.local',
    'configuration' => 'config/private.php',
    'undeclared image' => 'docs/undeclared.png',
    'disguised text' => 'docs/fake.png',
    'svg' => 'docs/vector.svg',
    'dot directory' => '.private/secret.png',
    'directory' => 'docs',
    'parent segment' => 'docs/../docs/screenshot.png',
    'outside traversal' => '../outside.png',
    'absolute path' => '/docs/screenshot.png',
    'NUL byte' => "docs/screen\0shot.png",
    'trailing NUL byte' => "docs/screenshot.png\0",
    'backslash' => 'docs\\screenshot.png',
    'escaping symlink' => 'docs/escaped.png',
    'symlink to hidden image' => 'docs/hidden.png',
    'symlink to markdown with image contents' => 'docs/markdown.png',
]);

it('rejects encoded traversal through the real route', function (): void {
    $this->actingAsAdmin()->get(route('capell-admin.extension-asset') . '?package=capell-app%2Fasset-test&path=docs%2F%2E%2E%2Fdocs%2Fscreenshot.png')->assertNotFound();
});

it('returns a plain not found response for an unknown package or missing file', function (): void {
    $this->actingAsAdmin()->get(extensionAssetUrl('docs/screenshot.png', 'capell-app/unknown'))->assertNotFound();
    $this->get(extensionAssetUrl('docs/missing.png'))->assertNotFound();
});

it('requires authentication for extension assets', function (): void {
    $this->getJson(extensionAssetUrl('docs/screenshot.png'))->assertUnauthorized();
});

it('denies authenticated users without admin panel access even with the page permission', function (): void {
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    $this->actingAsUser();
    $this->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
    $this->get(extensionAssetUrl('docs/screenshot.png'))->assertForbidden();
});

it('requires the Extensions page permission as well as panel access', function (): void {
    Role::findOrCreate('panel_user', 'web');
    $this->actingAsRole('panel_user')->get(extensionAssetUrl('docs/screenshot.png'))->assertForbidden();
});

it('allows a panel user with the Extensions page permission', function (): void {
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    Role::findOrCreate('panel_user', 'web');
    $this->actingAsRole('panel_user');
    $this->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
    $this->get(extensionAssetUrl('docs/screenshot.png'))->assertOk();
});

it('links only serviceable local screenshots and preserves the marketplace fallback', function (string $path, bool $local): void {
    $manifest = resolve(CapellPackageRegistry::class)->all()['capell-app/asset-test'];
    $manifest->marketplaceScreenshots = [ExtensionScreenshotData::fromArray(['path' => $path, 'alt' => 'Test'])];
    $this->actingAsAdmin();

    $images = BuildExtensionOperationsSummaryAction::run()->packages[$manifest->name]->imageUrls;

    if ($local) {
        expect($images)->toBe([extensionAssetUrl($path)]);
        $this->get($images[0])->assertOk();
    } else {
        expect($images)->toBe([MarketplaceAssetUrl::toUrl($path)]);
        $this->get(extensionAssetUrl($path))->assertNotFound();
    }
})->with([
    'declared image' => ['docs/screenshot.png', true],
    'missing image' => ['docs/missing.png', false],
    'non image declaration' => ['README.md', false],
    'disguised text' => ['docs/fake.png', false],
    'escaping symlink' => ['docs/escaped.png', false],
]);
