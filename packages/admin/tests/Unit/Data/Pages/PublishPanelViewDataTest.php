<?php

declare(strict_types=1);

use Capell\Admin\Data\Pages\PublishPanelViewData;
use Capell\Admin\Enums\PublishPanelStatusEnum;

it('defaults to publishable', function (): void {
    $view = new PublishPanelViewData(
        status: PublishPanelStatusEnum::draft,
        publishedAt: null,
        goesLiveAt: null,
        expiresAt: null,
        updatedAt: null,
        createdAt: null,
        editorName: null,
        creatorName: null,
    );

    expect($view->isPublishable())->toBeTrue();
});

it('reports not publishable when the flag is false', function (): void {
    $view = new PublishPanelViewData(
        status: PublishPanelStatusEnum::draft,
        publishedAt: null,
        goesLiveAt: null,
        expiresAt: null,
        updatedAt: null,
        createdAt: null,
        editorName: null,
        creatorName: null,
        enabled: true,
        publishable: false,
    );

    expect($view->isPublishable())->toBeFalse();
});
