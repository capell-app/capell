<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicArtefactPublicationLock;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * The test seam: an array cache store whose locks report each acquisition and
 * whose reads can run a step of another process at an exact point, so
 * interleavings are deterministic without sleeps.
 */
function publicationSeamStore(): ArrayStore
{
    $store = new class extends ArrayStore
    {
        /** @var list<string> */
        public array $acquired = [];

        /** @var (Closure(string): void)|null */
        public ?Closure $beforeAcquire = null;

        /** @var (Closure(string): void)|null Runs after a read returns its value to the store, before the reader sees it. */
        public ?Closure $onGet = null;

        public function lock($name, $seconds = 0, $owner = null): ArrayLock
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function __construct(private readonly ArrayStore $seam, string $name, int $seconds, ?string $owner)
                {
                    parent::__construct($seam, $name, $seconds, $owner);
                }

                public function acquire(): bool
                {
                    $this->seam->beforeAcquire?->__invoke($this->name);
                    $acquired = parent::acquire();

                    if ($acquired) {
                        $this->seam->acquired[] = $this->name;
                    }

                    return $acquired;
                }
            };
        }

        public function get($key): mixed
        {
            // The step runs after the value is read, before the reader acts on it.
            $value = parent::get($key);
            $this->onGet?->__invoke((string) $key);

            return $value;
        }
    };

    Cache::extend('publication-seam', fn (): Repository => Cache::repository($store));
    config([
        'cache.stores.publication-seam' => ['driver' => 'publication-seam'],
        'cache.default' => 'publication-seam',
    ]);
    Cache::forgetDriver('publication-seam');

    return $store;
}

function publicationRestrictReads(): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'publication-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }
    });
}

function publicationExportRoot(): string
{
    $root = sys_get_temp_dir() . '/capell-publication-' . bin2hex(random_bytes(4));
    config(['capell-frontend.static_artifacts_path' => $root]);

    return $root;
}

it('takes the same lock to write a site artefact as to withdraw that site', function (): void {
    $seam = publicationSeamStore();
    publicationRestrictReads();
    $domain = SiteDomain::factory()->default()->create();

    PublishPublicArtefactAction::run($domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], static fn (PublicArtefactPermit $permit): bool => true);
    $writer = $seam->acquired;
    $seam->acquired = [];
    RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);

    $publication = static fn (array $names): array => array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, PublicArtefactPublicationLock::KEY_PREFIX)));

    expect($publication($writer))->toBe(PublicArtefactPublicationLock::keys([$domain->site_id]))
        ->and($publication($seam->acquired))->toBe(PublicArtefactPublicationLock::keys([$domain->site_id]));
});

it('refuses a write whose rules were withdrawn while it waited for the lock', function (): void {
    $seam = publicationSeamStore();
    publicationRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    $builtUnder = resolve(PublicReadEligibilityState::class)->stamp();
    $key = PublicArtefactPublicationLock::keys([$domain->site_id])[0];
    $stage = 'armed';
    $wrote = false;

    // Just before the writer takes its lock, a rule change and its withdrawal run to completion.
    $seam->beforeAcquire = static function (string $name) use (&$stage, $key, $domain): void {
        if ($name === $key && $stage === 'armed') {
            $stage = 'changing';
            RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);
            $stage = 'changed';
        }
    };

    $written = PublishPublicArtefactAction::run($domain->site_id, $builtUnder, [], static function (PublicArtefactPermit $permit) use (&$wrote): bool {
        $wrote = true;

        return true;
    });

    expect($stage)->toBe('changed')
        ->and($written)->toBeFalse()
        ->and($wrote)->toBeFalse();
});

it('makes a withdrawal wait while a write holds the lock, then purges what it wrote', function (): void {
    publicationSeamStore();
    publicationRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    $root = publicationExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $key = PublicArtefactPublicationLock::keys([$domain->site_id])[0];
    $withdrawalCouldStart = null;

    try {
        $written = PublishPublicArtefactAction::run($domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], function (PublicArtefactPermit $permit) use ($store, $domain, $key, &$withdrawalCouldStart): bool {
            $store->putOwnedHtml($permit, 'race/index.html', 'Built under the old rules', $domain->site_id);
            // A withdrawal starting now cannot take the lock until this write returns.
            $withdrawalCouldStart = Cache::lock($key, 1)->get();

            return true;
        });

        expect($written)->toBeTrue()
            ->and($withdrawalCouldStart)->toBeFalse()
            ->and(is_file($root . '/race/index.html'))->toBeTrue();

        RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);

        expect(is_file($root . '/race/index.html'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('never lets a permit write once its publication has returned', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = publicationExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $kept = new class
    {
        public ?PublicArtefactPermit $permit = null;
    };

    try {
        PublishPublicArtefactAction::run($domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], static function (PublicArtefactPermit $permit) use ($kept): bool {
            $kept->permit = $permit;

            return true;
        });
        $permit = expectPresent($kept->permit);

        expect(fn () => $store->putOwnedHtml($permit, 'late/index.html', 'Written outside the lock', $domain->site_id))
            ->toThrow(RuntimeException::class, 'PublishPublicArtefactAction')
            ->and(is_file($root . '/late/index.html'))->toBeFalse()
            // Nothing but the publication action can issue one.
            ->and(fn (): PublicArtefactPermit => new ReflectionClass(PublicArtefactPermit::class)->newInstance(static fn (): bool => true))
            ->toThrow(ReflectionException::class);
    } finally {
        File::deleteDirectory($root);
    }
});
