<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Privacy;

use Capell\Core\Support\Privacy\PrivacyIdentifierHasher;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Rewrites stored identifier hashes under the current key, so earlier keys can be retired.
 *
 * A hash cannot be converted on its own, so the caller says how to read each row's identifier
 * (for example by decrypting its stored email address). Rows whose identifier is unavailable
 * are left as they are and counted, and an earlier key must stay configured until that count
 * is zero. Safe to run again: a row already under the current key is not written.
 *
 * @method static array{rehashed: int, unavailable: int} run(QueryBuilder|EloquentBuilder<covariant Model> $query, string $hashColumn, Closure(object): ?string $identifier, string $keyColumn = 'id')
 */
final class RehashPrivacyIdentifiersAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  QueryBuilder|EloquentBuilder<covariant Model>  $query  the rows to rehash
     * @param  Closure(object): ?string  $identifier  the row's identifier, or null when it is not available
     * @return array{rehashed: int, unavailable: int}
     */
    public function handle(QueryBuilder|EloquentBuilder $query, string $hashColumn, Closure $identifier, string $keyColumn = 'id'): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $rehashed = 0;
        $unavailable = 0;

        $base->clone()->chunkById(200, function ($rows) use ($base, $hashColumn, $identifier, $keyColumn, &$rehashed, &$unavailable): void {
            foreach ($rows as $row) {
                $value = $identifier($row);

                if (! is_string($value) || trim($value) === '') {
                    $unavailable++;

                    continue;
                }

                $hash = PrivacyIdentifierHasher::hash($value);

                if (($row->{$hashColumn} ?? null) === $hash) {
                    continue;
                }

                $base->clone()->where($keyColumn, $row->{$keyColumn})->update([$hashColumn => $hash]);
                $rehashed++;
            }
        }, $keyColumn);

        return ['rehashed' => $rehashed, 'unavailable' => $unavailable];
    }
}
