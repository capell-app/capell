<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

final class DatabaseUrlExpression
{
    /** @return Expression<literal-string> */
    public static function siteDomain(): Expression
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $dialect = CapellDatabase::for($connection)->queryDialect();
        $url = $dialect->concatenate(
            SqlFragment::raw($grammar->wrap('scheme')),
            SqlFragment::raw("'://'"),
            SqlFragment::raw($grammar->wrap('domain')),
            SqlFragment::raw('COALESCE(' . $grammar->wrap('path') . ", '')"),
        );

        return $dialect->trimTrailingSlash($url)->expression();
    }

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
