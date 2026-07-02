<?php

/**
 * Unit tests voor _batchreminders_parse_blocked_state().
 *
 * De prewarm-state-file is de brug tussen de prewarm-cron (schrijft) en de
 * alterMailParams-hook (leest). Deze tests verifiëren het fail-open contract:
 * bij ELKE afwijking (geen file, corrupt, verkeerd formaat) wordt er niets
 * geblokkeerd — liever een kapotte template versturen dan de keten stilleggen.
 *
 * @group headless
 */
class Batchreminders_BlockedStateTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    if (!defined('CIVICRM_SYSTEM')) {
      define('CIVICRM_SYSTEM', 1);
    }
    require_once __DIR__ . '/../../../batchreminders.php';
  }

  // ########################################################################
  // ### 1. GEZONDE STATE
  // ########################################################################

  public function testVerseStateMetBlokkades(): void {
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":[144,145],"generated_at":"2026-07-02 16:00:00"}', 60, 1200);
    $this->assertEquals('ok', $r['status']);
    $this->assertEquals([144, 145], $r['blocked']);
  }

  public function testVerseStateZonderBlokkades(): void {
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":[],"generated_at":"2026-07-02 16:00:00"}', 60, 1200);
    $this->assertEquals('ok', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }

  public function testIdsWordenIntegers(): void {
    // JSON kan strings bevatten (bv. na handmatig editen) — altijd casten
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":["144","145"]}', 60, 1200);
    $this->assertSame([144, 145], $r['blocked']);
  }

  // ########################################################################
  // ### 2. VEROUDERDE STATE (prewarm-cron gestopt?)
  // ########################################################################

  public function testOudeState_LijstWelGebruikt_MaarStale(): void {
    // Bewuste keuze: een verouderde blocked-lijst is beter dan geen —
    // de status 'stale' zorgt alleen voor een error in de log.
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":[144]}', 3600, 1200);
    $this->assertEquals('stale', $r['status']);
    $this->assertEquals([144], $r['blocked'], 'Verouderde lijst wordt wél gebruikt');
  }

  public function testPrecies_OpDeDrempel_IsNogOk(): void {
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":[]}', 1200, 1200);
    $this->assertEquals('ok', $r['status']);
  }

  // ########################################################################
  // ### 3. FAIL-OPEN: ALLES WAT AFWIJKT BLOKKEERT NIETS
  // ########################################################################

  public function testGeenFile_BlokkeertNiets(): void {
    $r = _batchreminders_parse_blocked_state(NULL, 0, 1200);
    $this->assertEquals('missing', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }

  public function testCorrupteJson_BlokkeertNiets(): void {
    $r = _batchreminders_parse_blocked_state('{{{niet-json', 60, 1200);
    $this->assertEquals('corrupt', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }

  public function testLegeFile_BlokkeertNiets(): void {
    $r = _batchreminders_parse_blocked_state('', 60, 1200);
    $this->assertEquals('corrupt', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }

  public function testJsonZonderBlockedIdsKey_BlokkeertNiets(): void {
    $r = _batchreminders_parse_blocked_state('{"generated_at":"2026-07-02"}', 60, 1200);
    $this->assertEquals('corrupt', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }

  public function testBlockedIdsGeenArray_BlokkeertNiets(): void {
    $r = _batchreminders_parse_blocked_state('{"blocked_ids":"144"}', 60, 1200);
    $this->assertEquals('corrupt', $r['status']);
    $this->assertEquals([], $r['blocked']);
  }
}
