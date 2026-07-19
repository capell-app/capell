<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecMediaDownload;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class AttachSiteSpecMediaAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, Page>  $pagesBySlug
     * @param  list<SiteSpecMediaDownload>  $downloads
     */
    public function handle(Site $site, array $pagesBySlug, array $downloads): void
    {
        /** @var list<Media> $attachedMedia */
        $attachedMedia = [];

        try {
            foreach ($downloads as $download) {
                $owner = $download->pageSlug === null
                    ? $site
                    : ($pagesBySlug[$download->pageSlug] ?? null);

                throw_unless($owner instanceof Site || $owner instanceof Page, RuntimeException::class, sprintf(
                    'Unable to attach site spec media to missing page [%s].',
                    (string) $download->pageSlug,
                ));

                $attachedMedia[] = $owner->addMedia($download->path)
                    ->preservingOriginal()
                    ->usingFileName($download->fileName)
                    ->usingName(pathinfo($download->fileName, PATHINFO_FILENAME))
                    ->withCustomProperties([
                        'capell' => [
                            'site_spec' => [
                                'source_origin' => $download->sourceOrigin,
                                'source_hash' => $download->sourceHash,
                            ],
                        ],
                    ])
                    ->toMediaCollection($download->collection->value);
            }
        } catch (Throwable $throwable) {
            foreach (array_reverse($attachedMedia) as $media) {
                try {
                    $media->delete();
                } catch (Throwable) {
                    // Preserve the original attachment failure.
                }
            }

            throw $throwable;
        }
    }
}
