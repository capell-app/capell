<?php

declare(strict_types=1);

return [
    'untrusted_signature' => 'Backup manifest signature is missing, invalid, or signed by an untrusted key.',
    'signing_unavailable' => 'Backup signing requires the Sodium PHP extension.',
    'signing_key_invalid' => 'The backup signing key is not configured or is invalid.',
    'unsafe_source' => 'Backup media artifact has an unsafe source target.',
    'destinations_collide' => 'Backup media destinations collide.',
    'temporary_release_failed' => 'Unable to release a backup temporary file.',
];
