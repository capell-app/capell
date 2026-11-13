<?php

declare(strict_types=1);

/*
 * Plain words for the editor-facing admin. `term` is the word editors see,
 * `instead_of` is the technical word it replaces, and `meaning` explains it.
 * Editor screens (pages, media, menus, users) use `term`; developer screens
 * may keep `instead_of`.
 */
return [
    'page_address' => [
        'term' => 'Page address',
        'instead_of' => 'Slug',
        'meaning' => 'The last part of a page’s web address, such as “about-us”.',
    ],
    'page_type' => [
        'term' => 'Page type',
        'instead_of' => 'Blueprint',
        'meaning' => 'Decides which fields a page has and how it is laid out.',
    ],
    'language' => [
        'term' => 'Language',
        'instead_of' => 'Locale',
        'meaning' => 'The language and region a version of your content is written for.',
    ],
    'main_address' => [
        'term' => 'Main address',
        'instead_of' => 'Canonical',
        'meaning' => 'The address search engines should use when a page can be reached at more than one.',
    ],
    'unsaved_changes' => [
        'term' => 'Unsaved changes',
        'instead_of' => 'Recovery draft',
        'meaning' => 'Work Capell kept for you when the editor closed before you saved.',
    ],
    'blocks' => [
        'term' => 'Blocks',
        'instead_of' => 'Content blocks',
        'meaning' => 'Sections you stack to build a page, such as text, images and buttons.',
    ],
    'draft' => [
        'term' => 'Draft',
        'instead_of' => 'Unpublished revision',
        'meaning' => 'Changes only people in the admin can see until you publish them.',
    ],
];
