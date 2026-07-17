<?php

declare(strict_types=1);

use Capell\Core\Support\Security\SignedUrlCanonicalizer;

it('orders query parameters and removes signatures from canonical urls', function (): void {
    expect(SignedUrlCanonicalizer::canonicalize(
        'https://example.test:8443/page?z=last&signature=ignored&a=hello+world',
    ))->toBe('https://example.test:8443/page?a=hello%20world&z=last');
});
