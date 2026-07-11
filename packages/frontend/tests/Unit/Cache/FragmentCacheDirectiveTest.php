<?php

declare(strict_types=1);

use Capell\Frontend\Support\Cache\FragmentCacheDirective;

it('compiles paired cache directives with ttl and surrogate keys', function (): void {
    $directive = new FragmentCacheDirective;

    expect($directive->compile("'nav-menu', 120, ['site-1']"))
        ->toBe("<?php echo app('capell-frontend.fragment-cache')->remember('nav-menu', function() { ob_start(); ?>")
        ->and($directive->compileEnd())
        ->toBe("<?php return ob_get_clean(); }, (int) 120, ['site-1']); ?>");
});

it('uses defaults and clears nested directive state', function (): void {
    $directive = new FragmentCacheDirective;

    $directive->compile("'outer', 60, ['outer']");
    $directive->compile("'inner', 30, ['inner']");

    expect($directive->compileEnd())->toBe("<?php return ob_get_clean(); }, (int) 30, ['inner']); ?>");

    $directive->flushOctaneState();

    expect($directive->compileEnd())->toBe('<?php return ob_get_clean(); }, 3600, []); ?>');
});
