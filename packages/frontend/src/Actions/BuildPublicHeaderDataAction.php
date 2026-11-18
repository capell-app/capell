<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicHeaderData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicHeaderDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend, private readonly ThemeChromeRegistry $chrome) {}

    public function handle(): PublicHeaderData
    {
        $site = $this->frontend->site();
        $domain = $site?->relationLoaded('siteDomain') ? $site->siteDomain : null;
        $translation = $site?->relationLoaded('translation') ? $site->translation : null;
        $logoView = PublicModelMeta::get($site, 'logo_blade_view', 'brand.capell-logo');
        $logoView = is_string($logoView) && array_key_exists($logoView, $this->chrome->logoOptions()) && view()->exists($logoView)
            ? $logoView
            : null;
        $logo = $logoView === null && $site?->relationLoaded('logo') ? $site->logo : null;

        return new PublicHeaderData(
            homeUrl: $domain?->url ?? '/',
            siteDisplayName: $translation?->title ?? $site?->name ?? '',
            logoView: $logoView,
            renderedMediaLogo: $logo === null ? null : Blade::render('<x-capell::logo :media="$logo" />', ['logo' => $logo]),
        );
    }
}
