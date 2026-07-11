<?php

declare(strict_types=1);

use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationWidthMode;
use Capell\Frontend\Actions\RenderWidgetRuntimeAttributesAction;

it('renders normalized custom width in public widget runtime styles', function (): void {
    $attributes = RenderWidgetRuntimeAttributesAction::run(new PresentationSettingsData(
        widthMode: PresentationWidthMode::Custom,
        alignment: PresentationAlignment::Center,
        customWidth: 'min(90vw, 72rem)',
    ));

    expect($attributes['style'])
        ->toContain('max-width: min(90vw, 72rem)')
        ->toContain('margin-left: auto')
        ->toContain('margin-right: auto');
});

it('drops unsafe direct custom width before rendering public widget runtime styles', function (): void {
    $attributes = RenderWidgetRuntimeAttributesAction::run(new PresentationSettingsData(
        widthMode: PresentationWidthMode::Custom,
        customWidth: '1px; background-image: url(https://example.test/track)',
    ));

    expect($attributes['style'])
        ->not->toContain('max-width:')
        ->not->toContain('background-image')
        ->not->toContain('url(');
});
