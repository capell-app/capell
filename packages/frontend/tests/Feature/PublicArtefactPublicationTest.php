<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicArtefactFence;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

/** A fence that runs a step of another process just before the store lock is taken. */
function publicationFenceWithStepBefore(PublicArtefactFence $store, Closure $step): PublicArtefactFence
{
    return new readonly class($store, $step) implements PublicArtefactFence
    {
        public function __construct(private PublicArtefactFence $store, private Closure $step) {}

        public function exclusive(Closure $callback): mixed
        {
            ($this->step)();

            return $this->store->exclusive($callback);
        }

        public function fenceIdentity(): string
        {
            return $this->store->fenceIdentity();
        }
    };
}

function publicationLockIsFree(StaticPageArtifactStore $store): bool
{
    $probe = fopen($store->ownershipPath() . '.lock', 'c+');
    $free = flock($probe, LOCK_EX | LOCK_NB);

    if ($free) {
        flock($probe, LOCK_UN);
    }

    fclose($probe);

    return $free;
}

it('holds the store lock a withdrawal takes while it checks and writes, and lets withdrawals run between writes', function (): void {
    publicationRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    $root = publicationExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $freeWhileWriting = null;

    try {
        $written = PublishPublicArtefactAction::run($store, $domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], static function (PublicArtefactPermit $permit) use ($store, $domain, &$freeWhileWriting): bool {
            $store->putOwnedHtml($permit, 'race/index.html', 'Built under the old rules', $domain->site_id);
            $freeWhileWriting = publicationLockIsFree($store);

            return true;
        });

        expect($written)->toBeTrue()
            ->and($freeWhileWriting)->toBeFalse()
            ->and(publicationLockIsFree($store))->toBeTrue()
            ->and(is_file($root . '/race/index.html'))->toBeTrue();

        // The withdrawal takes the same lock and removes what was written.
        RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);

        expect(is_file($root . '/race/index.html'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses a write whose rules were withdrawn while it waited for the store lock', function (): void {
    publicationRestrictReads();
    $domain = SiteDomain::factory()->default()->create();
    $root = publicationExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $builtUnder = resolve(PublicReadEligibilityState::class)->stamp();
    $stage = 'armed';
    $wrote = false;

    try {
        // Just before the writer takes the store lock, a rule change and its withdrawal run to completion.
        $fence = publicationFenceWithStepBefore($store, static function () use (&$stage, $domain): void {
            $stage = 'changing';
            RecordPublicReadEligibilityChangeAction::run('test', $domain->site_id);
            $stage = 'changed';
        });

        $written = PublishPublicArtefactAction::run($fence, $domain->site_id, $builtUnder, [], static function (PublicArtefactPermit $permit) use (&$wrote): bool {
            $wrote = true;

            return true;
        });

        expect($stage)->toBe('changed')
            ->and($written)->toBeFalse()
            ->and($wrote)->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('never lets a permit write once its publication has returned, or write a store whose lock it does not hold', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $root = publicationExportRoot();
    $store = resolve(StaticPageArtifactStore::class);
    $kept = new class
    {
        public ?PublicArtefactPermit $permit = null;
    };
    $otherStore = new readonly class implements PublicArtefactFence
    {
        public function exclusive(Closure $callback): mixed
        {
            return $callback();
        }

        public function fenceIdentity(): string
        {
            return 'another-store';
        }
    };

    try {
        PublishPublicArtefactAction::run($store, $domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], static function (PublicArtefactPermit $permit) use ($kept): bool {
            $kept->permit = $permit;

            return true;
        });
        $permit = expectPresent($kept->permit);

        expect(fn () => $store->putOwnedHtml($permit, 'late/index.html', 'Written outside the lock', $domain->site_id))
            ->toThrow(RuntimeException::class, 'PublishPublicArtefactAction')
            ->and(fn (): bool => PublishPublicArtefactAction::run($otherStore, $domain->site_id, resolve(PublicReadEligibilityState::class)->stamp(), [], static function (PublicArtefactPermit $permit) use ($store, $domain): bool {
                $store->putOwnedHtml($permit, 'elsewhere/index.html', 'Written under another lock', $domain->site_id);

                return true;
            }))->toThrow(RuntimeException::class, 'under the lock of the store being written')
            ->and(is_file($root . '/late/index.html'))->toBeFalse()
            ->and(is_file($root . '/elsewhere/index.html'))->toBeFalse()
            // Nothing but the publication action can issue one.
            ->and(fn (): PublicArtefactPermit => new ReflectionClass(PublicArtefactPermit::class)->newInstance(static fn (): bool => true, $store->fenceIdentity()))
            ->toThrow(ReflectionException::class);
    } finally {
        File::deleteDirectory($root);
    }
});

it('keeps a failure that lands while a successful write is clearing the unknown state', function (): void {
    $seam = publicationSeamStore();
    publicationRestrictReads();
    $first = new PublicReadEligibilityRevision;
    $race = new stdClass;
    $race->failing = true;
    $race->stage = 'armed';
    $race->holding = false;

    DB::beforeExecuting(static function (string $query) use ($race): void {
        throw_if(str_starts_with(mb_strtolower(ltrim($query)), 'update') && str_contains($query, 'public_read_eligibility_state') && $race->failing === true, RuntimeException::class, 'The revision write fails.');
    });

    expect($first->advance())->toBeNull();

    // A later advance succeeds; while it holds the revision lock to clear the
    // unknown state, another process's write fails without taking the lock.
    $race->failing = false;
    $seam->beforeAcquire = static function (string $name) use ($race): void {
        $race->holding = str_contains($name, 'revision-unknown-lock');
    };
    $seam->onGet = static function (string $key) use ($race): void {
        if ($race->stage !== 'armed' || $race->holding !== true) {
            return;
        }

        $race->stage = 'failing';
        $race->failing = true;
        (new PublicReadEligibilityRevision)->advance();
        $race->failing = false;
        $race->stage = 'done';
    };

    expect((new PublicReadEligibilityRevision)->advance())->toBeNull()
        ->and($race->stage)->toBe('done')
        ->and((new PublicReadEligibilityRevision)->isUnknown())->toBeTrue();
});
