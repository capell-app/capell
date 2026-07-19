<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Http\Request;

uses(TestingFrontend::class);

it('bootstraps and returns context without redirect or error for normal page', function (): void {
    $site = Site::factory()->withTranslations()->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();

    $domain = $site->siteDomains->first();

    $kernel = resolve(FrontendKernelInterface::class);

    $server = ['HTTP_HOST' => $domain->domain];
    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $basePath = $domain->path ?? '/';
    $request = Request::create($basePath, Symfony\Component\HttpFoundation\Request::METHOD_GET, server: $server);

    $result = $kernel->bootstrap($request);

    expect($result->redirect)->toBeNull()
        ->and($result->error)->toBeNull()
        ->and($result->context)->not()->toBeNull();
});

it('resolves a page path that repeats the site domain prefix', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/en',
    ])->create();
    $page = Page::factory()
        ->site($site)
        ->withTranslations(slug: 'en/products')
        ->create();

    $result = resolve(FrontendKernelInterface::class)->bootstrap(
        Request::create('https://example.com/en/en/products'),
    );

    expect($page->pageUrl?->url)->toBe('/en/products')
        ->and($result->redirect)->toBeNull()
        ->and($result->error)->toBeNull()
        ->and($result->context?->page?->getKey())->toBe($page->getKey());
});
