<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\Pages\ValidatePageAuthoringAction;
use Capell\Core\Contracts\Actionable;
use Capell\Core\Models\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Page run(array<string, mixed> $data)
 */
class CreatePageAction implements Actionable
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Page
    {
        ValidatePageAuthoringAction::run(
            formData: $data,
            page: null,
            operation: 'action-create',
        );

        /** @var class-string<Page> $pageModel */
        $pageModel = Page::class;

        $page = $pageModel::create(Arr::except($data, ['translations']));

        if (isset($data['translations']) && is_array($data['translations']) && $data['translations'] !== []) {
            $this->createTranslations($page, $data['translations']);
        }

        return $page;
    }

    /**
     * @param  array<int, array{language_id: int, title: string, content: string, slug?: string, meta?: array<string, mixed>}>  $translations
     */
    private function createTranslations(Page $page, array $translations): void
    {
        foreach ($translations as $translation) {
            $pageTranslation = $page->translations();

            if (isset($translation['slug'])) {
                if (! isset($translation['meta'])) {
                    $translation['meta'] = [];
                }

                $translation['meta']['slug'] = Str::slug($translation['slug']);
            }

            $pageTranslation->create([
                'language_id' => $translation['language_id'],
                'title' => $translation['title'],
                'content' => $translation['content'],
                'meta' => $translation['meta'] ?? null,
            ]);
        }
    }
}
