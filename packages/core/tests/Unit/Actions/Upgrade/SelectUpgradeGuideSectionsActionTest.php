<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\SelectUpgradeGuideSectionsAction;

it('selects and orders only guide sections in the upgrade range', function (): void {
    $guide = "# Guide\n\n## v1.2.0\n\n- Two\n\n## v1.0.0\n\n- Old\n\n## v1.1.0\n\n- One\n\n## v2.0.0\n\n- Future\n";

    expect(SelectUpgradeGuideSectionsAction::run('v1.0.0', '1.2.0', $guide))
        ->toBe(['1.1.0' => '- One', '1.2.0' => '- Two'])
        ->and(SelectUpgradeGuideSectionsAction::run('1.2.0', '1.2.0', $guide))->toBe([]);
});

it('does not guess a range when versions or guide are unavailable', function (?string $from, ?string $to, string $guide): void {
    expect(SelectUpgradeGuideSectionsAction::run($from, $to, $guide))->toBeNull();
})->with([
    [null, '1.1.0', '## v1.1.0' . "\n" . '- Step'],
    ['dev-main', '1.1.0', '## v1.1.0' . "\n" . '- Step'],
    ['1.2.0', '1.1.0', '## v1.1.0' . "\n" . '- Step'],
    ['1.0.0', '1.1.0', 'No version sections'],
]);

it('ships the documented baseline steps in the Core package', function (): void {
    expect(SelectUpgradeGuideSectionsAction::run('0.9.0', '1.0.0'))
        ->toHaveKey('1.0.0')
        ->and(SelectUpgradeGuideSectionsAction::run('0.9.0', '1.0.0')['1.0.0'])
        ->toContain('Back up the database, environment and storage');
});
