<?php
declare(strict_types=1);

/**
 * Optional (CLI only). Not needed: housekeeping runs automatically on
 * Projektor requests and finished sessions are delivered via webhook.php.
 * If a cron job becomes available, run this every 5–10 minutes:
 *   php /path/to/httpdocs/api/projektor/cron.php
 */

require __DIR__ . '/lib/bootstrap.php';
pj_require_cli();

pj_housekeeping(pj_config(), fn(string $msg) => print(pj_now()->format('Y-m-d H:i:s') . " $msg\n"));
