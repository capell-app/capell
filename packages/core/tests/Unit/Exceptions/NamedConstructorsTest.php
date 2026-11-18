<?php

declare(strict_types=1);

use Capell\Core\Actions\GetComponentViewPathAction;
use Capell\Core\Exceptions\ComponentNotFoundException;
use Capell\Core\Exceptions\InvalidPageModelException;
use Capell\Core\Exceptions\LayoutNotFoundException;
use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Exceptions\ThemeNotFoundException;
use Capell\Core\Exceptions\UnsafeSvgUploadException;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Security\SvgUploadSanitizer;

it('tells the developer how to register a component that cannot be found', function (): void {
    expect(fn (): string => GetComponentViewPathAction::run('capell-missing-widget'))
        ->toThrow(ComponentNotFoundException::class, 'capell-missing-widget component not found.');

    try {
        GetComponentViewPathAction::run('capell-missing-widget');
    } catch (ComponentNotFoundException $componentNotFoundException) {
        expect($componentNotFoundException->getMessage())->toContain('ComponentRegistry::registerComponent()');
    }
});

it('names where a missing theme and layout are registered', function (): void {
    expect(ThemeNotFoundException::unresolvedForRequest()->getMessage())
        ->toStartWith('No theme could be resolved for the frontend request.')
        ->toContain('ThemeRegistry::register()')
        ->toContain('capell-frontend.foundation_theme')
        ->and(LayoutNotFoundException::unresolvedForRequest()->getMessage())
        ->toStartWith('No layout could be resolved for the frontend request.')
        ->toContain('LayoutViewRegistry');
});

it('keeps the messages callers and tests already match on', function (): void {
    expect(InvalidPageModelException::forModel('Nope')->getMessage())->toBe('Invalid page class: Nope')
        ->and(SiteDomainNotFoundException::noSitesConfigured()->getMessage())->toBe('No sites are configured.')
        ->and(UnsupportedDatabaseDriver::forDriver('sqlsrv')->getMessage())->toBe('Unsupported database driver [sqlsrv].')
        ->and(fn (): string => (new Site)->getSiteDomainUrl((new Language)->forceFill(['id' => 7])))
        ->toThrow(SiteDomainNotFoundException::class, 'Site domain not found for language: 7');
});

it('rejects an unsafe SVG with the reason it was refused', function (string $svg, string $message): void {
    expect(fn (): string => (new SvgUploadSanitizer)->sanitize($svg))
        ->toThrow(UnsafeSvgUploadException::class, $message);
})->with([
    'empty' => ['  ', 'The SVG upload is empty.'],
    'doctype' => ['<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>', 'SVG uploads may not contain a DOCTYPE or ENTITY declaration.'],
    'not svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>', 'The SVG upload must be an SVG document.'],
]);
