<?php

declare(strict_types=1);

use Capell\Frontend\Support\Cache\FragmentCacheDirective;
use Illuminate\Support\Facades\Blade;

it('compiles paired cache directives with ttl and surrogate keys', function (): void {
    $directive = new FragmentCacheDirective;

    expect($directive->compile("'nav-menu', 120, ['site-1']"))
        ->toBe("<?php \$__capellFragmentArguments1 = ['nav-menu', 120, ['site-1']]; \$__capellFragmentScope1 = get_defined_vars(); echo app('capell-frontend.fragment-cache')->remember((string) (\$__capellFragmentArguments1[0] ?? ''), function() use (\$__capellFragmentScope1) { extract(\$__capellFragmentScope1, EXTR_SKIP); ob_start(); ?>")
        ->and($directive->compileEnd())
        ->toBe('<?php return ob_get_clean(); }, (int) ($__capellFragmentArguments1[1] ?? 3600), ($__capellFragmentArguments1[2] ?? [])); ?>');
});

it('uses defaults and isolates nested directive state between scoped instances', function (): void {
    $directive = new FragmentCacheDirective;

    $directive->compile("'outer', 60, ['outer']");
    $directive->compile("'inner', 30, ['inner']");

    expect($directive->compileEnd())
        ->toBe('<?php return ob_get_clean(); }, (int) ($__capellFragmentArguments2[1] ?? 3600), ($__capellFragmentArguments2[2] ?? [])); ?>');

    $directive = new FragmentCacheDirective;

    expect($directive->compileEnd())->toBe('<?php return ob_get_clean(); }, 3600, []); ?>');
});

it('renders array arguments and preserves variables from the Blade scope', function (): void {
    $cache = new class
    {
        /** @var list<array{string, int, list<string>}> */
        public array $calls = [];

        /** @param list<string> $surrogateKeys */
        public function remember(string $key, callable $callback, int $ttl, array $surrogateKeys): string
        {
            $this->calls[] = [$key, $ttl, $surrogateKeys];

            return (string) $callback();
        }
    };
    app()->instance('capell-frontend.fragment-cache', $cache);

    $html = Blade::render(
        <<<'BLADE'
        @cache('article-card', 120, ['page-123', 'site-456'])
            <article>{{ $title }}</article>
        @endcache
        BLADE,
        ['title' => 'Preserved title'],
    );

    expect($html)->toContain('<article>Preserved title</article>')
        ->and($cache->calls)->toBe([
            ['article-card', 120, ['page-123', 'site-456']],
        ]);
});
