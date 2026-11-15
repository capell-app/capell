<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\DescribeInstallFirstPageStatusAction;

it('describes every recorded first page status in words a person can act on', function (string $status): void {
    $description = DescribeInstallFirstPageStatusAction::run($status);

    expect($description)->not->toBe('')
        ->not->toBe($status)
        ->not->toStartWith('capell-core::');
})->with(['editable', 'present_unverified', 'draft', 'missing', 'unavailable']);

it('gives each status its own description', function (): void {
    $descriptions = array_map(
        static fn (string $status): string => DescribeInstallFirstPageStatusAction::run($status),
        ['editable', 'present_unverified', 'draft', 'missing', 'unavailable'],
    );

    expect(array_unique($descriptions))->toHaveCount(5);
});

it('treats an unrecognised status as one that could not be checked', function (): void {
    expect(DescribeInstallFirstPageStatusAction::run('something_else'))->toBe(DescribeInstallFirstPageStatusAction::run('unavailable'));
});

it('names the missing Admin panel instead of promising an edit link when none is installed', function (): void {
    expect(DescribeInstallFirstPageStatusAction::run('present_unverified', hasAdmin: false))
        ->not->toBe(DescribeInstallFirstPageStatusAction::run('present_unverified', hasAdmin: true))
        ->toContain('Admin');
});
