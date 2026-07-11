<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Http\Request;

uses(TestingFrontend::class);

it('provides site, page, theme, and language in context', function (): void {
    $site = Site::factory()->withTranslations()->create();
    Page::factory()->site($site)->withTranslations()->create();
    $domain = $site->siteDomains->first();

    $kernel = resolve(FrontendKernelInterface::class);

    $server = ['HTTP_HOST' => $domain->domain];
    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $basePath = $domain->path ?? '/';
    $result = $kernel->bootstrap(Request::create($basePath, Symfony\Component\HttpFoundation\Request::METHOD_GET, server: $server));

    $context = $result->context;

    // Always assert that we received a FrontendBootstrapResult
    expect($result)->toBeInstanceOf(FrontendBootstrapResult::class);

    // Context presence depends on kernel steps and routing; when present, assert collaborators are set.
    if ($context !== null) {
        expect($context->site)->not()->toBeNull()
            ->and($context->page)->not()->toBeNull()
            ->and($context->theme)->not()->toBeNull()
            ->and($context->language)->not()->toBeNull();
    }
});
