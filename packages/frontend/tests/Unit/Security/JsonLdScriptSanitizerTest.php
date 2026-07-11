<?php

declare(strict_types=1);

use Capell\Frontend\Support\Security\JsonLdScriptSanitizer;

it('escapes script terminators in custom json ld', function (): void {
    $jsonLd = '{"name":"</script><script>alert(1)</script>"}';

    $sanitized = JsonLdScriptSanitizer::sanitize($jsonLd);

    expect($sanitized)
        ->not->toContain('</script>')
        ->not->toContain('</SCRIPT>')
        ->toContain('<\/script>');
});
