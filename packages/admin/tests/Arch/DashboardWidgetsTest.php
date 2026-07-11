<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDefaultContentHealthAction;
use Capell\Admin\Actions\Dashboard\BuildPublishingTrendAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;

arch('every admin dashboard action has a handle method')
    ->expect('Capell\Admin\Actions\Dashboard')
    ->classes()
    ->toHaveMethod('handle');

arch('all Dashboard namespace widgets implement CapellFilamentWidgetContract')
    ->expect('Capell\Admin\Filament\Widgets\Dashboard')
    ->toImplement(CapellFilamentWidgetContract::class);

arch('all Health namespace widgets implement CapellFilamentWidgetContract')
    ->expect('Capell\Admin\Filament\Widgets\Health')
    ->toImplement(CapellFilamentWidgetContract::class);

it('does not keep optional report widgets in core admin', function (): void {
    expect(class_exists(PublishingTrendChartFilamentWidget::class))->toBeFalse();
    expect(class_exists(ContentHealthFilamentWidget::class))->toBeFalse();
    expect(class_exists(BuildPublishingTrendAction::class))->toBeFalse();
    expect(class_exists(BuildDefaultContentHealthAction::class))->toBeFalse();
});
