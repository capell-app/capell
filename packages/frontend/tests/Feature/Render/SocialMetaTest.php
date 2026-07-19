<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

beforeEach(function (): void {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
});

it('renders escaped open graph and twitter metadata', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: [
        'default' => true,
        'domain' => 'example.test',
        'path' => null,
        'scheme' => 'https',
    ])->createOne();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => 'article']);

    Page::factory()
        ->published()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($site->language, [
            'title' => 'Hello & news',
            'meta' => ['description' => '"><script>alert(1)</script>'],
        ], slug: 'article')
        ->createOne();

    $response = $this->get('https://example.test/article')->assertOk();

    $response
        ->assertSee('property="og:title"', false)
        ->assertSee('content="Hello &amp; news"', false)
        ->assertSee('property="og:description"', false)
        ->assertSee('content="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', false)
        ->assertSee('property="og:url"', false)
        ->assertSee('content="https://example.test/article"', false)
        ->assertSee('property="og:type" content="article"', false)
        ->assertSee('name="twitter:card" content="summary"', false)
        ->assertDontSee('"><script>alert(1)</script>', false);
});
