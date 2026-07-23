<?php

declare(strict_types=1);

use Capell\Admin\Jobs\RunFrontendBuildJob;
use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('capell.multi_node', false);
    Cache::forget(RunFrontendBuildJob::STATUS_KEY);
});

it('refuses an already queued node-local frontend build in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    RunNpmBuildAction::shouldNotRun();

    expect(function (): void {
        (new RunFrontendBuildJob)->handle(new MultiNodeTopologyGuard);
    })
        ->toThrow(RuntimeException::class, 'Frontend asset build cannot run while CAPELL_MULTI_NODE=true');

    expect(Cache::get(RunFrontendBuildJob::STATUS_KEY))->toBeNull();
});
