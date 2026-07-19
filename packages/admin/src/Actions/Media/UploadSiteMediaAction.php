<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UploadSiteMediaAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site, mixed $uploadedFiles): int
    {
        $paths = collect(is_array($uploadedFiles) ? $uploadedFiles : [])
            ->flatten()
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values();

        $paths->each(function (string $path) use ($site): void {
            $absolutePath = Storage::disk('local')->path($path);

            $site
                ->addMedia($absolutePath)
                ->usingName(pathinfo($path, PATHINFO_FILENAME))
                ->toMediaCollection('uploads', config('media-library.disk_name', 'public'));
        });

        return $paths->count();
    }
}
