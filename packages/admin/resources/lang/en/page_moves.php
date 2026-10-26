<?php

declare(strict_types=1);

return [
    'batch' => 'the selected batch',
    'failed_rollback' => 'The move could not be saved. All changes from this batch were rolled back. Refresh the selection and parent, then try again; contact an administrator if the problem continues.',
    'target_help' => 'Only parents eligible for at least one selected page are offered. Pages from other sites, recursive moves and pages you cannot update will be skipped. Select pages from one site to move them together.',
    'skipped' => ':page was skipped: :reason.',
    'moved' => ':page was moved under :parent.',
    'reasons' => [
        'unavailable' => 'the move is no longer available',
        'cross_sites' => 'the parent belongs to another site',
        'cycle' => 'the parent is this page or one of its descendants',
        'unauthorized' => 'you cannot update this page',
        'target_parent_denied' => 'you cannot update the chosen parent',
    ],
];
