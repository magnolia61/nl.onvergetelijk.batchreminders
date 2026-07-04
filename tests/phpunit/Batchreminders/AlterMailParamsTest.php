<?php

/**
 * Regressietest voor batchreminders_civicrm_alterMailParams().
 *
 * Vangt de key-mismatch-bug: de hook zette ooit $params['abort'] terwijl
 * CiviCRM-core (CRM_Utils_Mail::send) alleen $params['abortMailSend'] leest.
 * Met de foute key deed het vangnet dus helemaal niets — geen enkele
 * bestaande test riep deze hook ooit aan, dus niets ving het op.
 *
 * @runTestsInSeparateProcesses
 * @group headless
 */
class Batchreminders_AlterMailParamsTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    if (!defined('CIVICRM_SYSTEM')) {
      define('CIVICRM_SYSTEM', 1);
    }
    require_once __DIR__ . '/../../../batchreminders.php';
  }

  /**
   * Bouwt minimale, realistische $params voor één reminder-mail.
   */
  private function makeParams(int $scheduleId): array {
    return [
      'contact_id' => 392,
      'toEmail'    => 'test@example.org',
      'subject'    => 'Test reminder',
      'entity_id'  => $scheduleId,
    ];
  }

  public function testBinnenBatchlimiet_WordtNietGeaborteerd(): void {
    Civi::$stubSettings['batchreminders_batchsize'] = 2;

    $params = $this->makeParams(999999901);
    batchreminders_civicrm_alterMailParams($params, 'singleEmail');

    $this->assertArrayNotHasKey('abortMailSend', $params, 'Eerste mail binnen de batchlimiet mag niet geaborteerd worden');
  }

  public function testBatchlimietBereikt_ZetAbortMailSend(): void {
    // batchsize=1: de EERSTE mail wordt nog net toegelaten (sentCount 0 -> 1),
    // de TWEEDE mail in dezelfde run moet het vangnet raken.
    Civi::$stubSettings['batchreminders_batchsize'] = 1;

    $eerste = $this->makeParams(999999902);
    batchreminders_civicrm_alterMailParams($eerste, 'singleEmail');
    $this->assertArrayNotHasKey('abortMailSend', $eerste, 'Eerste mail (sentCount 0 -> 1) valt nog binnen batchsize 1');

    $tweede = $this->makeParams(999999902);
    batchreminders_civicrm_alterMailParams($tweede, 'singleEmail');

    // Dit is de exacte key die CRM_Utils_Mail::send() controleert (regel 197):
    // "if (!empty($params['abortMailSend']) || empty($params['toEmail'])) return FALSE;"
    $this->assertArrayHasKey('abortMailSend', $tweede, 'Tweede mail na het bereiken van de batchlimiet moet het vangnet raken');
    $this->assertTrue($tweede['abortMailSend'], 'abortMailSend moet TRUE zijn zodra CRM_Utils_Mail::send() de verzending moet afbreken');
    $this->assertArrayNotHasKey('abort', $tweede, "Regressie-check: de oude, foute key 'abort' (die core niet leest) mag niet terugkomen");
  }

}
