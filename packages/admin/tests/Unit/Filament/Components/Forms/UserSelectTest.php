<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\UserSelect;

it('loads and searches users for admin select fields', function (): void {
    $matchingByName = test()->createUser(['name' => 'Ben Editor', 'email' => 'ben@example.test']);
    $matchingByEmail = test()->createUser(['name' => 'Project Owner', 'email' => 'owner@example.test']);
    $nonMatching = test()->createUser(['name' => 'Other User', 'email' => 'other@example.test']);

    $select = UserSelect::make();

    expect(UserSelect::getDefaultName())->toBe('user_id')
        ->and($select->getOptions())->toHaveKey($matchingByName->getKey(), 'Ben Editor')
        ->and($select->getSearchResults('Ben'))->toBe([$matchingByName->getKey() => 'Ben Editor'])
        ->and($select->getSearchResults('owner'))->toBe([$matchingByEmail->getKey() => 'Project Owner'])
        ->and($select->getSearchResults('Other'))->toBe([$nonMatching->getKey() => 'Other User']);
});

it('defaults to the authenticated user when requested', function (): void {
    $user = test()->createUser(['name' => 'Current Admin']);
    test()->actingAs($user);

    expect(UserSelect::make()->defaultAuth()->getDefaultState())
        ->toBe($user->getKey());
});
