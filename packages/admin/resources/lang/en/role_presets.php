<?php

declare(strict_types=1);

return [
    'client_editor' => [
        'label' => 'Client editor',
        'description' => 'For the person who looks after the website day to day. They keep pages, images and menus up to date, but cannot change how the site is set up.',
        'can' => [
            'Add, edit and publish pages',
            'Move pages to the bin and bring them back',
            'Change the web address of a page',
            'Undo a page back to an earlier version',
            'Upload, replace and remove images and files',
            'Add and reorder menu links',
        ],
        'cannot' => [
            'Change the page layout or design',
            'Change site settings, domains or languages',
            'Invite, edit or remove users',
            'Install or switch off extensions',
        ],
    ],
    'can_heading' => 'They can',
    'cannot_heading' => 'They cannot',
];
