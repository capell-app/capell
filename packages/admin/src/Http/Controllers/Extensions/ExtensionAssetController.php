<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Extensions;

use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExtensionAssetController
{
    public function __invoke(Request $request, CapellPackageRegistry $registry): BinaryFileResponse
    {
        $packageName = $request->query('package');
        $assetPath = $request->query('path');

        abort_unless(is_string($packageName) && is_string($assetPath), 404);

        $manifest = $registry->all()[$packageName] ?? null;

        abort_unless($manifest?->installPath !== null, 404);

        $packagePath = realpath($manifest->installPath);

        abort_unless(is_string($packagePath), 404);

        $absoluteAssetPath = realpath($packagePath . DIRECTORY_SEPARATOR . ltrim($assetPath, '/'));

        abort_unless(
            is_string($absoluteAssetPath)
                && str_starts_with($absoluteAssetPath, $packagePath . DIRECTORY_SEPARATOR)
                && is_file($absoluteAssetPath),
            404,
        );

        return response()->file($absoluteAssetPath);
    }
}
