<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Upgrade;

use Capell\Admin\Support\Upgrade\InvalidateCachedLivewireSnapshotsStep;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Upgrade\HtmlCacheClearCommand;
use Capell\Core\Data\UpgradeContext;
use Illuminate\Support\Facades\Artisan;

/**
 * Livewire snapshots in stored public HTML were rendered before snapshots recorded their rendering
 * route, so they are rejected; the upgrade marks that HTML stale so pages are regenerated.
 */
final class InvalidateCachedLivewireSnapshotsStepTest extends AdminTestCase
{
    public function test_the_step_is_registered_with_the_upgrade_pipeline(): void
    {
        $steps = array_map(static fn (object $step): string => $step::class, iterator_to_array(app()->tagged('capell.upgrade-steps')));

        $this->assertContains(InvalidateCachedLivewireSnapshotsStep::class, $steps);
    }

    public function test_the_step_marks_stored_public_html_stale(): void
    {
        $command = new HtmlCacheClearCommand;
        Artisan::registerCommand($command);

        $this->assertTrue(resolve(InvalidateCachedLivewireSnapshotsStep::class)->run($this->context()));
        $this->assertSame(1, $command->runs);
    }

    public function test_the_step_succeeds_where_no_public_html_is_stored(): void
    {
        $this->assertTrue(resolve(InvalidateCachedLivewireSnapshotsStep::class)->run($this->context()));
    }

    public function test_the_step_fails_when_public_html_cannot_be_marked_stale(): void
    {
        Artisan::registerCommand(new HtmlCacheClearCommand(HtmlCacheClearCommand::FAILURE));

        $this->assertFalse(resolve(InvalidateCachedLivewireSnapshotsStep::class)->run($this->context()));
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []);
    }
}
