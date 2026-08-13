<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Fragments;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicFragmentContentVersionAction
{
    use AsFake;
    use AsObject;

    /**
     * @var array<string, array{
     *     page: array<string, mixed>,
     *     site: array<string, mixed>,
     *     language: array<string, mixed>,
     *     layout: array<string, mixed>,
     *     translation: array<string, mixed>|null,
     *     pageUrl: array<string, mixed>|null
     * }>
     */
    private array $contextSnapshots = [];

    /**
     * @param  array<string, int|string>  $ownerContext
     */
    public function handle(
        Model&Pageable $page,
        Site $site,
        Language $language,
        Layout $layout,
        array $ownerContext,
        bool $fresh = false,
    ): string {
        $snapshotKey = $this->snapshotKey($page, $site, $language, $layout);
        $contextSnapshot = $fresh
            ? $this->resolveContextSnapshot($page, $site, $language, $layout)
            : ($this->contextSnapshots[$snapshotKey]
                ??= $this->resolveContextSnapshot($page, $site, $language, $layout));

        $payload = $this->canonicalize([
            ...$contextSnapshot,
            'ownerContext' => $ownerContext,
        ]);

        return hash('sha256', JsonCodec::encode($payload));
    }

    /**
     * @param  Model&Pageable  $page
     * @return array{
     *     page: array<string, mixed>,
     *     site: array<string, mixed>,
     *     language: array<string, mixed>,
     *     layout: array<string, mixed>,
     *     translation: array<string, mixed>|null,
     *     pageUrl: array<string, mixed>|null
     * }
     */
    private function resolveContextSnapshot(
        Model $page,
        Site $site,
        Language $language,
        Layout $layout,
    ): array {
        $freshPage = $page->newQuery()->withTrashed()->whereKey($page->getKey())->firstOrFail();
        $freshSite = Site::query()->withTrashed()->whereKey($site->getKey())->firstOrFail();
        $freshLanguage = Language::query()->withTrashed()->whereKey($language->getKey())->firstOrFail();
        $freshLayout = Layout::query()->withTrashed()->whereKey($layout->getKey())->firstOrFail();

        throw_unless($freshPage instanceof Pageable, LogicException::class, 'Public fragment pages must implement the Pageable contract.');

        $translation = Translation::query()
            ->where('translatable_type', $freshPage->getMorphClass())
            ->where('translatable_id', $freshPage->getKey())
            ->where('language_id', $freshLanguage->getKey())
            ->first();
        $pageUrl = PageUrl::query()
            ->where('pageable_type', $freshPage->getMorphClass())
            ->where('pageable_id', $freshPage->getKey())
            ->where('site_id', $freshSite->getKey())
            ->where('language_id', $freshLanguage->getKey())
            ->first();

        return [
            'page' => $freshPage->getAttributes(),
            'site' => $freshSite->getAttributes(),
            'language' => $freshLanguage->getAttributes(),
            'layout' => $freshLayout->getAttributes(),
            'translation' => $translation?->getAttributes(),
            'pageUrl' => $pageUrl?->getAttributes(),
        ];
    }

    /**
     * @param  Model&Pageable  $page
     */
    private function snapshotKey(Model $page, Site $site, Language $language, Layout $layout): string
    {
        return hash('sha256', JsonCodec::encode($this->canonicalize([
            'page' => [
                'type' => $page->getMorphClass(),
                'connection' => $page->getConnectionName(),
                'id' => $page->getKey(),
            ],
            'site' => [
                'connection' => $site->getConnectionName(),
                'id' => $site->getKey(),
            ],
            'language' => [
                'connection' => $language->getConnectionName(),
                'id' => $language->getKey(),
            ],
            'layout' => [
                'connection' => $layout->getConnectionName(),
                'id' => $layout->getKey(),
            ],
        ])));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalize($value);
            }
        }

        return $values;
    }
}
