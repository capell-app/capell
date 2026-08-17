<?php

declare(strict_types=1);

use Capell\Core\Support\Bootstrap\CloudInstallContext;

afterEach(function (): void {
    foreach (['CAPELL_INSTALL_MODE', 'CAPELL_INSTALL_PACKAGES'] as $key) {
        putenv($key);
        unset($_SERVER[$key]);
    }
});

it('resolves cloud package selection directly from the pre-bootstrap process environment', function (): void {
    putenv('CAPELL_INSTALL_MODE=cloud');
    putenv('CAPELL_INSTALL_PACKAGES= vendor/one, vendor/two, vendor/one ');

    $context = CloudInstallContext::fromProcess();

    expect($context->isCloudInstall())->toBeTrue()
        ->and($context->selects('vendor/one'))->toBeTrue()
        ->and($context->selects('vendor/two'))->toBeTrue()
        ->and($context->selects('vendor/three'))->toBeFalse();
});

it('does not enable cloud provider selection outside cloud provisioning', function (): void {
    putenv('CAPELL_INSTALL_MODE=local');
    putenv('CAPELL_INSTALL_PACKAGES=vendor/one');

    $context = CloudInstallContext::fromProcess();

    expect($context->isCloudInstall())->toBeFalse()
        ->and($context->selects('vendor/one'))->toBeTrue();
});
