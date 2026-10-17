<?php

return [

    'verify_email' => [
        'subject' => 'Verify your email address',
        'intro' => 'Confirm this email address to finish setting up your SN3 account.',
        'action' => 'Verify email address',
        'expiry' => 'This link expires in :minutes minutes.',
        'outro' => 'If you did not create an account, you can ignore this email.',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'intro' => 'We received a request to reset the password for your SN3 account.',
        'action' => 'Reset password',
        'expiry' => 'This link expires in :minutes minutes.',
        'outro' => 'If you did not request a password reset, no action is needed and your password stays the same.',
    ],

    'account_exists' => [
        'subject' => 'Someone tried to sign up with your email',
        'intro' => 'Someone tried to create an SN3 account with this email address, but you already have an account.',
        'action' => 'Sign in',
        'reset_hint' => 'Forgot your password? Reset it at :url',
        'outro' => 'If this was not you, you can ignore this email. Your account has not changed.',
    ],

    'team_invitation' => [
        'subject' => 'You are invited to join :team on SN3',
        'intro' => ':inviter invited you to join the team ":team" on SN3 as :role.',
        'action' => 'View invitation',
        'expiry' => 'This invitation expires in :days days.',
        'verified' => 'To accept it, sign in or create an SN3 account with this email address and verify the address.',
        'outro' => 'If you did not expect this invitation, you can ignore this email.',
        'roles' => [
            'admin' => 'an Admin',
            'member' => 'a Member',
            'viewer' => 'a Viewer',
        ],
    ],

];
