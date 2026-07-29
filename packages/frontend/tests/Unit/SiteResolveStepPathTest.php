<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;

uses()->group('kernel');

it('assigns the canonical page-relative path to both public state accessors', function (
    ?string $domain,
    ?string $domainPath,
    string $requestUrl,
    string $expectedPath,
): void {
    Site::factory()->withTranslations(siteDomainData: [
        'domain' => $domain,
        'scheme' => 'https',
        'path' => $domainPath,
        'status' => true,
    ])->create();

    $state = new FrontendState;
    $work = new FrontendWork(Request::create($requestUrl), $state);

    $result = resolve(SiteResolveStep::class)->handle(
        $work,
        fn (FrontendWork $frontendWork): FrontendWork => $frontendWork,
    );

    expect($result)->toBe($work)
        ->and($state->relativePath())->toBe($expectedPath)
        ->and($state->effectiveUrl())->toBe($expectedPath);
})->with([
    'prefixed domain root' => ['example.com', '/en', 'https://example.com/en', '/'],
    'repeated prefix remains page relative' => ['example.com', '/en', 'https://example.com/en/en/products', '/en/products'],
    'front controller path resolves to root' => ['example.com', '/en', 'https://example.com/en/index.php', '/'],
    'root domain resolves the homepage' => ['example.com', '/', 'https://example.com/', '/'],
    'root domain strips a trailing slash' => ['example.com', '/', 'https://example.com/products/', '/products'],
    'wildcard domain uses the request host' => [null, '/tenant', 'https://customer.example.test/tenant/blog/', '/blog'],
]);
