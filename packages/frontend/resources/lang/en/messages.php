<?php

declare(strict_types=1);

return [
    'static_page_response_failed' => ':url: HTTP :status received; expected a successful HTML response.',
    'static_page_url_unmatched' => ':url: no eligible published page URL matched the explicit export request.',
    'static_generation_incomplete' => "Static generation failed. No new manifest was published. Fix these pages and retry:\n:failures",
    'static_artifact_write_failed' => 'Could not publish static artifact [:path]. Check directory permissions and available disk space, then retry.',
    'frontend_migration_publish_failed' => 'Frontend migration publishing failed.',
    'frontend_schema_migrations_failed' => 'Frontend schema migrations failed.',
    'frontend_settings_migrations_failed' => 'Frontend settings migrations failed.',
    'of' => 'of',
    'page' => 'Page',
    'pagination_info' => 'Showing :from to :to of :total results',
    'results_found' => 'results',
    'showing' => 'Showing',
];
