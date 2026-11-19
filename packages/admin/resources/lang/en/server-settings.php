<?php

declare(strict_types=1);

return [
    'section' => 'Recommended settings',
    'healthy' => 'No changes recommended. The measured PHP, opcache, queue and memory settings fit this server.',
    'healthy_label' => 'Server settings',
    'not_measured' => ':setting: not measured. PHP-FPM could not be read from this process, so Capell makes no recommendation for it.',
    'measured_detail' => 'Measured: :measured.',
    'change_validate_timestamps' => 'Set opcache.validate_timestamps=:recommended in php.ini, then reload PHP-FPM after every release so workers pick up new code. Checking file dates on every request costs time in production.',
    'change_opcache_memory' => 'Raise opcache.memory_consumption to :recommended (MB) in php.ini and reload PHP-FPM. A full cache evicts compiled scripts and recompiles them.',
    'change_opcache_interned_strings' => 'Raise opcache.interned_strings_buffer to :recommended (MB) in php.ini and reload PHP-FPM.',
    'change_opcache_max_files' => 'Raise opcache.max_accelerated_files to :recommended in php.ini and reload PHP-FPM. Scripts beyond the limit are not cached.',
    'change_fpm_max_children' => 'Lower pm.max_children from :measured to :recommended in the PHP-FPM pool file and reload PHP-FPM. More workers than the memory and cores support push the server into swap.',
    'change_swap_pressure' => 'Add memory or lower pm.max_children in the PHP-FPM pool file. The server used :measured of its swap this week, which makes every request slower.',
    'change_idle_queue_workers' => 'Run :recommended queue worker instead of :measured: no job waited on the queue in the last 7 days. Lower numprocs in the supervisor or systemd unit that starts queue:work.',
];
