<?php

/**
 * Settings voor nl.onvergetelijk.batchreminders.
 *
 * batchreminders_batchsize is bewust ÉÉN knop voor zowel de render-limiet
 * (civi.actionSchedule.prepareMailingQuery → LIMIT op de wachtrij-query) als de
 * verzend-limiet (alterMailParams-vangnet). Twee losse waarden zijn alleen correct
 * als ze gelijk staan: renderen zonder verzenden is pure verspilling (de dure fase
 * wordt weggegooid en volgende run herhaald), verzenden zonder renderen kan niet.
 *
 * LET OP bij verhogen: de timeout in cron-civicrm-reminders.sh moet evenredig mee
 * (vuistregel: timeout ≈ 3 × (10 + batchsize × 7) seconden; 25 → 600s).
 */
return [
	'batchreminders_batchsize' => [
		'group_name'	=> 'Batchreminders Preferences',
		'group'			=> 'batchreminders',
		'name'			=> 'batchreminders_batchsize',
		'type'			=> 'Integer',
		'default'		=> 25,
		'add'			=> '3.1',
		'title'			=> 'Batchgrootte scheduled reminders (render + verzend per cron-run)',
		'is_domain'		=> 1,
		'is_contact'	=> 0,
		'description'	=> 'Maximaal aantal reminders dat per send_reminder-cronrun wordt gerenderd én verzonden. Timeout in cron-civicrm-reminders.sh evenredig meeschalen bij wijzigen.',
		'help_text'		=> 'Eén knop voor zowel de render-LIMIT als het verzend-vangnet, zodat die nooit uit elkaar kunnen lopen.',
	],
];
