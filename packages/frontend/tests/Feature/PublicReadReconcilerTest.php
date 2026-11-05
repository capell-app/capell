<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\FingerprintsPublicReadRules;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Core\Support\TableName;
use Capell\Frontend\Support\PublicRead\FrontendPublicReadWithdrawalSteps;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Registers rules whose stored state is a version number the test changes
 * directly, as an editor's save would, with or without anything announcing it.
 */
function reconcilerRules(): stdClass
{
    $rules = new stdClass;
    $rules->version = 1;

    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($rules) implements FingerprintsPublicReadRules, PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private stdClass $rules) {}

        public function key(): string
        {
            return 'reconciler-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function publicReadRulesFingerprint(): string
        {
            return 'version:' . $this->rules->version;
        }
    });

    return $rules;
}

function reconcilerExportRoot(): string
{
    $root = sys_get_temp_dir() . '/capell-reconciler-' . bin2hex(random_bytes(4));
    config(['capell-frontend.static_artifacts_path' => $root]);

    return $root;
}

function reconcilerCdn(bool $available): void
{
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'test-token',
        'capell-frontend.cloudflare_zone_id' => 'test-zone',
    ]);
    app()->instance('capell.test.reconciler-cdn-available', $available);

    if (! app()->bound('capell.test.reconciler-cdn-faked')) {
        app()->instance('capell.test.reconciler-cdn-faked', true);
        Http::fake(['api.cloudflare.com/*' => static fn () => resolve('capell.test.reconciler-cdn-available') === true
            ? Http::response(['success' => true])
            : Http::response(['success' => false], 503)]);
    }
}

/** Writes a static export the way the generator does: built under the site's current stamp. */
function reconcilerExport(int $siteId, string $file, string $html): bool
{
    app()->forgetScopedInstances();
    $store = resolve(StaticPageArtifactStore::class);
    $builtUnder = resolve(PublicReadEligibilityState::class)->freshStamp($siteId);

    return $builtUnder instanceof PublicReadStampData
        && PublishPublicArtefactAction::run($store, $siteId, $builtUnder, [], static function (PublicArtefactPermit $permit) use ($store, $file, $html, $siteId): bool {
            $store->putOwnedHtml($permit, $file, $html, $siteId);

            return true;
        });
}

function reconcile(int $limit = 100): int
{
    app()->forgetScopedInstances();

    return Artisan::call('capell:public-read:reconcile', ['--limit' => $limit]);
}

function reconcilerOwedRows(): int
{
    return DB::table(TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE))->count();
}

function reconcilerRevision(): ?string
{
    app()->forgetScopedInstances();

    return resolve(PublicReadEligibilityRevision::class)->current();
}

it('withdraws a copy built under old rules after an outage lost the change, with no owed row written', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = reconcilerExportRoot();
    reconcilerCdn(true);
    $rules = reconcilerRules();

    try {
        expect(reconcile())->toBe(0)
            ->and(reconcilerExport($domain->site_id, 'gated/index.html', 'Old rules'))->toBeTrue();
        $before = reconcilerRevision();

        // The rules change while the public-read tables are unavailable, and the
        // process stops before its withdrawal runs: nothing durable records the change.
        $state = TableName::resolve('public_read_eligibility_state');
        $owed = TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE);
        $rules->version = 2;
        Schema::rename($state, $state . '_unavailable');
        Schema::rename($owed, $owed . '_unavailable');
        Event::fake([PublicReadEligibilityChanged::class]);

        try {
            expect(RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id))->toBeNull();
        } finally {
            Schema::rename($state . '_unavailable', $state);
            Schema::rename($owed . '_unavailable', $owed);
        }

        // Recovery on a fresh process: the shared failure counter is gone too.
        Cache::flush();

        expect(reconcilerRevision())->toBe($before)
            ->and(is_file($root . '/gated/index.html'))->toBeTrue()
            ->and(reconcile())->toBe(0)
            ->and(is_file($root . '/gated/index.html'))->toBeFalse()
            ->and(reconcilerOwedRows())->toBe(0)
            ->and(reconcilerRevision())->not->toBe($before)->not->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

