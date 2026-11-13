<?php

declare(strict_types=1);

return [
    'action' => [
        'label' => 'Invite someone',
        'heading' => 'Invite someone to the admin',
        'description' => 'We email them a link to choose their own password. You never need to set or share one.',
        'submit' => 'Send invitation',
        'sent_title' => 'Invitation sent',
        'sent_body' => 'We emailed :email a link to set their password. The link works once and expires in 7 days.',
    ],
    'resend' => [
        'label' => 'Email a set-password link',
        'heading' => 'Email a set-password link',
        'description' => 'We email :email a new link to choose their password. Any earlier link stops working. Their current password keeps working until they choose a new one.',
        'submit' => 'Send link',
        'sent_title' => 'Link sent',
    ],
    'form' => [
        'name' => 'Name',
        'email' => 'Email address',
        'email_help' => 'The invitation goes here, and they sign in with it.',
        'preset' => 'What they can do',
    ],
    'validation' => [
        'email_taken' => 'Someone already uses this email address. To send them a new link, use "Email a set-password link" on their row in the list.',
    ],
    'mail' => [
        'subject' => 'You have been invited to edit :site',
        'greeting' => 'Hello,',
        'intro' => ':inviter has invited you to help look after :site as a :role.',
        'intro_without_role' => ':inviter has sent you a link to choose your password for :site.',
        'action' => 'Choose your password',
        'expiry' => 'This link works once and expires in :days days. If it has expired, ask the person who invited you to send a new one.',
        'ignore' => 'If you were not expecting this, you can ignore this email.',
    ],
    'accept' => [
        'title' => 'Accept your invitation',
        'heading' => 'Choose your password',
        'subheading' => 'You will sign in as :email.',
        'password' => 'Password',
        'password_help' => 'Choose a password you do not use on any other website.',
        'password_confirmation' => 'Type the password again',
        'submit' => 'Save password and sign in',
        'invalid_title' => 'This invitation link no longer works',
        'invalid_body' => 'It may have been used already or be more than 7 days old. Ask the person who invited you to send a new invitation.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
    ],
];
