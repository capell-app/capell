<?php

declare(strict_types=1);

return [
    'login_page' => [
        'link' => 'Email me a sign-in link instead',
    ],
    'request' => [
        'title' => 'Get a sign-in link',
        'heading' => 'Sign in with a link',
        'subheading' => 'Enter the email address you use for the admin. We email you a link that signs you in without your password.',
        'email' => 'Email address',
        'submit' => 'Email me a link',
        'back_to_login' => 'Sign in with your password instead',
        'sent_title' => 'Check your email',
        'sent_body' => 'If that address belongs to someone who can use the admin, we have emailed them a sign-in link. It works once and expires in :minutes minutes. If you use two-factor authentication, sign in with your password and code instead.',
        'throttled' => 'Too many requests from this connection. Wait :seconds seconds, then try again.',
    ],
    'sign_in' => [
        'title' => 'Sign in with a link',
        'submit' => 'Sign in',
        'back_to_login' => 'Go to the sign-in page',
        'throttled' => 'Too many attempts. Wait :seconds seconds, then try again.',
        'ready' => [
            'heading' => 'Sign in to the admin',
            'body' => 'Press Sign in to continue. The link then stops working, so nobody else can use it.',
        ],
        'invalid' => [
            'heading' => 'This sign-in link no longer works',
            'body' => 'It may have been used already, replaced by a newer link, or be more than :minutes minutes old, and links stop working when the password changes. Ask for a new link or sign in with your password.',
        ],
        'no_panel_access' => [
            'heading' => 'This account cannot use the admin',
            'body' => 'Nothing has changed on your account. Ask an administrator to give you access, then ask for a new link.',
        ],
        'two_factor_enabled' => [
            'heading' => 'Sign in with your password and code',
            'body' => 'Your account uses two-factor authentication, so a link cannot sign you in on its own. Sign in with your password and the code from your authenticator.',
        ],
    ],
    'refusals' => [
        'no_panel_access' => 'This account cannot use the admin panel, so no sign-in link was created. Give them panel access first.',
        'two_factor_enabled' => 'This account uses two-factor authentication, so a sign-in link would bypass it. They must sign in with their password and code.',
    ],
    'mail' => [
        'subject' => 'Your sign-in link for :site',
        'intro' => 'Someone asked to sign in to the :site admin with this email address. Use the button below to sign in without your password.',
        'action' => 'Sign in',
        'expiry' => 'This link works once and expires in :minutes minutes. Asking for another link stops this one working.',
        'ignore' => 'If you did not ask for this, you can ignore this email: nobody can sign in without the link.',
    ],
    'command' => [
        'disabled' => 'Admin sign-in links are turned off. Set CAPELL_ADMIN_LOGIN_LINKS_ENABLED=true to use this command.',
        'not_found' => 'No user has the email address :email. Check the address, or create the user first.',
        'created' => 'Sign-in link for :email. It works once and expires in :minutes minutes. Treat it like a password.',
    ],
];
