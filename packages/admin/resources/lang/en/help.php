<?php

declare(strict_types=1);

return [
    'open' => 'Help for this screen',
    'glossary' => 'Words you may see',
    'documentation' => 'Read the documentation',
    'answers' => 'Common questions',
    'pages' => [
        'title' => 'Pages',
        'summary' => 'Find and organise your site’s pages. Changes to a page can affect what visitors see.',
        'next' => 'Choose a page to review its content.',
        'answers' => [
            'add_page' => [
                'question' => 'How do I add a page?',
                'answer' => 'Select New page, choose what kind of page it is, then give it a title. It stays a draft until you publish it.',
            ],
            'find_page' => [
                'question' => 'How do I find a page?',
                'answer' => 'Type part of its title in the search box. You can also filter the list by site, language or whether the page is published.',
            ],
            'move_page' => [
                'question' => 'How do I move a page under another page?',
                'answer' => 'Open the page, change its parent page and save. Its web address changes to match. Unless automatic redirects are turned off for your site, Capell adds a redirect from the old address, and you can check it under Redirects.',
            ],
            'remove_page' => [
                'question' => 'How do I remove a page?',
                'answer' => 'Open the page and move it to the bin. Visitors can no longer see it, and you can bring it back from Recently deleted.',
            ],
        ],
    ],
    'page_edit' => [
        'title' => 'Edit page',
        'summary' => 'Change this page’s content and settings. Published changes can affect visitors.',
        'next' => 'Review your changes and preview the page before publishing.',
        'answers' => [
            'change_content' => [
                'question' => 'How do I change what visitors see?',
                'answer' => 'Edit the content, then select Preview to check it. Visitors see your changes only after you publish.',
            ],
            'publish' => [
                'question' => 'How do I publish my changes?',
                'answer' => 'Select Publish now, or choose Schedule to publish at a set date and time.',
            ],
            'change_address' => [
                'question' => 'How do I change the page’s web address?',
                'answer' => 'Edit the page address under the title. Unless automatic redirects are turned off for your site, Capell adds a redirect from the old address so existing links still reach this page.',
            ],
            'restore_version' => [
                'question' => 'How do I get back an earlier version?',
                'answer' => 'Select Revisions at the top of the page, choose the version you want and restore it. Your current version is kept in the list too.',
            ],
        ],
    ],
    'media' => [
        'title' => 'Media',
        'summary' => 'Find images and files used on your site. Removing a file can affect pages that use it.',
        'next' => 'Choose a file to check its details and usage.',
        'answers' => [
            'upload' => [
                'question' => 'How do I add an image or file?',
                'answer' => 'Select Upload and choose the file. You can then use it on any page.',
            ],
            'describe_image' => [
                'question' => 'How do I describe an image for people who cannot see it?',
                'answer' => 'Open the image and fill in the alternative text with a short description of what it shows. Mark it decorative if it adds nothing to the page.',
            ],
            'find_usage' => [
                'question' => 'How do I see where a file is used?',
                'answer' => 'Open the file. The usage list shows every page and layout that uses it, so you can check before changing it.',
            ],
            'delete_used_file' => [
                'question' => 'Can I delete a file that pages use?',
                'answer' => 'Check the usage list first. If you remove a file that pages still use, those pages show a gap until you choose another file.',
            ],
        ],
    ],
    'sites' => [
        'title' => 'Sites',
        'summary' => 'Manage the sites available here. Site settings affect their pages and addresses.',
        'next' => 'Choose a site to review its settings.',
    ],
    'layouts' => [
        'title' => 'Layouts',
        'summary' => 'Manage the arrangements used to display pages. Changes can affect every page using a layout.',
        'next' => 'Choose a layout to review it.',
    ],
    'themes' => [
        'title' => 'Themes',
        'summary' => 'Manage the look of your sites. Theme changes can affect many pages.',
        'next' => 'Choose a theme to review its settings.',
    ],
    'blueprints' => [
        'title' => 'Blueprints',
        'summary' => 'Manage the templates that define content fields. Changes can affect the editing form.',
        'next' => 'Choose a blueprint to review its fields.',
    ],
    'users' => [
        'title' => 'Users',
        'summary' => 'Manage who can sign in. Changes to access affect what a person can do.',
        'next' => 'Choose a user to review their access.',
        'answers' => [
            'invite' => [
                'question' => 'How do I give someone access?',
                'answer' => 'Select Invite someone, enter their name and email address and choose what they can do. We email them a link to set their own password.',
            ],
            'invitation_missing' => [
                'question' => 'Their invitation has not arrived. What now?',
                'answer' => 'Ask them to check their spam folder, then use Send the invitation again. Their earlier link stops working.',
            ],
            'change_role' => [
                'question' => 'How do I change what someone can do?',
                'answer' => 'Select Edit user next to the person and change their role. The change applies the next time they open a screen.',
            ],
            'remove_access' => [
                'question' => 'How do I stop someone signing in?',
                'answer' => 'Select the person in the list and delete them. They can no longer sign in, and the pages they changed stay as they are.',
            ],
        ],
    ],
    'roles' => [
        'title' => 'Roles',
        'summary' => 'Manage groups of permissions. Changing a role affects people assigned to it.',
        'next' => 'Choose a role to review its permissions.',
    ],
    'settings' => [
        'title' => 'Settings',
        'summary' => 'Review settings for the admin and site. Some changes affect everyone using them.',
        'next' => 'Open a settings section and review it before saving.',
        'answers' => [
            'what_changes' => [
                'question' => 'What can I change here?',
                'answer' => 'Settings that apply to the whole admin or site, such as the site name, languages and email details.',
            ],
            'visitor_impact' => [
                'question' => 'Will a change affect visitors?',
                'answer' => 'Some settings change what visitors see straight away. Read the description under each setting before you save.',
            ],
            'undo_change' => [
                'question' => 'How do I undo a settings change?',
                'answer' => 'Set the value back to what it was and save. Make a note of the old value before you change anything important.',
            ],
            'hidden_settings' => [
                'question' => 'Why can I not see some settings?',
                'answer' => 'Your role decides which settings you can change. Ask the site owner if you need one that is hidden.',
            ],
        ],
    ],
    'extensions' => [
        'title' => 'Extensions',
        'summary' => 'Manage features added to Capell. Changing an extension can affect its features and data.',
        'next' => 'Review an extension and its details before making changes.',
    ],
    'redirects' => [
        'title' => 'Redirects',
        'summary' => 'Send old addresses to new ones. An incorrect destination can prevent visitors finding a page.',
        'next' => 'Review the old and new addresses before saving.',
    ],
    'sitemap' => [
        'title' => 'Sitemap',
        'summary' => 'Review the pages listed for search engines. The list helps them discover your site.',
        'next' => 'Check the site and its listed pages.',
    ],
    'site_health' => [
        'title' => 'Site health',
        'summary' => 'Review checks that may need attention. An unresolved issue can affect your site.',
        'next' => 'Open a check to see the next step.',
    ],
    'languages' => [
        'title' => 'Languages',
        'summary' => 'Manage languages available to your sites. Changes can affect translated content and addresses.',
        'next' => 'Choose a language to review its settings.',
    ],
    'activity' => [
        'title' => 'Activity',
        'summary' => 'Review recorded changes. This can help you understand what happened and when.',
        'next' => 'Find an activity entry to review its details.',
    ],
    'recently_deleted' => [
        'title' => 'Recently deleted',
        'summary' => 'Review items that were removed. Some items may be available to restore.',
        'next' => 'Choose an item and review the available actions.',
    ],
    'analytics' => [
        'title' => 'Analytics',
        'summary' => 'Review available information about your site’s visitors. Figures depend on the data available.',
        'next' => 'Choose the period or site you want to review.',
    ],
    'upgrade' => [
        'title' => 'Upgrade',
        'summary' => 'Review available upgrade information. Upgrades can change how your site works.',
        'next' => 'Read the guidance before starting an upgrade.',
    ],
    'dashboard' => [
        'title' => 'Dashboard',
        'summary' => 'See the work and site information available to you. Some items may need your attention.',
        'next' => 'Choose an item to review or continue your work.',
    ],
];
