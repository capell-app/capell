<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Support\Pagination\StatelessPaginationResolver;
use Illuminate\Http\Request;

it('activates only for anonymous cacheable GET requests', function (): void {
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('session.cookie', 'capell_session');

    $bypass = new class implements CacheBypassResolver
    {
        public function shouldBypass(): bool
        {
            return false;
        }
    };
    $resolver = new StatelessPaginationResolver($bypass);

    expect($resolver->isActive(Request::create('/listing', Symfony\Component\HttpFoundation\Request::METHOD_GET)))->toBeTrue()
        ->and($resolver->isPublicCacheableRequest(Request::create('/listing', Symfony\Component\HttpFoundation\Request::METHOD_POST)))->toBeFalse()
        ->and($resolver->isPublicCacheableRequest(Request::create('/listing', Symfony\Component\HttpFoundation\Request::METHOD_GET, [], [], [], ['HTTP_X_LIVEWIRE' => 'true'])))->toBeFalse();
});

it('rejects authenticated, session-backed, and bypassed requests', function (): void {
    config()->set('session.cookie', 'capell_session');
    $request = Request::create('/listing', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->cookies->set('capell_session', 'present');

    $bypass = new class implements CacheBypassResolver
    {
        public function shouldBypass(): bool
        {
            return true;
        }
    };

    expect(new StatelessPaginationResolver($bypass)->isPublicCacheableRequest($request))->toBeFalse();
});

it('honours the feature flag and filters configured query parameters', function (): void {
    config()->set('capell-html-cache.stateless_pagination.enabled', false);
    config()->set('capell-html-cache.stateless_pagination.params', ['page', 'filter', 12, null]);

    $bypass = new class implements CacheBypassResolver
    {
        public function shouldBypass(): bool
        {
            return false;
        }
    };
    $resolver = new StatelessPaginationResolver($bypass);

    expect($resolver->isEnabled())->toBeFalse()
        ->and($resolver->isActive(Request::create('/listing', Symfony\Component\HttpFoundation\Request::METHOD_GET)))->toBeFalse()
        ->and($resolver->allowedParams())->toBe(['page', 'filter']);
});
