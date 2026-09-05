<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Term;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

final class AgentAdminAuthorization
{
    public function site(Authenticatable $user, int $siteId): Site
    {
        $site = Site::query()->findOrFail($siteId);
        Gate::forUser($user)->authorize('view', $site);

        return $site;
    }

    public function page(AgentAdminToolInvocationData $invocation, string $ability): Page
    {
        $pageId = $invocation->payload['page_id'] ?? null;
        $page = Page::query()
            ->whereKey($pageId)
            ->where('site_id', $invocation->siteId)
            ->firstOrFail();

        Gate::forUser($invocation->user)->authorize($ability, $page);

        return $page;
    }

    public function term(AgentAdminToolInvocationData $invocation): Term
    {
        $termId = $invocation->payload['term_id'] ?? null;
        $term = Term::query()
            ->whereKey($termId)
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $invocation->siteId))
            ->with(['taxonomy', 'propertyValues.propertyDefinition.propertySet'])
            ->firstOrFail();

        $this->site($invocation->user, $invocation->siteId);

        return $term;
    }

    public function canViewPages(Authenticatable $user, int $siteId): bool
    {
        try {
            $this->site($user, $siteId);

            return Gate::forUser($user)->allows('viewAny', Page::class);
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }

    public function canUpdatePages(Authenticatable $user, int $siteId): bool
    {
        try {
            $this->site($user, $siteId);

            return Gate::forUser($user)->allows(ResourceEnum::Page->permission('update'));
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }

    public function canUpdateSite(Authenticatable $user, int $siteId): bool
    {
        try {
            $site = $this->site($user, $siteId);

            return Gate::forUser($user)->allows('update', $site);
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }

    /**
     * Core and Admin settings are process-wide settings, so site-scoped
     * update permission must not expose this write surface.
     */
    public function canUpdateGlobalSettings(Authenticatable $user, int $siteId): bool
    {
        try {
            $this->site($user, $siteId);

            return method_exists($user, 'isGlobalAdmin')
                && $user->isGlobalAdmin();
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }

    public function canViewBlueprints(Authenticatable $user, int $siteId): bool
    {
        try {
            $this->site($user, $siteId);

            return Gate::forUser($user)->allows(ResourceEnum::Blueprint->permission('view_any'));
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }
}
