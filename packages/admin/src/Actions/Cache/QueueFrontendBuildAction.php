<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Cache;

use Capell\Admin\Enums\FrontendBuildQueueResultEnum;
use Capell\Admin\Jobs\RunFrontendBuildJob;
use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static FrontendBuildQueueResultEnum run() */
final class QueueFrontendBuildAction
{
    use AsFake;
    use AsObject;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 3;

    public function __construct(
        private readonly MultiNodeTopologyGuard $topologyGuard,
    ) {}

    public function handle(): FrontendBuildQueueResultEnum
    {
        $this->topologyGuard->assertNodeLocalOperationIsAllowed('Frontend asset build dispatch');
        AssertQueueConnectionReadyAction::run();

        $lock = Cache::lock(RunFrontendBuildJob::STATUS_KEY . '.dispatch', self::LOCK_SECONDS);

        try {
            // Wait briefly rather than failing instantly: the lock is held only for
            // the few milliseconds it takes to read the status and dispatch, so a
            // collision is almost always transient.
            return $lock->block(self::LOCK_WAIT_SECONDS, function (): FrontendBuildQueueResultEnum {
                $status = data_get(Cache::get(RunFrontendBuildJob::STATUS_KEY), 'status');

                if (in_array($status, ['queued', 'running'], true)) {
                    return FrontendBuildQueueResultEnum::AlreadyRunning;
                }

                Cache::put(RunFrontendBuildJob::STATUS_KEY, [
                    'status' => 'queued',
                    'queued_at' => now()->toIso8601String(),
                ], RunFrontendBuildJob::STATUS_TTL_SECONDS);

                dispatch(new RunFrontendBuildJob);

                return FrontendBuildQueueResultEnum::Queued;
            });
        } catch (LockTimeoutException) {
            return FrontendBuildQueueResultEnum::Contended;
        }
    }
}
