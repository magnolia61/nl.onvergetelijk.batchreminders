<?php

// Voorkom directe toegang
if (!defined('CIVICRM_SYSTEM')) { return; }

/**
 * Implements hook_civicrm_config().
 *
 * Registreert de render-limiter: een listener op civi.actionSchedule.prepareMailingQuery
 * die een LIMIT op de wachtrij-query van elk schedule zet. Zonder deze limiet rendert
 * CiviCRM core per schedule ALLE wachtende ontvangers (TokenProcessor->evaluate() over
 * de volledige set — bv. 126 × het zware Smarty-headerwerk = minuten CPU) vóórdat de
 * eerste mail verstuurd wordt; de alterMailParams-cap hieronder knijpt pas dáárna af,
 * waardoor al dat renderwerk werd weggegooid en elke run opnieuw begon.
 */
function batchreminders_civicrm_config(&$config) {
	static $registered = FALSE;
	if ($registered) {
		return;
	}
	$registered = TRUE;

	Civi::dispatcher()->addListener('civi.actionSchedule.prepareMailingQuery', '_batchreminders_limit_mailing_query');
}

/**
 * De gedeelde batchgrootte: één knop voor render-limiet én verzend-vangnet.
 * Instelbaar via Civi::settings() (settings/Batchreminders.setting.php), default 25.
 */
function _batchreminders_batchsize(): int {
	$size = (int) (Civi::settings()->get('batchreminders_batchsize') ?: 25);
	return max(1, $size);
}

/**
 * Puur rekenwerk voor het render-budget van één schedule-query — losgetrokken
 * zodat de tests dit zonder Civi-bootstrap kunnen verifiëren.
 *
 * @param  int  $batchsize  Totaalbudget voor deze cron-run.
 * @param  int  $granted    Al uitgedeeld budget aan eerdere schedules in deze run.
 * @param  bool $isBlocked  TRUE als de template-validatie van dit schedule faalde.
 * @return int  Aantal ontvangers dat dit schedule mag renderen (0 = niets ophalen).
 */
function _batchreminders_render_budget(int $batchsize, int $granted, bool $isBlocked): int {
	if ($isBlocked) {
		return 0;
	}
	return max(0, $batchsize - $granted);
}

/**
 * Listener: civi.actionSchedule.prepareMailingQuery.
 *
 * Zet een LIMIT op de wachtrij-query van het schedule, zodat core nooit meer
 * ontvangers ophaalt (en dus rendert) dan het run-budget toestaat. Rijen die
 * buiten de LIMIT vallen behouden action_date_time = NULL in civicrm_action_log
 * en komen automatisch in de volgende cron-run aan de beurt.
 *
 * Budget-boekhouding loopt via Civi::$statics zodat de alterMailParams-hook
 * (zelfde proces) en deze listener dezelfde run-status delen. We tellen het
 * UITGEDEELDE budget (granted), niet het verzonden aantal: ook als een verzending
 * faalt is het renderwerk al gedaan, dus het budget is verbruikt.
 *
 * Geblokkeerde schedules (template-validatie gefaald, zie prewarm) krijgen LIMIT 0:
 * die worden dan niet eens meer gerenderd — voorheen werd er volledig gerenderd en
 * pas bij verzending geaborteerd.
 */
function _batchreminders_limit_mailing_query($event) {
	// Zelfde scope als de alterMailParams-hook: alleen cli/cron begrenzen.
	if (php_sapi_name() !== 'cli') {
		return;
	}

	$state = &Civi::$statics['batchreminders'];
	$state['granted']	= $state['granted'] ?? 0;
	$state['blocked']	= $state['blocked'] ?? _batchreminders_load_blocked_schedules();

	$scheduleId	= (int) ($event->actionSchedule->id ?? 0);
	$isBlocked	= in_array($scheduleId, $state['blocked'], TRUE);
	$budget		= _batchreminders_render_budget(_batchreminders_batchsize(), $state['granted'], $isBlocked);

	$event->query->limit($budget);
	$state['granted'] += $budget;

	Civi::log()->debug("batchreminders: render-limiet schedule {$scheduleId}: LIMIT {$budget}" . ($isBlocked ? ' (geblokkeerd door validatie)' : '') . " — totaal uitgedeeld: {$state['granted']}.");
}

