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
    'trend_day' => 'Last 24 hours: load averaged :average and peaked at :peak of capacity, around :peak_at.',
    'trend_week' => 'Last 7 days: load averaged :average and peaked at :peak of capacity, around :peak_at. Busiest hours: :from to :to.',
    'trend_week_short' => 'Last 7 days: load averaged :average and peaked at :peak of capacity, around :peak_at.',
    'trend_none' => 'Trends appear here after the server has been recorded for a few hours.',
    'trend_busiest_tasks' => 'Longest scheduled tasks this week: :tasks.',
    'guidance_peak_critical' => 'Load went past what the cores can supply (:peak of capacity). Move heavy scheduled tasks out of the busiest hours or add cores.',
    'guidance_peak_warning' => 'Load came close to capacity (:peak) at its peak. Keep new scheduled tasks out of the busiest hours.',
    'guidance_memory' => 'Available memory fell to :percent% this week. Lower the number of PHP workers or add memory before the server starts using swap.',
    'guidance_swap' => 'The server used up to :percent% of its swap this week. Swap is slow: add memory or lower the number of PHP workers.',
    'guidance_queue' => 'Up to :jobs jobs were waiting on the queue. Add a queue worker if jobs wait this long every day.',
    'guidance_room' => 'Load stayed below :ceiling of capacity this week, so there is room for more scheduled work.',
    'remediation' => 'Each scheduled command starts its own PHP process and boots the whole application. Lower how often busy tasks run, or run them inside the scheduler process, then check the readings again in ten minutes.',
];
