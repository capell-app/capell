<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Http\Request;

uses(TestingFrontend::class)->group('kernel');

dataset('paths', [
    ['/', false, null],
    ['/index.php', false, null],
    ['/non-existent', true, 404],
]);

it('kernel steps produce expected bootstrap result', function (string $path, bool $mayError, ?int $expectedStatus): void {
    $site = Site::factory()->withTranslations(siteDomainData: ['path' => 'test123'])->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $errorType = Blueprint::query()->pageType()->where('key', 'error')->first()
        ?? Blueprint::factory()->page()->state(['key' => 'error'])->create();
    Page::factory()
        ->site($site)
        ->blueprint($errorType)
        ->withTranslations()
        ->create();
    $domain = $site->siteDomains->first();

    $kernel = resolve(FrontendKernelInterface::class);

    $server = ['HTTP_HOST' => $domain->domain];
    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $base = $domain->path ?? '/';
    $base = $base === '' ? '/' : $base;

    $reqPath = rtrim($base, '/') . ($path === '/' ? '' : $path);

    $request = Request::create($reqPath, Symfony\Component\HttpFoundation\Request::METHOD_GET, server: $server);

    $result = $kernel->bootstrap($request);

    expect($result)->toBeInstanceOf(FrontendBootstrapResult::class)
        ->and($result->redirect)->toBeNull();

    if ($mayError) {
        $context = expectPresent($result->context);

        expect($context)->not()->toBeNull()
            ->and($context->isError())->toBeTrue()
            ->and(resolve(FrontendState::class)->isError())->toBeTrue();
    } else {
        $context = expectPresent($result->context);

        expect($result->error)->toBeNull()
            ->and($context)->not()->toBeNull()
            ->and($context->isError())->toBeFalse()
            ->and(resolve(FrontendState::class)->isError())->toBeFalse();
    }
})->with('paths');
