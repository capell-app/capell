<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Route;
use Throwable;

final class HomepageRouteCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.route.homepage';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        try {
            $site = Site::query()->default()->with('language')->first() ?? Site::query()->with('language')->first();
            $language = $site?->language;
            $resolvedPage = $site instanceof Site && $language instanceof Language
                ? ResolvePublicPageByUrlAction::run($site, $language, '/')->page
                : null;
        } catch (Throwable) {
            $resolvedPage = null;
        }

        if (! $resolvedPage instanceof Page || $resolvedPage->isErrorPage()) {
            return new DoctorCheckResultData(
                'Homepage route resolves',
                false,
                'The public page resolver returned no page (or the error page) for "/".',
                'Confirm the homepage page type is enabled and accessible, the page is published, and page URLs were generated.',
            );
        }

        $routeRegistered = Route::has('capell.home') || Route::has('capell.frontend') || Route::has('frontend');

        return new DoctorCheckResultData(
            'Homepage route resolves',
            true,
            $routeRegistered
                ? sprintf('Homepage #%d resolves through the public resolver and a frontend route is registered.', $resolvedPage->getKey())
                : sprintf('Homepage #%d resolves through the public resolver.', $resolvedPage->getKey()),
        );
    }
}
