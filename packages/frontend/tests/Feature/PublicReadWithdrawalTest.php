<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Exceptions\PublicReadWithdrawalFailedException;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\TableName;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\PublicRead\FrontendPublicReadWithdrawalSteps;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\Frontend\Tests\Fixtures\FragmentCacheTestStore;
use Capell\Frontend\Tests\Support\PublishesStaticArtefacts;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function withdrawalRestrictReads(): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'withdrawal-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }
    });
}

/** @return list<string> */
function withdrawalOwedSteps(): array
{
    return array_values(DB::table(TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE))->orderBy('step')->pluck('step')->map(static fn (mixed $step): string => (string) $step)->all());
}

function withdrawalFakeCdn(bool $available): void
{
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'test-token',
        'capell-frontend.cloudflare_zone_id' => 'test-zone',
    ]);
    // One fake for the whole test; later calls only change whether the CDN answers.
    app()->instance('capell.test.cdn-available', $available);

    if (! app()->bound('capell.test.cdn-faked')) {
        app()->instance('capell.test.cdn-faked', true);
        Http::fake(['api.cloudflare.com/*' => static fn () => resolve('capell.test.cdn-available') === true
            ? Http::response(['success' => true])
            : Http::response(['success' => false], 503)]);
    }
}

function withdrawalCdnPurges(): int
{
    return count(Http::recorded(static fn ($request): bool => str_ends_with((string) $request->url(), '/purge_cache')));
}

function withdrawalExportRoot(): string
{
    $root = sys_get_temp_dir() . '/capell-withdrawal-' . bin2hex(random_bytes(4));
    config(['capell-frontend.static_artifacts_path' => $root]);

    return $root;
}

it('still removes static exports and purges the CDN when the fragment cache store throws, keeping only that step owed', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = withdrawalExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $failing = new FragmentCacheTestStore;
    $failing->namespaceWriteFailure = 'throw';

    app()->instance(FragmentCache::class, new FragmentCache(new Repository($failing)));
    withdrawalFakeCdn(true);

    try {
        PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'gated/index.html', 'Old rules', $domain->site_id));
        withdrawalRestrictReads();

        expect(is_file($root . '/gated/index.html'))->toBeTrue()
            ->and(fn (): ?string => RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id))
            ->toThrow(PublicReadWithdrawalFailedException::class, FrontendPublicReadWithdrawalSteps::FRAGMENTS)
            ->and(is_file($root . '/gated/index.html'))->toBeFalse()
            ->and(withdrawalCdnPurges())->toBe(1)
            ->and(withdrawalOwedSteps())->toBe([FrontendPublicReadWithdrawalSteps::FRAGMENTS]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('keeps a CDN purge that throws owed until the reconcile command completes it', function (): void {
    withdrawalRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    withdrawalFakeCdn(false);

    expect(fn (): ?string => RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id))
        ->toThrow(PublicReadWithdrawalFailedException::class, FrontendPublicReadWithdrawalSteps::CDN)
        ->and(withdrawalOwedSteps())->toBe([FrontendPublicReadWithdrawalSteps::CDN])
        ->and(DB::table(TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE))->value('attempts'))->toBe(1);

    // Still unavailable: the step stays owed and the command reports failure.
    expect(Artisan::call('capell:public-read:reconcile'))->toBe(1);

    expect(withdrawalOwedSteps())->toBe([FrontendPublicReadWithdrawalSteps::CDN]);

    withdrawalFakeCdn(true);
    expect(Artisan::call('capell:public-read:reconcile'))->toBe(0);

    expect(withdrawalOwedSteps())->toBe([]);
});

it('records each step as owed before it runs and clears it once it succeeds', function (): void {
    withdrawalRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    $owedWhenPurging = null;
    config([
        'capell-frontend.cdn_provider' => 'cloudflare',
        'capell-frontend.cloudflare_purge_token' => 'test-token',
        'capell-frontend.cloudflare_zone_id' => 'test-zone',
    ]);
    Http::fake(['api.cloudflare.com/*' => static function () use (&$owedWhenPurging) {
        $owedWhenPurging = withdrawalOwedSteps();

        return Http::response(['success' => true]);
    }]);

    RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);

    // The CDN purge runs last: it still sees itself owed, and the earlier steps are cleared.
    expect($owedWhenPurging)->toBe([FrontendPublicReadWithdrawalSteps::CDN])
        ->and(withdrawalOwedSteps())->toBe([]);
});
