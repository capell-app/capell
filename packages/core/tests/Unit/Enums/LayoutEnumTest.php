<?php

declare(strict_types=1);

use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\LayoutGroupEnum;

it('has default case with correct value', function (): void {
    expect(LayoutEnum::Default->value)->toBe('default');
});

it('has home case with correct value', function (): void {
    expect(LayoutEnum::Home->value)->toBe('home');
});

it('has results case with correct value', function (): void {
    expect(LayoutEnum::Results->value)->toBe('results');
});

it('returns label for default layout', function (): void {
    $label = LayoutEnum::Default->getLabel();
    expect($label)->toBeString();
    expect($label)->not()->toBeEmpty();
});

it('returns label for home layout', function (): void {
    $label = LayoutEnum::Home->getLabel();
    expect($label)->toBeString();
    expect($label)->not()->toBeEmpty();
});

it('returns label for results layout', function (): void {
    $label = LayoutEnum::Results->getLabel();
    expect($label)->toBeString();
    expect($label)->not()->toBeEmpty();
});

it('returns default group for default layout', function (): void {
    $group = LayoutEnum::Default->getGroup();
    expect($group)->toBe(LayoutGroupEnum::Default);
});

it('returns default group for home layout', function (): void {
    $group = LayoutEnum::Home->getGroup();
    expect($group)->toBe(LayoutGroupEnum::Default);
});

it('returns system group for results layout', function (): void {
    $group = LayoutEnum::Results->getGroup();
    expect($group)->toBe(LayoutGroupEnum::System);
});

it('all layouts have labels', function (): void {
    foreach (LayoutEnum::cases() as $layout) {
        expect($layout->getLabel())->not()->toBeEmpty();
    }
});

it('all layouts have groups', function (): void {
    foreach (LayoutEnum::cases() as $layout) {
        expect($layout->getGroup())->toBeInstanceOf(LayoutGroupEnum::class);
    }
});
