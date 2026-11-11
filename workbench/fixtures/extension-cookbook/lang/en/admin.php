<?php

declare(strict_types=1);

return [
    'navigation' => [
        'extension_cookbook' => 'Capell Extension Cookbook',
        'entries' => 'Reference entries',
        'group' => 'System',
    ],
    'settings' => [
        'title' => 'Capell Extension Cookbook',
        'enabled' => 'Enable extension cookbook',
    ],
    'stats' => [
        'enabled' => 'Enabled examples',
    ],
    'dashboard' => [
        'title' => 'Extension coverage',
        'description' => 'Review the contribution coverage supplied by this extension cookbook.',
        'registrar_title' => 'Registrar method coverage',
        'registrar_description' => 'Safe methods are demonstrated; dangerous global replacement seams remain deliberately inactive.',
    ],
    'schema' => [
        'help' => 'Reference entry fields are package-owned; this note is presentation-only.',
    ],
    'configurator' => [
        'entry' => 'Reference entry key',
    ],
    'fields' => [
        'title' => 'Title',
        'slug' => 'Key',
        'enabled' => 'Enabled',
    ],
    'widget' => [
        'label' => 'Capell Extension Cookbook widget',
        'title' => 'Title',
        'summary' => 'Summary',
    ],
    'actions' => [
        'open' => 'Open capell extension cookbook',
    ],
    'workflow' => [
        'label' => 'Capell Extension Cookbook needs an enabled example',
        'owner' => 'Capell Extension Cookbook',
        'action' => 'Open extension cookbook',
    ],
    'coverage' => [
        'groups' => [
            'core' => 'Core contributions',
            'admin' => 'Admin contributions',
            'frontend' => 'Frontend contributions',
        ],
        'statuses' => [
            'demonstrated' => 'Demonstrated',
            'inactive' => 'Deliberately inactive',
        ],
        'registrar' => [
            'groups' => [
                'safe' => 'Demonstrated safe registrar methods',
                'inactive' => 'Dangerous global replacement seams (deliberately inactive)',
            ],
            'methods' => [
                'page' => 'Registers a package-owned Filament page.',
                'resource' => 'Registers a package-owned Filament resource in its own group.',
                'extension_dashboard_widget' => 'Adds a bounded widget to the extensions dashboard.',
                'filament_dashboard_widget' => 'Adds a bounded overview stat to the main dashboard.',
                'resource_header_action_extender' => 'Adds an action only to the reference entries list page.',
                'schema_extender' => 'Adds a package-owned page schema note.',
                'configurator' => 'Registers a package-owned blueprint configurator.',
                'settings_schema' => 'Registers the package settings schema.',
                'extension_page' => 'Publishes the package page through the extension-page registry.',
                'dashboard_page' => 'Would replace the host dashboard globally.',
                'extension_removal_coordinator' => 'Would replace the host extension-removal coordinator globally.',
                'pending_theme_install_provider' => 'Would alter the host theme-install pipeline globally.',
            ],
        ],
        'types' => [
            'admin-page' => 'Expose a package-owned admin page.',
            'admin-resource' => 'Manage package-owned records in the admin panel.',
            'admin-action-extender' => 'Extend an existing admin resource page with a bounded action.',
            'section' => 'Provide a package-owned content section.',
            'page-type' => 'Register a package-owned page type.',
            'dashboard-widget' => 'Add a package-owned widget to an admin dashboard.',
            'overview-stat' => 'Add a package-owned summary statistic to an admin dashboard.',
            'schema-extender' => 'Extend a supported admin schema seam.',
            'configurator' => 'Add a package-owned configurator to a supported admin surface.',
            'model' => 'Register a package-owned model for Capell discovery.',
            'permission' => 'Declare permissions for package-owned admin behaviour.',
            'route' => 'Register a package-owned route.',
            'setting' => 'Register package-owned settings.',
            'page-variation' => 'Register a package-owned page variation.',
            'frontend-component' => 'Register a package-owned frontend component.',
            'content-widget' => 'Register a package-owned content widget.',
            'render-hook' => 'Contribute to a supported frontend render hook.',
            'asset' => 'Publish package-owned frontend assets.',
            'migration' => 'Declare package-owned database migrations.',
            'scheduled-job' => 'Register package-owned scheduled work.',
            'console-command' => 'Register a package-owned console command.',
            'agent-capability' => 'Declare a package-owned agent capability.',
            'content-graph' => 'Contribute package-owned content graph extraction.',
            'health-check' => 'Register a package-owned health check.',
            'workflow-attention' => 'Contribute a permission-aware workflow attention item.',
            'outbound-event' => 'Declare a package-owned outbound event.',
            'blueprint-subject' => 'Register a package-owned blueprint subject.',
        ],
    ],
];
