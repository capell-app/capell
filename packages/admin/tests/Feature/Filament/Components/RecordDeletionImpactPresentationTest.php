<?php

declare(strict_types=1);

use Capell\Admin\Data\RecordDeletionImpactData;

it('renders the authoritative unused label for records without known references', function (): void {
    $html = view('capell-admin::components.record-deletion-impact', [
        'impact' => new RecordDeletionImpactData(
            knownReferenceCount: 0,
            authoritative: true,
            noReferencesLabel: __('capell-admin::generic.deletion_impact_unused'),
        ),
    ])->render();

    expect($html)
        ->toContain('Unused')
        ->not->toContain('<a ');
});

it('renders the no tracked uses label without claiming a non-authoritative zero is unused', function (): void {
    $html = view('capell-admin::components.record-deletion-impact', [
        'impact' => new RecordDeletionImpactData(
            knownReferenceCount: 0,
            authoritative: false,
            noReferencesLabel: __('capell-admin::generic.deletion_impact_no_tracked_uses'),
        ),
    ])->render();

    expect($html)
        ->toContain('No tracked uses')
        ->not->toContain('Unused')
        ->not->toContain('<a ');
});

it('does not allow a non-authoritative zero to claim a record is unused', function (): void {
    $html = view('capell-admin::components.record-deletion-impact', [
        'impact' => new RecordDeletionImpactData(
            knownReferenceCount: 0,
            authoritative: false,
            noReferencesLabel: __('capell-admin::generic.deletion_impact_unused'),
        ),
    ])->render();

    expect($html)
        ->toContain('No tracked uses')
        ->not->toContain('Unused');
});

it('renders known references as an accessible link with supplementary review copy', function (): void {
    $html = view('capell-admin::components.record-deletion-impact', [
        'impact' => new RecordDeletionImpactData(
            knownReferenceCount: 3,
            authoritative: true,
            noReferencesLabel: __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: trans_choice('capell-admin::generic.deletion_impact_pages', 3, ['count' => 3]),
            referencesUrl: '/admin/pages?filters[layout_id][value]=7',
            reviewLabel: 'Review dependent pages before deletion.',
        ),
    ])->render();

    expect($html)
        ->toContain('3 known pages')
        ->toContain('Review dependent pages before deletion.')
        ->toContain('href="/admin/pages?filters[layout_id][value]=7"');
});

it('renders known references without a link when no references URL is available', function (): void {
    $html = view('capell-admin::components.record-deletion-impact', [
        'impact' => new RecordDeletionImpactData(
            knownReferenceCount: 1,
            authoritative: true,
            noReferencesLabel: __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: trans_choice('capell-admin::generic.deletion_impact_pages', 1, ['count' => 1]),
        ),
    ])->render();

    expect($html)
        ->toContain('1 known page')
        ->not->toContain('<a ');
});
