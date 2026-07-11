<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Facades\Frontend;
use Illuminate\Http\Request;

it('boots kernel and returns context without redirect or error', function (): void {
    $siteDomain = SiteDomain::factory()
        ->enabled()
        ->state([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/',
        ])
        ->create();

    Page::factory()->site($siteDomain->site)->home()->withTranslations(slug: '/')->create();

    $kernel = resolve(FrontendKernelInterface::class);

    $request = Request::create('https://example.com/');

    $result = $kernel->bootstrap($request);

    expect($result->isOk())->toBeTrue()
        ->and($result->redirect)->toBeNull()
        ->and($result->error)->toBeNull()
        ->and(Frontend::site())->not()->toBeNull();

});
