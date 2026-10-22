<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Auth\SessionGuard;
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
    $files->makeDirectory($path . '/ docs');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', strict: true);
    assert(is_string($png));
    foreach (["\x0C", "\u{00A0}", "\u{200B}"] as $padding) {
        $files->makeDirectory($path . '/' . $padding . 'docs');
        $files->put($path . '/' . $padding . 'docs/screenshot.png', $png);
    }

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', strict: true);
    assert(is_string($png));
    foreach (['docs/screenshot.png', 'docs/undeclared.png', '.private/secret.png', 'docs/assets/marketplace/extension-card.jpg', ' docs/screenshot.png', 'docs/mixed.PnG', 'docs/image.JPG.php', 'docs/image.png.'] as $image) {
        $files->put($path . '/' . $image, $png);
    }

    foreach (['README.md', 'src/Secret.php', 'composer.json', '.env', '.env.local', 'config/private.php', 'docs/fake.png', 'docs/vector.svg'] as $file) {
        $files->put($path . '/' . $file, 'private package contents');
    }

    $files->put($path . '/docs/clean.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" fill="red"/></svg>');
    $files->put($path . '/docs/truncated.gif', 'GIF89a');
    $files->put($path . '/docs/header-only.png', substr($png, 0, 25));

    $large = imagecreatetruecolor(3000, 3000);
    ob_start();
    imagepng($large, null, 9);
    $largePng = (string) ob_get_clean();
    $files->put($path . '/docs/large.png', $largePng);
    $files->put($path . '/docs/large-truncated.png', substr($largePng, 0, (int) (strlen($largePng) / 2)));
    $files->put($path . '/docs/doctype.svg', '<!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>');
    $files->put($path . '/docs/oversized.png', $png . str_repeat(' ', 10 * 1024 * 1024));
    foreach ([
        'script' => '<ScRiPt>alert(1)</ScRiPt>',
        'event' => '<rect OnLoad = "alert(1)"/>',
        'javascript' => '<a href="JaVaScRiPt:alert(1)">link</a>',
        'foreign-object' => '<foreignObject><p>HTML</p></foreignObject>',
    ] as $name => $markup) {
        $files->put($path . '/docs/' . $name . '.svg', '<svg xmlns="http://www.w3.org/2000/svg">' . $markup . '</svg>');
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
            ['docs/screenshot.png', 'README.md', 'src/Secret.php', 'composer.json', '.env', '.env.local', 'config/private.php', 'docs/fake.png', 'docs/vector.svg', '.private/secret.png', 'docs/escaped.png', 'docs/hidden.png', 'docs/markdown.png', 'docs', 'docs/clean.svg', 'docs/script.svg', 'docs/event.svg', 'docs/javascript.svg', 'docs/foreign-object.svg', 'docs/truncated.gif', 'docs/header-only.png', 'docs/large.png', 'docs/large-truncated.png', 'docs/doctype.svg', 'docs/oversized.png', ' docs/screenshot.png', "\x0Cdocs/screenshot.png", "\u{00A0}docs/screenshot.png", "\u{200B}docs/screenshot.png", 'docs/mixed.PnG', 'docs/image.JPG.php', 'docs/image.png.', 'docs//screenshot.png', 'docs/../docs/screenshot.png', '../outside.png', '/docs/screenshot.png', dirname($path) . '/outside.png'],
        )]],
    ), $path);
    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(dirname(extensionAssetFixturePath()));
});

it('serves declared screenshots and the fixed marketplace card to an admin as images', function (string $path): void {
    $response = $this->actingAsAdmin()->get(extensionAssetUrl($path))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')->not->toContain('public');
})->with(['docs/screenshot.png', 'docs/assets/marketplace/extension-card.jpg', 'docs/mixed.PnG', 'docs/large.png']);

