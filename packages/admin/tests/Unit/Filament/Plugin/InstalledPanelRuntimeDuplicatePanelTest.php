<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Filament\Facades\Filament;
use Filament\Panel;
use ReflectionProperty;

/** Two providers can claim one panel id, and only the registered panel's routes exist. */
final class InstalledPanelRuntimeDuplicatePanelTest extends AdminTestCase
{
    public function test_a_losing_duplicate_panel_does_not_replace_the_registered_panel(): void
    {
        $registered = Filament::getPanel(AdminPanelEntrypoint::PANEL);
        $runtime = resolve(InstalledPanelRuntime::class);
        $synchronised = new ReflectionProperty($runtime, 'synchronised');
        $this->assertSame($registered, $this->synchronisedPanel($synchronised, $runtime));

        $loser = Panel::make()->id($registered->getId())->path($registered->getPath());
        $runtime->extend($loser);

        $this->assertSame($registered, $this->synchronisedPanel($synchronised, $runtime));
    }

    private function synchronisedPanel(ReflectionProperty $synchronised, InstalledPanelRuntime $runtime): mixed
    {
        $panels = $synchronised->getValue($runtime);
        $this->assertIsArray($panels);

        return $panels[AdminPanelEntrypoint::PANEL] ?? null;
    }
}
