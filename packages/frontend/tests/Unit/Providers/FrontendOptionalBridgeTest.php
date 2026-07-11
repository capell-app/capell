<?php

declare(strict_types=1);

use Capell\Frontend\Providers\FrontendServiceProvider;

it('boots extracted frontend package bridges as optional integrations', function (): void {
    $reflection = new ReflectionClass(FrontendServiceProvider::class);
    $source = file_get_contents((string) $reflection->getFileName());

    expect($source)
        ->toContain('Capell\\\\HtmlCache\\\\Support\\\\Bridges\\\\HtmlCacheFrontendBridge')
        ->and($source)
        ->toContain("method_exists(\$bridgeClass, 'register')")
        ->and($source)
        ->toContain("call_user_func([\$bridgeClass, 'register'], \$this->app);");
});
