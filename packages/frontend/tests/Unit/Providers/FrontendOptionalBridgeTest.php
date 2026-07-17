<?php

declare(strict_types=1);

use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Tests\Fixtures\Autoload\FrontendBridgeForProviderTest;

afterEach(function (): void {
    FrontendBridgeForProviderTest::$invocations = 0;
});

it('boots extracted frontend package bridges as optional integrations', function (): void {
    FrontendBridgeForProviderTest::$invocations = 0;

    $method = new ReflectionMethod(FrontendServiceProvider::class, 'bootOptionalFrontendBridge');
    $provider = new FrontendServiceProvider(app());
    $method->invoke($provider, FrontendBridgeForProviderTest::class);
    $method->invoke($provider, 'Capell\\Missing\\UnknownFrontendBridge');

    expect(FrontendServiceProvider::OPTIONAL_FRONTEND_BRIDGES)->toBe([
        'Capell\\HtmlCache\\Support\\Bridges\\HtmlCacheFrontendBridge',
    ])->and(FrontendBridgeForProviderTest::$invocations)->toBe(1);
});
