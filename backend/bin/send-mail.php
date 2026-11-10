<?php

declare(strict_types=1);

/**
 * Sends the email copies of members' messages still waiting, for every SACCO. They also go out
 * by themselves (after each request where the server allows, and on the bell's checks); this is
 * for a steady pace on a busy day.
 *
 *   php backend/bin/send-mail.php
 *
 * From cron (cPanel -> Cron Jobs), e.g. every 5 minutes:
 *   0-59/5 * * * * /usr/local/bin/php /home/USER/sacco/backend/bin/send-mail.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Support\Outbox;
use Sacco\Tenant;

foreach (Database::platform()->query('SELECT * FROM saccos ORDER BY id') as $sacco) {
    $sent = Tenant::actAs($sacco, fn (PDO $db) => Outbox::flush($db, 200));
    echo "SACCO {$sacco['code']}: $sent emails sent\n";
}
