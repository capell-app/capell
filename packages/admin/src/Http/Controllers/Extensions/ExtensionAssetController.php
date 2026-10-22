<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Extensions;

use Capell\Admin\Actions\Extensions\ResolveExtensionAssetPathAction;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExtensionAssetController
{
    public function __invoke(Request $request, CapellPackageRegistry $registry): BinaryFileResponse
    {
        $panel = Filament::getPanel('admin');
        abort_unless($panel !== null, 403);

        $actor = $panel->auth()->user();
        abort_unless($actor !== null, 401);
        abort_unless($actor instanceof FilamentUser && $actor->canAccessPanel($panel), 403);

        // TrimStrings can remove a trailing NUL before the resolver sees the query value.
        $queryString = $request->server('QUERY_STRING');
        abort_if(is_string($queryString) && str_contains(urldecode($queryString), "\0"), 404);

        // Reject whitespace before TrimStrings can select a different declared image.
        if (is_string($queryString)) {
            parse_str($queryString, $rawQuery);
            $rawPath = $rawQuery['path'] ?? null;
            abort_if(is_string($rawPath) && Str::trim($rawPath) !== $rawPath, 404);
        }

        $packageName = $request->query('package');
        $assetPath = $request->query('path');

        abort_unless(is_string($packageName) && is_string($assetPath), 404);

        $manifest = $registry->all()[$packageName] ?? null;

        abort_unless($manifest !== null, 404);

        $absoluteAssetPath = ResolveExtensionAssetPathAction::run($manifest, $assetPath);

        abort_if($absoluteAssetPath === null, 404);

        $response = response()->file($absoluteAssetPath, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->setPrivate();

        if (strtolower(pathinfo((string) $absoluteAssetPath, PATHINFO_EXTENSION)) === 'svg') {
            $response->headers->set('Content-Type', 'image/svg+xml');
            $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
        }

        return $response;
    }
}
