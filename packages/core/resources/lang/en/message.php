<?php

declare(strict_types=1);

return [
    'composer_package_recovery_failed' => 'Composer files were restored after package removal failed, but the installed package graph could not be recovered. Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.',
    'composer_package_removal_failed' => 'Composer could not complete the package removal. Composer output was withheld because it may contain credentials. Run the removal from the application root in a trusted terminal, resolve the reported Composer error, then retry.',
    'redirect_auto_conflict' => 'An automatic redirect already exists for this source URL.',
    'redirect_chain_detected' => 'This redirect points to another redirect. Final target: :final_target',
    'redirect_duplicate_source' => 'A URL already exists for this source path.',
    'redirect_invalid_status_code' => 'Choose a supported redirect status code.',
    'redirect_loop_detected' => 'This redirect would create a loop.',
    'redirect_self_redirect' => 'The source and target URLs must be different.',
    'redirect_source_empty' => 'Enter a source URL.',
    'redirect_source_must_start_with_slash' => 'The source URL must start with a slash.',
    'redirect_target_empty' => 'Enter a target URL.',
    'redirect_target_invalid' => 'Enter a valid relative URL or full http/https URL.',
    'site_spec_import_complete' => 'Imported site ":name" (#:id) with :pages page(s).',
    'site_spec_import_path_required' => 'Provide a SiteSpec JSON file path.',
    'site_spec_import_validation_failed' => 'The SiteSpec failed validation.',
];
