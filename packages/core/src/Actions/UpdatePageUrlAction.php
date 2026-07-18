<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Site $site, Translation $translation, string $parentUrl = '')
 */
final class UpdatePageUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site, Translation $translation, string $parentUrl = ''): void
    {
        if (! str_starts_with($parentUrl, '/')) {
            $parentUrl = '/' . $parentUrl;
        }

        $this->updateTranslation($site, $translation, $parentUrl);
    }

    private function updateTranslation(Site $site, Translation $translation, string $url): void
    {
        $slug = $translation->slug;

        if ($slug !== '/') {
            if (! str_ends_with($url, '/')) {
                $url .= '/';
            }

            $url .= $slug;
        }

        $this->saveUrl([
            'url' => $url,
            'pageable_id' => $translation->translatable_id,
            'pageable_type' => $translation->translatable_type,
            'language_id' => $translation->language_id,
            'site_id' => $site->getKey(),
        ]);
    }

    /**
     * @param  array{url: string, pageable_id: int|string|null, pageable_type: string|null, language_id: int|string|null, site_id: int|string|null}  $data
     */
    private function saveUrl(array $data, bool $quietly = false): PageUrl
    {
        /** @var class-string<PageUrl> $model */
        $model = PageUrl::class;

        $url = $model::query()->firstOrNew([
            'language_id' => $data['language_id'],
            'site_id' => $data['site_id'],
            'pageable_id' => $data['pageable_id'],
            'pageable_type' => $data['pageable_type'],
        ]);

        $url->fill(['url' => $data['url']]);

        if ($quietly) {
            $url->saveQuietly();
        } else {
            $url->save();
        }

        return $url;
    }
}
