<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Loader\PageCachePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;
use Symfony\Component\HttpFoundation\Response;

final class LivewireFrontendResponseRenderer implements FrontendResponseRenderer
{
    public function runtime(): FrontendRuntime
    {
        return FrontendRuntime::Livewire;
    }

    public function render(FrontendRenderContextData $context): Response
    {
        if (App::bound('capell.frontend.page-markdown-response')) {
            $markdownResponse = App::make('capell.frontend.page-markdown-response')();

            if ($markdownResponse instanceof Response) {
                if ($context->status !== null) {
                    $markdownResponse->setStatusCode($context->status);
                }

                AssertPublicRenderContractAction::run($markdownResponse);

                return $markdownResponse;
            }
        }

        $page = $context->page;

        if (! $page instanceof Pageable) {
            return response()->noContent($context->status ?? 404);
        }

        $component = $this->componentFor($page);

        $instance = $this->componentInstance($component);
        $response = $this->toResponse(app()->call([$instance, '__invoke']));

        if ($context->status !== null) {
            $response->setStatusCode($context->status);
        }

        if (PageCachePolicy::shouldCache($page) && $this->isAnonymousCacheableRequest(request())) {
            SupportDisablingBackButtonCache::$disableBackButtonCache = false;
        }

        AssertPublicRenderContractAction::run($response);

        return $response;
    }

    private function isAnonymousCacheableRequest(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->query->count() > 0) {
            return false;
        }

        if ($request->headers->has('Authorization') || $request->headers->has('X-Livewire')) {
            return false;
        }

        if ($request->headers->has('X-Inertia')
            || $request->headers->has('X-Inertia-Version')
            || $request->headers->has('X-Inertia-Partial-Component')
            || $request->headers->has('X-Inertia-Partial-Data')
            || $request->headers->has('X-Inertia-Reset')) {
            return false;
        }

        return $request->user() === null;
    }

    private function componentFor(Pageable $page): string
    {
        $blueprint = $page instanceof Model && $page->relationLoaded('blueprint')
            ? $page->blueprint
            : null;

        return ResolveRenderableComponentAction::run(
            RenderableTypeEnum::Page,
            $page->meta['component']
                ?? $blueprint?->component
                ?? $blueprint?->meta['component']
                ?? LivewirePageComponentEnum::Default->value,
            'livewire',
        );
    }

    private function componentInstance(string $component): object
    {
        return resolve('livewire')->new($component);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        return response($result);
    }
}