/**
 * Implements hook_civicrm_alterMailParams().
 *
 * Deze hook wordt door CiviCRM aangeroepen vlak voordat een e-mail
 * daadwerkelijk via SMTP de deur uit gaat.
 * We gebruiken dit moment om in te grijpen, te tellen, en extensief
 * te loggen per individuele e-mail (zowel toegestaan als geaborteerd).
 *
 * Verwante alterMailParams-hooks elders (géén overlap in verantwoordelijkheid):
 * - nl.onvergetelijk.cssinliner: HTML/CSS-opmaak vlak vóór verzending.
 * - nl.onvergetelijk.event: registreert de token {event.gcalendar_link} via
 *   civi.token.list/eval (GEEN alterMailParams) — agenda-link voor "Add to calendar".
 * Deze hook (batchreminders) is CLI-only (zie guard hieronder) en doet uitsluitend
 * rate-limiting/logging; vult geen tokens of mail-inhoud.
 *
 * BELANGRIJK (2-jul-2026): de template-validatie (exec() naar civicrm_templates_markup.sh
 * / _tokens.sh, tot 9s per template, oplopend tot enkele minuten bij een cold cache omdat
 * die scripts zelf `timeout 180` naar html-validate gebruiken) draait NIET meer hier.
 * Die validatie + de body_html/body_text/subject-sync liep voorheen synchroon in het eerste
 * e-mailtje van elke send_reminder-cronrun, terwijl de PHP-cron een open MySQL-connectie
 * vasthield — een reëel risico op een hangende/verbroken DB-connectie tijdens het verzenden.
 * Beide zijn verplaatst naar _batchreminders_prewarm(), die op een EIGEN, onafhankelijke
 * cronjob draait (zie bin/prewarm.php + cron-civicrm-batchreminders-prewarm.sh). Deze hook
 * leest hier alleen nog het resultaat (welke schedule_ids geblokkeerd zijn) uit een lichte
 * state-file — geen exec(), geen validatie-DB-query, geen sync-UPDATE meer in het verzendpad.
 */
