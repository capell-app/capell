<?php

declare(strict_types=1);

return [
    'notice' => [
        'reassurance' => 'Your site keeps working while you sort this out. Local and staging sites never need a licence.',
        'missing_key' => [
            'heading' => 'Add your licence key',
            'body' => 'This site uses paid packages (:packages). Copy the licence key from your Capell account and set it as CAPELL_LICENCE_KEY.',
        ],
        'over_limit' => [
            'heading' => 'Your licence has no free site',
            'body' => 'All :limit sites on this licence are in use. Release a site you no longer use from your Capell account, or move to the unlimited licence.',
        ],
        'expired' => [
            'heading' => 'Your licence has ended',
            'body' => 'Renew it from your Capell account to keep updates and support for :packages.',
        ],
        'unlicensed' => [
            'heading' => 'Some packages need a licence',
            'body' => 'No licence key on this site covers :packages. Add the key from your Capell account as CAPELL_LICENCE_KEY.',
        ],
    ],
    'command' => [
        'no_paid_packages' => 'No paid packages are installed, so there is nothing to check and nothing was sent.',
        'state' => 'Licence check finished: :state.',
    ],
];