it('serves a clean declared SVG with a sandbox policy', function (): void {
    $this->actingAsAdmin()->get(extensionAssetUrl('docs/clean.svg'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

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
    'disguised SVG' => 'docs/vector.svg',
    'script SVG' => 'docs/script.svg',
    'event handler SVG' => 'docs/event.svg',
    'javascript SVG' => 'docs/javascript.svg',
    'foreign object SVG' => 'docs/foreign-object.svg',
    'truncated raster' => 'docs/truncated.gif',
    'header-only PNG that does not decode' => 'docs/header-only.png',
    'large truncated PNG without a final chunk' => 'docs/large-truncated.png',
    'SVG with a DOCTYPE and entity' => 'docs/doctype.svg',
    'leading form feed' => "\x0Cdocs/screenshot.png",
    'leading no-break space' => "\u{00A0}docs/screenshot.png",
    'leading zero-width space' => "\u{200B}docs/screenshot.png",
    'oversized raster' => 'docs/oversized.png',
    'leading whitespace' => ' docs/screenshot.png',
    'executable double suffix' => 'docs/image.JPG.php',
    'trailing dot' => 'docs/image.png.',
    'double slash' => 'docs//screenshot.png',
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

it('allows panel users without the Extensions page permission', function (): void {
    Role::findOrCreate('panel_user', 'web');
    $this->actingAsRole('panel_user')->get(extensionAssetUrl('docs/screenshot.png'))->assertOk();
});

it('uses only the admin panel guard even when another panel is the default', function (bool $otherDefault, bool $adminSession, bool $panelAccess): void {
    config()->set('auth.guards.admin', config('auth.guards.web'));
    $panel = Filament::getPanel('admin');
    expect($panel)->toBeInstanceOf(Panel::class);
    $panel->authGuard('admin');

    if ($otherDefault) {
        $other = Panel::make()->id('other')->authGuard('web')->default();
        resolve(PanelRegistry::class)->defaultPanel = $other;
        Filament::setCurrentPanel($other);
    }

    Role::findOrCreate('panel_user', 'web');
    $user = $panelAccess ? $this->createUserWithRole('panel_user') : $this->createUser();
    if ($adminSession) {
        $guard = $panel->auth();
        assert($guard instanceof SessionGuard);
        $this->withSession([$guard->getName() => $user->getAuthIdentifier()]);
    } else {
        $this->actingAs($user, 'web');
    }

    auth()->shouldUse('web');
    expect(auth()->guard('web')->check())->toBe(! $adminSession)
        ->and(auth()->guard('admin')->check())->toBe($adminSession);
    $response = $this->get(extensionAssetUrl('docs/screenshot.png'));
    $response->assertStatus($adminSession ? ($panelAccess ? 200 : 403) : 401);
})->with([
    'admin guard session only' => [false, true, true],
    'alternative default panel' => [true, true, true],
    'default guard must not authorise admin assets' => [false, false, true],
    'alternative panel must not authorise admin assets' => [true, false, true],
    'admin guard without panel admission' => [true, true, false],
]);

it('denies unauthenticated requests when the admin panel uses a separate guard', function (): void {
    config()->set('auth.guards.admin', config('auth.guards.web'));
    Filament::getPanel('admin')->authGuard('admin');

    $this->getJson(extensionAssetUrl('docs/screenshot.png'))->assertUnauthorized();
});

it('denies access when the admin panel is missing', function (): void {
    $this->actingAsAdmin();
    unset(resolve(PanelRegistry::class)->panels['admin']);

    $this->get(extensionAssetUrl('docs/screenshot.png'))->assertForbidden();
});

it('rejects an absolute filesystem path even when maliciously declared', function (): void {
    $this->actingAsAdmin()->get(extensionAssetUrl(dirname(extensionAssetFixturePath()) . '/outside.png'))->assertNotFound();
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
    'clean SVG' => ['docs/clean.svg', true],
    'script SVG' => ['docs/script.svg', false],
    'leading whitespace' => [' docs/screenshot.png', false],
    'trailing whitespace' => ['docs/screenshot.png ', false],
    'malicious declared traversal' => ['../outside.png', false],
    'mixed case' => ['docs/mixed.PnG', true],
    'executable double suffix' => ['docs/image.JPG.php', false],
    'trailing dot' => ['docs/image.png.', false],
    'double slash' => ['docs//screenshot.png', false],
    'missing image' => ['docs/missing.png', false],
    'non image declaration' => ['README.md', false],
    'disguised text' => ['docs/fake.png', false],
    'escaping symlink' => ['docs/escaped.png', false],
]);