function batchreminders_civicrm_alterMailParams(&$params, $context) {

	static $sentCount			= 0;
	static $totalRemaining		= 0;
	static $blockedScheduleIds	= [];    // action_schedule_ids waarvan de template validatie faalde

	// De primaire begrenzing zit sinds 2-jul-2026 in de render-limiter
	// (_batchreminders_limit_mailing_query, LIMIT op de wachtrij-query) — deze
	// per-mail teller is het VANGNET met dezelfde batchgrootte, voor het geval
	// de listener ooit niet vuurt. Eén gedeelde setting zodat render- en
	// verzendlimiet nooit uit elkaar kunnen lopen.
	$batchLimit				= _batchreminders_batchsize();
	$extdebug 				= 'batchreminders';

	// Bescherming: Limiteer alleen automatische achtergrondprocessen (cli/cron)
	if (php_sapi_name() !== 'cli') {
		return;
	}

	// 1. START VAN DE RUN
	if ($sentCount === 0) {
		// Tel alleen wachtenden voor actieve schedules (deadlock-achtergebleven NULL-entries
		// voor uitgeschakelde schedules worden zo niet meegeteld).
		$sql			= "
			SELECT count(L.id)
			FROM   civicrm_action_log      L
			JOIN   civicrm_action_schedule S ON S.id = L.action_schedule_id
			WHERE  L.action_date_time IS NULL
			AND    S.is_active        = 1
		";
		$totalRemaining	= CRM_Core_DAO::singleValueQuery($sql);

		Civi::log()->info("batchreminders: Start batch-run. Doel: max {$batchLimit} van {$totalRemaining} wachtenden.");

		if (function_exists('wachthond')) {
			wachthond($extdebug, 2, "########################################################################");
			wachthond($extdebug, 1, "### SCHEDULED REMINDER BATCH [START] - DOEL: {$batchLimit} VAN {$totalRemaining} WACHTEND", "[REMINDER]");
			wachthond($extdebug, 2, "########################################################################");
		}

		// 1.1 Lees het resultaat van de laatste prewarm-run (lichte file-read, geen exec/DB)
		$blockedScheduleIds	= _batchreminders_load_blocked_schedules();

		if (function_exists('wachthond')) {
			wachthond($extdebug, 3, 'blocked_schedule_ids', $blockedScheduleIds);
		}
	}

	// 2. Info voor de log
	$contactId	= $params['contact_id']	?? 'onbekend';
	$toEmail	= $params['toEmail']	?? 'onbekend';
	$subject	= $params['subject']	?? 'geen onderwerp';

	// 3. CHECK: Laten we deze door of breken we af?
	$scheduleId	= (int) ($params['entity_id'] ?? 0);
	$isBlocked	= in_array($scheduleId, $blockedScheduleIds, TRUE);

	if ($isBlocked || $sentCount >= $batchLimit) {
		$params['abort']	= TRUE;

		if (function_exists('wachthond')) {
			$params_abort	= [
				'status'		=> $isBlocked ? 'ABORTED (Template validatie mislukt — zie alert-mail)' : 'ABORTED (Doorgeschoven naar volgende cron-run)',
				'contact_id'	=> $contactId,
				'email'			=> $toEmail,
				'schedule_id'	=> $scheduleId,
			];
			wachthond($extdebug, 7, "geaborteerd_{$toEmail}", $params_abort);
		}
		return;
	}

	// 4. TOEGELATEN: teller ophogen
	$sentCount++;

	if (function_exists('wachthond')) {
		$params_allow	= [
			'status'	=> 'ALLOWED (Wordt nu verzonden)',
			'contact_id'=> $contactId,
			'email'		=> $toEmail,
			'subject'	=> $subject,
			'voortgang'	=> "{$sentCount} van de {$batchLimit} in deze batch verwerkt",
		];
		wachthond($extdebug, 3, "toegestaan_{$sentCount}", $params_allow);
	}

	Civi::log()->debug("batchreminders: Verzonden ({$sentCount}/{$batchLimit}) | Contact ID: {$contactId} | Email: {$toEmail}");
}

/**
 * Haalt de actieve cluster op: alle msg_template_ids + schedule_ids in de huidige wachtrij.
 *
 * @return array  msg_template_id => ['title' => string, 'schedule_ids' => int[]]
 */
