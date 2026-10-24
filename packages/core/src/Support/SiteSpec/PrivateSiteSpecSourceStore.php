<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Authored source is kept outside models hydrated into public render data. */
final class PrivateSiteSpecSourceStore
{
    public function path(string $hash): string
    {
        throw_unless(preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, RuntimeException::class, 'Invalid site spec hash.');

        return storage_path('app/capell-private/site-spec/' . $hash . '.json');
    }

    public function put(string $hash, CapellSiteSpecData $spec): void
    {
        $path = $this->path($hash);
        File::ensureDirectoryExists(dirname($path), 0700, true);
        throw_unless(File::chmod(dirname($path), 0700), RuntimeException::class, 'Cannot secure private site spec directory.');
        File::replace($path, JsonCodec::encode($spec->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        throw_unless(File::chmod($path, 0600), RuntimeException::class, 'Cannot secure private site spec source.');
    }
}
