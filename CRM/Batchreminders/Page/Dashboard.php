<?php

/**
 * Reminder-dashboard: realtime zicht op de scheduled-reminder-wachtrij.
 *
 * URL: /civicrm/batchreminders/dashboard
 * Toont per schedule hoeveel ontvangers wachten (civicrm_action_log met
 * action_date_time IS NULL), wat de volgende cron-run zou versturen (dezelfde
 * ranking als _batchreminders_build_allocation()), welke schedules geblokkeerd
 * staan (prewarm-validatie) en hoe lang geleden de laatste mail daadwerkelijk
 * verstuurd is (cron-gezondheid).
 */
class CRM_Batchreminders_Page_Dashboard extends CRM_Core_Page {

	public function run(): void {
		$extdebug = 'batchreminders.dashboard';
		$apidebug = FALSE;

		CRM_Utils_System::setTitle(ts('Reminderqueue Dashboard'));

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 1.0 AUTH",                       "[START]");
		wachthond($extdebug, 2, "########################################################################");

		// 1.0 Alleen voor beheerders — dit toont interne wachtrij-/cron-status.
		if (!CRM_Core_Permission::check('administer CiviCRM')) {
			CRM_Utils_System::permissionDenied();
			return;
		}

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 2.0 CRON-GEZONDHEID",           "[QUERY]");
		wachthond($extdebug, 2, "########################################################################");

		// 2.0 Laatst daadwerkelijk verstuurde reminder (over alle schedules heen).
		$sql_laatste	= "SELECT MAX(action_date_time) AS laatste FROM civicrm_action_log WHERE action_date_time IS NOT NULL";
		wachthond($extdebug, 7, 'sql_laatste', $sql_laatste);
		$dao_laatste	= CRM_Core_DAO::executeQuery($sql_laatste);
		$laatsteVerstuurd = $dao_laatste->fetch() ? $dao_laatste->laatste : NULL;
		wachthond($extdebug, 9, 'laatsteVerstuurd', $laatsteVerstuurd);

		$minutenGeleden = $laatsteVerstuurd ? (int) round((strtotime('now') - strtotime($laatsteVerstuurd)) / 60) : NULL;

		// 2.1 Zitten we nu in het venster waarin de reminder-cron zou moeten draaien?
		// Ma-za 07-22u, zo 12-22u (zie /etc/crontab, gefixt 5-jul-2026).
		$nuDow	= (int) date('w'); // 0=zo
		$nuUur	= (int) date('G');
		$inVenster = ($nuDow >= 1 && $nuDow <= 6 && $nuUur >= 7 && $nuUur <= 22)
			|| ($nuDow === 0 && $nuUur >= 12 && $nuUur <= 22);

		// Waarschuw alleen als we in het venster zitten én het langer dan 20 min stil is
		// (elke cron-run duurt zelf al tot enkele minuten, zie cron-civicrm-reminders.sh).
		$cronWaarschuwing = $inVenster && $minutenGeleden !== NULL && $minutenGeleden > 20;

		// 2.2 Volgende verwachte cron-tick: */2 min, sec 45, ma-za 07-22u / zo 12-22u
		// (zie /etc/crontab, "Slot ... Reminders"-regel).
		$volgendeRun = self::volgendeReminderCronRun(new DateTimeImmutable());
		$volgendeRunLabel = $volgendeRun['secondenTot'] < 60
			? $volgendeRun['secondenTot'] . 's'
			: round($volgendeRun['secondenTot'] / 60) . ' min';

		wachthond($extdebug, 3, 'cron_status', [
			'laatsteVerstuurd'	=> $laatsteVerstuurd,
			'minutenGeleden'	=> $minutenGeleden,
			'inVenster'			=> $inVenster,
			'waarschuwing'		=> $cronWaarschuwing,
			'volgendeRun'		=> $volgendeRun,
		]);

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 3.0 GEBLOKKEERDE SCHEDULES (PREWARM-STATE)", "[QUERY]");
		wachthond($extdebug, 2, "########################################################################");

		// 3.0 Blocked-state rechtstreeks parsen (NIET via _batchreminders_load_blocked_schedules(),
		// die schrijft bij verouderde/ontbrekende state een Civi::log()-waarschuwing — dat willen we
		// niet elke 30s herhalen door de auto-refresh van dit dashboard).
		$stateFile	= _batchreminders_state_file();
		$exists		= file_exists($stateFile);
		$raw		= $exists ? (string) @file_get_contents($stateFile) : NULL;
		$ageSec		= $exists ? (time() - filemtime($stateFile)) : NULL;
		$state		= _batchreminders_parse_blocked_state($raw, (int) $ageSec, 1200);
		$blockedIds	= $state['blocked'];
		wachthond($extdebug, 3, 'blocked_state', ['status' => $state['status'], 'age_sec' => $ageSec, 'blocked' => $blockedIds]);

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 4.0 WACHTRIJ PER SCHEDULE",     "[QUERY]");
		wachthond($extdebug, 2, "########################################################################");

		// 4.0 Alle schedules met wachtende ontvangers (action_date_time IS NULL) — zelfde bron-query
		// als _batchreminders_build_allocation(), maar hier met volledige rijen voor weergave.
		$sql_wachtrij = "
			SELECT S.id, S.title, S.is_active, S.start_action_unit, S.start_action_offset, S.entity_value,
			       S.msg_template_id, T.msg_title,
			       COUNT(L.id) AS pending, MIN(L.id) AS oldest_id
			FROM   civicrm_action_log      L
			JOIN   civicrm_action_schedule S ON S.id = L.action_schedule_id
			LEFT   JOIN civicrm_msg_template T ON T.id = S.msg_template_id
			WHERE  L.action_date_time IS NULL
			GROUP  BY S.id
		";
		wachthond($extdebug, 7, 'sql_wachtrij', $sql_wachtrij);
		$dao_wachtrij = CRM_Core_DAO::executeQuery($sql_wachtrij);

		// 4.1 Dag-herleiding via de nullfix-snapshothistorie (zelfde bestand/logica als
		// _batchreminders_build_allocation()).
		$history = [];
		foreach (@file('/tmp/nullfix_max_id.history', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
				$history[] = [(int) $parts[0], (int) $parts[1]];
			}
		}
		$vandaag = date('Y-m-d');

		// 4.2 Preview van de volgende cron-run: hergebruikt de daadwerkelijke ranking/verdeel-logica
		// van de extensie, zodat dit dashboard nooit uit sync kan raken met wat er echt verstuurd wordt.
		$volgendeRunAlloc = _batchreminders_build_allocation($blockedIds);
		wachthond($extdebug, 3, 'volgende_run_alloc', $volgendeRunAlloc);

		// 4.3 Omzettingstabel eenheid -> dagen, zodat "3 week" en "21 dag" op één schaal
		// vergelijkbaar zijn voor de sortering.
		static $eenheidNaarDagen = ['hour' => 1 / 24, 'day' => 1, 'week' => 7, 'month' => 30, 'year' => 365];

		$totaalPending	= 0;
		$rijen			= [];
		while ($dao_wachtrij->fetch()) {
			$id			= (int) $dao_wachtrij->id;
			$pending	= (int) $dao_wachtrij->pending;
			$totaalPending += $pending;

			$class		= _batchreminders_classify_title((string) $dao_wachtrij->title);
			$dag		= _batchreminders_id_to_day((int) $dao_wachtrij->oldest_id, $history, $vandaag);
			$urgentie	= _batchreminders_urgency_bucket(
				$dao_wachtrij->start_action_unit ?: NULL,
				$dao_wachtrij->start_action_offset === NULL ? NULL : (int) $dao_wachtrij->start_action_offset
			);
			$isBlocked	= in_array($id, $blockedIds, TRUE);

			$unit		= (string) ($dao_wachtrij->start_action_unit ?: 'day');
			$offset		= (int) ($dao_wachtrij->start_action_offset ?? 0);
			$offsetDagen= $offset * ($eenheidNaarDagen[$unit] ?? 1);

			// entity_value is bij deze schedules het kamp/event-type-ID (bv. KK1=11, JK2=24) —
			// kan komma-gescheiden zijn bij meerdere types op één schedule; neem de eerste voor sortering.
			$eventTypeId = (int) (explode(',', (string) $dao_wachtrij->entity_value)[0] ?? 0);

			$rijen[] = [
				'id'           => $id,
				'title'        => (string) $dao_wachtrij->title,
				'is_active'    => (bool) $dao_wachtrij->is_active,
				'pending'      => $pending,
				'wachtdag'     => $dag,
				'wacht_dagen'  => max(0, (int) round((strtotime($vandaag) - strtotime($dag)) / 86400)),
				'is_leiding'   => $class['leid'],
				'kamp_prio'    => $class['camp'],
				'urgentie'     => $urgentie,
				'geblokkeerd'  => $isBlocked,
				'volgende_run' => $isBlocked ? 0 : (int) ($volgendeRunAlloc[$id] ?? 0),
				'offset_label' => $offset . ' ' . $unit,
				'offset_dagen' => $offsetDagen,
				'event_type_id'=> $eventTypeId,
				'template_id'  => $dao_wachtrij->msg_template_id !== NULL ? (int) $dao_wachtrij->msg_template_id : NULL,
				'template_titel' => (string) ($dao_wachtrij->msg_title ?? ''),
			];
		}

		// 4.4 Sorteren: langste vooruitloop (offset) eerst — zelfde volgorde als de echte
		// verzendprioriteit in _batchreminders_rank_and_allocate() — en daarbinnen op event-type-ID.
		usort($rijen, function($a, $b) {
			return [$b['offset_dagen'], $a['event_type_id']]
			   <=> [$a['offset_dagen'], $b['event_type_id']];
		});

		wachthond($extdebug, 3, 'rijen', $rijen);

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 5.0 RECENTE VERZENDINGEN",       "[QUERY]");
		wachthond($extdebug, 2, "########################################################################");

		// 5.0 Laatste 3 uur aan daadwerkelijk verstuurde reminders, per schedule geaggregeerd —
		// geeft een "golf"-beeld van wat de laatste cron-runs hebben afgehandeld.
		$sql_recent = "
			SELECT S.id, S.title, S.start_action_unit, S.start_action_offset, S.entity_value,
			       COUNT(*) AS aantal, MAX(L.action_date_time) AS laatste
			FROM   civicrm_action_log      L
			JOIN   civicrm_action_schedule S ON S.id = L.action_schedule_id
			WHERE  L.action_date_time >= (NOW() - INTERVAL 3 HOUR)
			GROUP  BY S.id
		";
		wachthond($extdebug, 7, 'sql_recent', $sql_recent);
		$dao_recent = CRM_Core_DAO::executeQuery($sql_recent);
		$recent = [];
		while ($dao_recent->fetch()) {
			$class	= _batchreminders_classify_title((string) $dao_recent->title);
			$unit	= (string) ($dao_recent->start_action_unit ?: 'day');
			$offset	= (int) ($dao_recent->start_action_offset ?? 0);

			$recent[] = [
				'title'        => (string) $dao_recent->title,
				'aantal'       => (int) $dao_recent->aantal,
				'laatste'      => $dao_recent->laatste,
				'offset_label' => $offset . ' ' . $unit,
				'offset_dagen' => $offset * ($eenheidNaarDagen[$unit] ?? 1),
				'kamp_prio'    => $class['camp'],
			];
		}

		// 5.1 Zelfde sortering als de wachtrij-tabel: langste vooruitloop eerst,
		// daarbinnen KK -> BK -> TK -> JK -> TOP (onbekend/niet-kamp vóóraan).
		usort($recent, function($a, $b) {
			return [$b['offset_dagen'], $a['kamp_prio']]
			   <=> [$a['offset_dagen'], $b['kamp_prio']];
		});
		wachthond($extdebug, 3, 'recent', $recent);

		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 1, "### BATCHREMINDERS [DASH] 6.0 ASSIGN",                     "[BUILD]");
		wachthond($extdebug, 2, "########################################################################");

