<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'sign_in' => 'Sign in',
    'sign_in_title' => 'Sign in to your account',
    'or_sign_in_with' => 'Or sign in with',
    'forgot_password' => 'Forgot your password?',
    'dont_have_account' => 'Don\'t have an account?',
    'already_have_account' => 'Already have an account?',

    'sign_up' => 'Sign up',
    'sign_up_title' => 'Create your account',

    'logout' => 'Logout',

    'input' => [
        'email' => 'Email',
        'email_label' => 'Email Address',
        'password' => 'Password',
    ],

    'register_throttle' => 'Too many registrations from this network. Please try again in :seconds seconds.',

    'register_taken' => [
        'subject' => 'Someone tried to register with your email',
        'body' => 'Someone tried to open an account at :app with this email address, which already has one. If it was you, log in at :login, or set a new password at :reset. If it was not you, you can ignore this message: nothing was changed.',
        'sent' => 'We have sent an email to :email with the next step. Please check your inbox.',
    ],

    'oauth' => [
        'unverified_google_account' => 'Your Google account\'s email is not verified: verify it at Google, or log in with your password.',
        'unverified_discord_account' => 'Your Discord account is not verified.',
        'unverified_account' => 'Confirm this account\'s email first: reset its password with the link we send you, then log in with your provider again.',
        'not_logged_in' => 'We could not log you in through this provider. If you have an account, log in with your password (or reset it); if not, register.',
        'account_not_registered' => 'You are not registered on this site.',
    ],

    'reset_password' => 'Reset password',

    'verify_2fa' => 'Verify 2FA',
    'use_recovery_code' => 'Lost your authenticator app? Use a recovery code',
    'recovery_code' => 'Recovery code',
    'recovery_code_invalid' => 'This recovery code is not valid, or it was used already.',
    'verify' => 'Verify',

    'verification' => [
        'notice' => 'Verify your email address',
        'sent' => 'A new verification link has been sent to your email address.',
        'check_your_email' => 'Before proceeding, please check your email for a verification link.',
        'not_received' => 'If you did not receive the email you can request another verification email.',
        'request_another' => 'Resend verification email',
    ],
    'captcha_invalid' => 'The CAPTCHA was invalid or has expired. Try again.',
    'remember_me' => 'Remember me',
];
