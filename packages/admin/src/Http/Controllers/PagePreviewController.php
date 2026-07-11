<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PagePreviewController extends BaseController
{
    public function __invoke(Page $page): SymfonyResponse
    {
        $actor = $this->ensureAdmin();
        if ($actor instanceof SymfonyResponse) {
            return $actor;
        }

        $page->loadMissing(['site.theme', 'site.language', 'layout', 'translations.language']);

        $site = $page->site;
        abort_unless($site instanceof Site, SymfonyResponse::HTTP_NOT_FOUND);

        $this->abortUnlessActorCanPreviewSite($actor, $site);

        $theme = $site->theme;
        abort_unless($theme instanceof Theme, SymfonyResponse::HTTP_NOT_FOUND);
        abort_unless(app()->bound(ThemePreviewRendererInterface::class), SymfonyResponse::HTTP_NOT_FOUND);

        $response = resolve(ThemePreviewRendererInterface::class)->render($theme, $site, $page);

        $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function ensureAdmin(): Authenticatable|SymfonyResponse
    {
        $actor = Filament::auth()->user();

        if (! $actor instanceof Authenticatable) {
            return redirect()->guest(Filament::getLoginUrl() ?? '/admin/login');
        }

        abort_unless(
            $actor->canAccessPanel(Filament::getPanel('admin')),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        return $actor;
    }

    private function abortUnlessActorCanPreviewSite(Authenticatable $actor, Site $site): void
    {
        abort_unless(SiteScope::actorCanUseSite($actor, $site), SymfonyResponse::HTTP_FORBIDDEN);
    }
}
