<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseUrlExpression
{
    /** @return Expression<literal-string> */
    public static function siteDomain(): Expression
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $dialect = CapellDatabase::for($connection)->queryDialect();
        $defaultScheme = config('capell-frontend.default_scheme', request()->getScheme());
        $defaultScheme = is_string($defaultScheme) && $defaultScheme !== ''
            ? $defaultScheme
            : request()->getScheme();
        $quotedDefaultScheme = $connection->getPdo()->quote($defaultScheme);

        throw_unless(is_string($quotedDefaultScheme), RuntimeException::class, 'Unable to quote the default site domain scheme.');

        $url = $dialect->concatenate(
            SqlFragment::raw(sprintf(
                'COALESCE(%s, %s)',
                $grammar->wrap('scheme'),
                $quotedDefaultScheme,
            )),
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
