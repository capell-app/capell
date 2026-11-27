<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\Cache\FrontendCachePolicy;
use Capell\Frontend\Support\Cache\PrivateFrontendResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

describe('PrivateFrontendResponse', function (): void {
    beforeEach(function (): void {
        config()->set('capell-frontend.cache_skip_authenticated', false);
        config()->set('session.cookie');
    });

    it('stops the cache policy caching a request carrying the marker', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);
        $request = Request::create('/account');
        PrivateFrontendResponse::mark($request);

        expect((new FrontendCachePolicy)->shouldCache($context, $request))->toBeFalse()
            ->and((new FrontendCachePolicy)->shouldCache($context, Request::create('/account')))->toBeTrue();
    });

    it('stops the cache policy caching a response sent with private headers', function (): void {
        $context = Mockery::mock(FrontendContextReader::class);
        $context->allows('isError')->andReturn(false);
        $response = PrivateFrontendResponse::applyHeaders(new Response('account'));

        expect((new FrontendCachePolicy)->shouldCache($context, Request::create('/account'), $response))->toBeFalse()
            ->and((new FrontendCachePolicy)->shouldCache($context, Request::create('/account'), new Response('page')))->toBeTrue();
    });

    it('sends private no-store headers that vary on the cookie', function (): void {
        $response = PrivateFrontendResponse::applyHeaders(new Response('account'));

        expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
            ->and($response->getVary())->toContain('Cookie');
    });
});
