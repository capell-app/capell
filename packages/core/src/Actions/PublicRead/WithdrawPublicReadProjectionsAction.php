<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Exceptions\PublicReadWithdrawalFailedException;
use Capell\Core\Support\PublicRead\PublicReadWithdrawalSteps;
use Capell\Core\Support\TableName;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Withdraws stored public output after a public-read eligibility change.
 *
 * Before anything is purged, every registered step is recorded as owed for
 * this revision and scope. Each step then runs on its own: a step that throws
 * is reported and stays owed, the others still run, and the failures are
 * rethrown together at the end. An owed step is cleared only when it has
 * succeeded; the reconcile command (capell:public-read:reconcile) runs
 * whatever is still owed.
 *
 * @experimental
 *
 * @method static void run(string|null $revision, int|null $siteId)
 */
final class WithdrawPublicReadProjectionsAction
{
    use AsFake;
    use AsObject;

    public const string TABLE = 'public_read_owed_withdrawals';

    public function __construct(private readonly PublicReadWithdrawalSteps $steps) {}

    /**
     * Runs one owed step and clears it when it succeeds. Returns the failure,
     * after recording it against the owed row.
     *
     * @param  Closure(int|null): void  $step
     */
    public static function attempt(string $revision, string $key, string $scope, ?int $siteId, Closure $step): ?Throwable
    {
        $owed = static fn () => DB::table(TableName::resolve(self::TABLE))
            ->where('revision', $revision)
            ->where('step', $key)
            ->where('scope', $scope);

        try {
            $step($siteId);
        } catch (Throwable $throwable) {
            report($throwable);

            try {
                $owed()->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => mb_substr($throwable::class . ': ' . $throwable->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $recordFailure) {
                report($recordFailure);
            }

            return $throwable;
        }

        try {
            $owed()->delete();
        } catch (Throwable $throwable) {
            // Still owed: the retry runs the idempotent step again.
            report($throwable);
        }

        return null;
    }

    public static function scope(?int $siteId): string
    {
        return $siteId === null ? 'all' : 'site:' . $siteId;
    }

    public function handle(?string $revision, ?int $siteId): void
    {
        $steps = $this->steps->all();

        if ($steps === []) {
            return;
        }

        // A change whose revision could not be stored is still owed; key it uniquely.
        $revision ??= 'unknown:' . Str::ulid();
        $scope = self::scope($siteId);
        $this->recordOwed($revision, $scope, $siteId, array_keys($steps));

        $failures = [];

        foreach ($steps as $key => $step) {
            $failure = self::attempt($revision, $key, $scope, $siteId, $step);

            if ($failure instanceof Throwable) {
                $failures[$key] = $failure;
            }
        }

        throw_if($failures !== [], PublicReadWithdrawalFailedException::class, $failures);
    }

    /** @param list<string> $keys */
    private function recordOwed(string $revision, string $scope, ?int $siteId, array $keys): void
    {
        $now = now();

        try {
            DB::table(TableName::resolve(self::TABLE))->insertOrIgnore(array_map(static fn (string $key): array => [
                'revision' => $revision,
                'step' => $key,
                'scope' => $scope,
                'site_id' => $siteId,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $keys));
        } catch (Throwable $throwable) {
            // Withdrawing is still safe and more urgent than recording it.
            report($throwable);
        }
    }
}
