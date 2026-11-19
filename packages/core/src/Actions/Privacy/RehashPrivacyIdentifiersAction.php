<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Privacy;

use Capell\Core\Support\Privacy\PrivacyIdentifierHasher;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * A row is only written while its hash is still the one that was read, so an identifier changed
 * by someone else during the run is never overwritten with a hash of the old value; such a row is
 * counted as skipped and the next run picks it up. Soft-deleted rows of an Eloquent query are
 * included, because they still hold a hash under the old key.
 *
 * @method static array{rehashed: int, unavailable: int, skipped: int} run(QueryBuilder|EloquentBuilder<covariant Model> $query, string $hashColumn, Closure(object): ?string $identifier, string $keyColumn = 'id')
 */
final class RehashPrivacyIdentifiersAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  QueryBuilder|EloquentBuilder<covariant Model>  $query  the rows to rehash
     * @param  Closure(object): ?string  $identifier  the row's identifier, or null when it is not available
     * @return array{rehashed: int, unavailable: int, skipped: int}
     */
    public function handle(QueryBuilder|EloquentBuilder $query, string $hashColumn, Closure $identifier, string $keyColumn = 'id'): array
    {
        if ($query instanceof EloquentBuilder && in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query = $query->withTrashed();
        }

        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $rehashed = 0;
        $unavailable = 0;
        $skipped = 0;

        $base->clone()->chunkById(200, function ($rows) use ($base, $hashColumn, $identifier, $keyColumn, &$rehashed, &$unavailable, &$skipped): void {
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

                $stored = $row->{$hashColumn} ?? null;
                $updated = $base->clone()
                    ->where($keyColumn, $row->{$keyColumn})
                    ->when(
                        $stored === null,
                        static fn (QueryBuilder $update): QueryBuilder => $update->whereNull($hashColumn),
                        static fn (QueryBuilder $update): QueryBuilder => $update->where($hashColumn, $stored),
                    )
                    ->update([$hashColumn => $hash]);

                if ($updated === 0) {
                    $skipped++;

                    continue;
                }

                $rehashed++;
            }
        }, $keyColumn);

        return ['rehashed' => $rehashed, 'unavailable' => $unavailable, 'skipped' => $skipped];
    }
}
