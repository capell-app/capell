<?php

declare(strict_types=1);

it('defaults the sitemap index path to the canonical /sitemap.xml', function (): void {
    expect(config('capell.sitemap.xml_path'))->toBe('/sitemap.xml');
});