		// 6.0 Naar template.
		$this->assign('laatsteVerstuurd',   $laatsteVerstuurd);
		$this->assign('minutenGeleden',     $minutenGeleden);
		$this->assign('inVenster',          $inVenster);
		$this->assign('cronWaarschuwing',   $cronWaarschuwing);
		$this->assign('volgendeRunTijd',     $volgendeRun['tijd']->format('Y-m-d H:i:s'));
		$this->assign('volgendeRunLabel',    $volgendeRunLabel);
		$this->assign('volgendeRunSeconden', $volgendeRun['secondenTot']);
		$this->assign('batchsize',          _batchreminders_batchsize());
		$this->assign('stateStatus',        $state['status']);
		$this->assign('stateAgeSec',        $ageSec);
		$this->assign('rijen',              $rijen);
		$this->assign('totaalPending',      $totaalPending);
		$this->assign('recent',             $recent);
		$this->assign('nu',                 date('Y-m-d H:i:s'));

		parent::run();
	}

	/**
	 * Berekent de volgende cron-tick voor de reminders-cron uit /etc/crontab:
	 *   (elke 2 min) 07-22 * * 1-6 ... sleep 45 && ... cron-civicrm-reminders.sh
	 *   (elke 2 min) 12-22 * * 0   ... sleep 45 && ... cron-civicrm-reminders.sh
	 * Loopt gewoon vooruit in de tijd tot de eerste tick ná "$vanaf" — bewust brute-force
	 * (max enkele duizend iteraties) in plaats van los te reken-hacken, zodat dit nooit uit
	 * sync raakt met de crontab-regel als die venstertijden ooit wijzigen.
	 */
	private static function volgendeReminderCronRun(DateTimeImmutable $vanaf): array {
		for ($dagOffset = 0; $dagOffset <= 8; $dagOffset++) {
			$dag		= $vanaf->modify("+{$dagOffset} day")->setTime(0, 0, 0);
			$dow		= (int) $dag->format('w'); // 0 = zondag
			$vensterStart	= ($dow === 0) ? 12 : 7;
			$vensterEind	= 22;

			for ($uur = $vensterStart; $uur <= $vensterEind; $uur++) {
				for ($minuut = 0; $minuut <= 58; $minuut += 2) {
					$tick = $dag->setTime($uur, $minuut, 45);
					if ($tick > $vanaf) {
						return [
							'tijd'        => $tick,
							'secondenTot' => $tick->getTimestamp() - $vanaf->getTimestamp(),
						];
					}
				}
			}
		}

		// Kan niet gebeuren binnen 8 dagen vooruitkijken, maar defensief toch een fallback.
		return ['tijd' => $vanaf, 'secondenTot' => 0];
	}

}
