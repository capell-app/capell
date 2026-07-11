<?php

declare(strict_types=1);

use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('returns a frontend not found response when no sites are configured', function (): void {
    config()->set('capell-frontend.throw_on_no_sites', false);

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('http://unknown.test/path'), $state);
    $step = resolve(SiteResolveStep::class);

    expect(fn () => $step->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork))
        ->toThrow(NotFoundHttpException::class);
});

it('throws SiteDomainNotFoundException when no sites configured and throw_on_no_sites is true', function (): void {
    config()->set('capell-frontend.throw_on_no_sites', true);

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('http://unknown.test/path'), $state);
    $step = resolve(SiteResolveStep::class);

    expect(fn () => $step->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork))
        ->toThrow(SiteDomainNotFoundException::class, 'No sites are configured.');
});

it('redirects to default site when enabled', function (): void {
    config()->set('capell-frontend.redirect_default_site', true);

    $defaultDomain = SiteDomain::factory()->enabled()->state([
        'default' => true,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/en',
    ])->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('http://unknown.test/path?x=1&x=2'), $state);

    $step = resolve(SiteResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $w): FrontendWork => $w);

    expect($result->getRedirect())->not()->toBeNull()
        ->and($result->getRedirect()->getTargetUrl())->toBe('https://example.com/en/path?x=1&x=2');
});

it('redirects to the request host when the default site domain is hostless', function (): void {
    config()->set('capell-frontend.redirect_default_site', true);

    SiteDomain::factory()->enabled()->state([
        'default' => true,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/en',
    ])->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('http://unknown.test/path?x=1&x=2'), $state);

    $step = resolve(SiteResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork);

    expect($result->getRedirect())->not()->toBeNull()
        ->and($result->getRedirect()->getTargetUrl())->toBe('https://unknown.test/en/path?x=1&x=2');
});

it('redirects root hostless default domains to the request host', function (): void {
    config()->set('capell-frontend.redirect_default_site', true);

    SiteDomain::factory()->enabled()->state([
        'default' => true,
        'domain' => null,
        'scheme' => 'https',
        'path' => null,
    ])->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create('http://tenant.test/path'), $state);

    $step = resolve(SiteResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $frontendWork): FrontendWork => $frontendWork);

    expect($result->getRedirect())->not()->toBeNull()
        ->and($result->getRedirect()->getTargetUrl())->toBe('https://tenant.test/path');
});
