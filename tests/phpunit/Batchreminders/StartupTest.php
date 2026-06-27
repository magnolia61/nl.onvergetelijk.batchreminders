<?php

/**
 * Unit tests voor _batchreminders_startup().
 *
 * Test de validatie- en blokkeerlogica zonder echte shell-aanroepen of DB.
 * De $execFn callback simuleert de markup/token scripts.
 *
 * @group headless
 */
class Batchreminders_StartupTest extends \PHPUnit\Framework\TestCase {

  /**
   * Laad de functies uit batchreminders.php zonder CIVICRM_SYSTEM-check.
   * Definieer de constante eenmalig zodat de include-guard niet blokkeert.
   */
  public static function setUpBeforeClass(): void {
    if (!defined('CIVICRM_SYSTEM')) {
      define('CIVICRM_SYSTEM', 1);
    }
    require_once __DIR__ . '/../../../batchreminders.php';
  }

  // ########################################################################
  // ### HELPERS
  // ########################################################################

  /**
   * Bouwt een fake $execFn die per commando-fragment een vaste exit-code geeft.
   *
   * @param  array $exitMap  ['markup' => int, 'tokens' => int]  (standaard alles 0)
   * @return callable
   */
  private function makeExecFn(array $exitMap = []): callable {
    $markupExit = $exitMap['markup'] ?? 0;
    $tokenExit  = $exitMap['tokens'] ?? 0;
    return function(string $cmd) use ($markupExit, $tokenExit): array {
      if (strpos($cmd, 'markup') !== FALSE) {
        return ['output' => $markupExit !== 0 ? ['Markup fout'] : [], 'exit' => $markupExit];
      }
      return ['output' => $tokenExit !== 0 ? ['Token fout'] : [], 'exit' => $tokenExit];
    };
  }

  /**
   * Bouwt een minimale $clusterTemplates array.
   */
  private function makeCluster(array $templates): array {
    $cluster = [];
    foreach ($templates as $t) {
      $cluster[$t['id']] = [
        'title'        => $t['title'],
        'schedule_ids' => $t['schedule_ids'],
      ];
    }
    return $cluster;
  }

  // ########################################################################
  // ### 1. ALLES OK
  // ########################################################################

  public function testAllesOk_GeenBlockedIds(): void {
    $cluster = $this->makeCluster([
      ['id' => 10, 'title' => 'Reminder A', 'schedule_ids' => [1, 2]],
      ['id' => 20, 'title' => 'Reminder B', 'schedule_ids' => [3]],
    ]);

    $result = _batchreminders_startup($cluster, '/scripts', $this->makeExecFn());

    $this->assertEmpty($result['blocked_ids'],  'Geen blocked_ids verwacht bij alle OK');
    $this->assertEmpty($result['errors'],        'Geen errors verwacht bij alle OK');
    $this->assertContains(10, $result['ok_ids'], 'Template 10 moet in ok_ids zitten');
    $this->assertContains(20, $result['ok_ids'], 'Template 20 moet in ok_ids zitten');
  }

  // ########################################################################
  // ### 2. MARKUP FOUT
  // ########################################################################

  public function testMarkupFout_BlockeertJuisteSchedules(): void {
    $cluster = $this->makeCluster([
      ['id' => 10, 'title' => 'Reminder A', 'schedule_ids' => [1, 2]],
      ['id' => 20, 'title' => 'Reminder B', 'schedule_ids' => [3]],
    ]);

    // Template 10 heeft markup-fout; template 20 is OK
    $execFn = function(string $cmd) {
      if (strpos($cmd, '10') !== FALSE && strpos($cmd, 'markup') !== FALSE) {
        return ['output' => ['Smarty: onbekende variabele'], 'exit' => 1];
      }
      return ['output' => [], 'exit' => 0];
    };

    $result = _batchreminders_startup($cluster, '/scripts', $execFn);

    $this->assertContains(1, $result['blocked_ids'], 'Schedule 1 moet geblokkeerd zijn');
    $this->assertContains(2, $result['blocked_ids'], 'Schedule 2 moet geblokkeerd zijn');
    $this->assertNotContains(3, $result['blocked_ids'], 'Schedule 3 (gezond template) mag niet geblokkeerd zijn');
    $this->assertArrayHasKey(10, $result['errors'],  'Template 10 moet in errors staan');
    $this->assertNotContains(10, $result['ok_ids'],  'Template 10 mag niet in ok_ids staan');
    $this->assertContains(20, $result['ok_ids'],     'Template 20 moet in ok_ids staan');
  }

