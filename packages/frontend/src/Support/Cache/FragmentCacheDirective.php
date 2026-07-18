<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

final class FragmentCacheDirective
{
    /**
     * Stack of "(ttl, surrogateKeys)" parameter tuples captured by `@cache`,
     * popped by `@endcache` so nested directives compile correctly.
     *
     * @var array<int, string>
     */
    private array $tailStack = [];

    /**
     * Compile `@cache` directive into PHP code.
     *
     * Usage examples (Blade):
     *   - cache('nav-menu', 3600, ['page-123', 'site-456'])
     *   - cache('sidebar-content', 7200)
     *   - cache('newsletter-form')
     *
     * @param  string  $expression  raw Blade expression, e.g. "'nav-menu', 3600, ['page-123']"
     */
    public function compile(string $expression): string
    {
        $parts = array_map(trim(...), str_getcsv($expression, ',', escape: '\\'));

        $key = $parts[0] ?? "''";
        $ttl = $parts[1] ?? '3600';
        $surrogateKeys = $parts[2] ?? '[]';

        $this->tailStack[] = sprintf(', (int) %s, %s', $ttl, $surrogateKeys);

        return sprintf("<?php echo app('capell-frontend.fragment-cache')->remember(%s, function() { ob_start(); ?>", $key);
    }

    public function compileEnd(): string
    {
        $tail = array_pop($this->tailStack) ?? ', 3600, []';

        return sprintf('<?php return ob_get_clean(); }%s); ?>', $tail);
    }
}
