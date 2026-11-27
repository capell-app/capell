<?php

declare(strict_types=1);

use Capell\Core\Exceptions\NonInteractiveInputRequiredException;

it('lists every valid value so a script can retry without guessing', function (): void {
    $exception = new NonInteractiveInputRequiredException('Maker', 'Pass a maker argument.', ['core.action', 'core.data']);

    expect($exception->getMessage())->toBe('Maker is required in non-interactive mode. Pass a maker argument. Valid values: core.action, core.data.')
        ->and($exception->validValues)->toBe(['core.action', 'core.data']);
});

it('keeps the valid values when another missing input is added', function (): void {
    $combined = new NonInteractiveInputRequiredException('Maker', 'Pass a maker argument.', ['core.action'])
        ->withAlsoRequired('Pass --name=<name>.');

    expect($combined->getMessage())->toBe('Maker is required in non-interactive mode. Pass a maker argument. Also: Pass --name=<name>. Valid values: core.action.');
});

it('keeps the plain message when there is nothing to list', function (): void {
    expect(new NonInteractiveInputRequiredException('Site URL', 'Pass --url=<url>.')->getMessage())
        ->toBe('Site URL is required in non-interactive mode. Pass --url=<url>.');
});
