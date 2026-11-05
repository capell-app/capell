<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Data\PublicRead\PublicReadReconciliationData;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Core\Support\PublicRead\PublicReadWithdrawalSteps;
use Capell\Core\Support\TableName;
use Closure;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * The source of truth for withdrawing stored public output.
 *
 * Every store that keeps public copies outside Laravel's per-request checks
 * registers a withdrawal step. For each step and site, a watermark records
 * the public-read stamp (revision and next transition) under which that
 * site's copies were last withdrawn. Copies are written only under the
 * current stamp (PublishPublicArtefactAction checks it inside the store's
 * lock), so while a watermark equals the site's current stamp every copy in
 * that store is current. Whenever they differ, the step runs again.
 *
 * The durable revision is checked first: when the fingerprint of the stored
 * rules differs from the one recorded with the revision (a change nothing
 * announced, or whose revision write failed), the revision is advanced. So a
 * change converges within one run without any owed row, unknown token or
 * cache entry having been written. Those remain a fast path only.
 *
 * Fail closed: when the rules, the revision or the watermarks cannot be read,
 * every step runs for every site and no watermark is marked clean. The one
 * exception is a store with no watermark while nothing restricts reads (a
 * fresh install or upgrade without any restricting package): it cannot hold
 * a restricted page, so it is not purged.
 *
 * Fair: due work is taken in order of its last attempt (failed work after
 * work that succeeded at the same moment), at most $limit items per run, and
 * each item runs on its own, so one failing store never blocks the others.
 *
 * @experimental
 *
 * @method static PublicReadReconciliationData run(int $limit = 100)
 */
final class ReconcilePublicReadProjectionsAction
{
    use AsFake;
    use AsObject;

    public const string TABLE = 'public_read_projection_watermarks';

    public function __construct(
        private readonly PublicReadWithdrawalSteps $steps,
        private readonly PublicPageReadEligibilityRegistry $registry,
        private readonly PublicReadEligibilityRevision $revision,
        private readonly PublicReadEligibilityState $state,
    ) {}

    public function handle(int $limit = 100): PublicReadReconciliationData
    {
        $steps = $this->steps->all();

        if ($steps === []) {
            return new PublicReadReconciliationData([], []);
        }

        $rulesReadable = $this->repairRevision();
        $sites = $this->siteIds();
        $targets = [];

        foreach ($sites as $siteId) {
            $targets[WithdrawPublicReadProjectionsAction::scope($siteId)] = $rulesReadable ? $this->state->freshStamp($siteId)?->key() : null;
        }

        $watermarks = $this->watermarks();
        $due = [];

        foreach (array_keys($steps) as $key) {
            foreach ($sites as $siteId) {
                $scope = WithdrawPublicReadProjectionsAction::scope($siteId);
                $watermark = $watermarks === null ? null : ($watermarks[$key . "\n" . $scope] ?? null);
                $target = $targets[$scope];

                if ($target !== null && $watermark !== null && $watermark['stamp_key'] === $target) {
                    continue;
                }

                // Nothing restricts reads and this store was never withdrawn: no
                // restricted page can be in it, so there is nothing to withdraw.
                // Once any rule restricts reads, the missing watermark is due.
                if ($target === PublicReadStampData::UNRESTRICTED_KEY && $watermarks !== null && $watermark === null) {
                    continue;
                }

                $due[] = [
                    'key' => $key,
                    'scope' => $scope,
                    'site' => $siteId,
                    'target' => $target,
                    'attempted' => $watermark['attempted_at'] ?? '',
                    'failed' => $watermark !== null && $watermark['failed'],
                ];
            }
        }

        usort($due, static fn (array $a, array $b): int => [$a['attempted'], $a['failed'], $a['key'], $a['scope']] <=> [$b['attempted'], $b['failed'], $b['key'], $b['scope']]);

        $withdrawn = [];
        $failures = [];

        foreach (array_slice($due, 0, max(1, $limit)) as $item) {
            $label = $item['key'] . ' (' . $item['scope'] . ')';
            $failure = $this->attempt($steps[$item['key']], $item['site']);

            if ($failure instanceof Throwable) {
                $failures[$label] = $failure->getMessage();
                $this->recordFailure($item['key'], $item['scope'], $item['site'], $failure);

                continue;
            }

            $withdrawn[] = $label;
            $this->recordSuccess($item['key'], $item['scope'], $item['site'], $item['target']);
        }

        return new PublicReadReconciliationData($withdrawn, $failures);
    }

