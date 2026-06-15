<?php

// Voorkom directe toegang
if (!defined('CIVICRM_SYSTEM')) { return; }

/**
 * Implements hook_civicrm_alterMailParams().
 *
 * Deze hook wordt door CiviCRM aangeroepen vlak voordat een e-mail 
 * daadwerkelijk via SMTP de deur uit gaat.
 * We gebruiken dit moment om in te grijpen, te tellen, en extensief
 * te loggen per individuele e-mail (zowel toegestaan als geaborteerd).
 */
function batchreminders_civicrm_alterMailParams(&$params, $context) {

	static $sentCount		= 0;
	static $totalRemaining	= 0;

	$batchLimit				= 10;
	$extdebug 				= 'batchreminders'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

	// Bescherming: Limiteer alleen automatische achtergrondprocessen (cli/cron)
	if (php_sapi_name() !== 'cli') {
		return;
	}

	// 1. START VAN DE RUN (Tel het totaal in de database)
	if ($sentCount === 0) {
		$sql				= "SELECT count(id) FROM civicrm_action_log WHERE action_date_time IS NULL";
		$totalRemaining		= CRM_Core_DAO::singleValueQuery($sql);

		Civi::log()->info("batchreminders: Start batch-run. Doel: max {$batchLimit} van {$totalRemaining} wachtenden.");

		if (function_exists('wachthond')) {
			wachthond($extdebug, 2, "########################################################################");
			wachthond($extdebug, 1, "### SCHEDULED REMINDER BATCH [START] - DOEL: {$batchLimit} VAN {$totalRemaining} WACHTEND", "[REMINDER]");
			wachthond($extdebug, 2, "########################################################################");
		}
	}

	// 2. We verzamelen de nuttige info voor de log per e-mail
	$contactId				= $params['contact_id']	?? 'onbekend';
	$toEmail				= $params['toEmail']	?? 'onbekend';
	$subject				= $params['subject']	?? 'geen onderwerp';

	// 3. CHECK: Laten we deze door of breken we af?
	if ($sentCount >= $batchLimit) {
		$params['abort']	= TRUE;

		// Uitgebreide watchdog logging PER GEABORTEERDE EMAIL
		if (function_exists('wachthond')) {
			$params_abort	= [
				'status'		=> 'ABORTED (Doorgeschoven naar volgende cron-run)',
				'contact_id'	=> $contactId,
				'email'			=> $toEmail,
			];
			wachthond($extdebug, 7, "geaborteerd_{$toEmail}",			$params_abort);
		}
		
		return;
	}

	// 4. TOEGELATEN: We hogen de teller op
	$sentCount++;

	// Uitgebreide watchdog logging PER TOEGESTANE EMAIL
	if (function_exists('wachthond')) {
		$params_allow		= [
			'status'		=> 'ALLOWED (Wordt nu verzonden)',
			'contact_id'	=> $contactId,
			'email'			=> $toEmail,
			'subject'		=> $subject,
			'voortgang'		=> "{$sentCount} van de {$batchLimit} in deze batch verwerkt",
		];
		wachthond($extdebug, 4, "toegestaan_{$sentCount}",			$params_allow);
	}
	
	// Ook in de reguliere CiviCRM log per e-mail
	Civi::log()->debug("batchreminders: Verzonden ({$sentCount}/{$batchLimit}) | Contact ID: {$contactId} | Email: {$toEmail}");
}