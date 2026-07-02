<?php

/**
 * CLI-entrypoint voor de batchreminders-cache-prewarm.
 *
 * Draait ONAFHANKELIJK van job.send_reminder (zie cron-civicrm-batchreminders-prewarm.sh),
 * zodat de exec()-validatie (tot enkele minuten bij een cold cache) nooit meer de
 * MySQL-connectie van de mail-verzend-cron kan blokkeren. Zie batchreminders.php voor
 * de volledige uitleg en de _batchreminders_prewarm()-implementatie zelf.
 *
 * Aanroepen via:
 *   cv scr nl.onvergetelijk.batchreminders/bin/prewarm.php --user=civicrm.cron --cwd=/var/www/vhosts/ozkprod/web
 */

if (!function_exists('_batchreminders_prewarm')) {
	fwrite(STDERR, "FOUT: _batchreminders_prewarm() bestaat niet — is nl.onvergetelijk.batchreminders enabled?\n");
	exit(1);
}

$result = _batchreminders_prewarm();

printf(
	"batchreminders prewarm: %d ok, %d geblokkeerd, %d fouten, %d schedule-rijen gesynct.\n",
	count($result['ok_ids']),
	count($result['blocked_ids']),
	count($result['errors']),
	$result['synced']
);

exit(empty($result['errors']) ? 0 : 2);
