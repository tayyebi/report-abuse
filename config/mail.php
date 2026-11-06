<?php

declare(strict_types=1);

/**
 * Outgoing SMTP + inbound IMAP settings.
 */
return [
    'transport' => env('MAIL_TRANSPORT', 'smtp'), // smtp | log
    'from' => env('MAIL_FROM', 'abuse@abuse-desk.local'),
    'from_name' => env('MAIL_FROM_NAME', 'Abuse Report Desk'),
    'outbox_path' => storage_path('outbox'),

    'smtp' => [
        'host' => env('SMTP_HOST', ''),
        'port' => env_int('SMTP_PORT', 587),
        'encryption' => env('SMTP_ENCRYPTION', 'tls'), // none | tls | ssl
        'username' => env('SMTP_USER', ''),
        'password' => env('SMTP_PASSWORD', ''),
        'timeout' => env_int('SMTP_TIMEOUT', 15),
        'helo' => env('SMTP_HELO', ''),
    ],

    'imap' => [
        'host' => env('IMAP_HOST', ''),
        'port' => env_int('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'), // none | tls | ssl
        'username' => env('IMAP_USER', ''),
        'password' => env('IMAP_PASSWORD', ''),
        'timeout' => env_int('IMAP_TIMEOUT', 20),
        'intake_mailbox' => env('IMAP_INTAKE_MAILBOX', 'INBOX'),
        'response_mailbox' => env('IMAP_RESPONSE_MAILBOX', 'INBOX'),
        'batch_limit' => env_int('IMAP_BATCH_LIMIT', 25),
    ],
];
