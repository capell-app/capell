<?php

declare(strict_types=1);

use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\CollapsibleTabsLivewire;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;

it('renders named tabs and both editable panels through a mounted schema', function (): void {
    $component = Livewire::test(CollapsibleTabsLivewire::class)->assertSuccessful();
    TestResponse::fromBaseResponse(new Response($component->html()))
        ->assertElementExists('[role="tablist"]', fn (AssertElement $tabs): BaseAssert => $tabs
            ->contains('[role="tab"]', ['text' => 'Content'])
            ->contains('[role="tab"]', ['text' => 'Metadata']))
        ->assertElementExists('body', fn (AssertElement $body): BaseAssert => $body
            ->contains('[role="tabpanel"]', [], 2)
            ->contains('input[id$=".title"]')
            ->contains('input[id$=".summary"]'));

    $component->set('data.summary', 'Updated summary')
        ->assertSet('data.title', 'Studio news')
        ->assertSet('data.summary', 'Updated summary');
});
