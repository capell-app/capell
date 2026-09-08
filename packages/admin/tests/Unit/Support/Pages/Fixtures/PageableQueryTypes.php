<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Pages\Fixtures;

use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Support\Pages\DefaultPageTableStatusResolver;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;

use function PHPStan\Testing\assertType;

final class PageableQueryTypes
{
    /**
     * @param  Builder<Page>  $pages
     * @param  Builder<NonPageablePageForResolverTest>  $articles
     */
    public function preservesModels(
        PageTableStatusResolver $resolver,
        DefaultPageTableStatusResolver $defaultResolver,
        Builder $pages,
        Builder $articles,
    ): void {
        assertType('Illuminate\Database\Eloquent\Builder<Capell\Core\Models\Page>', $resolver->modifyQuery($pages));
        assertType('Illuminate\Database\Eloquent\Builder<Capell\Admin\Tests\Unit\Support\Pages\Fixtures\NonPageablePageForResolverTest>', $resolver->modifyQuery($articles));
        assertType('Illuminate\Database\Eloquent\Builder<Capell\Admin\Tests\Unit\Support\Pages\Fixtures\NonPageablePageForResolverTest>', $defaultResolver->modifyQuery($articles));
    }
}
