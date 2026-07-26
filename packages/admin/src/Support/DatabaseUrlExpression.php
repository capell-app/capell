<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

final class DatabaseUrlExpression
{
    public static function full(BuilderContract $query, mixed $defaultScheme, mixed $defaultDomain): SqlFragment
    {
        $grammar = $query->getQuery()->getGrammar();

        return CapellDatabase::for($query->getModel())->queryDialect()->concatenate(
            new SqlFragment('COALESCE(' . $grammar->wrap('site_domains.scheme') . ', ?)', [$defaultScheme]),
            SqlFragment::raw("'://'"),
            new SqlFragment('COALESCE(' . $grammar->wrap('site_domains.domain') . ', ?)', [$defaultDomain]),
            SqlFragment::raw('COALESCE(' . $grammar->wrap('site_domains.path') . ", '')"),
            SqlFragment::raw($grammar->wrap('page_urls.url')),
        );
    }

    public static function withoutScheme(BuilderContract $query, mixed $defaultDomain): SqlFragment
    {
        $grammar = $query->getQuery()->getGrammar();

        return CapellDatabase::for($query->getModel())->queryDialect()->concatenate(
            new SqlFragment('COALESCE(' . $grammar->wrap('site_domains.domain') . ', ?)', [$defaultDomain]),
            SqlFragment::raw('COALESCE(' . $grammar->wrap('site_domains.path') . ", '')"),
            SqlFragment::raw($grammar->wrap('page_urls.url')),
        );
    }
}
