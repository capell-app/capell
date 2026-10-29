<?php

declare(strict_types=1);

return [
    'site_spec_private_audit_required' => 'Private import :id predates the privacy repair. Review the original spec and current publication decisions, then reimport with --repair-private and --keep-published for deliberate publications.',
    'site_domain_claim_conflict' => 'This origin is already assigned to another site. Ask a global administrator to configure shared domains.',
    'component_directory_failed' => 'Could not create component directory [:path]. Check directory permissions and retry.',
    'component_publication_summary' => 'Components: :published published, :skipped skipped, :failed failed.',
    'component_publication_failed' => 'Component publication failed. Resolve the errors above and rerun this command.',
    'required_command_failed' => 'Required command [:command] failed with exit code :exit_code. Resolve its error and rerun this command.',
    'invalid_visit_destination' => 'invalid destination',
    'url_visit_failed' => 'URL visit failed for [:url]: :reason',
    'visit_requires_http' => 'Use an HTTP or HTTPS URL.',
    'visit_requires_registered_host' => 'Register and enable the destination site domain before retrying.',
    'visit_requires_public_address' => 'The destination must resolve to a public IP address. Check its DNS and network configuration.',
    'visit_http_failed' => 'HTTP :status received; expected HTTP 200. Fix the page response before retrying.',
    'composer_package_recovery_failed' => 'Composer files were restored after package removal failed, but the installed package graph could not be recovered. Composer output was withheld because it may contain credentials. Installed dependencies may not match composer.lock. Run "composer install --no-interaction --no-scripts" from the application root in a trusted terminal.',
    'composer_package_removal_failed' => 'Composer could not complete the package removal. Composer output was withheld because it may contain credentials. Run the removal from the application root in a trusted terminal, resolve the reported Composer error, then retry.',
    'redirect_auto_conflict' => 'An automatic redirect already exists for this source URL.',
    'redirect_chain_detected' => 'This target is already a redirect, which sends visitors on to :next_target. If that is where you want them to land, point this rule at it directly.',
    'redirect_duplicate_source' => 'A URL already exists for this source path.',
    'redirect_invalid_status_code' => 'Choose a supported redirect status code.',
    'redirect_loop_detected' => 'This redirect would create a loop.',
    'redirect_self_redirect' => 'The source and target URLs must be different.',
    'redirect_source_empty' => 'Enter a source URL.',
    'redirect_source_must_start_with_slash' => 'The source URL must start with a slash.',
    'redirect_target_empty' => 'Enter a target URL.',
    'redirect_target_invalid' => 'Enter a valid relative URL or full http/https URL.',
    'site_spec_import_complete' => 'Imported site ":name" (#:id) with :pages page(s).',
    'site_spec_import_invalid_json' => 'The SiteSpec contains invalid JSON: :reason',
    'site_spec_import_path_required' => 'Provide a SiteSpec JSON file path.',
    'site_spec_import_validation_failed' => 'The SiteSpec failed validation.',
];
