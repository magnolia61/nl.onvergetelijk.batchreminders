<?php

/**
 * Unit tests voor de prioriteits- en budgetverdeling van de render-limiter.
 *
 * Puur rekenwerk (geen Civi-bootstrap): titel-classificatie, dag-herleiding uit
 * de nullfix-snapshothistorie, en de greedy budgetverdeling volgens de
 * verzendprioriteit: dag (oudste eerst) → niet-kamp/onbekend (camp 0, sinds 5-jul-2026
 * bewust vooraan) → kamp KK/BK/TK/JK/TOP → leiding laatst (alleen bínnen een herkend kamp).
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

  /**
   * Bouwt een schedule-regel voor de allocator ($urg default 2 = normaal).
   */
  private function sched(int $id, string $day, bool $leid, int $camp, int $pending, int $urg = 2): array {
    return ['id' => $id, 'day' => $day, 'urg' => $urg, 'leid' => $leid, 'camp' => $camp, 'pending' => $pending];
  }

  // ########################################################################
  // ### 1. TITEL-CLASSIFICATIE (kamp + deelnemer/leiding)
  // ########################################################################

  public function testClassificatie_DeelnemerKampen(): void {
    $this->assertEquals(['leid' => FALSE, 'camp' => 1], _batchreminders_classify_title('07_JD_4WEKEN_INFO_KK1'), 'KK-titel moet als deelnemer camp 1 classificeren');
    $this->assertEquals(['leid' => FALSE, 'camp' => 2], _batchreminders_classify_title('07_JD_4WEKEN_INFO_BK2'), 'BK-titel moet als deelnemer camp 2 classificeren');
    $this->assertEquals(['leid' => FALSE, 'camp' => 3], _batchreminders_classify_title('07_JD_4WEKEN_INFO_TK1'), 'TK-titel moet als deelnemer camp 3 classificeren');
    $this->assertEquals(['leid' => FALSE, 'camp' => 4], _batchreminders_classify_title('07_JD_4WEKEN_INFO_JK2'), 'JK-titel moet als deelnemer camp 4 classificeren');
  }

  public function testClassificatie_Leiding(): void {
    $this->assertTrue(_batchreminders_classify_title('17_JL_1WEEK_LEID_WK1')['leid'], 'JL-segment met LEID-woord moet als leiding herkend worden');
    $this->assertTrue(_batchreminders_classify_title('05_JL_MAIL1MEI_TIPS_LEID')['leid'], 'JL-segment met LEID-woord moet als leiding herkend worden');
    $this->assertFalse(_batchreminders_classify_title('01_JD_NA1WEEK_VOORLOPIG_KKBKTKJK')['leid'], 'JD-segment zonder LEID-woord mag niet als leiding herkend worden');
  }

  public function testClassificatie_GecombineerdeTitelPaktEersteKamp(): void {
    // KKBKTKJK-brede reminders matchen als KK (hoogste prioriteit van de set)
    $this->assertEquals(1, _batchreminders_classify_title('05_JD_MAIL1MEI_TIPS_KKBKTKJK')['camp'], 'Titel met alle kamp-tokens moet matchen op KK (hoogste prioriteit)');
  }

  // ########################################################################
  // ### 2. DAG-HERLEIDING UIT SNAPSHOTHISTORIE
  // ########################################################################

  public function testIdToDay_VindtVroegsteSnapshotDieDeRijKende(): void {
    $d1 = strtotime('2026-06-30 12:00');
    $d2 = strtotime('2026-07-01 12:00');
    $history = [[$d2, 5000], [$d1, 1000]];   // bewust ongesorteerd
    $this->assertEquals('2026-06-30', _batchreminders_id_to_day(900,  $history, '2026-07-02'), 'Rij-id 900 werd al gekend door de vroegste snapshot (2026-06-30)');
    $this->assertEquals('2026-07-01', _batchreminders_id_to_day(3000, $history, '2026-07-02'), 'Rij-id 3000 werd pas gekend door de latere snapshot (2026-07-01)');
  }

  public function testIdToDay_ZonderHistorieValtTerugOpVandaag(): void {
    $this->assertEquals('2026-07-02', _batchreminders_id_to_day(999999, [], '2026-07-02'), 'Zonder snapshothistorie moet teruggevallen worden op vandaag');
  }

  // ########################################################################
  // ### 2b. CHRONOLOGIE-EMMERS (verhaalvolgorde van de mailreeks)
  // ########################################################################

  public function testChronoBucket_VroegInDeReeks(): void {
    // Langste vooruitloop = chronologisch eerste mail van de reeks
    $this->assertEquals(1, _batchreminders_urgency_bucket('week', 4),  '4WEKEN-info = vroeg in de reeks');
    $this->assertEquals(1, _batchreminders_urgency_bucket('week', 28), '28 weken = vroeg in de reeks');
  }

  public function testChronoBucket_Midden(): void {
    $this->assertEquals(2, _batchreminders_urgency_bucket('day', 5), '5 dagen vooruitloop = midden in de reeks');
    $this->assertEquals(2, _batchreminders_urgency_bucket('day', 7), '7 dagen vooruitloop = midden in de reeks');
    $this->assertEquals(2, _batchreminders_urgency_bucket('week', 1), '1 week vooruitloop = midden in de reeks');
    $this->assertEquals(2, _batchreminders_urgency_bucket('week', 2), '2 weken vooruitloop = midden in de reeks');
    $this->assertEquals(2, _batchreminders_urgency_bucket(NULL, NULL), 'absolute-datum schedules = midden');
  }

  public function testChronoBucket_LaatInDeReeks(): void {
    $this->assertEquals(3, _batchreminders_urgency_bucket('hour', 30), '30 uur = laat in de reeks');
    $this->assertEquals(3, _batchreminders_urgency_bucket('day', 1),   '1 dag = laat in de reeks');
    $this->assertEquals(3, _batchreminders_urgency_bucket('day', 0),   'zelfde dag = laat in de reeks');
  }

  public function testChronologie_4WekenGaatVoorNA1DAG_OngeachtKamp(): void {
    // De mailreeks is een lopend verhaal: 4WEKEN KK vóór NA1DAG JK
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(42,  '2026-07-02', FALSE, 4, 8,   3),   // NA1DAG JK, laat in reeks
      $this->sched(144, '2026-07-02', FALSE, 1, 100, 1),   // 4WEKEN KK, vroeg in reeks
    ], 25);
    $this->assertEquals([144 => 25], $alloc, '4WEKEN (vroeg in de reeks) moet volledig vóór NA1DAG (laat) bediend worden, ongeacht kamp');
  }

  public function testOudereDag_WintVanChronologie(): void {
    // Dag blijft primair: NA1DAG van gisteren vóór 4WEKEN van vandaag
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(144, '2026-07-02', FALSE, 1, 30, 1),
      $this->sched(42,  '2026-07-01', FALSE, 4, 8,  3),
    ], 25);
    $this->assertEquals([42 => 8, 144 => 17], $alloc, 'Dag blijft primair boven chronologie: gisteren (42) eerst volledig bediend, rest naar vandaag (144)');
  }

  public function testGelijkeChronologie_KampvolgordeBeslist(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(149, '2026-07-02', FALSE, 3, 20, 1),    // TK, vroeg in reeks
      $this->sched(144, '2026-07-02', FALSE, 1, 20, 1),    // KK, vroeg in reeks
    ], 25);
    $this->assertEquals([144 => 20, 149 => 5], $alloc, 'Bij gelijke chronologie-emmer beslist de kampvolgorde: KK vóór TK');
  }

  // ########################################################################
  // ### 3. PRIORITEIT: DAG WINT VAN KAMP, KAMP WINT VAN ID
  // ########################################################################

  public function testOudereDag_WintVanKampvolgorde(): void {
    // JK van gisteren gaat vóór KK van vandaag
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(144, '2026-07-02', FALSE, 1, 100),   // KK, vandaag
      $this->sched(151, '2026-07-01', FALSE, 4, 10),    // JK, gisteren
    ], 25);
    $this->assertEquals([151 => 10, 144 => 15], $alloc, 'Oudere dag wint van kampvolgorde: JK van gisteren vóór KK van vandaag');
  }

  public function testBinnenZelfdeDag_KampvolgordeBeslist(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(150, '2026-07-02', FALSE, 4, 50),    // JK
      $this->sched(146, '2026-07-02', FALSE, 2, 50),    // BK
      $this->sched(144, '2026-07-02', FALSE, 1, 10),    // KK
    ], 25);
    // KK eerst (10), dan BK (15); JK moet wachten
    $this->assertEquals([144 => 10, 146 => 15], $alloc, 'Binnen dezelfde dag beslist de kampvolgorde KK > BK > JK');
  }

  public function testLeiding_KomtNaAlleDeelnemers(): void {
    // Leiding-KK van vandaag komt NA deelnemer-JK van vandaag
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(55,  '2026-07-02', TRUE,  1, 30),    // leiding
      $this->sched(150, '2026-07-02', FALSE, 4, 20),    // deelnemer JK
    ], 25);
    $this->assertEquals([150 => 20, 55 => 5], $alloc, 'Leiding komt na alle deelnemers, ook al is het kamp lager geprioriteerd');
  }

  public function testOudereLeiding_WintVanNieuwereDeelnemer(): void {
    // Dag blijft primair: leiding van gisteren gaat vóór deelnemer van vandaag
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(144, '2026-07-02', FALSE, 1, 100),
      $this->sched(55,  '2026-07-01', TRUE,  6, 5),
    ], 25);
    $this->assertEquals([55 => 5, 144 => 20], $alloc, 'Dag blijft primair: leiding van gisteren gaat vóór deelnemer van vandaag');
  }

  /**
   * Regressie 5-jul-2026 (JUBILEUM/FIETS-incident): het "deelnemer vóór leiding"-onderscheid
   * geldt alleen BÍNNEN een herkend kamp (camp>0). Een LEIDING-schedule zonder kamp-token
   * (camp=0, bv. "02_JL_24WEKEN_JUBILEUM_LEID_BIJNA") mag niet in de leiding-tier van een
   * échte kamp-leiding-mail terechtkomen — die tier komt pas ná ALLE deelnemer-kampen, en de
   * kleine losse wachtrij (typisch 1-2 wachtenden) kwam daardoor structureel nooit aan de beurt.
   */
  public function testNietKampLeiding_KomtVoorKampLeiding_NietErachter(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(150, '2026-07-02', FALSE, 4, 50),    // deelnemer JK
      $this->sched(160, '2026-07-02', TRUE,  4, 30),    // kamp-leiding JK (komt na alle deelnemers)
      $this->sched(142, '2026-07-02', TRUE,  0, 1),      // niet-kamp leiding (JUBILEUM-achtig)
    ], 25);
    // Niet-kamp-leiding (142) eerst volledig bediend (1), dan pas deelnemer-JK (24 van de 24 resterende).
    $this->assertEquals([142 => 1, 150 => 24], $alloc, 'Niet-kamp-leiding moet vóór kamp-deelnemers gaan, en kamp-leiding moet nog wachten');
    $this->assertArrayNotHasKey(160, $alloc, 'Kamp-leiding (achteraan haar eigen kamp-tier) mag hier nog niets krijgen');
  }

  // ########################################################################
  // ### 4. BUDGETVERDELING BLIJFT CORRECT
  // ########################################################################

  public function testTotaalNooitBovenBatchsize(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(144, '2026-07-02', FALSE, 1, 126),
      $this->sched(145, '2026-07-02', FALSE, 1, 104),
      $this->sched(146, '2026-07-02', FALSE, 2, 44),
    ], 25);
    $this->assertEquals(25, array_sum($alloc), 'De totale verdeling mag nooit boven de batchsize uitkomen');
    $this->assertEquals([144 => 25], $alloc, 'Grootste-prioriteit schedule wordt eerst volledig bediend');
  }

  public function testLegeSchedules_KrijgenNiets(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(23,  '2026-07-02', FALSE, 6, 0),
      $this->sched(144, '2026-07-02', FALSE, 1, 10),
    ], 25);
    $this->assertEquals([144 => 10], $alloc, 'Schedule met pending 0 mag geen budget krijgen');
    $this->assertArrayNotHasKey(23, $alloc, 'Lege schedule (pending 0) mag niet in de verdeling voorkomen');
  }

  public function testKleineWachtrijen_BudgetSchuiftDoor(): void {
    // 8 schedules met elk 10 wachtenden, budget 25 → 10 + 10 + 5
    $scheds = [];
    foreach (range(1, 8) as $i) {
      $scheds[] = $this->sched(100 + $i, '2026-07-02', FALSE, $i <= 5 ? $i : 6, 10);
    }
    $alloc = _batchreminders_rank_and_allocate($scheds, 25);
    $this->assertEquals([101 => 10, 102 => 10, 103 => 5], $alloc, 'Budget moet doorschuiven naar de volgende schedule in prioriteitsvolgorde zodra een wachtrij leeg is bediend');
  }

  public function testBatchsizeEen_MinimaleVerdeling(): void {
    $alloc = _batchreminders_rank_and_allocate([
      $this->sched(144, '2026-07-02', FALSE, 1, 100),
    ], 1);
    $this->assertEquals([144 => 1], $alloc, 'Bij batchsize 1 mag slechts één item verdeeld worden');
  }

  public function testGeenSchedules_LegeVerdeling(): void {
    $this->assertEquals([], _batchreminders_rank_and_allocate([], 25), 'Zonder schedules moet de verdeling leeg zijn');
  }

  // ########################################################################
  // ### 5. CLASSIFICATIE VAN ECHTE PRODUCTIE-TITELS (peiling 2-jul-2026)
  // ########################################################################

  public function testEchteTitels_Leiding(): void {
    // JL-segment én LEID-woord komen beide voor in productie
    $this->assertTrue(_batchreminders_classify_title('02_JL_24WEKEN_JUBILEUM_LEID_BIJNA')['leid'], 'Productietitel met JL-segment en LEID-woord moet als leiding herkend worden');
    $this->assertTrue(_batchreminders_classify_title('CHECK_FOTO_LEID')['leid'], 'Productietitel met LEID-woord moet als leiding herkend worden');
  }

  public function testEchteTitels_ZonderKampOfLeiding_VallenVooraan(): void {
    // Geen kamp-token en geen leiding-token → camp 0 ("onbekend"/niet-kamp).
    // Sinds 5-jul-2026 bewust VOORAAN (niet achteraan): dit zijn typisch kleine, losse
    // reminders (jubilea, fietshuur e.d.) die niet mogen verdrinken achter de veel
    // grotere kamp-wachtrijen in de greedy budgetverdeling.
    $this->assertEquals(['leid' => FALSE, 'camp' => 0], _batchreminders_classify_title('GEBED_WEEK1'), 'Titel zonder kamp- of leiding-token moet vooraan vallen (camp 0)');
    $this->assertEquals(0, _batchreminders_classify_title('Update Communication Preferences')['camp'], 'Titel zonder kamp-token moet vooraan vallen (camp 0)');
  }

  public function testEchteTitels_Keukenteam_GeenValsKampOfLeid(): void {
    // 'Keukenteam' bevat geen KK-substring ('K_K'/'euke') en geen LEID
    $klass = _batchreminders_classify_title('HANDBOEK_Keukenteam');
    $this->assertFalse($klass['leid'], "'Keukenteam' mag niet vals als leiding-titel matchen");
    $this->assertEquals(0, $klass['camp'], "'Keukenteam' mag niet vals als KK-kamp matchen en moet camp 0 (onbekend) krijgen");
  }

  public function testEchteTitels_TopkampHerkend(): void {
    $this->assertEquals(5, _batchreminders_classify_title('07_JD_4WEKEN_INFO_TOP')['camp'], 'TOP-titel moet als topkamp (camp 5) herkend worden');
  }

  // ########################################################################
  // ### 6. DAG-HERLEIDING: RANDGEVALLEN
  // ########################################################################

  public function testIdToDay_RijNieuwerDanAlleSnapshots_ValtTerugOpVandaag(): void {
    $d1 = strtotime('2026-06-30 12:00');
    // Rij-id 9999 is groter dan elke bekende max-id → geen snapshot kende hem
    $this->assertEquals('2026-07-02', _batchreminders_id_to_day(9999, [[$d1, 1000]], '2026-07-02'), 'Rij nieuwer dan alle snapshots moet terugvallen op vandaag');
  }

  public function testIdToDay_MeerdereSnapshotsZelfdeDag_PaktVroegste(): void {
    $ochtend = strtotime('2026-07-01 08:00');
    $middag  = strtotime('2026-07-01 14:00');
    $this->assertEquals('2026-07-01', _batchreminders_id_to_day(500, [[$middag, 900], [$ochtend, 600]], '2026-07-02'), 'Bij meerdere snapshots op dezelfde dag moet de vroegste snapshot die de rij al kende gepakt worden');
  }

  public function testIdToDay_ExactOpDeGrens(): void {
    $d1 = strtotime('2026-07-01 08:00');
    // max-id == rij-id: de snapshot kende de rij (>=), dus die dag telt
    $this->assertEquals('2026-07-01', _batchreminders_id_to_day(600, [[$d1, 600]], '2026-07-02'), 'Rij-id exact gelijk aan de snapshot max-id moet nog als gekend tellen (>=)');
  }

  // ########################################################################
  // ### 7. CHRONOLOGIE-EMMER: RANDGEVALLEN
  // ########################################################################

  public function testChronoBucket_MonthUnit(): void {
    $this->assertEquals(1, _batchreminders_urgency_bucket('month', 1), '1 maand = vroeg in de reeks');
  }

  public function testChronoBucket_OnbekendeUnit_TeltAlsDagen(): void {
    // Onbekende unit valt terug op 1 dag per eenheid (defensief)
    $this->assertEquals(2, _batchreminders_urgency_bucket('fortnight', 5), 'Onbekende tijdseenheid moet defensief als dagen geteld worden (midden)');
  }

  public function testChronoBucket_GrensExact2Dagen(): void {
    $this->assertEquals(3, _batchreminders_urgency_bucket('day', 2),  '2 dagen = nog laat in de reeks');
    $this->assertEquals(2, _batchreminders_urgency_bucket('day', 3),  '3 dagen = midden');
  }

  public function testChronoBucket_GrensExact2Weken(): void {
    $this->assertEquals(2, _batchreminders_urgency_bucket('week', 2), '14 dagen = nog midden');
    $this->assertEquals(1, _batchreminders_urgency_bucket('day', 15), '15 dagen = vroeg in de reeks');
  }

  // ########################################################################
  // ### 8. INTEGRAAL: DE PRODUCTIESITUATIE VAN 2-JUL-2026
  // ########################################################################

  public function testProductiescenario_2jul2026(): void {
    // De echte wachtrij van die dag: 8 × 4WEKEN (rustig) + stel dat er ook
    // een NA1WEEK (midden) en een 2DAGEN (laat) hadden gestaan.
    $scheds = [
      $this->sched(144, '2026-07-02', FALSE, 1, 126, 1),   // 4WEKEN KK1
      $this->sched(145, '2026-07-02', FALSE, 1, 104, 1),   // 4WEKEN KK2
      $this->sched(146, '2026-07-02', FALSE, 2, 44,  1),   // 4WEKEN BK1
      $this->sched(23,  '2026-07-02', FALSE, 1, 12,  2),   // NA1WEEK (midden)
      $this->sched(42,  '2026-07-02', FALSE, 1, 6,   3),   // 2DAGEN (laat)
      $this->sched(55,  '2026-07-02', TRUE,  1, 40,  1),   // leiding
    ];
    $alloc = _batchreminders_rank_and_allocate($scheds, 25);
    // Verhaalvolgorde: eerst de 4WEKEN-reeks (vroegst), binnen die emmer KK1 eerst.
    $this->assertEquals([144 => 25], $alloc, 'Verhaalvolgorde: 4WEKEN-reeks (vroegst) eerst, binnen die emmer KK1 eerst');

    // Als KK1 leeg is (pending 0), schuift alles door: KK2 aan de beurt.
    $scheds[0]['pending'] = 0;
    $alloc = _batchreminders_rank_and_allocate($scheds, 25);
    $this->assertEquals([145 => 25], $alloc, 'Bij lege KK1 (pending 0) schuift het budget door naar KK2');
  }
}
