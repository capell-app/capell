<?php

declare(strict_types=1);

return [
    'untrusted_signature' => 'Backup manifest signature is missing, invalid, or signed by an untrusted key.',
    'signing_unavailable' => 'Backup signing requires the Sodium PHP extension.',
    'signing_key_invalid' => 'The backup signing key is not configured or is invalid.',
    'unsafe_source' => 'Backup media artifact has an unsafe source target.',
    'destinations_collide' => 'Backup media destinations collide.',
    'restore_media_failed' => 'Media restore stopped after :restored of :expected files: :reason',
    'restore_doctor_process_failed' => 'The doctor could not run against the scratch database (exit code :exit): :reason',
    'restore_doctor_invalid_json' => 'The doctor ran against the scratch database but did not return a valid JSON report (exit code :exit): :reason',
    'restore_doctor_checks_failed' => ':count doctor check(s) failed against the scratch database.',
    'restore_failed_check' => ':label (:id): :message',
    'restore_failed_check_remedy' => 'Remedy: :remedy',
    'restore_retained_targets' => 'The scratch targets were left in place for diagnosis. No live database or media was changed.',
    'restore_retained_database' => 'Scratch database',
    'restore_retained_media' => 'Scratch media',
    'restore_retained_media_value' => ':restored of :expected files written to disk ":disk", prefix ":prefix"',
    'restore_retry' => 'Inspect the scratch targets, then remove them manually. Retry with a new scratch database name and an empty media prefix; the restore refuses a non-empty prefix.',
    'temporary_release_failed' => 'Unable to release a backup temporary file.',
];
