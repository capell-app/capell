<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Feature;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\SetUrlGeneratorStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;
use RuntimeException;

it('forces laravel url() to use siteDomain full_url', function (): void {
    /** @var Site $site */
    $site = Site::factory()->createOne();
    /** @var Language $lang */
    $lang = Language::factory()->createOne();
    /** @var SiteDomain $domain */
    $domain = SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'language_id' => $lang->id,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => null,
        'status' => true,
        'default' => true,
    ]);

    // Bind request and set FrontendState domain for the test context
    $req = Request::create('https://example.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $req);
    /** @var FrontendState $state */
    $state = resolve(FrontendState::class);
    $state->withDomain($domain);

    // Ensure URL generator uses site domain
    config(['capell-frontend.use_site_domain_for_urls' => true]);

    expect(url('/foo'))->toStartWith($domain->full_url)
        ->and(URL::to('/bar'))->toStartWith($domain->full_url);
});

it('restores forced url generator state after applying frontend domain state', function (): void {
    /** @var UrlGenerator $url */
    $url = resolve(\Illuminate\Contracts\Routing\UrlGenerator::class);
    $url->useOrigin('https://original.test/base');
    $url->forceScheme('https');

    /** @var SiteDomain $domain */
    $domain = SiteDomain::factory()->createOne([
        'domain' => 'tenant.test',
        'scheme' => 'http',
        'path' => null,
        'status' => true,
    ]);

    $request = Request::create('http://tenant.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $state = (new FrontendState)->withDomain($domain);
    $work = new FrontendWork($request, $state);
    $generatedDuringWork = null;

    try {
        new SetUrlGeneratorStep()->handle($work, function () use (&$generatedDuringWork): string {
            $generatedDuringWork = url('/inside');

            return 'ok';
        });

        expect($generatedDuringWork)->toBe('http://tenant.test/inside')
            ->and(url('/after'))->toBe('https://original.test/base/after');
    } finally {
        $url->useOrigin(null);
        $url->forceScheme(null);
    }
});

it('restores forced url generator state when frontend work throws', function (): void {
    /** @var UrlGenerator $url */
    $url = resolve(\Illuminate\Contracts\Routing\UrlGenerator::class);
    $url->useOrigin('https://original.test/base');
    $url->forceScheme('https');

    /** @var SiteDomain $domain */
    $domain = SiteDomain::factory()->createOne([
        'domain' => 'tenant.test',
        'scheme' => 'http',
        'path' => null,
        'status' => true,
    ]);

    $request = Request::create('http://tenant.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $state = (new FrontendState)->withDomain($domain);
    $work = new FrontendWork($request, $state);

    try {
        expect(fn (): mixed => new SetUrlGeneratorStep()->handle(
            $work,
            fn (): never => throw new RuntimeException('render failed'),
        ))->toThrow(RuntimeException::class, 'render failed');

        expect(url('/after'))->toBe('https://original.test/base/after');
    } finally {
        $url->useOrigin(null);
        $url->forceScheme(null);
    }
});
