<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\Cache;

it('emits exactly one noindex robots tag on a frontend 404', function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false]);
    Cache::flush();
    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create(['meta' => null]);

    $response = $this->followingRedirects()->get('/a-missing-page', ['HTTP_HOST' => 'localhost']);
    $response->assertNotFound();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tags = new DOMXPath($document)->query('//meta[translate(@name, "ROBOTS", "robots")="robots"]') ?: throw new RuntimeException('Invalid XPath');
    $tag = $tags->item(0);
    throw_unless($tag instanceof DOMElement, RuntimeException::class, 'Missing robots tag');

    expect($tags->length)->toBe(1)
        ->and($tag->getAttribute('content'))->toBe('noindex');
});

it('suppresses managed metadata from body hooks on a frontend response', function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false]);
    Cache::flush();
    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create(['meta' => null]);
    resolve(RenderHookRegistry::class)->register(RenderHookLocation::BodyEnd, fn (): string => '<meta name="robots" content="index"><span>Body hook survives</span>');
    $response = $this->followingRedirects()->get('/a-missing-page', ['HTTP_HOST' => 'localhost']);
    $response->assertNotFound();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tags = new DOMXPath($document)->query('//meta[@name="robots"]') ?: throw new RuntimeException('Invalid XPath');
    expect($tags->length)->toBe(1)->and($response->getContent())->toContain('Body hook survives');
});
