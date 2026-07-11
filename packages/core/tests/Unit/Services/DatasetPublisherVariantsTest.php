<?php

declare(strict_types=1);

use Capell\Core\Support\Dataset\DatasetPublisher;

it('normalizes path variants', function (): void {
    $svc = new DatasetPublisher;

    expect($svc->normalizePath(null))->toBeNull();
    expect($svc->normalizePath(''))->toBeNull();
    expect($svc->normalizePath('0'))->toBeNull();
});

it('validates supported blueprints only', function (): void {
    $svc = new DatasetPublisher;

    expect($svc->validateType('migrations'))->toBeTrue();
    expect($svc->validateType('settings'))->toBeTrue();
    expect($svc->validateType('other'))->toBeFalse();
});
