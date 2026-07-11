<?php

declare(strict_types=1);

use Capell\Core\Data\PageTypeData;
use Capell\Core\Exceptions\ExtensionRegistrationException;

it('builds actionable extension registration messages', function (): void {
    $previous = new RuntimeException('inner');

    expect(ExtensionRegistrationException::forPageType('App\\Pages\\Broken', $previous))
        ->getMessage()->toContain('App\\Pages\\Broken')
        ->getMessage()->toContain(PageTypeData::class)
        ->getMessage()->toContain('#page-types')
        ->getPrevious()->toBe($previous);

    expect(ExtensionRegistrationException::forSchema('App\\Schemas\\Broken')->getMessage())
        ->toContain('Capell\\Core\\Contracts\\SchemaInterface')
        ->toContain('#schemas');

    expect(ExtensionRegistrationException::forWidget('App\\Widgets\\Broken')->getMessage())
        ->toContain('Capell\\Core\\Contracts\\WidgetInterface')
        ->toContain('#widgets');
});
