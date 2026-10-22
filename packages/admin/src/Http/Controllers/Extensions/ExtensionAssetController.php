<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Extensions;

use Capell\Admin\Actions\Extensions\ResolveExtensionAssetPathAction;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExtensionAssetController
{
    public function __invoke(Request $request, CapellPackageRegistry $registry): BinaryFileResponse
    {
        $actor = $request->user();

        abort_unless(
            $actor instanceof FilamentUser
                && $actor->canAccessPanel(Filament::getPanel('admin'))
                && ExtensionsPage::canAccess(),
            403,
        );

        // TrimStrings can remove a trailing NUL before the resolver sees the query value.
        $queryString = $request->server('QUERY_STRING');
        abort_if(is_string($queryString) && str_contains(urldecode($queryString), "\0"), 404);

        $packageName = $request->query('package');
        $assetPath = $request->query('path');

        abort_unless(is_string($packageName) && is_string($assetPath), 404);

        $manifest = $registry->all()[$packageName] ?? null;

        abort_unless($manifest !== null, 404);

        $absoluteAssetPath = ResolveExtensionAssetPathAction::run($manifest, $assetPath);

        abort_unless($absoluteAssetPath !== null, 404);

        return response()->file($absoluteAssetPath, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
