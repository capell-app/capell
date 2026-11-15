<?php

declare(strict_types=1);

return [
    'title' => 'Server under load',
    'description' => 'A server has been busy for several minutes in a row. Pages still load, but scheduled work and the admin may slow down.',
    'node' => ':node is :status',
    'busiest' => 'Busiest processes:',
    'open_site_health' => 'Open Site Health to see the readings',
    'section' => 'Server load',
    'section_empty' => 'No server readings have been recorded yet. The scheduler records one every minute once it is running.',
    'section_detail' => 'Load :load across :cores cores, :tasks scheduled tasks starting :processes PHP processes in the last run.',
    'busiest_detail' => 'Busiest processes: :processes.',
    'slowest_detail' => 'Slowest scheduled tasks: :tasks.',
    'remediation' => 'Each scheduled command starts its own PHP process and boots the whole application. Lower how often busy tasks run, or run them inside the scheduler process, then check the readings again in ten minutes.',
];
