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
 * Implements hook_civicrm_xmlMenu().
 *
 * Registreert het dashboard op civicrm/batchreminders/dashboard (zie xml/Menu/batchreminders.xml
 * en CRM/Batchreminders/Page/Dashboard.php).
 */
function batchreminders_civicrm_xmlMenu(&$files) {
	$files[] = __DIR__ . '/xml/Menu/batchreminders.xml';
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Voegt het dashboard toe onder Administer -> CiviMail (naast "CiviMail Component Settings"),
 * zodat beheerders er ook zonder de directe URL bij kunnen.
 */
function batchreminders_civicrm_navigationMenu(&$nodes) {
	$civiMailNode = &_batchreminders_find_navigation_node($nodes, 'CiviMail');
	if ($civiMailNode === NULL) {
		return; // geen "CiviMail"-menu gevonden — niets om aan toe te voegen
	}

	if (!isset($civiMailNode['child']) || !is_array($civiMailNode['child'])) {
		$civiMailNode['child'] = [];
	}

	// Nieuwe navID die niet met bestaande IDs in de hele boom botst.
	$newNavId = _batchreminders_max_navigation_id($nodes) + 1;

	// Niet dubbel toevoegen als deze functie meermaals per request-cyclus aangeroepen wordt.
	foreach ($civiMailNode['child'] as $child) {
		if (($child['attributes']['name'] ?? '') === 'Reminderqueue Dashboard') {
			return;
		}
	}

	$civiMailNode['child'][$newNavId] = [
		'attributes' => [
			'label'        => 'Reminderqueue Dashboard',
			'name'         => 'Reminderqueue Dashboard',
			'url'          => 'civicrm/batchreminders/dashboard?reset=1',
			'permission'   => 'administer CiviCRM',
			'operator'     => NULL,
			'separator'    => NULL,
			'parentID'     => $civiMailNode['attributes']['navID'] ?? NULL,
			'navID'        => $newNavId,
			'active'       => 1,
		],
	];
}

/**
 * Zoekt in de navigationMenu-boom (recursief) naar een node op naam. Retourneert een
 * referentie zodat de caller direct in de boom kan schrijven (child toevoegen).
 */
function &_batchreminders_find_navigation_node(array &$nodes, string $name) {
	foreach ($nodes as &$node) {
		if (($node['attributes']['name'] ?? '') === $name) {
			return $node;
		}
		if (isset($node['child']) && is_array($node['child'])) {
			$found = &_batchreminders_find_navigation_node($node['child'], $name);
			if ($found !== NULL) {
				return $found;
			}
		}
	}
	$null = NULL;
	return $null;
}

/**
 * Hoogste navID in de hele boom, om een botsingsvrije nieuwe ID te kiezen.
 */
function _batchreminders_max_navigation_id(array $nodes): int {
	$max = 0;
	foreach ($nodes as $navId => $node) {
		$max = max($max, (int) $navId);
		if (isset($node['child']) && is_array($node['child'])) {
			$max = max($max, _batchreminders_max_navigation_id($node['child']));
		}
	}
	return $max;
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
 * Classificeert een schedule-titel voor de verzendprioriteit.
 *
 * Kamp wordt herkend aan KK/BK/TK/JK/TOP in de titel (bv. "07_JD_4WEKEN_INFO_KK1").
 * Leiding-templates herkennen we aan het JL-segment of LEID in de titel
 * (bv. "17_JL_1WEEK_LEID_WK1") — die komen ná alle deelnemer-templates.
 *
 * Niet-kamp-templates (bv. jubilea, fietshuur) krijgen bewust de HOOGSTE prioriteit
 * (camp=0, vóór KK): het zijn meestal losse, kleine wachtrijen die anders structureel
 * verdrongen worden door de veel grotere kamp-wachtrijen in de greedy budgetverdeling
 * (_batchreminders_rank_and_allocate) — vastgesteld 5-jul-2026 toen 2 losse reminders
 * (JUBILEUM, FIETS) met elk 1 wachtende nooit aan de beurt kwamen.
 *
 * @return array{leid: bool, camp: int}  camp: onbekend=0 KK=1 BK=2 TK=3 JK=4 TOP=5
 */
function _batchreminders_classify_title(string $title): array {
	$isLeid	= (bool) preg_match('/(^|_)JL(_|$)|LEID/i', $title);
	$camp	= 0;
	// Simpele substring-match in prioriteitsvolgorde: dekt zowel losse kampen (KK1)
	// als samengestelde reminders (KKBKTKJK → telt als KK, het hoogst geprioriteerde
	// kamp in de set). Woordgrenzen werken hier niet door die samenstellingen.
	foreach (['KK' => 1, 'BK' => 2, 'TK' => 3, 'JK' => 4, 'TOP' => 5] as $kamp => $prio) {
		if (stripos($title, $kamp) !== FALSE) {
			$camp = $prio;
			break;
		}
	}
	return ['leid' => $isLeid, 'camp' => $camp];
}

/**
 * Deelt een schedule in een chronologie-emmer in op basis van zijn vooruitloop
 * (start_action_offset + unit). De mailreeks per kamp is een lopend verhaal
 * (28wk → 4wk info → 1wk → 2dgn → 1dag): bij een achterstand moeten mails in
 * die VERHAALVOLGORDE aankomen, dus de mail met de langste vooruitloop (die
 * chronologisch het eerst in de reeks thuishoort) gaat vóór. Grove emmers
 * (geen exacte offset-volgorde) zodat een triviaal verschil (5 vs 7 dagen)
 * de kampvolgorde niet overruled — zelfde redenering als dag-niveau i.p.v.
 * uur-niveau bij de wachttijd.
 *
 * In de praktijk (peiling 2-jul-2026): hour 30-36, day 0-7, week 1-28.
 *
 * @param  string|null $unit    hour/day/week (NULL bij absolute_date-schedules).
 * @param  int|null    $offset  Vooruitloop in $unit-eenheden.
 * @return int  1 = vroeg in de reeks (> 2 weken vooruitloop), 2 = midden
 *              (<= 2 weken, ook absolute datum), 3 = laat in de reeks (<= 2 dagen).
 */
function _batchreminders_urgency_bucket(?string $unit, ?int $offset): int {
	if ($offset === NULL || $unit === NULL) {
		return 2;
	}
	$perUnit	= ['hour' => 1 / 24, 'day' => 1, 'week' => 7, 'month' => 30];
	$dagen		= $offset * ($perUnit[strtolower($unit)] ?? 1);
	if ($dagen > 14) {
		return 1;
	}
	return $dagen > 2 ? 2 : 3;
}

/**
 * Herleidt de (dag)datum waarop een action_log-rij is aangemaakt uit de
 * nullfix-snapshothistorie (/tmp/nullfix_max_id.history: per run "epoch max_id").
 * action_log heeft zelf geen aanmaakdatum-kolom; de vroegste snapshot waarvan de
 * max-id >= deze rij-id is, geeft de bovengrens van de aanmaakdag (±30 min).
 *
 * @param  int    $id        action_log-id van de oudste wachtende rij.
 * @param  array  $history   [[epoch, maxid], ...] (hoeft niet gesorteerd te zijn).
 * @param  string $fallback  Dag (Y-m-d) als de historie geen antwoord heeft.
 */
function _batchreminders_id_to_day(int $id, array $history, string $fallback): string {
	$best = NULL;
	foreach ($history as $snap) {
		[$epoch, $maxid] = $snap;
		if ($maxid >= $id && ($best === NULL || $epoch < $best)) {
			$best = $epoch;
		}
	}
	return $best === NULL ? $fallback : date('Y-m-d', $best);
}

/**
 * Rangschikt schedules en verdeelt het run-budget — puur rekenwerk, testbaar.
 *
 * Prioriteit (voorkeur Richard, 2-jul-2026):
 *   1. oudste wachtende dag eerst (dag-niveau, niet uur-niveau)
 *   2. chronologie-emmer van het reminder-type: LANGSTE vooruitloop eerst
 *      (>2wkn, dan <=2wkn, dan <=2dgn) — de mailreeks is een lopend verhaal,
 *      dus een 4WEKEN-infomail gaat vóór een NA1DAG-mail, ongeacht kamp
 *   3. binnen gelijke urgentie: eerst niet-kamp-templates (jubilea, fietshuur e.d. —
 *      kleine losse wachtrijen, bewust vóóraan zodat ze niet verdrinken achter de
 *      veel grotere kamp-wachtrijen) — inclusief hun leiding-varianten (bv. de
 *      JUBILEUM_LEID-reminder), want die zijn net zo klein en los; daarna pas
 *      deelnemer-kampvolgorde KK -> BK -> TK -> JK -> TOP
 *   4. daarna pas de kamp-leiding-templates (binnen hun dag+urgentie-emmer) — dit
 *      "deelnemer vóór leiding"-onderscheid geldt alleen bij een herkend kamp;
 *      niet-kamp-templates slaan die tier over (zie punt 3)
 * Het budget wordt greedy uitgedeeld in die volgorde: het hoogst geprioriteerde
 * schedule wordt volledig bediend vóór het volgende aan de beurt komt.
 * Bijvangst van groeperen per reminder-type: batches zijn homogeen per template
 * (warme render-caches, leesbare "golven" in log en dashboard).
 *
 * @param  array $schedules  Per schedule: ['id'=>int,'day'=>'Y-m-d','urg'=>int,'leid'=>bool,'camp'=>int,'pending'=>int]
 * @param  int   $batchsize  Totaalbudget voor deze run.
 * @return array  schedule_id => toegekend budget (alleen schedules met budget > 0).
 */
function _batchreminders_rank_and_allocate(array $schedules, int $batchsize): array {
	usort($schedules, function($a, $b) {
		// Het "deelnemer vóór leiding"-onderscheid geldt alleen binnen een herkend kamp (camp>0).
		// Bij niet-kamp-templates (camp=0) doet leid er voor de sortering niet toe — die horen
		// allemaal in de voorste, kleine tier, leiding-variant of niet.
		$leidA = ($a['camp'] === 0) ? FALSE : $a['leid'];
		$leidB = ($b['camp'] === 0) ? FALSE : $b['leid'];

		return [$a['day'], $a['urg'] ?? 2, (int) $leidA, $a['camp'], $a['id']]
		   <=> [$b['day'], $b['urg'] ?? 2, (int) $leidB, $b['camp'], $b['id']];
	});

	$alloc		= [];
	$remaining	= $batchsize;
	foreach ($schedules as $s) {
		if ($remaining <= 0) {
			break;
		}
		$give = min($remaining, max(0, (int) $s['pending']));
		if ($give > 0) {
			$alloc[$s['id']]	= $give;
			$remaining			-= $give;
		}
	}
	return $alloc;
}

/**
 * Bouwt de budgetverdeling voor deze cron-run: alle schedules met wachtenden
 * ophalen, classificeren (kamp/leiding + oudste-dag via de nullfix-historie)
 * en het budget verdelen volgens _batchreminders_rank_and_allocate().
 */
function _batchreminders_build_allocation(array $blockedScheduleIds): array {
	$dao = CRM_Core_DAO::executeQuery("
		SELECT S.id, S.title, S.start_action_unit, S.start_action_offset,
		       COUNT(L.id) AS pending, MIN(L.id) AS oldest_id
		FROM   civicrm_action_log      L
		JOIN   civicrm_action_schedule S ON S.id = L.action_schedule_id
		WHERE  L.action_date_time IS NULL
		AND    S.is_active        = 1
		GROUP  BY S.id
	");

	// Snapshothistorie van de nullfix-cron inlezen voor de dag-herleiding.
	$history = [];
	foreach (@file('/tmp/nullfix_max_id.history', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		$parts = preg_split('/\s+/', trim($line));
		if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
			$history[] = [(int) $parts[0], (int) $parts[1]];
		}
	}
	$today = date('Y-m-d');

	$schedules = [];
	while ($dao->fetch()) {
		if (in_array((int) $dao->id, $blockedScheduleIds, TRUE)) {
			continue; // validatie gefaald: geen budget, wordt ook niet gerenderd
		}
		$class = _batchreminders_classify_title((string) $dao->title);
		$schedules[] = [
			'id'		=> (int) $dao->id,
			'day'		=> _batchreminders_id_to_day((int) $dao->oldest_id, $history, $today),
			'urg'		=> _batchreminders_urgency_bucket($dao->start_action_unit ?: NULL, $dao->start_action_offset === NULL ? NULL : (int) $dao->start_action_offset),
			'leid'		=> $class['leid'],
			'camp'		=> $class['camp'],
			'pending'	=> (int) $dao->pending,
		];
	}

	return _batchreminders_rank_and_allocate($schedules, _batchreminders_batchsize());
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
	$state['blocked']	= $state['blocked'] ?? _batchreminders_load_blocked_schedules();

	// Eénmalig per run: prioriteitsranking + budgetverdeling opbouwen.
	// Kanttekening: de verdeling is een snapshot van vóór core's recipient-INSERTs;
	// rijen die core tijdens deze run nog toevoegt komen de volgende run aan de beurt.
	if (!isset($state['alloc'])) {
		$state['alloc'] = _batchreminders_build_allocation($state['blocked']);
		if (!empty($state['alloc'])) {
			Civi::log()->debug("batchreminders: budgetverdeling deze run (dag → kamp KK/BK/TK/JK/TOP → leiding): " . json_encode($state['alloc']));
		}
	}

	$scheduleId	= (int) ($event->actionSchedule->id ?? 0);
	$isBlocked	= in_array($scheduleId, $state['blocked'], TRUE);
	$budget		= $isBlocked ? 0 : (int) ($state['alloc'][$scheduleId] ?? 0);

	$event->query->limit($budget);

	// Alleen loggen als er iets te melden valt (budget > 0 of geblokkeerd) — LIMIT 0
	// voor een leeg/niet-geprioriteerd schedule is de norm.
	if ($budget > 0 || $isBlocked) {
		Civi::log()->debug("batchreminders: render-limiet schedule {$scheduleId}: LIMIT {$budget}" . ($isBlocked ? ' (geblokkeerd door validatie)' : ''));
	}
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
		$params['abortMailSend']	= TRUE;

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
 * Pure detectie/strip-logica voor een mailtest.sh-restmarker in een onderwerp — géén DB,
 * géén side-effects, dus los headless testbaar. Zelfde patroon als de sed-regex in
 * mailtest.sh (CLEAN_SUBJ) en de REGEXP_REPLACE-vangnetten in _batchreminders_sync_templates()
 * en cssinliner_civicrm_alterMailParams() — alle vier moeten wijzigen als dit patroon ooit
 * verandert.
 *
 * @return array{changed: bool, subject: string}
 */
function _batchreminders_strip_test_marker(string $subject): array {
	$after = preg_replace('/^\[[A-Z][0-9]{3}\] /', '', $subject);
	$after = preg_replace('/ \[[0-9]{8}_[0-9]{6}\]$/', '', $after);
	return ['changed' => $after !== $subject, 'subject' => $after];
}

/**
 * Ontsmet msg_subject van gegeven templates INSTANT AAN DE BRON (civicrm_msg_template),
 * i.p.v. te blokkeren of alleen de kopie in action_schedule te ontsmetten (zie
 * _batchreminders_sync_templates() voor die tweede, defensieve laag).
 *
 * Waarom instant opschonen i.p.v. blokkeren: dit is geen inhoudelijke fout die een mens moet
 * beoordelen (zoals kapotte markup/tokens) — het is een mechanisch herkenbaar restje van een
 * afgebroken mailtest.sh-run (zie die file voor de bronfix van de vicieuze cirkel). Blokkeren
 * zou de reminder onnodig laten hangen tot iemand het toevallig opmerkt; direct herstellen is
 * hier veiliger én sneller dan wachten.
 *
 * @param  int[] $templateIds  Te controleren msg_template-ids (typisch: de huidige cluster).
 * @return int    Aantal templates waarvan msg_subject is opgeschoond.
 */
function _batchreminders_clean_test_markers(array $templateIds): int {
	if (empty($templateIds)) {
		return 0;
	}

	$idList		= implode(',', array_map('intval', $templateIds));
	$dao		= CRM_Core_DAO::executeQuery("
		SELECT id, msg_title, msg_subject
		FROM   civicrm_msg_template
		WHERE  id IN ({$idList})
		AND   (msg_subject REGEXP '^\\\\[[A-Z][0-9]{3}\\\\] ' OR msg_subject REGEXP ' \\\\[[0-9]{8}_[0-9]{6}\\\\]\$')
	");

	$cleaned = 0;
	while ($dao->fetch()) {
		$before	= (string) $dao->msg_subject;
		$strip	= _batchreminders_strip_test_marker($before);
		if (!$strip['changed']) {
			continue; // false-positive uit de bredere SQL REGEXP-voorfilter
		}
		$after	= $strip['subject'];

		CRM_Core_DAO::executeQuery("
			UPDATE civicrm_msg_template SET msg_subject = %1 WHERE id = %2
		", [
			1 => [$after, 'String'],
			2 => [(int) $dao->id, 'Integer'],
		]);

		Civi::log()->warning("batchreminders: mailtest-restmarker instant opgeschoond in msg_template {$dao->id} ({$dao->msg_title}): '{$before}' -> '{$after}'");
		$cleaned++;
	}

	return $cleaned;
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
 * Puur rekenwerk: interpreteert de inhoud van de prewarm-state-file — testbaar
 * zonder Civi-bootstrap of filesystem. Fail-open op elke afwijking: liever een
 * (mogelijk kapotte) reminder versturen dan de hele keten stil laten blokkeren.
 *
 * @param  string|null $raw            Ruwe file-inhoud (NULL = file bestaat niet).
 * @param  int         $ageSeconds     Leeftijd van de file in seconden.
 * @param  int         $maxAgeSeconds  Vanaf wanneer de state als verouderd geldt.
 * @return array{blocked: int[], status: string}
 *         status: ok | missing (geen file) | stale (te oud, lijst wél gebruikt) | corrupt
 */
function _batchreminders_parse_blocked_state(?string $raw, int $ageSeconds, int $maxAgeSeconds): array {
	if ($raw === NULL) {
		return ['blocked' => [], 'status' => 'missing'];
	}

	$data = json_decode($raw, TRUE);
	if (!is_array($data) || !isset($data['blocked_ids']) || !is_array($data['blocked_ids'])) {
		return ['blocked' => [], 'status' => 'corrupt'];
	}

	return [
		'blocked'	=> array_map('intval', $data['blocked_ids']),
		'status'	=> $ageSeconds > $maxAgeSeconds ? 'stale' : 'ok',
	];
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
	$exists		= file_exists($stateFile);
	$raw		= $exists ? (string) @file_get_contents($stateFile) : NULL;
	$age		= $exists ? (time() - filemtime($stateFile)) : 0;

	$state = _batchreminders_parse_blocked_state($raw, $age, $maxAgeSeconds);

	switch ($state['status']) {
		case 'missing':
			Civi::log()->warning("batchreminders: geen prewarm-state gevonden ({$stateFile}) — nog geen enkele run geweest? Niets wordt geblokkeerd.");
			break;

		case 'stale':
			Civi::log()->error("batchreminders: prewarm-state is {$age}s oud (drempel {$maxAgeSeconds}s) — draait de prewarm-cron nog? Laatst bekende blocked-lijst wordt gebruikt.");
			break;

		case 'corrupt':
			Civi::log()->error("batchreminders: prewarm-state onleesbaar/corrupt ({$stateFile}) — niets wordt geblokkeerd.");
			break;
	}

	return $state['blocked'];
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
	// VANGNET tegen mailtest.sh-testmarkers (bv. "[L084] ... [20260701_231629]"): als een
	// eerdere testrun ooit hard gekilld is vóór herstel, kan msg_subject permanent besmet
	// blijven staan in civicrm_msg_template (zie mailtest.sh voor de bronfix). Deze sync
	// mag zo'n restje nooit naar de live schedule-subject doorzetten — REGEXP_REPLACE
	// strript het defensief, onafhankelijk van of de bron ooit hersteld wordt.
	CRM_Core_DAO::executeQuery("
		UPDATE civicrm_action_schedule AS S
		INNER JOIN civicrm_msg_template AS M ON S.msg_template_id = M.id
		SET    S.body_html  = M.msg_html,
		       S.body_text  = M.msg_text,
		       S.subject    = TRIM(REGEXP_REPLACE(
		                         REGEXP_REPLACE(M.msg_subject, '^\\\\[[A-Z][0-9]{3}\\\\] ', ''),
		                         ' \\\\[[0-9]{8}_[0-9]{6}\\\\]\$', ''
		                       ))
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

	// Instant opschonen vóór validatie: mailtest-restmarkers zijn geen inhoudelijke fout die
	// blokkade + mens-review verdient (zie _batchreminders_clean_test_markers()).
	$markersOpgeschoond	= _batchreminders_clean_test_markers(array_keys($clusterTemplates));
	if ($markersOpgeschoond > 0 && function_exists('wachthond')) {
		wachthond($extdebug, 1, "### {$markersOpgeschoond} MAILTEST-RESTMARKER(S) INSTANT OPGESCHOOND", "[ZELFHERSTEL]");
	}

	$startup			= _batchreminders_startup($clusterTemplates, $templateScriptDir);

	if (function_exists('wachthond')) {
		wachthond($extdebug, 3, 'cluster_templates', $clusterTemplates);
		wachthond($extdebug, 3, 'markers_opgeschoond', $markersOpgeschoond);
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
