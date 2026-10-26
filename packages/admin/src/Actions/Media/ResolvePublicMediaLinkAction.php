<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Support\AuthoringLinkRequestCache;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicMediaLinkAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media): ?string
    {
        return AuthoringLinkRequestCache::remember(
            self::class . ':' . $media->getKey(),
            fn (SiteAccess $access): ?string => $this->resolve($media, $access),
        );
    }

    private function resolve(Media $media, SiteAccess $access): ?string
    {
        if (! Gate::allows('view', $media)
            || ! $access->scopeMedia($access->query(Media::class))->whereKey($media->getKey())->exists()) {
            return null;
        }

        if ($media->collection_name === MediaCollectionEnum::Video->value || $media->isExternalVideo()) {
            $video = $media->externalVideo();
            $parsed = $video instanceof ExternalVideoData ? YouTubeVideoUrl::parse($video->url) : null;

            return $parsed instanceof ExternalVideoData ? 'https://www.youtube.com/watch?v=' . $parsed->videoId : null;
        }

        if (config('filesystems.disks.' . $media->disk . '.visibility') !== 'public') {
            return null;
        }

        try {
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();

            if (! $disk->exists($path) || $disk->getVisibility($path) !== 'public') {
                return null;
            }

            return $media->getUrl();
        } catch (FilesystemException $filesystemException) {
            // Adapter messages can contain paths, endpoint details or credentials.
            Log::warning('Unable to resolve public media link.', [
                'media_id' => $media->getKey(),
                'disk' => $media->disk,
                'exception' => $filesystemException::class,
            ]);

            return null;
        }
    }
}
