<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallSummaryLinesAction;
use Capell\Core\Data\Diagnostics\DoctorWarningSummaryData;
use Capell\Core\Data\Install\InstallHandoffData;

function summaryHandoff(array $overrides = []): InstallHandoffData
{
    return new InstallHandoffData(...[
        'schemaVersion' => 1,
        'status' => 'completed',
        'selectedPackages' => ['capell-app/admin', 'capell-app/core', 'capell-app/frontend'],
        'outcomes' => ['migrations' => 'completed', 'setup' => 'completed', 'doctor' => 'passed'],
        'urls' => ['admin' => 'https://summary.example.test/admin', 'public' => 'https://summary.example.test/'],
        'firstPage' => ['status' => 'editable'],
        'warnings' => [],
        'nextAction' => ['label' => 'Next', 'url' => 'https://docs.capell.app/'],
        'publicImpact' => ['summary' => 'Done.', 'accountConnection' => 'not_required', 'telemetrySubmission' => 'not_performed'],
        'tips' => [
            ['key' => 'sign_in', 'label' => 'Sign in', 'detail' => 'https://summary.example.test/admin as ben@example.test'],
            ['key' => 'first_page', 'label' => 'Your site', 'detail' => 'Edit your homepage under Admin → Pages.'],
        ],
        ...$overrides,
    ]);
}

it('shows what was installed, the site and admin addresses and the next steps', function (): void {
    $text = implode("\n", BuildInstallSummaryLinesAction::run(summaryHandoff()));

    expect($text)->toContain('capell-app/admin')
        ->toContain('capell-app/frontend')
        ->toContain('https://summary.example.test/')
        ->toContain('https://summary.example.test/admin')
        ->toContain((string) __('capell-core::install.handoff.title'))
        ->toContain('Sign in')
        ->toContain('Edit your homepage');
});

it('stays within sixty lines however many packages, warnings and tips an install produced', function (): void {
    $packages = array_map(static fn (int $index): string => sprintf('capell-app/extension-with-a-long-name-%03d', $index), range(1, 150));
    $warnings = array_map(static fn (int $index): string => sprintf('Warning %d: %s', $index, str_repeat('long text ', 80)), range(1, 40));
    $tips = array_map(static fn (int $index): array => ['key' => 'tip' . $index, 'label' => 'Tip ' . $index, 'detail' => str_repeat('detail ', 60)], range(1, 30));

    $lines = BuildInstallSummaryLinesAction::run(summaryHandoff([
        'selectedPackages' => $packages,
        'warnings' => $warnings,
        'tips' => $tips,
        'warningCount' => 40,
    ]), wroteJson: true);

    expect(count($lines))->toBeLessThanOrEqual(BuildInstallSummaryLinesAction::MAX_LINES);

    $text = implode("\n", $lines);

    expect($text)->toContain('https://summary.example.test/admin')
        ->toContain((string) __('capell-core::install.handoff.title'))
        ->toContain(DoctorWarningSummaryData::LIST_COMMAND)
        ->toContain((string) __('capell-core::install.handoff.summary.more_packages', ['count' => 150 - substr_count($text, 'extension-with-a-long-name-')]));
});

it('reports an unavailable admin address and an incomplete install without hiding the next step', function (): void {
    $text = implode("\n", BuildInstallSummaryLinesAction::run(summaryHandoff([
        'status' => 'incomplete',
        'urls' => ['admin' => null, 'public' => 'https://summary.example.test/'],
        'outcomes' => ['migrations' => 'completed', 'setup' => 'incomplete', 'doctor' => 'unknown'],
        'tips' => [['key' => 'review', 'label' => 'Review', 'detail' => 'Resolve the incomplete checks, then rerun capell:install.']],
    ])));

    expect($text)->toContain((string) __('capell-core::install.handoff.incomplete_title'))
        ->toContain((string) __('capell-core::install.handoff.summary.unavailable'))
        ->toContain('Resolve the incomplete checks');
});

it('keeps multi-line warnings and tips to one summary line each', function (): void {
    $lines = BuildInstallSummaryLinesAction::run(summaryHandoff([
        'warnings' => array_fill(0, 5, "First line\nsecond line\n\nthird line"),
        'tips' => array_fill(0, 8, ['key' => 'tip', 'label' => 'Tip', 'detail' => "Detail\nover\nlines"]),
    ]));

    expect(count(explode("\n", implode("\n", $lines))))->toBe(count($lines));
});
