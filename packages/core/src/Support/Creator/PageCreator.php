<?php

declare(strict_types=1);

namespace Capell\Core\Support\Creator;

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Contracts\ModelInterceptors\PageInterceptorInterface;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\PageCreatable;
use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Slug\SlugGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageCreator implements PageCreatable
{
    /**
     * HTTP error statuses seeded with per-status copy on the error page.
     *
     * Mirrors Capell\Frontend\Enums\ErrorPageStatusEnum but kept as a plain
     * list here so core stays free of any frontend dependency.
     *
     * @var array<int, string>
     */
    private const array ERROR_PAGE_STATUSES = ['401', '402', '403', '404', '419', '429', '500', '503'];

    protected LayoutCreator $layoutCreator;

    /**
     * @var class-string<Layout>
     */
    protected string $layoutModel = Layout::class;

    /**
     * @var class-string<Page>
     */
    protected string $pageModel = Page::class;

    protected BlueprintCreator $typeCreator;

    /**
     * @var class-string<Blueprint>
     */
    protected string $typeModel = Blueprint::class;

    public function __construct()
    {
        $this->layoutCreator = resolve(LayoutCreator::class);
        $this->typeCreator = resolve(BlueprintCreator::class);
    }

    /**
     * @param  Collection<int, Language>|null  $languages
     */
    public function createErrorPage(Site $site, ?Collection $languages = null): Page
    {
        $languages ??= $site->languages;
        $type = $this->getPageType(PageTypeEnum::NotFound);
        $layout = $this->getLayout(LayoutEnum::System);

        $defaults = [
            'layout_id' => $layout->id,
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => __('capell::generic.page_not_found'),
            'meta' => [
                'robots' => ['noindex' => true],
            ],
        ];

        $page = CapellCore::createOrUpdateModel(
            $this->pageModel,
            [
                'layout_id' => $layout->id,
                'site_id' => $site->id,
                'blueprint_id' => $type->id,
            ],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            PageInterceptorInterface::class,
        );

        $languages->each(function (Language $language) use ($page): void {
            $page->translations()->firstOrCreate([
                'language_id' => $language->id,
            ], [
                'title' => __('capell::generic.page_not_found'),
                'content' => __('capell::generic.page_not_found_content'),
                'meta' => [
                    'slug' => 'not-found',
                    'error_status_copy' => $this->errorStatusCopyDefaults(),
                ],
            ]);
        });

        SetupPageUrlsAction::run($page);

        return $page;
    }

    /**
     * @param  Collection<int, Language>|null  $languages
     */
    public function createMaintenancePage(Site $site, ?Collection $languages = null): Page
    {
        $languages ??= $site->languages;
        $type = $this->getPageType(PageTypeEnum::Maintenance);
        $layout = $this->getLayout(LayoutEnum::System);

        $defaults = [
            'layout_id' => $layout->id,
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => __('capell::generic.maintenance'),
            'meta' => [
                'robots' => ['noindex' => true],
            ],
        ];

        $page = CapellCore::createOrUpdateModel(
            $this->pageModel,
            [
                'layout_id' => $layout->id,
                'site_id' => $site->id,
                'blueprint_id' => $type->id,
            ],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            PageInterceptorInterface::class,
        );

        $languages->each(function (Language $language) use ($page): void {
            $page->translations()->firstOrCreate([
                'language_id' => $language->id,
            ], [
                'title' => __('capell::generic.maintenance'),
                'content' => '<p>We are making a few updates. Please check back soon.</p>',
                'meta' => ['slug' => 'maintenance'],
            ]);
        });

        SetupPageUrlsAction::run($page);

        return $page;
    }

    /**
     * @param  Collection<int, Language>  $languages
     */
    public function createHomePage(Site $site, Collection $languages): Page
    {
        $layout = $this->getLayout(LayoutEnum::Home);
        $type = $this->getPageType(PageTypeEnum::Home);

        $defaults = [
            'layout_id' => $layout->id,
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => __('capell::generic.home'),
            'order' => 1,
        ];

        $page = $this->existingRootPage($site, $languages);

        if ($page instanceof Page) {
            $page->forceFill($defaults)->save();
        } else {
            $page = CapellCore::createOrUpdateModel(
                $this->pageModel,
                [
                    'layout_id' => $layout->id,
                    'site_id' => $site->id,
                    'blueprint_id' => $type->id,
                ],
                fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
                PageInterceptorInterface::class,
            );
        }

        $languages->each(function (Language $language) use ($page, $site): void {
            $title = ctype_digit($site->name[0]) ? $site->name : Str::title($site->name);

            $page->translations()->firstOrCreate([
                'language_id' => $language->id,
            ], [
                'title' => $title,
                'content' => sprintf('<p>Welcome to %s</p>', $title),
                'meta' => [
                    'slug' => '/',
                    'label' => __('capell::generic.home'),
                    'title' => ':site',
                    'hero' => sprintf('<p>Welcome to %s</p>', $title),
                ],
            ]);
        });

        SetupPageUrlsAction::run($page);

        return $page;
    }

    /**
     * @param  Collection<int, Language>|null  $languages
     */
    public function createWelcomePage(Site $site, ?Collection $languages = null): Page
    {
        $languages ??= $site->languages;
        $type = $this->getPageType(PageTypeEnum::Default);
        $layout = $this->getLayout(LayoutEnum::Default);

        $defaults = [
            'layout_id' => $layout->id,
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => __('capell::generic.welcome'),
            'meta' => [
                'robots' => ['noindex' => true],
            ],
        ];

        $page = CapellCore::createOrUpdateModel(
            $this->pageModel,
            [
                'layout_id' => $layout->id,
                'site_id' => $site->id,
                'blueprint_id' => $type->id,
            ],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            PageInterceptorInterface::class,
        );

        $languages->each(function (Language $language) use ($page): void {
            $page->translations()->firstOrCreate([
                'language_id' => $language->id,
            ], [
                'title' => __('capell::generic.welcome'),
                'content' => __('capell::generic.welcome_content'),
                'meta' => ['slug' => 'welcome'],
            ]);
        });

        SetupPageUrlsAction::run($page);

        return $page;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Language>  $languages
     */
    public function createPage(array $data, Site $site, Collection $languages): Pageable
    {
        $meta = $data['meta'] ?? [];
        $meta['image_id'] = $data['image_id'] ?? ($meta['image_id'] ?? null);

        $defaults = [
            'name' => $data['name'],
            'layout_id' => $data['layout_id'] ?? $this->getLayout($data['layout_key'] ?? LayoutEnum::Default)->id,
            'site_id' => $site->id,
            'blueprint_id' => $data['blueprint_id'] ?? $this->getPageType($data['type_key'] ?? PageTypeEnum::Default)->id,
            'parent_id' => $data['parent_id'] ?? null,
            'meta' => $meta,
            'visible_from' => $data['visible_from'] ?? null,
        ];

        /** @var Page $page */
        $page = CapellCore::createOrUpdateModel(
            $this->pageModel,
            [
                'name' => $data['name'],
                'layout_id' => $defaults['layout_id'],
                'site_id' => $site->id,
                'blueprint_id' => $defaults['blueprint_id'],
                'parent_id' => $defaults['parent_id'],
            ],
            fn (array $data): array => CapellCore::mergeModelInterceptorData($defaults, $data),
            PageInterceptorInterface::class,
        );

        $languages->each(function (Language $language) use ($data, $page): void {
            $translation_data = $data['translations'][$language->code] ?? [];

            $meta = $translation_data['meta'] ?? [];
            $meta['summary'] = $translation_data['summary'] ?? null;

            if (isset($translation_data['link_text'])) {
                $meta['link_text'] = $translation_data['link_text'];
            }

            if (! isset($meta['slug'])) {
                $meta['slug'] = $translation_data['slug'] ?? SlugGenerator::slug($data['name']);
            }

            $attributes = [
                'title' => $translation_data['title'] ?? $data['name'],
                'content' => $translation_data['content'] ?? null,
                'meta' => $meta,
                'language_id' => $language->id,
            ];

            $translation = $page->translations()->firstOrNew(['language_id' => $language->id]);

            $translation->fill($attributes);

            if (isset($data['user_id'])) {
                $translation->forceFill([
                    'created_by' => $data['user_id'],
                    'updated_by' => $data['user_id'],
                ]);
            }

            $translation->save();
        });

        return $page;
    }

    protected function getLayout(LayoutEnum|string $key): Layout
    {
        if ($key instanceof LayoutEnum) {
            $key = $key->value;
        }

        $layout = $this->layoutModel::query()->firstWhere('key', $key);

        if ($layout !== null) {
            return $layout;
        }

        return $this->layoutCreator->create($key);
    }

    protected function getPageType(string|PageTypeEnum $key): Blueprint
    {
        $type = $this->typeQuery()->where('key', $key)->pageType()->first();

        if ($type !== null) {
            return $type;
        }

        if ($key instanceof PageTypeEnum) {
            $key = $key->value;
        }

        return $this->typeCreator->createPageType($key);
    }

    /**
     * Per-status headline + description defaults keyed by HTTP status.
     *
     * Note: numeric-string status keys are coerced to integer array keys by PHP,
     * so consumers must look them up as integers (e.g. $copy[500]).
     *
     * @return array<int, array{headline: string, description: string}>
     */
    private function errorStatusCopyDefaults(): array
    {
        $copy = [];

        foreach (self::ERROR_PAGE_STATUSES as $status) {
            $copy[$status] = [
                'headline' => (string) __(sprintf('capell::generic.error_%s_headline', $status)),
                'description' => (string) __(sprintf('capell::generic.error_%s_description', $status)),
            ];
        }

        return $copy;
    }

    /**
     * @return Builder<Blueprint>
     */
    private function typeQuery(): Builder
    {
        return $this->typeModel::query();
    }

    /**
     * @param  Collection<int, Language>  $languages
     */
    private function existingRootPage(Site $site, Collection $languages): ?Page
    {
        $languageIds = $languages
            ->map(fn (Language $language): int => (int) $language->getKey())
            ->filter()
            ->values()
            ->all();

        if ($languageIds === []) {
            return null;
        }

        $pageUrl = PageUrl::query()
            ->where('site_id', $site->getKey())
            ->whereIn('language_id', $languageIds)
            ->where('url', '/')
            ->where('pageable_type', (new Page)->getMorphClass())
            ->with('pageable')
            ->first();

        $page = $pageUrl?->pageable;

        return $page instanceof Page ? $page : null;
    }
}