    /**
     * Advances the durable revision when the stored rules no longer match the
     * fingerprint recorded with it, or when a failed advance left it unknown.
     * Returns false when the rules cannot be read (everything is then stale).
     */
    private function repairRevision(): bool
    {
        if (! $this->registry->hasContributors()) {
            return true;
        }

        try {
            $fingerprint = $this->registry->fingerprint();
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        $durable = $this->revision->durable();

        if ($durable !== null && ($durable['unknown'] || $durable['fingerprint'] !== $fingerprint)) {
            $this->revision->advance();
        }

        return true;
    }

    /**
     * Every site, or a single "every site" scope when sites cannot be listed.
     *
     * @return list<int|null>
     */
    private function siteIds(): array
    {
        try {
            $ids = Site::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        } catch (Throwable $throwable) {
            report($throwable);

            return [null];
        }

        return $ids === [] ? [null] : array_values($ids);
    }

    /**
     * Watermarks keyed by step and scope, or null when they cannot be read.
     *
     * @return array<string, array{stamp_key: string|null, attempted_at: string, failed: bool}>|null
     */
    private function watermarks(): ?array
    {
        try {
            $rows = DB::table(TableName::resolve(self::TABLE))->get(['step', 'scope', 'stamp_key', 'attempted_at', 'failed_at']);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        $watermarks = [];

        foreach ($rows as $row) {
            $watermarks[$row->step . "\n" . $row->scope] = [
                'stamp_key' => is_string($row->stamp_key) && $row->stamp_key !== '' ? $row->stamp_key : null,
                'attempted_at' => is_string($row->attempted_at) ? $row->attempted_at : '',
                'failed' => $row->failed_at !== null,
            ];
        }

        return $watermarks;
    }

    /** @param Closure(int|null): void $step */
    private function attempt(Closure $step, ?int $siteId): ?Throwable
    {
        try {
            $step($siteId);
        } catch (Throwable $throwable) {
            report($throwable);

            return $throwable;
        }

        return null;
    }

    /**
     * Marks the store clean for the stamp read before the step ran. A change
     * during the run leaves it different from the next target, so the step
     * runs again; with an unknown target it stays unmarked and runs every time.
     */
    private function recordSuccess(string $key, string $scope, ?int $siteId, ?string $target): void
    {
        $this->record($key, $scope, $siteId, [
            'stamp_key' => $target,
            'attempts' => 0,
            'failed_at' => null,
            'last_error' => null,
        ]);
    }

    private function recordFailure(string $key, string $scope, ?int $siteId, Throwable $failure): void
    {
        $this->record($key, $scope, $siteId, [
            'attempts' => DB::raw('attempts + 1'),
            'failed_at' => now(),
            'last_error' => mb_substr($failure::class . ': ' . $failure->getMessage(), 0, 2000),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function record(string $key, string $scope, ?int $siteId, array $values): void
    {
        $now = now();

        try {
            $table = DB::table(TableName::resolve(self::TABLE));
            $updated = (clone $table)->where('step', $key)->where('scope', $scope)->update([...$values, 'attempted_at' => $now, 'updated_at' => $now]);

            if ($updated === 0) {
                $table->insertOrIgnore([
                    'step' => $key,
                    'scope' => $scope,
                    'site_id' => $siteId,
                    'stamp_key' => $values['stamp_key'] ?? null,
                    'attempts' => array_key_exists('stamp_key', $values) ? 0 : 1,
                    'attempted_at' => $now,
                    'failed_at' => $values['failed_at'] ?? null,
                    'last_error' => $values['last_error'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (Throwable $throwable) {
            // Unrecorded work is simply due again on the next run.
            report($throwable);
        }
    }
}
