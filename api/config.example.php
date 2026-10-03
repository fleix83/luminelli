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

    // ---------- Projektor (AI draft generator, see README) ----------
    'projektor' => [
        'enabled' => false,                  // set true once the API key and setup.php are done

        // Anthropic API key (console.anthropic.com → API Keys). Never commit it.
        'anthropic_api_key' => '',
        // Signing secret of the webhook endpoint (Console → Manage → Webhooks, starts with whsec_)
        'webhook_secret' => '',
        // Console workspace ID for the session trace links in notification mails ('default' = Default workspace)
        'anthropic_workspace' => 'default',

        'model'  => 'claude-sonnet-5-5',
        'effort' => 'high',                  // low | medium | high | xhigh | max

        // Hard cost cap per generation, in US cents (enforced by Anthropic): 300 = $3.00
        'budget_cents' => 300,
        // Generations that may start per calendar day (Europe/Zurich)
        'daily_global_cap' => 10,
        'per_ip_per_day' => 1,
        // Form submissions per IP and hour (incl. rejected ones)
        'submits_per_ip_per_hour' => 3,

        // Public base URL of the site (for links in mails), no trailing slash
        'site_url' => 'http://localhost/luminelli',
        // Where finished drafts are written, and the URL that serves that folder.
        // Production: the document root of entwurf.luminelli.ch (outside httpdocs!).
        'drafts_dir' => dirname(__DIR__, 2) . '/luminelli-entwurf',
        'drafts_url' => 'http://localhost/luminelli-entwurf',

        // Who gets the internal notifications (start, finished, failed)
        'notify_to' => 'service@luminelli.ch',

        // Housekeeping (cron.php)
        'max_runtime_minutes' => 45,         // finalize/abort sessions running longer than this
        'keep_drafts_days' => 60,
    ],
];
