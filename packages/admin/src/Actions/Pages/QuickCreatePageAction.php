<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Actions\CreatePageAction;
use Capell\Admin\Data\Pages\QuickCreatePageData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Actionable;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\Page;
use Capell\Core\Support\Slug\SlugGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates a page from the short list-page form: a title, a type, a place in the
 * tree and a status. Layout and the remaining defaults come from the page resource.
 *
 * @method static Page run(QuickCreatePageData $data, class-string<PageResource> $resource)
 */
final class QuickCreatePageAction implements Actionable
{
    use AsFake;
    use AsObject;

    /**
     * @param  class-string<PageResource>  $resource
     */
    public function handle(QuickCreatePageData $data, string $resource = PageResource::class): Page
    {
        $slug = filled($data->slug) ? SlugGenerator::slug($data->slug) : SlugGenerator::slug($data->title);

        $translations = [[
            'language_id' => $data->languageId,
            'title' => $data->title,
            'content' => [],
            'meta' => ['slug' => $slug],
        ]];

        $attributes = [
            'name' => $data->title,
            'site_id' => $data->siteId,
            'blueprint_id' => $data->blueprintId,
            'parent_id' => $data->parentId,
        ];

        $resource::mutateFormDataBeforeCreate($attributes, ['translations' => $translations]);

        throw_if(
            blank($attributes['layout_id'] ?? null),
            ValidationException::withMessages(['layout_id' => __('capell-admin::message.quick_create_page_no_layout')]),
        );

        if (! $data->publish) {
            $attributes['visible_from'] = PagePublishSentinel::draftValue();
        }

        $payload = [...$attributes, 'translations' => $translations];

        try {
            return DB::transaction(function () use ($payload): Page {
                $page = CreatePageAction::run($payload);

                PageSavedAction::run($page, $payload);

                return $page;
            });
        } catch (PageUrlCollisionException) {
            throw ValidationException::withMessages([
                'slug' => __('capell-admin::message.quick_create_page_slug_taken', ['slug' => $slug]),
            ]);
        }
    }
}
