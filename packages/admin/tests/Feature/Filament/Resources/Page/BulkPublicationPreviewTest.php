<?php

declare(strict_types=1);

use Capell\Admin\Data\Publishing\BulkPublicationPreviewData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;

it('renders accurate changed unchanged and blocked preview totals', function (): void {
    $result = fn (PublicationTransitionOutcome $outcome): PublicationTransitionResultData => new PublicationTransitionResultData(
        outcome: $outcome,
        beforeState: PublishVisibilityStateEnum::draft,
        afterState: PublishVisibilityStateEnum::draft,
        visibleFrom: null,
        visibleUntil: null,
        reasonKey: 'publication.transition.' . $outcome->value,
    );
    $preview = new BulkPublicationPreviewData(
        records: [
            ['id' => 1, 'label' => 'Will change', 'result' => $result(PublicationTransitionOutcome::Changed)],
            ['id' => 2, 'label' => 'Already live', 'result' => $result(PublicationTransitionOutcome::AlreadyCorrect)],
            ['id' => 3, 'label' => 'No permission', 'result' => $result(PublicationTransitionOutcome::Unauthorized)],
        ],
        counts: [
            'changed' => 1,
            'already-correct' => 1,
            'unauthorized' => 1,
            'invalid-transition' => 0,
            'failed' => 0,
        ],
    );

    expect(view('capell-admin::filament.actions.bulk-publication-preview', compact('preview'))->render())
        ->toContain('Will change')
        ->toContain('Unchanged')
        ->toContain('Blocked')
        ->toContain('No permission');
});
