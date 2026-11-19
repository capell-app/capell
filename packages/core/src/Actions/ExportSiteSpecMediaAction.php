<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecMediaData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Writes the site's logo and each exported page's image as the remote URLs a
 * SiteSpec carries, the inverse of {@see FetchSiteSpecMediaAction}. An import
 * downloads over HTTPS from one declared origin within fixed size limits, so an
 * image that import would refuse (served over plain HTTP, from another origin,
 * too large, not a web image, or beyond the image limit) is left out and named
 * instead of written as a spec that fails to apply. Applying the export back to
 * the same site attaches nothing: the import recognises an image it already has
 * by its content.
 *
 * @method static array{media: CapellSiteSpecMediaData, omitted: list<string>} run(Site $site, array<int, string> $pageSlugsById)
 */
final class ExportSiteSpecMediaAction
{
    use AsFake;
    use AsObject;

    private const array IMAGE_MIME_TYPES = ['image/gif', 'image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param  array<int, string>  $pageSlugsById
     * @return array{media: CapellSiteSpecMediaData, omitted: list<string>}
     */
    public function handle(Site $site, array $pageSlugsById): array
    {
        $origin = null;
        $omitted = [];
        $logo = null;
        $images = [];
        $totalBytes = 0;

        $candidates = [['slug' => null, 'media' => $site->getFirstMedia(MediaCollectionEnum::Logo->value)]];

        $slugs = $pageSlugsById;
        asort($slugs);

        $pages = $site->pages()->whereKey(array_keys($slugs))->with('media')->get()->keyBy(static fn (Page $page): int => (int) $page->getKey());

        foreach ($slugs as $pageId => $slug) {
            $page = $pages->get($pageId);
            $candidates[] = ['slug' => $slug, 'media' => $page?->getFirstMedia(MediaCollectionEnum::Image->value)];
        }

        foreach ($candidates as $candidate) {
            $media = $candidate['media'];

            if (! $media instanceof Media) {
                continue;
            }

            $url = $this->url($media);
            $reason = $this->refusal($media, $url, $origin, count($images), $totalBytes);

            if ($reason !== null) {
                $omitted[] = (string) __('capell-core::message.site_spec_export_media_item', [
                    'item' => $candidate['slug'] === null ? (string) __('capell-core::message.site_spec_export_media_logo') : (string) __('capell-core::message.site_spec_export_media_page', ['page' => $candidate['slug']]),
                    'reason' => $reason,
                ]);

                continue;
            }

            $origin ??= $this->origin((string) $url);
            $totalBytes += (int) $media->size;

            if ($candidate['slug'] === null) {
                $logo = (string) $url;
            } else {
                $images[$candidate['slug']] = (string) $url;
            }
        }

        return [
            'media' => new CapellSiteSpecMediaData(sourceUrl: $origin, logo: $logo, images: $images),
            'omitted' => $omitted,
        ];
    }

    private function url(Media $media): ?string
    {
        try {
            return $media->getFullUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function refusal(Media $media, ?string $url, ?string $origin, int $count, int $totalBytes): ?string
    {
        $parts = $url === null ? false : parse_url($url);

        return match (true) {
            $url === null || ! is_array($parts) => (string) __('capell-core::message.site_spec_export_media_reason_no_address'),
            config('filesystems.disks.' . $media->disk . '.visibility') !== 'public' => (string) __('capell-core::message.site_spec_export_media_reason_private_disk'),
            strlen($url) > CapellSiteSpecConstraints::MAX_REMOTE_URL_LENGTH => (string) __('capell-core::message.site_spec_export_media_reason_long_address'),
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) => (string) __('capell-core::message.site_spec_export_media_reason_not_https'),
            $origin !== null && $this->origin($url) !== $origin => (string) __('capell-core::message.site_spec_export_media_reason_other_origin'),
            ! in_array(strtolower((string) $media->mime_type), self::IMAGE_MIME_TYPES, true) => (string) __('capell-core::message.site_spec_export_media_reason_not_image'),
            (int) $media->size > CapellSiteSpecConstraints::MAX_MEDIA_FILE_BYTES => (string) __('capell-core::message.site_spec_export_media_reason_too_large'),
            $totalBytes + (int) $media->size > CapellSiteSpecConstraints::MAX_MEDIA_TOTAL_BYTES => (string) __('capell-core::message.site_spec_export_media_reason_total_too_large'),
            $count >= CapellSiteSpecConstraints::MAX_MEDIA_IMAGES => (string) __('capell-core::message.site_spec_export_media_reason_too_many', ['max' => CapellSiteSpecConstraints::MAX_MEDIA_IMAGES]),
            default => null,
        };
    }

    private function origin(string $url): string
    {
        $parts = (array) parse_url($url);
        $port = (int) ($parts['port'] ?? 443);

        return sprintf('https://%s%s', strtolower(trim((string) ($parts['host'] ?? ''), '[]')), $port === 443 ? '' : ':' . $port);
    }
}