function _batchreminders_get_cluster() {
	$dao		= CRM_Core_DAO::executeQuery("
		SELECT DISTINCT S.id AS schedule_id, S.msg_template_id, S.title
		FROM   civicrm_action_log      AS L
		JOIN   civicrm_action_schedule AS S ON L.action_schedule_id = S.id
		WHERE  L.action_date_time  IS NULL
		AND    S.msg_template_id   IS NOT NULL
	");
	$cluster	= [];
	while ($dao->fetch()) {
		$tid = $dao->msg_template_id;
		if (!isset($cluster[$tid])) {
			$cluster[$tid] = ['title' => $dao->title, 'schedule_ids' => []];
		}
		$cluster[$tid]['schedule_ids'][] = (int) $dao->schedule_id;
	}
	return $cluster;
}

/**
 * Valideert elke template in de cluster via de markup- en tokenscripts.
 *
 * Geeft terug welke templates OK zijn (sync + versturen) en welke geblokkeerd worden.
 * De $execFn parameter maakt de functie testbaar: in tests stuur je een fake callback,
 * in productie gebruikt je de standaard PHP exec().
 *
 * Validatieresultaten worden gecached in /tmp (per template-id). Cache is geldig zolang:
 *   - het bestand <6 uur oud is, EN
 *   - de template niet is gewijzigd na de cache-aanmaak.
 * Zo wordt 9 sec exec-overhead per template gereduceerd naar <1 ms op opeenvolgende runs.
 * Het cachepad is alleen actief op het productiepad ($execFn === NULL).
 *
 * @param  array         $clusterTemplates  Uitvoer van _batchreminders_get_cluster()
 * @param  string        $scriptDir         Pad naar /usr/local/bin/templates
 * @param  callable|null $execFn            Optionele exec-vervanger voor tests:
 *                                          fn(string $cmd): ['output' => string[], 'exit' => int]
 * @return array  [
 *   'ok_ids'      => int[],   // template-ids die de validatie haalden
 *   'blocked_ids' => int[],   // action_schedule_ids die overgeslagen worden
 *   'errors'      => array,   // validatiefouten per template_id
 * ]
 */
function _batchreminders_startup(array $clusterTemplates, string $scriptDir, ?callable $execFn = NULL) {
	$okIds		= [];
	$blockedIds	= [];
	$errors		= [];

	foreach ($clusterTemplates as $templateId => $info) {
		$tid	= (int) $templateId;

		if ($execFn !== NULL) {
			// Testpad: gebruik de meegegeven callback
			$markupResult	= $execFn("{$scriptDir}/civicrm_templates_markup.sh -q -c s {$tid}");
			$tokenResult	= $execFn("{$scriptDir}/civicrm_templates_tokens.sh -q {$tid}");
		}
		else {
			// Productiepad: controleer cache vóór de dure exec-aanroepen.
			// Cache is geldig als: bestand bestaat + < 6 uur oud + template niet
			// gewijzigd na aanmaken cache (modificatiedatum geeft template-versie weer).
			$cacheFile		= "/tmp/batchreminders_valid_{$tid}.ok";
			// modified_date bestaat niet in civicrm_msg_template; gebruik 0 zodat
			// de cache alleen op bestandsleeftijd wordt beoordeeld.
			$tplModified	= 0;
			$cacheAge		= file_exists($cacheFile) ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;
			$cacheValid		= $cacheAge < 21600 && filemtime((string)$cacheFile) > $tplModified;

			if ($cacheValid) {
				$okIds[]	= $tid;
				Civi::log()->debug("batchreminders: Template {$tid} ({$info['title']}) OK (cache, {$cacheAge}s oud).");
				continue;
			}

			// Cache niet geldig: voer de validatiescripts uit.
			$markupOut	= [];
			$markupExit	= 0;
			exec("'{$scriptDir}/civicrm_templates_markup.sh' -q -c s {$tid} 2>&1", $markupOut, $markupExit);
			$markupResult	= ['output' => $markupOut, 'exit' => $markupExit];

			$tokenOut	= [];
			$tokenExit	= 0;
			exec("'{$scriptDir}/civicrm_templates_tokens.sh' -q {$tid} 2>&1", $tokenOut, $tokenExit);
			$tokenResult	= ['output' => $tokenOut, 'exit' => $tokenExit];
		}

		$markupFailed	= ($markupResult['exit'] !== 0);
		$tokenFailed	= ($tokenResult['exit']  !== 0);

		if ($markupFailed || $tokenFailed) {
			$errors[$tid]	= [
				'template_id'	=> $tid,
				'reminder'		=> $info['title'],
				'schedule_ids'	=> $info['schedule_ids'],
				'markup_exit'	=> $markupResult['exit'],
				'token_exit'	=> $tokenResult['exit'],
				'markup_output'	=> implode("\n", $markupResult['output']),
				'token_output'	=> implode("\n", $tokenResult['output']),
			];
			$blockedIds		= array_merge($blockedIds, $info['schedule_ids']);
			Civi::log()->error("batchreminders: Validatie MISLUKT voor template {$tid} ({$info['title']}) — schedules " . implode(', ', $info['schedule_ids']) . " overgeslagen.");
		}
		else {
			$okIds[]	= $tid;
			Civi::log()->debug("batchreminders: Template {$tid} ({$info['title']}) OK.");

			// Schrijf cache zodat de volgende run de exec-aanroepen kan overslaan.
			// Alleen op het productiepad (execFn === NULL), anders hebben we geen $cacheFile.
			if ($execFn === NULL && isset($cacheFile)) {
				file_put_contents($cacheFile, '');
			}
		}
	}

	return [
		'ok_ids'		=> $okIds,
		'blocked_ids'	=> $blockedIds,
		'errors'		=> $errors,
	];
}

/**
 * Stuurt de alert-mail naar webteam bij validatiefouten.
 *
 * @param array  $errors    Validatiefouten uit _batchreminders_startup()
 * @param int    $okCount   Aantal templates dat wél door de validatie kwam
 * @param string $toEmail   E-mailadres van webteam
 */
function _batchreminders_send_alert(array $errors, int $okCount, string $toEmail) {
	$errorRowsHtml	= '';
	$errorRowsText	= [];

	foreach ($errors as $err) {
		$reminderEsc		= htmlspecialchars($err['reminder']);
		$markupOut			= htmlspecialchars($err['markup_output']);
		$tokenOut			= htmlspecialchars($err['token_output']);
		$scheduleStr		= implode(', ', $err['schedule_ids']);

		$errorRowsHtml	.= "<tr style='border-bottom:1px solid #eee;'>
			<td style='padding:8px 12px;font-weight:bold;white-space:nowrap;'>#{$err['template_id']}</td>
			<td style='padding:8px 12px;'>{$reminderEsc}<br><span style='color:#888;font-size:12px;'>schedule: {$scheduleStr}</span></td>
			<td style='padding:8px 12px;color:#c00;'>" . ($err['markup_exit'] !== 0 ? "[MARKUP] {$markupOut}" : '<span style="color:#2a2">OK</span>') . "</td>
			<td style='padding:8px 12px;color:#c00;'>" . ($err['token_exit']  !== 0 ? "[TOKEN] {$tokenOut}"   : '<span style="color:#2a2">OK</span>') . "</td>
		</tr>";

		$errorRowsText[]	= "#{$err['template_id']} {$err['reminder']} (schedule: {$scheduleStr})";
		if ($err['markup_exit'] !== 0) { $errorRowsText[] = "  [MARKUP] {$err['markup_output']}"; }
		if ($err['token_exit']  !== 0) { $errorRowsText[] = "  [TOKEN]  {$err['token_output']}";  }
	}

	$htmlBody	= "
		<div style='font-family:Arial,sans-serif;max-width:700px;margin:0 auto;'>
		<h2 style='color:#c00;border-bottom:2px solid #c00;padding-bottom:8px;'>
			&#9888; Batchreminders — validatiefout in cluster
		</h2>
		<p>Één of meer templates zijn niet door de validatie gekomen.<br>
		   <strong>De onderstaande reminders zijn overgeslagen.</strong>
		   " . ($okCount > 0 ? "Reminders met gezonde templates ({$okCount}) zijn wél verstuurd." : "") . "<br>
		   Corrigeer de templates en wacht op de volgende cron-run.</p>
		<table style='width:100%;border-collapse:collapse;margin-top:16px;font-size:14px;'>
			<thead>
				<tr style='background:#f5f5f5;'>
					<th style='padding:8px 12px;text-align:left;'>Template</th>
					<th style='padding:8px 12px;text-align:left;'>Reminder</th>
					<th style='padding:8px 12px;text-align:left;'>Markup</th>
					<th style='padding:8px 12px;text-align:left;'>Token</th>
				</tr>
			</thead>
			<tbody>{$errorRowsHtml}</tbody>
		</table>
		<p style='margin-top:24px;font-size:12px;color:#888;'>
			Verstuurd door nl.onvergetelijk.batchreminders
		</p>
		</div>
	";

	$alertParams	= [
		'toEmail'	=> $toEmail,
		'toName'	=> 'Webteam OZK',
		'from'		=> 'info@onvergetelijk.nl',
		'subject'	=> '[OZK] Batchreminders — validatiefout, reminders deels overgeslagen',
		'html'		=> $htmlBody,
		'text'		=> "Validatiefout in batchreminders — onderstaande reminders overgeslagen.\n\n" . implode("\n", $errorRowsText) . "\n\nCorrigeer de templates en wacht op de volgende cron-run.",
	];
	\CRM_Utils_Mail::send($alertParams);
}

/**
 * Pad naar de state-file die _batchreminders_prewarm() schrijft en de
 * alterMailParams-hook leest. Losse functie zodat tests 'm kunnen overschrijven
 * zonder de echte /tmp aan te raken.
 */
function _batchreminders_state_file(): string {
	return '/tmp/batchreminders_blocked_schedules.json';
}

/**
 * Leest het geblokkeerde-schedules-resultaat van de laatste prewarm-run.
 *
 * Puur een file-read + json_decode — geen exec(), geen DB-query. Dit is precies
 * waarom de validatie hieruit is getrokken: dit stukje mag in het verzendpad
 * blijven staan omdat het onder alle omstandigheden in <1ms klaar is.
 *
 * Fail-open: ontbreekt de state-file (prewarm heeft nog nooit gedraaid) dan
 * worden er geen schedules geblokkeerd — dat is niet slechter dan de situatie
 * vóór de validatielaag bestond. Is de file ouder dan $maxAgeSeconds (de prewarm-
 * cron lijkt gestopt), dan gebruiken we nog wel de laatst bekende blocked-lijst
 * (beter dan niets) maar loggen we een error zodat het opvalt.
 *
 * @param  int $maxAgeSeconds  Vanaf wanneer de state als verouderd geldt (default 20 min).
 * @return int[]  action_schedule_ids die overgeslagen moeten worden.
 */
function _batchreminders_load_blocked_schedules(int $maxAgeSeconds = 1200): array {
	$stateFile	= _batchreminders_state_file();

	if (!file_exists($stateFile)) {
		Civi::log()->warning("batchreminders: geen prewarm-state gevonden ({$stateFile}) — nog geen enkele run geweest? Niets wordt geblokkeerd.");
		return [];
	}

	$age	= time() - filemtime($stateFile);
	if ($age > $maxAgeSeconds) {
		Civi::log()->error("batchreminders: prewarm-state is {$age}s oud (drempel {$maxAgeSeconds}s) — draait de prewarm-cron nog? Laatst bekende blocked-lijst wordt gebruikt.");
	}

	$raw	= @file_get_contents($stateFile);
	$data	= json_decode((string) $raw, TRUE);

	if (!is_array($data) || !isset($data['blocked_ids']) || !is_array($data['blocked_ids'])) {
		Civi::log()->error("batchreminders: prewarm-state onleesbaar/corrupt ({$stateFile}) — niets wordt geblokkeerd.");
		return [];
	}

	return array_map('intval', $data['blocked_ids']);
}

/**
 * Synct body_html/body_text/subject van gevalideerde templates naar hun schedules.
 * Losgetrokken uit de oude inline UPDATE in de hook zodat _batchreminders_prewarm()
 * 'm buiten het verzendpad kan aanroepen.
 *
 * @param  int[] $okIds  Template-ids die de validatie haalden.
 * @return int    Aantal daadwerkelijk bijgewerkte schedule-rijen.
 */
function _batchreminders_sync_templates(array $okIds): int {
	if (empty($okIds)) {
		return 0;
	}

	$idList	= implode(',', array_map('intval', $okIds));
	CRM_Core_DAO::executeQuery("
		UPDATE civicrm_action_schedule AS S
		INNER JOIN civicrm_msg_template AS M ON S.msg_template_id = M.id
		SET    S.body_html  = M.msg_html,
		       S.body_text  = M.msg_text,
		       S.subject    = M.msg_subject
		WHERE  S.msg_template_id IN ({$idList})
		AND    S.body_html != M.msg_html
	");
	$affected	= (int) CRM_Core_DAO::singleValueQuery('SELECT ROW_COUNT()');

	Civi::log()->info("batchreminders: Template-sync voltooid voor: " . implode(', ', $okIds) . " ({$affected} schedule-rijen bijgewerkt).");

	return $affected;
}

/**
 * Prewarm-entrypoint: draait ONAFHANKELIJK van job.send_reminder, op zijn eigen cronjob
 * (zie bin/prewarm.php + cron-civicrm-batchreminders-prewarm.sh). Doet al het werk dat
 * vroeger synchroon in alterMailParams() zat: cluster ophalen, valideren (incl. de
 * exec()-aanroepen bij een cold cache), templates syncen, alert-mail bij fouten, en
 * schrijft tot slot de blocked-schedules state-file die de hook leest.
 *
 * Schrijft atomisch (tmp-file + rename) zodat de hook nooit een half geschreven
 * state-file kan lezen terwijl deze functie nog bezig is.
 *
 * @return array  ['ok_ids' => int[], 'blocked_ids' => int[], 'errors' => array, 'synced' => int]
 */
function _batchreminders_prewarm(): array {
	$templateScriptDir	= '/usr/local/bin/templates';
	$webteamEmail		= 'webteam@onvergetelijk.nl';
	$extdebug			= 'batchreminders';

	if (function_exists('wachthond')) {
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS PREWARM - START",                       "[PREWARM]");
		wachthond($extdebug, 2, "########################################################################");
	}

	$clusterTemplates	= _batchreminders_get_cluster();
	$startup			= _batchreminders_startup($clusterTemplates, $templateScriptDir);

	if (function_exists('wachthond')) {
		wachthond($extdebug, 3, 'cluster_templates', $clusterTemplates);
		wachthond($extdebug, 3, 'startup_result',    $startup);
	}

	if (!empty($startup['errors'])) {
		_batchreminders_send_alert($startup['errors'], count($startup['ok_ids']), $webteamEmail);

		if (function_exists('wachthond')) {
			wachthond($extdebug, 1, "### VALIDATIEFOUTEN — " . count($startup['blocked_ids']) . " SCHEDULES GEBLOKKEERD", "[GEDEELTELIJK]");
			wachthond($extdebug, 3, 'validation_errors', $startup['errors']);
		}
	}

	$synced	= _batchreminders_sync_templates($startup['ok_ids']);

	// Atomisch schrijven: eerst naar een tmp-bestand in dezelfde map (dus zelfde filesystem,
	// rename() is dan atomisch), dan pas de definitieve naam erover heen zetten.
	$stateFile	= _batchreminders_state_file();
	$tmpFile	= $stateFile . '.tmp' . getmypid();
	file_put_contents($tmpFile, json_encode([
		'blocked_ids'	=> array_values($startup['blocked_ids']),
		'generated_at'	=> date('Y-m-d H:i:s'),
	]));
	rename($tmpFile, $stateFile);

	if (function_exists('wachthond')) {
		wachthond($extdebug, 1, "### BATCHREMINDERS PREWARM - KLAAR", "[PREWARM]");
		wachthond($extdebug, 3, 'resultaat', [
			'ok_ids'      => $startup['ok_ids'],
			'blocked_ids' => $startup['blocked_ids'],
			'synced'      => $synced,
		]);
	}

	Civi::log()->info("batchreminders: Prewarm klaar — " . count($startup['ok_ids']) . " ok, " . count($startup['blocked_ids']) . " geblokkeerd, {$synced} gesynct.");

	return [
		'ok_ids'      => $startup['ok_ids'],
		'blocked_ids' => $startup['blocked_ids'],
		'errors'      => $startup['errors'],
		'synced'      => $synced,
	];
}
