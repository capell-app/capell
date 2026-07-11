<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

it('returns 404 for bot UA when page not found', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $domain = SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
    ])->create();

    $state = new FrontendState;
    $state->withSite($site)->withLanguage($language)->withDomain($domain);
    $state->setEffectiveUrl('/does-not-exist');

    $request = Request::create('https://example.com/does-not-exist');
    $request->headers->set('User-Agent', 'SomeBot');

    $work = new FrontendWork($request, $state);

    $step = resolve(PageResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result->getError())->toBeArray()
        ->and($result->getError()['status'])->toBe(404);
});
