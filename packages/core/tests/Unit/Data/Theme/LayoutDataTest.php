<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\LayoutData;
use Capell\Core\Enums\ContainerSizeEnum;

it('hydrates layout from flat meta', function (): void {
    $data = LayoutData::from([
        'container' => 'lg',
        'secondary_containers' => ['sidebar', 'aside'],
    ]);

    expect($data->container)->toBe(ContainerSizeEnum::Lg)
        ->and($data->secondaryContainers)->toBe(['sidebar', 'aside']);
});

it('defaults container to null', function (): void {
    $data = LayoutData::from([]);

    expect($data->container)->toBeNull()
        ->and($data->secondaryContainers)->toBeArray()->toBeEmpty();
});
