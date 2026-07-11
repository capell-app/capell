<?php

declare(strict_types=1);

use Capell\Admin\Actions\BuildDefaultTranslationsAction;
use Capell\Core\Models\Site;

it('builds default translations map', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $result = BuildDefaultTranslationsAction::run($site->id);

    expect($result)->toBeArray()->toHaveCount(1);
});
