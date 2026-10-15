<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\IncrementNameAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\Slug\SlugGenerator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static Page run(Page $page, array<string, mixed> $data = [])
 */
class ReplicatePageAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Page $page, array $data = []): Page
    {
        // Cloning saves the page and registered relations before translations create their URLs.
        return $page->getConnection()->transaction(function () use ($page, $data): Page {
            $page->load('translations');

            $translations = [];
            if (isset($data['translations'])) {
                $translations = $data['translations'];
                unset($data['translations']);
            }

            $model = $page->fresh();
            throw_if($model === null, RuntimeException::class, 'Page could not be refreshed for replication.');

            $model->fill($data);

            $replica = $model->duplicate();
            throw_if($replica === null, RuntimeException::class, 'Page could not be duplicated.');

            if ($model->isClean('name')) {
                $replica->name = $this->getPageName($model);
            }

            // Nested-set replication clears the parent; retain the form's requested URL context.
            $replica->parent_id = $model->parent_id;

            $replica->setAttribute('created_at', now());
            $replica->setAttribute('updated_at', now());

            $replica->save();

            if ($translations) {
                foreach ($translations as $translation) {
                    $replica->translations()->create($translation);
                }

                $replica->load('translations');
            }

            return $replica;
        });
    }

    /**
     * @return array{name: string, translations: array<string, array<string, mixed>>}
     */
    public function getDefaults(Page $page): array
    {
        $page->loadMissing('translations.language');
        $translations = [];

        foreach ($page->translations->sortBy(fn (Translation $translation): int => $translation->language->default ? 0 : 1) as $translation) {
            $data = $translation->only($translation->getFillable());
            $name = trim($translation->slug ?? $translation->title ?? $page->name, '/') . ' copy';
            $slug = SlugGenerator::slug($name);
            $parentUrl = rtrim($page->getParentUrl($translation->language), '/');

            while ($this->slugExists($page, $translation, $slug, $parentUrl . '/' . $slug)) {
                $name = IncrementNameAction::run($name);
                $slug = SlugGenerator::slug($name);
            }

            $data['meta'] = array_replace($translation->meta ?? [], ['slug' => $slug]);
            $translations['record-' . $translation->id] = $data;
        }

        return ['name' => $this->getPageName($page), 'translations' => $translations];
    }

    private function slugExists(Page $page, Translation $translation, string $slug, string $url): bool
    {
        if (SiteAccess::current()->query(PageUrl::class)
            ->where('site_id', $page->site_id)
            ->where('language_id', $translation->language_id)
            ->where('url', $url)
            ->enabled()
            ->exists()) {
            return true;
        }

        return SiteAccess::current()->query(Translation::class)
            ->where('language_id', $translation->language_id)
            ->where('meta->slug', $slug)
            ->where('translatable_type', $page->getMorphClass())
            ->whereIn('translatable_id', SiteAccess::current()->query($page::class)
                ->select('id')
                ->where('site_id', $page->site_id)
                ->where('parent_id', $page->parent_id))
            ->exists();
    }

    private function getPageName(Page $page): string
    {
        $name = IncrementNameAction::run($page->name);

        while (SiteAccess::current()->query($page::class)->where('name', $name)->exists()) {
            $name = IncrementNameAction::run($name);
        }

        return $name;
    }
}
