<?php

declare(strict_types=1);

return [
    'error_pages' => [
        'description' => 'Diagnose why a static error page is or is not served, and which source supplies its copy.',

        'field' => 'Field',
        'value' => 'Value',

        'request_url' => 'Request URL',
        'scheme' => 'Scheme',
        'host' => 'Host',
        'path' => 'Path',
        'status' => 'Status',
        'store_bound' => 'StaticErrorPageStore bound',
        'manifest_path' => 'Manifest path',
        'manifest_exists' => 'Manifest exists',
        'entries_considered' => 'Manifest entries considered',
        'resolved' => 'Static page resolved',
        'reason' => 'Reason',
        'resolved_file' => 'Resolved file',
        'resolved_file_exists' => 'Resolved file exists',

        'candidates_heading' => 'Manifest entries',
        'candidate_index' => '#',
        'candidate_entry' => 'Entry (scheme/domain/status/path)',
        'candidate_outcome' => 'Outcome',
        'candidate_expected' => 'Expected',
        'candidate_actual' => 'Actual',
        'candidate_selected' => 'selected',
        'candidate_matched' => 'matched',
        'no_candidates' => 'The manifest contains no entries.',

        'copy_heading' => 'Copy precedence (:status on :host)',
        'copy_order' => '#',
        'copy_source' => 'Source',
        'copy_present' => 'Present',
        'copy_value' => 'Value',
        'copy_outcome' => 'Outcome',
        'copy_won' => 'WINS',
        'copy_field_heading' => 'Copy field: :field',
        'copy_winner' => 'Resolved :field',
        'copy_not_consulted' => 'not consulted at render time',

        'fallback_manifest_path' => 'Fallback manifest path',
        'fallback_manifest_exists' => 'Fallback manifest exists',
        'fallback_manifest_written_at' => 'Fallback manifest last written',
        'view_path' => 'Error view',

        'regeneration_warning' => 'Note: a public 404 records a not-found visit, whose model event regenerates the error pages before the response renders. The fallback manifest values above can therefore be replaced by a live 404 request while you are reading them.',

        'yes' => 'yes',
        'no' => 'no',
        'none' => 'none',
        'never' => 'never',
        'unknown' => 'unknown',
    ],
];
