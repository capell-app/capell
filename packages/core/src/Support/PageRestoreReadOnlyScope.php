<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Exceptions\PageRestoreCancelledException;
use Closure;
use Illuminate\Database\Connection;
use WeakMap;

/** Restore decisions must not mutate the state on which earlier decisions depended. */
final class PageRestoreReadOnlyScope
{
    /** @var WeakMap<Connection, self>|null */
    private static ?WeakMap $scopes = null;

    private int $depth = 0;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $check
     * @return TResult
     */
    public static function run(Connection $connection, Closure $check): mixed
    {
        self::$scopes ??= new WeakMap;
        if (! isset(self::$scopes[$connection])) {
            $scope = new self;
            self::$scopes[$connection] = $scope;
            $connection->beforeExecuting(static function (string $query) use ($scope): void {
                throw_if($scope->depth > 0 && preg_match('/\A\s*select\b/i', $query) !== 1, PageRestoreCancelledException::class);
            });
        }

        $scope = self::$scopes[$connection];
        $scope->depth++;
        try {
            return $check();
        } finally {
            $scope->depth--;
        }
    }
}
