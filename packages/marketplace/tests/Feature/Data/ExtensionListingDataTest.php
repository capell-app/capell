<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Filesystem\Filesystem;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

it('links the local card only when the asset route can serve it', function (string $target, string $viewer): void {
    $directory = sys_get_temp_dir() . '/capell-listing-asset-' . bin2hex(random_bytes(8));
    $path = $directory . '/package';
    $files = new Filesystem;
    $files->makeDirectory($path . '/docs/assets/marketplace', recursive: true);

    $card = $path . '/docs/assets/marketplace/extension-card.jpg';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', strict: true);
    assert(is_string($png));

    try {
        if ($target === 'image') {
            $files->put($card, $png);
        } elseif ($target === 'text') {
            $files->put($card, '<?php echo "private";');
        } elseif ($target === 'symlink') {
            $files->put($directory . '/outside.png', $png);
            symlink($directory . '/outside.png', $card);
        } elseif ($target === 'directory') {
            $files->makeDirectory($card);
        }

        $manifest = CapellManifestData::fromArray(capellManifestV3Array(name: 'capell-app/listing-asset-test'), $path);
        resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
        $listing = ExtensionListingData::fromApiResponse([
            'slug' => 'listing-asset-test',
            'name' => 'Asset Test',
            'composer_name' => $manifest->name,
            'image_url' => 'https://capell.test/fallback.png',
        ]);

        if ($viewer === 'marketplace') {
            Role::findOrCreate('panel_user', 'web');
            Permission::findOrCreate('View:MarketplacePage', 'web');
            Permission::findOrCreate('View:ExtensionsPage', 'web');
            $this->actingAsRole('panel_user');
            $this->authenticatedUser()->givePermissionTo('View:MarketplacePage');
            expect($this->authenticatedUser()->can('View:MarketplacePage'))->toBeTrue()
                ->and($this->authenticatedUser()->can('View:ExtensionsPage'))->toBeFalse();
        } else {
            $this->actingAsAdmin();
        }

        $url = route('capell-admin.extension-asset', [
            'package' => $manifest->name,
            'path' => 'docs/assets/marketplace/extension-card.jpg',
        ]);

        if ($target === 'image') {
            expect($listing->imageUrl)->toBe($url);
            $this->get($listing->imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        } else {
            expect($listing->imageUrl)->toBe('https://capell.test/fallback.png')
                ->and($listing->imageUrls)->toBe(['https://capell.test/fallback.png']);
            $this->get($url)->assertNotFound();
        }
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['image', 'missing', 'text', 'symlink', 'directory'])->with(['admin', 'marketplace']);
