<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support;

use Capell\Admin\Support\LivewireRouteComponentClass;
use Capell\Admin\Tests\AdminTestCase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\Livewire;

/** Route classification resolves single-file Livewire pages once per process, not once per boot. */
final class LivewireRouteComponentClassTest extends AdminTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/capell-route-component-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory . '/counter.blade.php', <<<'BLADE'
            <?php

            use Livewire\Component;

            new class extends Component {};
            ?>

            <div>Counter</div>
            BLADE);
        Livewire::addNamespace('route-component-probe', viewPath: $this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_rebooted_factory_reuses_the_declared_class_instead_of_declaring_another(): void
    {
        $first = LivewireRouteComponentClass::of('route-component-probe::counter');
        $declared = $this->declaredFromCompiledCounter();

        // A fresh application builds a fresh factory, whose own cache is empty.
        $this->app->forgetInstance('livewire.factory');
        $second = LivewireRouteComponentClass::of('route-component-probe::counter');

        $this->assertNotNull($first);
        $this->assertTrue(is_subclass_of($first, Component::class));
        $this->assertSame($first, $second);
        $this->assertSame(1, $declared);
        $this->assertSame(1, $this->declaredFromCompiledCounter());
    }

    public function test_an_edited_component_resolves_to_its_recompiled_class(): void
    {
        $first = LivewireRouteComponentClass::of('route-component-probe::counter');

        File::put($this->directory . '/counter.blade.php', <<<'BLADE'
            <?php

            use Livewire\Component;

            new class extends Component { public int $count = 1; };
            ?>

            <div>Counter {{ $count }}</div>
            BLADE);
        touch($this->directory . '/counter.blade.php', Date::now()->getTimestamp() + 10);
        $this->app->forgetInstance('livewire.factory');

        $second = LivewireRouteComponentClass::of('route-component-probe::counter');

        $this->assertNotSame($first, $second);
        $this->assertTrue(property_exists((string) $second, 'count'));
    }

    public function test_class_and_object_components_resolve_without_compiling(): void
    {
        $component = new class extends Component {};

        $this->assertSame($component::class, LivewireRouteComponentClass::of($component));
        $this->assertNull(LivewireRouteComponentClass::of(null));
    }

    /** Anonymous classes PHP has declared from the counter's compiled class file. */
    private function declaredFromCompiledCounter(): int
    {
        $compiled = resolve('livewire.compiler')->cacheManager->getClassPath($this->directory . '/counter.blade.php');

        return count(array_filter(
            get_declared_classes(),
            static fn (string $class): bool => str_contains($class, '@anonymous') && str_contains($class, (string) $compiled),
        ));
    }
}
