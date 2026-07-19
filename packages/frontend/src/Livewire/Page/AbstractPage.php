<?php

declare(strict_types=1);

namespace Capell\Frontend\Livewire\Page;

use Aimeos\Nestedset\Collection;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page as PageRecord;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Url\UrlPathNormalizer;
use Capell\Frontend\Actions\RenderPageRecordDataAction;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\Kernel\Steps\LayoutResolverStep;
use Capell\Frontend\Support\Kernel\Steps\NormalizeDomainPathStep;
use Capell\Frontend\Support\Kernel\Steps\NotifySubscribersStep;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ParseUrlStep;
use Capell\Frontend\Support\Kernel\Steps\RegisterThemeViewsStep;
use Capell\Frontend\Support\Kernel\Steps\SetUrlGeneratorStep;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ThemeResolverStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * @property-read Language $language
 * @property-read Layout $layout
 * @property-read Pageable $pageRecord
 * @property-read Site $site
 * @property-read Theme $theme
 */
abstract class AbstractPage extends Component
{
    use WithPagination {
        getPage as getPaginationPage;
    }

    public array $params = [];

    #[Locked]
    public ?string $stateToken = null;

    protected static string $defaultView = 'capell::livewire.page.page';

    protected bool $pageLoaded = false;

    protected bool $frontendContextRestored = false;

    protected null|Collection|\Illuminate\Support\Collection|LengthAwarePaginator $results = null;

    protected function setup(): void {}

    public static function getViewName(): string
    {
        return static::$defaultView;
    }

    public function dehydrate(): void
    {
        $this->restoreFrontendContextIfMissing();

        if ($this->pageLoaded) {
            $this->captureFrontendContextToken();

            return;
        }

        $this->setup();
        $this->pageLoaded = true;
        $this->captureFrontendContextToken();
    }

    /**
     * @return Application|Request|mixed|array<mixed>|object
     */
    public function getPage(string $pageName = 'page'): mixed
    {
        return request($pageName);
    }

    #[Computed]
    public function language(): Language
    {
        $this->restoreFrontendContextIfMissing();

        $language = Frontend::language();

        throw_unless($language instanceof Language, RuntimeException::class, 'Frontend language is not resolved.');

        return $language;
    }

    #[Computed]
    public function layout(): Layout
    {
        $this->restoreFrontendContextIfMissing();

        $layout = Frontend::layout();

        throw_unless($layout instanceof Layout, RuntimeException::class, 'Frontend layout is not resolved.');

        return $layout;
    }

    public function hydrate(): void
    {
        $this->restoreFrontendContextIfMissing();
    }

    public function mount(): void
    {
        $this->captureFrontendContextToken();
        $this->setup();

        $this->pageLoaded = true;
        $this->captureFrontendContextToken();
    }

    #[Computed]
    public function pageRecord(): Pageable
    {
        $this->restoreFrontendContextIfMissing();

        $page = Frontend::page();

        throw_unless($page instanceof Pageable, RuntimeException::class, 'Frontend page is not resolved.');

        return $page;
    }

    #[Computed]
    public function site(): Site
    {
        $this->restoreFrontendContextIfMissing();

        $site = Frontend::site();

        throw_unless($site instanceof Site, RuntimeException::class, 'Frontend site is not resolved.');

        return $site;
    }

    #[Computed]
    public function theme(): Theme
    {
        $this->restoreFrontendContextIfMissing();

        $theme = Frontend::theme();

        throw_unless($theme instanceof Theme, RuntimeException::class, 'Frontend theme is not resolved.');

        return $theme;
    }

    public function render(): View
    {
        RenderPageRecordDataAction::run($this->pageRecord, $this->params);

        return view(
            $this->getMasterFile($this->layout),
            [
                'params' => $this->params,
                'componentName' => $this->getName(),
                ...$this->getViewData(),
            ],
        )
            ->layout($this->getLayoutFile($this->layout), [
                'componentName' => $this->getName(),
                'language' => $this->language,
                'layout' => $this->layout,
                'livewireEnabled' => $this->livewireEnabled(),
                'params' => $this->params,
                'pageRecord' => $this->pageRecord,
                'site' => $this->site,
                'theme' => $this->theme,
            ])
            ->response(function (Response $response): Response {
                $this->restoreFrontendContextIfMissing();

                $page = Frontend::page();
                if ($page instanceof PageRecord && $page->isErrorPage()) {
                    $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
                }

                return $response;
            });
    }

    protected function getViewData(): array
    {
        return [];
    }

    private function livewireEnabled(): bool
    {
        return $this->pageRecordRequiresLivewire($this->pageRecord);
    }

