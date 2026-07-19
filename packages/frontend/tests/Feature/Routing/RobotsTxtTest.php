<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\RobotsDirectiveContributor;
use Capell\Frontend\Data\RobotsDirectiveData;

it('serves a conservative default robots policy and sitemap location', function (): void {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent("User-agent: *\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\n");
});

it('merges contributed crawler policies after the default group', function (): void {
    $contributor = new class implements RobotsDirectiveContributor
    {
        public function directives(): array
        {
            return [
                new RobotsDirectiveData('GPTBot', [], ['/']),
                new RobotsDirectiveData('CCBot', ['/public'], ['/private']),
            ];
        }
    };
    app()->instance('test.robots-directive-contributor', $contributor);
    app()->tag('test.robots-directive-contributor', RobotsDirectiveContributor::TAG);

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    $response->assertSeeText('User-agent: GPTBot')
        ->assertSeeText('Disallow: /')
        ->assertSeeText('User-agent: CCBot')
        ->assertSeeText('Allow: /public')
        ->assertSeeText('Disallow: /private');

    expect(strpos((string) $response->getContent(), 'User-agent: *'))
        ->toBeLessThan(strpos((string) $response->getContent(), 'User-agent: CCBot'))
        ->and(strpos((string) $response->getContent(), 'User-agent: CCBot'))
        ->toBeLessThan(strpos((string) $response->getContent(), 'User-agent: GPTBot'));
});
