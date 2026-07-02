<?php

/**
 * Unit tests voor _batchreminders_render_budget().
 *
 * Puur rekenwerk (geen Civi-bootstrap): verifieert dat het run-budget correct
 * over opeenvolgende schedule-queries wordt verdeeld en dat geblokkeerde
 * schedules altijd 0 krijgen.
 *
 * @group headless
 */
class Batchreminders_RenderBudgetTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    if (!defined('CIVICRM_SYSTEM')) {
      define('CIVICRM_SYSTEM', 1);
    }
    require_once __DIR__ . '/../../../batchreminders.php';
  }

  // ########################################################################
  // ### 1. EERSTE SCHEDULE KRIJGT HET VOLLE BUDGET
  // ########################################################################

  public function testEersteSchedule_KrijgtVolleBudget(): void {
    $this->assertEquals(25, _batchreminders_render_budget(25, 0, FALSE));
  }

  // ########################################################################
  // ### 2. VOLGENDE SCHEDULES KRIJGEN DE REST
  // ########################################################################

  public function testTweedeSchedule_KrijgtRestbudget(): void {
    // Schedule 1 kreeg al 25 → schedule 2 krijgt niets meer
    $this->assertEquals(0, _batchreminders_render_budget(25, 25, FALSE));
  }

  public function testGedeeltelijkVerbruikt_KrijgtVerschil(): void {
    // Schedule 1 had maar 10 wachtenden (granted=10) → schedule 2 mag nog 15
    // LET OP: granted telt wat is UITGEDEELD via LIMIT; had schedule 1 minder
    // rijen dan zijn LIMIT, dan is het budget tóch verbruikt (conservatief,
    // want de LIMIT was al aan de query gegeven vóór het resultaat bekend was).
    $this->assertEquals(15, _batchreminders_render_budget(25, 10, FALSE));
  }

  // ########################################################################
  // ### 3. GEBLOKKEERD SCHEDULE KRIJGT ALTIJD 0
  // ########################################################################

  public function testGeblokkeerdSchedule_KrijgtNul(): void {
    $this->assertEquals(0, _batchreminders_render_budget(25, 0, TRUE));
  }

  public function testGeblokkeerd_VerbruiktGeenBudget(): void {
    // Geblokkeerd schedule kreeg 0 → volgend gezond schedule heeft nog alles
    $granted = 0;
    $granted += _batchreminders_render_budget(25, $granted, TRUE);   // geblokkeerd: +0
    $this->assertEquals(25, _batchreminders_render_budget(25, $granted, FALSE));
  }

  // ########################################################################
  // ### 4. BUDGET KAN NIET NEGATIEF
  // ########################################################################

  public function testOverbesteed_GeeftNulNietNegatief(): void {
    $this->assertEquals(0, _batchreminders_render_budget(25, 30, FALSE));
  }

  // ########################################################################
  // ### 5. REALISTISCH SCENARIO: 8 SCHEDULES, BUDGET 25
  // ########################################################################

  public function testAchtSchedules_TotaalPreciesBatchsize(): void {
    // Nabootsing van de echte situatie (8 × 07_JD_4WEKEN_INFO_*): het totaal
    // uitgedeelde budget over alle schedules samen mag nooit boven batchsize komen.
    $granted = 0;
    foreach (range(1, 8) as $i) {
      $granted += _batchreminders_render_budget(25, $granted, FALSE);
    }
    $this->assertEquals(25, $granted, 'Totaal uitgedeeld budget = exact batchsize');
  }
}
