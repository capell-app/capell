<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\Cache\FrontendCachePolicy;
use Illuminate\Http\Request;

describe('FrontendCachePolicy', function (): void {
    beforeEach(function (): void {
        config()->set('capell-frontend.cache_skip_authenticated', false);
        config()->set('session.cookie');
    });

    it('caches a clean GET request with no session or auth', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET);

        expect($policy->shouldCache($context, $request))->toBeTrue();
    });

    it('does not cache POST requests', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_POST);

        expect($policy->shouldCache($context, $request))->toBeFalse();
    });

    it('does not cache error contexts', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(true);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET);

        expect($policy->shouldCache($context, $request))->toBeFalse();
    });

    it('does not cache signed preview requests', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET, ['signature' => 'abc123']);

        expect($policy->shouldCache($context, $request))->toBeFalse();
    });

    it('does not skip cache for empty signature query param', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET, ['signature' => '']);

        expect($policy->shouldCache($context, $request))->toBeTrue();
    });

    it('does not cache authenticated users when cache_skip_authenticated is true', function (): void {
        config()->set('capell-frontend.cache_skip_authenticated', true);

        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET);
        $request->setUserResolver(fn (?string $guard): object => new stdClass);

        expect($policy->shouldCache($context, $request))->toBeFalse();
    });

    it('caches authenticated user requests when cache_skip_authenticated is false', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET);
        $request->setUserResolver(fn (?string $guard): object => new stdClass);

        expect($policy->shouldCache($context, $request))->toBeTrue();
    });

    it('does not cache requests when session cookie is present', function (): void {
        config()->set('session.cookie', 'laravel_session');

        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET, [], ['laravel_session' => 'session-value']);

        expect($policy->shouldCache($context, $request))->toBeFalse();
    });

    it('caches GET requests when session cookie name is configured but not present in request', function (): void {
        config()->set('session.cookie', 'laravel_session');

        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);

        $policy = new FrontendCachePolicy;
        $request = Request::create('/page', Symfony\Component\HttpFoundation\Request::METHOD_GET);

        expect($policy->shouldCache($context, $request))->toBeTrue();
    });
});
