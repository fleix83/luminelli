<?php
/**
 * Mail settings for api/inquiry.php.
 *
 * Copy this file to api/config.php and adjust it. config.php is git-ignored
 * and blocked from web access by .htaccess. Never commit real credentials.
 */
return [
    // DEV mode: mails are NOT sent but appended to api/storage/mail.log.
    // Keep true locally (XAMPP cannot send mail), set false on the server.
    'dev_mode' => true,

    // Recipient of all inquiries.
    'to' => 'service@luminelli.ch',

    // Sender (must belong to the domain for SPF/DKIM/DMARC to pass).
    'from'      => 'noreply@luminelli.ch',
    'from_name' => 'Studio Luminelli Website',

    // Transport: 'mail' uses PHP mail(), 'smtp' uses the built-in SMTP client.
    'transport' => 'smtp',

    'smtp' => [
        'host'       => 'mail.example.ch',   // TODO: SMTP host of the hosting provider
        'port'       => 587,                 // 587 = STARTTLS, 465 = implicit TLS
        'encryption' => 'tls',               // 'tls' (STARTTLS), 'ssl' (implicit) or '' (none, not recommended)
        'username'   => 'noreply@luminelli.ch',
        'password'   => '',                  // TODO: set on the server only
        'timeout'    => 15,
    ],

    // Spam protection
    'min_seconds'        => 3,    // reject submissions faster than this after the dialog opened
    'rate_limit_max'     => 5,    // max submissions ...
    'rate_limit_window'  => 3600, // ... per IP in this many seconds

    // Random string used to hash IP addresses for the rate limit (no raw IPs are stored).
    'ip_salt' => 'change-me-to-a-long-random-string',
];
