<?php

declare(strict_types=1);

namespace Capell\Core\Http\Middleware;

use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Closure;
use Illuminate\Http\Request;

final class EnsureMultiNodeUploadsUseSharedStorage
{
    public function __construct(private readonly MultiNodeTopologyGuard $topologyGuard) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->routeIs('livewire.upload-file')) {
            return $next($request);
        }

        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        $this->topologyGuard->assertFilesystemDiskIsShared(
            is_string($disk) ? $disk : '',
            'Livewire temporary file uploads',
        );

        return $next($request);
    }
}