    private function pageRecordRequiresLivewire(Pageable $pageRecord): bool
    {
        $blueprint = $pageRecord instanceof Model && $pageRecord->relationLoaded('blueprint')
            ? $pageRecord->blueprint
            : null;

        $strategy = RenderingStrategyEnum::tryFrom((string) ($pageRecord->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::tryFrom((string) ($blueprint?->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::BladeOnly;
        if ($strategy->requiresLivewire()) {
            return true;
        }

        return $blueprint?->is_livewire === true;
    }

    private function captureFrontendContextToken(): void
    {
        $page = Frontend::page();

        if (! $page instanceof Pageable || ! $page instanceof Model) {
            return;
        }

        $site = Frontend::site();
        $language = Frontend::language();
        $layout = Frontend::layout();
        $theme = Frontend::theme();

        if (! $site instanceof Site
            || ! $language instanceof Language
            || ! $layout instanceof Layout
            || ! $theme instanceof Theme) {
            return;
        }

        $this->stateToken = Crypt::encryptString(JsonCodec::encode([
            'page_model' => $page->getMorphClass(),
            'page_id' => (int) $page->getKey(),
            'url' => $this->frontendContextUrl($page),
        ]));
    }

    private function frontendContextUrl(Pageable&Model $page): string
    {
        /** @var FrontendState $state */
        $state = resolve(FrontendState::class);
        $baseUrl = $state->baseUrl();
        $path = $state->effectiveUrl() ?? $state->relativePath();

        if (is_string($baseUrl) && $baseUrl !== '' && is_string($path) && $path !== '') {
            return UrlPathNormalizer::joinPrefix($baseUrl, $path);
        }

        $pageUrl = $page->relationLoaded('pageUrl') ? $page->getRelation('pageUrl') : null;
        if ($pageUrl !== null && isset($pageUrl->full_url) && is_string($pageUrl->full_url)) {
            return $pageUrl->full_url;
        }

        return request()->fullUrl();
    }

    /** @return array{page_model?: string|null, page_id?: int|null, url?: string|null} */
    private function frontendContextIdentifiers(): array
    {
        if ($this->stateToken === null) {
            return [];
        }

        try {
            $payload = JsonCodec::decode(Crypt::decryptString($this->stateToken));
        } catch (DecryptException|JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        return [
            'page_model' => is_string($payload['page_model'] ?? null) ? $payload['page_model'] : null,
            'page_id' => is_numeric($payload['page_id'] ?? null) ? (int) $payload['page_id'] : null,
            'url' => is_string($payload['url'] ?? null) ? $payload['url'] : null,
        ];
    }

    private function restoreFrontendContextIfMissing(): void
    {
        if ($this->frontendContextRestored || $this->hasFrontendContext()) {
            return;
        }

        $this->frontendContextRestored = true;

        /** @var FrontendState $state */
        $state = resolve(FrontendState::class);
        $identifiers = $this->frontendContextIdentifiers();

        $this->restoreFrontendContextFromResolvedUrl($state, $identifiers);
    }

    private function hasFrontendContext(): bool
    {
        /** @var FrontendState $state */
        $state = resolve(FrontendState::class);

        return $state->site() instanceof Site
            && $state->language() instanceof Language
            && $state->page() instanceof Pageable
            && $state->layout() instanceof Layout
            && $state->theme() instanceof Theme;
    }

    /**
     * @param  array{page_model?: string|null, page_id?: int|null, url?: string|null}  $identifiers
     */
    private function restoreFrontendContextFromResolvedUrl(FrontendState $state, array $identifiers): void
    {
        $pageModel = $identifiers['page_model'] ?? null;
        $pageId = $identifiers['page_id'] ?? null;
        $url = $identifiers['url'] ?? null;

        if (! is_string($pageModel) || ! is_int($pageId) || ! is_string($url) || $url === '') {
            return;
        }

        $modelClass = Relation::getMorphedModel($pageModel) ?? $pageModel;

        if (! is_string($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
            || ! is_subclass_of($modelClass, Pageable::class)) {
            return;
        }

        $resolvedState = new FrontendState;
        $request = Request::create($url, \Symfony\Component\HttpFoundation\Request::METHOD_GET);
        /** @var FrontendWork $work */
        $work = resolve(Pipeline::class)
            ->send(new FrontendWork($request, $resolvedState))
            ->through($this->frontendContextResolutionSteps())
            ->thenReturn();

        $context = $work->context();
        $page = $context?->page();

        if (! $page instanceof $modelClass || (int) $page->getKey() !== $pageId) {
            return;
        }

        $site = $context->site();
        $language = $context->language();
        $layout = $context->layout();
        $theme = $context->theme();

        if (! $site instanceof Site
            || ! $language instanceof Language
            || ! $page instanceof Pageable
            || ! $layout instanceof Layout
            || ! $theme instanceof Theme) {
            return;
        }

        $state
            ->withSite($site)
            ->withLanguage($language)
            ->withPage($page)
            ->withLayout($layout)
            ->withTheme($theme)
            ->withParams($context->params())
            ->withSlug($context->slug())
            ->markAsError($context->isError());

        if ($resolvedState->domain() instanceof SiteDomain) {
            $state->withDomain($resolvedState->domain());
        }

        $state
            ->withRelativePath($resolvedState->relativePath())
            ->setEffectiveUrl($resolvedState->effectiveUrl())
            ->setRevisionPageId($resolvedState->revisionPageId());
    }

    /** @return array<int, callable|string> */
    private function frontendContextResolutionSteps(): array
    {
        $excludedSteps = [
            RegisterThemeViewsStep::class,
            NotifySubscribersStep::class,
        ];

        return array_values(array_filter(
            config('frontend.kernel.steps', [
                ParseUrlStep::class,
                SiteResolveStep::class,
                SetUrlGeneratorStep::class,
                NormalizeDomainPathStep::class,
                PageResolveStep::class,
                LayoutResolverStep::class,
                ThemeResolverStep::class,
                RegisterThemeViewsStep::class,
                NotifySubscribersStep::class,
            ]),
            fn (mixed $step): bool => is_string($step) && ! in_array($step, $excludedSteps, true),
        ));
    }

    private function getLayoutFile(?Layout $layout): string
    {
        return $layout->meta['layout_file'] ?? config('capell-frontend.layout_file', 'capell::app');
    }

    private function getMasterFile(Layout $layout): string
    {
        return $layout->meta['master_file'] ?? static::$defaultView;
    }
}
