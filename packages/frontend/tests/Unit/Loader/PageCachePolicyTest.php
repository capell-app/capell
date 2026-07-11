<?php

declare(strict_types=1);

use Capell\Frontend\Support\Loader\PageCachePolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('allows caching only for eligible GET html responses', function (): void {
    $policy = new PageCachePolicy;

    $getHtml200 = Request::create('/home', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $resp200 = new Response('<html>ok</html>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    $postHtml200 = Request::create('/home', Symfony\Component\HttpFoundation\Request::METHOD_POST);

    expect($policy->eligible($getHtml200, $resp200))->toBeTrue()
        ->and($policy->eligible($postHtml200, $resp200))->toBeFalse();
});

it('does not cache html responses flagged by public html safety checks', function (): void {
    $policy = new PageCachePolicy;
    $request = Request::create('/news', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $response = new Response('<article>News</article>', Response::HTTP_OK, ['Content-Type' => 'text/html']);
    $response->headers->set('X-Capell-Public-Html-Safety', 'authoring_marker');

    expect($policy->eligible($request, $response))->toBeFalse();
});
