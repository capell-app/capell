<?php

declare(strict_types=1);

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Data\Publishing\PublishReadinessData;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Symfony\Component\Finder\Finder;

it('keeps advanced editorial collaboration implementations out of foundation', function (): void {
    $forbiddenClassNames = [
        'PageApprovedNotification',
        'PageRejectedNotification',
        'WorkspaceApproval',
        'WorkspaceReviewAssignment',
        'WorkspaceFieldComment',
        'ReleaseWorkspace',
    ];
    $violations = [];
    $files = (new Finder)
        ->files()
        ->in([
            dirname(__DIR__, 2) . '/packages/core/src',
            dirname(__DIR__, 2) . '/packages/admin/src',
        ])
        ->name('*.php');

    foreach ($files as $file) {
        foreach ($forbiddenClassNames as $className) {
            if (preg_match('/\b(?:class|trait|enum)\s+' . preg_quote($className, '/') . '\b/', $file->getContents()) === 1) {
                $violations[] = $file->getRealPath() . ' defines ' . $className;
            }
        }
    }

    expect($violations)->toBeEmpty();
});

it('keeps the foundation publication and workflow contribution contracts available', function (): void {
    expect(interface_exists(ContributesWorkflowAttention::class))->toBeTrue()
        ->and(class_exists(PublicationTransitionRequestData::class))->toBeTrue()
        ->and(class_exists(PublicationTransitionResultData::class))->toBeTrue()
        ->and(class_exists(PublishReadinessData::class))->toBeTrue()
        ->and(class_exists(BuildPublishReadinessAction::class))->toBeTrue();
});

it('documents the publishing studio ownership boundary', function (): void {
    $documentation = file_get_contents(dirname(__DIR__, 2) . '/docs/development/package-boundaries.md');

    expect($documentation)->toContain(
        'Publishing Studio owns the advanced collaboration implementation.',
        'records and decisions, reviewer assignments, release workspaces, field comments,',
        'must not replace the foundation state machine',
    );
});
