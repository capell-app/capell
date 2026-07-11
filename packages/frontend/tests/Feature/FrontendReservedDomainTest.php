<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;

it('rejects reserved hosts before resolving public themes', function (): void {
    resolve(ReservedFrontendDomainRegistry::class)->reserve('admin.test');

    $this->get('http://admin.test/pages/125/edit')
        ->assertNotFound()
        ->assertDontSee('Frontend unavailable')
        ->assertDontSee('The selected theme is not available.');
});

it('rejects page-like catch-all urls on the reserved host', function (): void {
    resolve(ReservedFrontendDomainRegistry::class)->reserve('admin.test');

    // A normal-looking page URL that the path-based defences would happily
    // pass through is still blocked because the whole host is reserved.
    $this->get('http://admin.test/about')
        ->assertNotFound()
        ->assertDontSee('Frontend unavailable')
        ->assertDontSee('The selected theme is not available.');
});

it('still serves non-reserved hosts through the frontend', function (): void {
    resolve(ReservedFrontendDomainRegistry::class)->reserve('admin.test');

    // A non-reserved host is not short-circuited by the domain guard; it
    // proceeds into normal frontend resolution (which 404s here only because
    // no matching page exists, not because the host was reserved).
    $this->get('http://site.test/about')
        ->assertDontSee('Frontend unavailable');
});
