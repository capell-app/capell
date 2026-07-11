<?php

declare(strict_types=1);

use Capell\Core\Support\Page\SignedUrlService;

it('generates and verifies signatures for page URLs', function (): void {
    $service = new SignedUrlService;

    $params = ['page' => 'home', 'rev' => '123'];
    $signed = $service->sign($params);

    expect($signed)->toHaveKey('signature');
    expect($service->verify($signed))->toBeTrue();

    $signed['rev'] = '999';
    expect($service->verify($signed))->toBeFalse();
});
