<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Every value can be overridden through the environment (see .env.example).
 */
return [
    'name' => env('APP_NAME', 'Abuse Report Desk'),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'debug' => env_bool('APP_DEBUG', false),
    'key' => env('APP_KEY', ''),

    'session_name' => env('SESSION_NAME', 'abuse_desk_session'),
    'session_lifetime' => env_int('SESSION_LIFETIME', 3600),

    /*
     * Passwordless admin login: a one-time code is mailed to the admin
     * address. No password and no username are ever stored.
     */
    'auth' => [
        'code_length' => 6,
        'code_ttl' => env_int('LOGIN_CODE_TTL', 600),
        'max_attempts' => env_int('LOGIN_CODE_MAX_ATTEMPTS', 5),
        'request_window' => env_int('LOGIN_CODE_WINDOW', 900),
        'request_max' => env_int('LOGIN_CODE_REQUEST_MAX', 5),
        'seed_admin_email' => env('ADMIN_EMAIL', 'tayyebimohammadreza@gmail.com'),
    ],

    'piggyback' => [
        // Seconds between implicit IMAP checks triggered by admin page loads.
        'imap_interval' => env_int('IMAP_CHECK_INTERVAL', 300),
        // Seconds an enrichment result is considered fresh.
        'enrichment_interval' => env_int('ENRICHMENT_INTERVAL', 900),
        // How many tickets get lazily enriched per dashboard load.
        'enrichment_batch' => env_int('ENRICHMENT_BATCH', 3),
        // Stop piggybacking IMAP when the request takes longer than this.
        'time_budget' => env_int('PIGGYBACK_TIME_BUDGET', 20),
    ],

    'uploads' => [
        'path' => storage_path('uploads'),
        'max_size' => env_int('UPLOAD_MAX_SIZE', 10485760),
        'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'eml', 'msg', 'log', 'csv', 'json', 'har', 'zip', 'html', 'htm'],
        'allowed_mimes' => [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf',
            'text/plain', 'message/rfc822', 'application/octet-stream', 'text/csv',
            'application/json', 'application/zip', 'text/html', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    'intake' => [
        // Anti-abuse: maximum web submissions per IP per hour (0 = unlimited).
        'rate_limit_per_hour' => env_int('SUBMIT_RATE_LIMIT_PER_HOUR', 10),
        'max_evidence_files' => env_int('MAX_EVIDENCE_FILES', 5),
    ],

    'reporter' => [
        'org' => env('REPORT_REPORTER_ORG', 'Abuse Report Desk'),
        'domain' => env('REPORT_REPORTER_DOMAIN', 'abuse-desk.local'),
        'email' => env('REPORT_REPORTER_EMAIL', 'abuse@abuse-desk.local'),
        'default_format' => env('REPORT_DEFAULT_FORMAT', 'arf'),
    ],

    'abuse_types' => [
        'phishing' => 'Phishing / credential theft',
        'malware' => 'Malware / malicious download',
        'spam' => 'Spam / unsolicited bulk mail',
        'fraud' => 'Fraud / scam',
        'botnet' => 'Botnet / C2 infrastructure',
        'other' => 'Other abuse',
    ],

    'statuses' => [
        'new' => 'New',
        'approved' => 'Approved',
        'reported' => 'Reported',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        'closed' => 'Closed',
    ],
];
