<?php

declare(strict_types=1);

use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Frontend\Livewire\Page\Page;
use Capell\Frontend\Support\Components\FrontendComponentRegistrar;
use Illuminate\Support\Facades\Config;
use Livewire\Component;

it('builds one typed map of frontend livewire component definitions', function (): void {
    Config::set('capell-frontend.livewire_components', [
        'configured-component' => FrontendRegistrarTestComponent::class,
        10 => FrontendRegistrarTestComponent::class,
        'invalid-component' => null,
        'not-a-livewire-component' => stdClass::class,
    ]);

    $components = resolve(FrontendComponentRegistrar::class)->livewireComponents();

    expect($components)
        ->toBe([
            LivewirePageComponentEnum::Default->value => Page::class,
            'configured-component' => FrontendRegistrarTestComponent::class,
        ]);
});

final class FrontendRegistrarTestComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
