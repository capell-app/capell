<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Livewire\ShortCircuitingPersistentMiddleware;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeForbiddenResponseMiddleware;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Override;
use RuntimeException;

/** A Livewire update honours a returned middleware response when Livewire registers its mechanisms before the admin package. */
final class ShortCircuitingPersistentMiddlewareLivewireFirstTest extends AdminTestCase
{
    public function test_the_replay_ends_with_a_returned_refusal_in_this_provider_order(): void
    {
        $order = array_keys($this->app->getLoadedProviders());
        $this->assertLessThan(
            array_search(AdminServiceProvider::class, $order, true),
            array_search(LivewireServiceProvider::class, $order, true),
        );
        $this->assertInstanceOf(ShortCircuitingPersistentMiddleware::class, resolve(PersistentMiddleware::class));

        $this->actingAsAdmin();
        Livewire::component('runtime.replay-counter', RuntimeCounterComponent::class);
        $panel = Filament::getPanel('admin');
        Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
            ->group(static fn () => Route::get('/admin/runtime-order', static fn (): string => Livewire::mount('runtime.replay-counter')));
        $html = (string) $this->get('/admin/runtime-order')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);
        RuntimeCounterComponent::$increments = 0;

        $panel->middleware([RuntimeForbiddenResponseMiddleware::class], isPersistent: true);
        Livewire::addPersistentMiddleware([RuntimeForbiddenResponseMiddleware::class]);
        Livewire::flushState();

        $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]],
        ], ['X-Livewire' => 'true'])->assertForbidden()->assertSee('refused by panel middleware');
        $this->assertSame(0, RuntimeCounterComponent::$increments);
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [LivewireServiceProvider::class, ...array_values(array_filter(parent::getPackageProviders($app), static fn (string $provider): bool => $provider !== LivewireServiceProvider::class))];
    }
}
