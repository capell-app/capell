<?php

declare(strict_types=1);

return [
    'client_editor' => [
        'label' => 'Client editor',
        'description' => 'For the person who looks after the website day to day. They keep pages, images and menus up to date, but cannot change how the site is set up.',
        'can' => [
            'publish_pages' => 'Add, edit and publish pages',
            'bin_pages' => 'Move pages to the bin and bring them back',
            'page_addresses' => 'Change the web address of a page',
            'roll_back' => 'Restore an earlier version of a page',
            'media' => 'Upload, replace and remove images and files',
            'menus' => 'Add menu links, rename them and change their order',
        ],
        'cannot' => [
            'design' => 'Change the page layout or design',
            'settings' => 'Change site settings, domains or languages',
            'users' => 'Invite, edit or remove users',
            'extensions' => 'Install or switch off extensions',
        ],
    ],
    'can_heading' => 'They can',
    'cannot_heading' => 'They cannot',
];
