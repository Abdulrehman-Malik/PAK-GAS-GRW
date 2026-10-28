<?php

/**
 * Welcome / sign-in screen copy (English). Single source of truth: the server
 * renders from these files and the browser receives the same catalogs, so the
 * language switch never needs a page reload.
 *
 * Rules: every key here must exist in every other language (enforced by tests);
 * `{name}` placeholders must match across languages; keys starting with `msg_`
 * are matched against server messages by their exact English text.
 */
return [
    'lang_label' => 'Language',

    // Hero
    'hero_1' => 'Your shop.',
    'hero_2' => 'Every cylinder',
    'hero_3' => 'counted.',

    // Sign-in card
    'signin_title'   => 'Sign in',
    'signin_sub'     => 'Use the username your shop admin gave you.',
    'label_login'    => 'Username or Email',
    'ph_login'       => 'e.g. admin',
    'label_password' => 'Password',
    'ph_password'    => 'Enter password',
    'show_password'  => 'Show password',
    'hide_password'  => 'Hide password',
    'remember'       => 'Keep me signed in',
    'submit'         => 'Sign in',
    'help'           => 'Need access? Ask your shop admin.',

    // Migration gate
    'gate_title'     => 'Database update required before login.',
    'gate_run'       => 'Execute Pending Migrations',
    'gate_busy'      => 'Executing...',
    'gate_progress'  => '{done} of {total} migrations completed.',
    'gate_order'     => 'The next migration is executed only after the previous one succeeds.',
    'th_seq'         => 'Seq',
    'th_migration'   => 'Migration',
    'th_status'      => 'Status',
    'th_result'      => 'Result',

    // Stage + footer
    'stage_label'  => 'Your shop',
    'cyl_alt'      => 'LPG cylinders',
    'chip_branch'  => 'Branch',
    'chip_system'  => 'System',
    'chip_ready'   => 'Ready',
    'chip_update'  => 'Update needed',
    'f_shop'       => 'Shop',
    'f_call'       => 'Call',
    'health_ok'    => 'Database connected · Up to date',
    'health_bad'   => 'Database update required',

    // Server messages, matched by exact English text (see UiLanguage::keyForMessage)
    'msg_invalid_login'          => 'Invalid username/email or password.',
    'msg_account_disabled'       => 'This account has been disabled. Contact an administrator.',
    'msg_logged_out'             => 'You have been logged out.',
    'msg_gate_default'           => 'Pending database migrations must be executed before users can sign in.',
    'msg_migrations_pending'     => 'Database migrations are pending. Execute them before signing in.',
    'msg_migration_check_failed' => 'Database migration status could not be checked.',
    'msg_login_required'         => 'The login field is required.',
    'msg_password_required'      => 'The password field is required.',
];
