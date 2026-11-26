<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Actions\Extensions\ResolveExtensionAssetPathAction;
use Capell\Core\Support\Manifest\CapellManifestData;
use Illuminate\Support\Facades\Route;

/**
 * Links to an extension's declared image through the admin asset route.
 *
 * Each link carries a version taken from the file itself, so the route can let the
 * browser keep the image for good: the extensions screen and the marketplace show
 * a few dozen of these, and without browser caching every visit sent each one back
 * through PHP to be decoded again. Upgrading the package changes the file, which
 * changes the link.
 */
final class ExtensionAssetUrl
{
    public const string VERSION_PARAMETER = 'v';

    public static function for(CapellManifestData $manifest, string $path): ?string
    {
        if (! Route::has('capell-admin.extension-asset')) {
            return null;
        }

        $absolutePath = ResolveExtensionAssetPathAction::run($manifest, $path, verifyDecode: false);

        if ($absolutePath === null) {
            return null;
        }

        return route('capell-admin.extension-asset', [
            'package' => $manifest->name,
            'path' => $path,
            self::VERSION_PARAMETER => self::version($absolutePath),
        ]);
    }

    public static function version(string $absolutePath): string
    {
        clearstatcache(true, $absolutePath);

        return substr(hash('xxh3', $absolutePath . '|' . (int) filemtime($absolutePath) . '|' . (int) filesize($absolutePath)), 0, 16);
    }
}
