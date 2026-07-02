<?php

/**
 * Unit tests voor _batchreminders_render_budget().
 *
 * Puur rekenwerk (geen Civi-bootstrap): verifieert dat het run-budget correct
 * over opeenvolgende schedule-queries wordt verdeeld, begrensd op het werkelijke
 * aantal wachtenden per schedule, en dat geblokkeerde schedules altijd 0 krijgen.
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
  // ### 1. EERSTE SCHEDULE KRIJGT MAX ZIJN EIGEN WACHTRIJ
  // ########################################################################

  public function testEersteSchedule_MetGroteWachtrij_KrijgtVolleBudget(): void {
    $this->assertEquals(25, _batchreminders_render_budget(25, 0, FALSE, 126));
  }

  public function testEersteSchedule_MetKleineWachtrij_KrijgtAlleenWatNodig(): void {
    // Schedule heeft maar 3 wachtenden → claimt 3, niet 25.
    // (De bug die dit afdekt: schedule 23 claimde het volle budget met 0 wachtenden,
    // waardoor schedules 144-151 met een echte wachtrij LIMIT 0 kregen.)
    $this->assertEquals(3, _batchreminders_render_budget(25, 0, FALSE, 3));
  }

  public function testEersteSchedule_ZonderWachtenden_KrijgtNul(): void {
    $this->assertEquals(0, _batchreminders_render_budget(25, 0, FALSE, 0));
  }

  // ########################################################################
  // ### 2. VOLGENDE SCHEDULES KRIJGEN DE REST
  // ########################################################################

  public function testTweedeSchedule_KrijgtRestbudget(): void {
    // Schedule 1 verbruikte al 25 → schedule 2 krijgt niets meer
    $this->assertEquals(0, _batchreminders_render_budget(25, 25, FALSE, 104));
  }

  public function testGedeeltelijkVerbruikt_KrijgtVerschil(): void {
    // Schedule 1 claimde 10 (had 10 wachtenden) → schedule 2 mag nog 15
    $this->assertEquals(15, _batchreminders_render_budget(25, 10, FALSE, 104));
  }

  // ########################################################################
  // ### 3. GEBLOKKEERD SCHEDULE KRIJGT ALTIJD 0
  // ########################################################################

  public function testGeblokkeerdSchedule_KrijgtNul(): void {
    $this->assertEquals(0, _batchreminders_render_budget(25, 0, TRUE, 126));
  }

  public function testGeblokkeerd_VerbruiktGeenBudget(): void {
    // Geblokkeerd schedule kreeg 0 → volgend gezond schedule heeft nog alles
    $granted = 0;
    $granted += _batchreminders_render_budget(25, $granted, TRUE, 126);   // geblokkeerd: +0
    $this->assertEquals(25, _batchreminders_render_budget(25, $granted, FALSE, 104));
  }

  // ########################################################################
  // ### 4. BUDGET KAN NIET NEGATIEF
  // ########################################################################

  public function testOverbesteed_GeeftNulNietNegatief(): void {
    $this->assertEquals(0, _batchreminders_render_budget(25, 30, FALSE, 104));
  }

  // ########################################################################
  // ### 5. REALISTISCH SCENARIO: leeg schedule eerst, dan volle wachtrij
  // ########################################################################

  public function testLeegScheduleEerst_BudgetGaatNaarVolleSchedules(): void {
    // Nabootsing van de echte situatie op 2-jul-2026: schedule 23 (0 wachtend)
    // wordt vóór 144 (126 wachtend) verwerkt. Budget moet bij 144 landen.
    $granted = 0;
    $granted += _batchreminders_render_budget(25, $granted, FALSE, 0);     // schedule 23: +0
    $b144 = _batchreminders_render_budget(25, $granted, FALSE, 126);
    $this->assertEquals(25, $b144, 'Schedule 144 krijgt het volle budget');
    $granted += $b144;
    $this->assertEquals(0, _batchreminders_render_budget(25, $granted, FALSE, 104), 'Schedule 145 moet wachten op volgende run');
  }

  public function testMeerdereKleineSchedules_TotaalMaxBatchsize(): void {
    // 8 schedules met elk 10 wachtenden, budget 25 → 10 + 10 + 5 + 0×5
    $granted = 0;
    $per = [];
    foreach (range(1, 8) as $i) {
      $b = _batchreminders_render_budget(25, $granted, FALSE, 10);
      $per[] = $b;
      $granted += $b;
    }
    $this->assertEquals([10, 10, 5, 0, 0, 0, 0, 0], $per);
    $this->assertEquals(25, $granted, 'Totaal uitgedeeld = exact batchsize');
  }
}
