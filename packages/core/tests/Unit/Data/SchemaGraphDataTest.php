<?php

declare(strict_types=1);

use Capell\Core\Data\SchemaGraphData;

it('escapes script-breaking JSON-LD values', function (): void {
    $graph = new SchemaGraphData([
        [
            '@type' => 'WebPage',
            'name' => '</script><script>alert(1)</script>',
        ],
    ]);

    $script = $graph->toJsonLdScript();

    expect($script)
        ->toStartWith('<script type="application/ld+json">')
        ->toEndWith('</script>')
        ->and(substr_count($script, '</script>'))->toBe(1)
        ->and($script)->toContain('\u003C/script\u003E');
});