it('converges after a cache clear when the failed advance could not record its unknown token', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = reconcilerExportRoot();
    reconcilerCdn(true);
    $rules = reconcilerRules();

    try {
        expect(reconcile())->toBe(0);
        $before = reconcilerRevision();

        // Every write to the state row fails: the revision and the unknown token are both lost.
        $failing = new stdClass;
        $failing->on = true;
        DB::beforeExecuting(static function (string $query) use ($failing): void {
            $sql = mb_strtolower(ltrim($query));

            throw_if($failing->on && (str_starts_with($sql, 'update') || str_starts_with($sql, 'insert')) && str_contains($sql, 'public_read_eligibility_state'), RuntimeException::class, 'The state row cannot be written.');
        });

        $rules->version = 2;
        expect(RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id))->toBeNull();
        $failing->on = false;
        Cache::flush();

        // The old revision is trusted again, so a copy built under it can be written.
        expect(reconcilerRevision())->toBe($before)
            ->and(reconcilerExport($domain->site_id, 'stale/index.html', 'Built under the old revision'))->toBeTrue()
            ->and(reconcile())->toBe(0)
            ->and(is_file($root . '/stale/index.html'))->toBeFalse()
            ->and(reconcilerRevision())->not->toBe($before)->not->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

it('withdraws static exports while a CDN purge keeps failing, and retries only the CDN', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = reconcilerExportRoot();
    reconcilerCdn(true);
    $rules = reconcilerRules();

    try {
        expect(reconcile())->toBe(0)
            ->and(reconcilerExport($domain->site_id, 'gated/index.html', 'Old rules'))->toBeTrue();

        reconcilerCdn(false);
        $rules->version = 2;

        expect(reconcile())->toBe(1)
            ->and(is_file($root . '/gated/index.html'))->toBeFalse();

        $failedAt = DB::table(TableName::resolve('public_read_projection_watermarks'))
            ->where('site_id', $domain->site_id)
            ->pluck('failed_at', 'step');

        expect($failedAt->has(FrontendPublicReadWithdrawalSteps::CDN))->toBeTrue()
            ->and($failedAt->get(FrontendPublicReadWithdrawalSteps::CDN))->not->toBeNull()
            ->and($failedAt->has(FrontendPublicReadWithdrawalSteps::STATIC_EXPORTS))->toBeTrue()
            ->and($failedAt->get(FrontendPublicReadWithdrawalSteps::STATIC_EXPORTS))->toBeNull();

        // A copy built under the new rules survives the next run, which retries only the CDN.
        $this->travel(1)->minutes();
        expect(reconcilerExport($domain->site_id, 'current/index.html', 'New rules'))->toBeTrue()
            ->and(reconcile())->toBe(1)
            ->and(is_file($root . '/current/index.html'))->toBeTrue();

        reconcilerCdn(true);
        $this->travel(1)->minutes();

        expect(reconcile())->toBe(0)
            ->and(is_file($root . '/current/index.html'))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

it('takes the least recently attempted work first, so one failing store cannot starve the others', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    reconcilerCdn(false);
    reconcilerRules();

    // The first run attempts every store; only the CDN purge fails.
    expect(reconcile(limit: 100))->toBe(1);

    $attempted = static fn (): array => DB::table(TableName::resolve('public_read_projection_watermarks'))
        ->where('site_id', $domain->site_id)
        ->pluck('attempted_at', 'step')
        ->map(static fn (mixed $at): string => (string) $at)
        ->all();
    $first = $attempted();

    $this->travel(1)->minutes();
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class implements FingerprintsPublicReadRules, PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'reconciler-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function publicReadRulesFingerprint(): string
        {
            return 'version:changed';
        }
    });

    // Every store is now due; the CDN was attempted last, so it goes last.
    expect(reconcile(limit: 1))->toBe(0);
    $second = $attempted();

    expect($second[FrontendPublicReadWithdrawalSteps::CDN])->toBe($first[FrontendPublicReadWithdrawalSteps::CDN])
        ->and(collect($second)->filter(static fn (string $at, string $step): bool => $at !== $first[$step])->keys()->all())->toHaveCount(1);
});