  // ########################################################################
  // ### 3. TOKEN FOUT
  // ########################################################################

  public function testTokenFout_BlockeertJuisteSchedules(): void {
    $cluster = $this->makeCluster([
      ['id' => 30, 'title' => 'Reminder C', 'schedule_ids' => [5]],
    ]);

    $result = _batchreminders_startup($cluster, '/scripts', $this->makeExecFn(['tokens' => 1]));

    $this->assertContains(5, $result['blocked_ids']);
    $this->assertArrayHasKey(30, $result['errors']);
    $this->assertEquals(0, $result['errors'][30]['markup_exit'], 'Markup moet OK zijn');
    $this->assertEquals(1, $result['errors'][30]['token_exit'],  'Token moet fout zijn');
    $this->assertEmpty($result['ok_ids']);
  }

  // ########################################################################
  // ### 4. BEIDE FOUT
  // ########################################################################

  public function testMarkupEnTokenFout_BeideGelogd(): void {
    $cluster = $this->makeCluster([
      ['id' => 40, 'title' => 'Reminder D', 'schedule_ids' => [7, 8]],
    ]);

    $result = _batchreminders_startup($cluster, '/scripts', $this->makeExecFn(['markup' => 1, 'tokens' => 1]));

    $this->assertEquals(1, $result['errors'][40]['markup_exit']);
    $this->assertEquals(1, $result['errors'][40]['token_exit']);
    $this->assertCount(2, $result['blocked_ids']);
    $this->assertEmpty($result['ok_ids']);
  }

  // ########################################################################
  // ### 5. LEGE CLUSTER (geen reminders in wachtrij)
  // ########################################################################

  public function testLegeCluster_GeeftLeegResultaat(): void {
    $result = _batchreminders_startup([], '/scripts', $this->makeExecFn());

    $this->assertEmpty($result['ok_ids']);
    $this->assertEmpty($result['blocked_ids']);
    $this->assertEmpty($result['errors']);
  }

  // ########################################################################
  // ### 6. MEERDERE TEMPLATES, EENTJE FOUT — schedule_ids correct gesplitst
  // ########################################################################

  public function testGedeeltelijkeFout_CorrecteSplitsing(): void {
    $cluster = $this->makeCluster([
      ['id' => 50, 'title' => 'Fout template',  'schedule_ids' => [10, 11]],
      ['id' => 51, 'title' => 'Gezond A',        'schedule_ids' => [12]],
      ['id' => 52, 'title' => 'Gezond B',        'schedule_ids' => [13, 14]],
    ]);

    $execFn = function(string $cmd) {
      if (strpos($cmd, '50') !== FALSE && strpos($cmd, 'markup') !== FALSE) {
        return ['output' => ['Fout in template 50'], 'exit' => 1];
      }
      return ['output' => [], 'exit' => 0];
    };

    $result = _batchreminders_startup($cluster, '/scripts', $execFn);

    $this->assertCount(2, $result['blocked_ids'], 'Alleen schedules van template 50 geblokkeerd');
    $this->assertContains(10, $result['blocked_ids']);
    $this->assertContains(11, $result['blocked_ids']);
    $this->assertCount(2, $result['ok_ids'], 'Templates 51 en 52 zijn OK');
    $this->assertContains(51, $result['ok_ids']);
    $this->assertContains(52, $result['ok_ids']);
    $this->assertCount(1, $result['errors'], 'Slechts één template in errors');
  }

  // ########################################################################
  // ### 7. ERROR BEVAT JUISTE VELDEN
  // ########################################################################

  public function testErrorStructuur_BevatVerplichtVelden(): void {
    $cluster = $this->makeCluster([
      ['id' => 60, 'title' => 'Broken reminder', 'schedule_ids' => [20]],
    ]);

    $execFn = function(string $cmd) {
      if (strpos($cmd, 'markup') !== FALSE) {
        return ['output' => ['regel 42: fout'], 'exit' => 1];
      }
      return ['output' => [], 'exit' => 0];
    };

    $result = _batchreminders_startup($cluster, '/scripts', $execFn);
    $err    = $result['errors'][60];

    $this->assertEquals(60,                 $err['template_id']);
    $this->assertEquals('Broken reminder',  $err['reminder']);
    $this->assertEquals([20],               $err['schedule_ids']);
    $this->assertEquals(1,                  $err['markup_exit']);
    $this->assertEquals(0,                  $err['token_exit']);
    $this->assertStringContainsString('regel 42', $err['markup_output']);
    $this->assertEmpty($err['token_output']);
  }
}
