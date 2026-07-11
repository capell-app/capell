<?php

declare(strict_types=1);

use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Symfony\Component\HttpFoundation\Response;

it('allows ordinary public html responses', function (): void {
    $response = new Response('<article><h1>News</h1></article>', Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response);

    expect(true)->toBeTrue();
});

it('rejects public html responses containing authoring markers', function (): void {
    $response = new Response('<div data-capell-authoring="page:1">Edit</div>', Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response);
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');

it('rejects public html responses containing signed admin urls', function (): void {
    $response = new Response('<a href="/admin/pages/1/edit?signature=abc">Edit</a>', Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response);
})->throws(RuntimeException::class, 'Public HTML contains a signed admin URL.');
