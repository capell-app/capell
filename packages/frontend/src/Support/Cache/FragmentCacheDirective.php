<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

final class FragmentCacheDirective
{
    /**
     * Stack of unique fragment identifiers captured by `@cache`, popped by
     * `@endcache` so nested directives compile correctly.
     *
     * @var list<int>
     */
    private array $tailStack = [];

    private int $fragmentCount = 0;

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
        $fragmentId = ++$this->fragmentCount;
        $arguments = sprintf('$__capellFragmentArguments%d', $fragmentId);
        $scope = sprintf('$__capellFragmentScope%d', $fragmentId);

        $this->tailStack[] = $fragmentId;

        return sprintf(
            "<?php %s = [%s]; %s = get_defined_vars(); echo app('capell-frontend.fragment-cache')->remember((string) (%s[0] ?? ''), function() use (%s) { extract(%s, EXTR_SKIP); ob_start(); ?>",
            $arguments,
            $expression,
            $scope,
            $arguments,
            $scope,
            $scope,
        );
    }

    public function compileEnd(): string
    {
        $fragmentId = array_pop($this->tailStack);

        if (! is_int($fragmentId)) {
            return '<?php return ob_get_clean(); }, 3600, []); ?>';
        }

        $arguments = sprintf('$__capellFragmentArguments%d', $fragmentId);

        return sprintf(
            '<?php return ob_get_clean(); }, (int) (%1$s[1] ?? 3600), (%1$s[2] ?? [])); ?>',
            $arguments,
        );
    }
}
