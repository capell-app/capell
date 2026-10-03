<?php

declare(strict_types=1);

use Capell\Admin\Policies\BlueprintPolicy;
use Capell\Admin\Policies\LanguagePolicy;
use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Policies\SiteDomainPolicy;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Policies\ThemePolicy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Gate;

/**
 * Admin policies live in `Capell\Admin\Policies\*` but they gate models in
 * `Capell\Core\Models\*`. Laravel's convention-based policy discovery only
 * finds policies in `App\Policies\{Model}Policy` — it will NOT resolve
 * admin-package policies. Filament's resource system registers them for
 * HTTP routes going through a Filament panel, but anything outside that
 * (Actions invoked from CLI / jobs / bulk flows) falls back to Laravel's
 * Gate, which then returns "denied by default" for every ability.
 *
 * AdminServiceProvider::registerPolicies() must therefore globally register
 * every policy via `Gate::policy()`. This test guards that contract.
 */
it('registers every admin policy globally via the service provider', function (): void {
    expect(Gate::getPolicyFor(Page::class))->toBeInstanceOf(PagePolicy::class)
        ->and(Gate::getPolicyFor(Layout::class))->toBeInstanceOf(LayoutPolicy::class)
        ->and(Gate::getPolicyFor(Site::class))->toBeInstanceOf(SitePolicy::class)
        ->and(Gate::getPolicyFor(Blueprint::class))->toBeInstanceOf(BlueprintPolicy::class)
        ->and(Gate::getPolicyFor(Language::class))->toBeInstanceOf(LanguagePolicy::class)
        ->and(Gate::getPolicyFor(Theme::class))->toBeInstanceOf(ThemePolicy::class)
        ->and(Gate::getPolicyFor(SiteDomain::class))->toBeInstanceOf(SiteDomainPolicy::class);

    if (class_exists(RedirectPolicy::class)) {
        expect(Gate::getPolicyFor(PageUrl::class))->toBeInstanceOf(RedirectPolicy::class);
    }
});
