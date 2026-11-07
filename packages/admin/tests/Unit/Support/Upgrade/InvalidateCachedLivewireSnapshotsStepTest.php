<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Upgrade;

use Capell\Admin\Support\Upgrade\InvalidateCachedLivewireSnapshotsStep;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Upgrade\HtmlCacheClearCommand;
use Capell\Admin\Tests\Fixtures\Upgrade\HtmlCacheEdgePurgeVerifyCommand;
use Capell\Admin\Tests\Fixtures\Upgrade\HtmlCacheProcessStaleCommand;
use Capell\Admin\Tests\Fixtures\Upgrade\StoredPublicPages;
use Capell\Core\Actions\Upgrade\RunUpgradeStepAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Livewire snapshots in stored public HTML were rendered before snapshots recorded their rendering route,
 * so they are rejected; the upgrade regenerates every stored page and purges it from the edge, or fails
 * with the commands that finish the refresh.
 */
final class InvalidateCachedLivewireSnapshotsStepTest extends AdminTestCase
{
    private StoredPublicPages $pages;

    public function test_the_step_is_registered_with_the_upgrade_pipeline(): void
    {
        $steps = array_map(static fn (object $step): string => $step::class, iterator_to_array(app()->tagged('capell.upgrade-steps')));

        $this->assertContains(InvalidateCachedLivewireSnapshotsStep::class, $steps);
    }

    public function test_the_step_regenerates_every_stored_page_and_purges_it_from_the_edge(): void
    {
        $this->installHtmlCache();

        $this->assertTrue($this->step()->run($this->context()));

        $this->assertSame(['/' => 'regenerated', '/about' => 'regenerated', '/contact' => 'regenerated'], $this->pages->pages);
        $this->assertSame(['/', '/about', '/contact'], $this->pages->edgePurges);
    }

    public function test_the_step_succeeds_where_no_public_html_is_stored(): void
    {
        $this->assertTrue($this->step()->run($this->context()));
    }

    public function test_the_step_fails_with_the_output_and_the_commands_that_finish_the_refresh(): void
    {
        $this->installHtmlCache();
        $this->pages->failing = ['/about'];

        try {
            $this->step()->run($this->context());
            $this->fail('The upgrade step succeeded while a stored page was still stale.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('1 failed', $runtimeException->getMessage());
            $this->assertStringContainsString('php artisan capell:html-cache:process-stale', $runtimeException->getMessage());
            $this->assertStringContainsString('php artisan capell:html-cache:edge-purge:verify', $runtimeException->getMessage());
        }

        $this->assertSame('stale', $this->pages->pages['/about']);
    }

    /** The upgrade runner reports the step failed, with its output and the remaining commands, and records it. */
    public function test_the_upgrade_runner_reports_the_unfinished_refresh(): void
    {
        $this->installHtmlCache();
        $this->pages->failing = ['/contact'];

        $result = RunUpgradeStepAction::run($this->step(), $this->context());

        $this->assertSame(UpgradeStepStatus::Failed->value, $result->status);
        $this->assertStringContainsString('1 failed', (string) $result->output);
        $this->assertStringContainsString('php artisan capell:html-cache:process-stale', (string) $result->output);
    }

    public function test_the_step_does_not_regenerate_pages_the_edge_would_keep_serving(): void
    {
        $this->installHtmlCache();
        $this->pages->edgeReady = false;

        try {
            $this->step()->run($this->context());
            $this->fail('The upgrade step succeeded while the edge cache could not be purged.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('no API token configured', $runtimeException->getMessage());
            $this->assertStringContainsString('php artisan capell:html-cache:edge-purge:verify', $runtimeException->getMessage());
        }

        $this->assertSame(['/' => 'served', '/about' => 'served', '/contact' => 'served'], $this->pages->pages);
    }

    private function installHtmlCache(): void
    {
        $this->pages = new StoredPublicPages;
        $this->app->instance(StoredPublicPages::class, $this->pages);
        foreach ([HtmlCacheClearCommand::class, HtmlCacheProcessStaleCommand::class, HtmlCacheEdgePurgeVerifyCommand::class] as $command) {
            Artisan::registerCommand($this->app->make($command));
        }
    }

    private function step(): InvalidateCachedLivewireSnapshotsStep
    {
        return resolve(InvalidateCachedLivewireSnapshotsStep::class);
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []);
    }
}
