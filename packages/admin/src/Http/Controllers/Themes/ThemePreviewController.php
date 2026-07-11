<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Themes;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ThemePreviewController extends BaseController
{
    public function __invoke(Theme $theme, Site $site, Page $page): SymfonyResponse
    {
        $actor = $this->ensureAdmin();
        if ($actor instanceof SymfonyResponse) {
            return $actor;
        }

        $this->abortUnlessActorCanPreviewSite($actor, $site);
        $this->abortUnlessPageBelongsToSite($page, $site);

        abort_unless(app()->bound(ThemePreviewRendererInterface::class), SymfonyResponse::HTTP_NOT_FOUND);

        $response = resolve(ThemePreviewRendererInterface::class)->render($theme, $site, $page);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

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

    private function abortUnlessPageBelongsToSite(Page $page, Site $site): void
    {
        abort_unless($page->site_id === $site->getKey(), SymfonyResponse::HTTP_NOT_FOUND);
    }
}
