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

it('sanitizes a json ld script payload without corrupting its wrapper', function (): void {
    $script = '<script type="application/ld+json">{"name":"</script><script>alert(1)</script>"}</script>';

    $sanitized = JsonLdScriptSanitizer::sanitizeScriptTag($script);

    expect($sanitized)
        ->toEndWith('</script>')
        ->toContain('<\/script><script>alert(1)<\/script>')
        ->and(substr_count($sanitized, '</script>'))->toBe(1);
});
