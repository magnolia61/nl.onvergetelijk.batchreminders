<?php

/**
 * Unit tests voor _batchreminders_strip_test_marker().
 *
 * Regressie 5-jul-2026: een afgebroken mailtest.sh-run kan permanent een restmarker als
 * "[L084] ... [20260701_231629]" in civicrm_msg_template.msg_subject achterlaten. Deze
 * pure detectie/strip-functie is de gedeelde logica achter drie beveiligingslagen:
 *   1. _batchreminders_clean_test_markers()          — instant opschonen aan de bron
 *   2. _batchreminders_sync_templates() (SQL-vangnet) — dezelfde patronen, maar als
 *      REGEXP_REPLACE rechtstreeks in de UPDATE-query (niet via deze functie aanroepbaar)
 *   3. cssinliner_civicrm_alterMailParams()            — synchroon vangnet vóór verzending
 * Puur rekenwerk, geen Civi-bootstrap nodig.
 *
 * @group headless
 */
class Batchreminders_TestMarkerTest extends \PHPUnit\Framework\TestCase {

  public static function setUpBeforeClass(): void {
    if (!defined('CIVICRM_SYSTEM')) {
      define('CIVICRM_SYSTEM', 1);
    }
    require_once __DIR__ . '/../../../batchreminders.php';
  }

  public function testStriptPrefixEnSuffixSamen() {
    $result = _batchreminders_strip_test_marker('[L084] Kampinformatie BK2 2026 voor Julia Burger [20260701_231629]');
    $this->assertTrue($result['changed'], 'Restmarker moet gedetecteerd worden.');
    $this->assertEquals('Kampinformatie BK2 2026 voor Julia Burger', $result['subject']);
  }

  public function testStriptAlleenPrefixZonderSuffix() {
    $result = _batchreminders_strip_test_marker('[D162] Meld je nu aan voor het kamp, Piet');
    $this->assertTrue($result['changed']);
    $this->assertEquals('Meld je nu aan voor het kamp, Piet', $result['subject']);
  }

  public function testStriptAlleenSuffixZonderPrefix() {
    $result = _batchreminders_strip_test_marker('Meld je nu aan voor het kamp, Piet [20260701_003316]');
    $this->assertTrue($result['changed']);
    $this->assertEquals('Meld je nu aan voor het kamp, Piet', $result['subject']);
  }

  public function testSchoonOnderwerpBlijftOngewijzigd() {
    $result = _batchreminders_strip_test_marker('Kampinformatie BK2 2026 voor Julia Burger');
    $this->assertFalse($result['changed'], 'Een schoon onderwerp mag niet als gewijzigd gemeld worden.');
    $this->assertEquals('Kampinformatie BK2 2026 voor Julia Burger', $result['subject']);
  }

  /**
   * Een prefix-achtig patroon MIDDEN in een onderwerp (niet aan het begin) mag niet
   * gestript worden — voorkomt valse positieven op legitieme onderwerpen die toevallig
   * met blokhaken beginnen elders in de tekst.
   */
  public function testNietBeginPrefixWordtNietGestript() {
    $result = _batchreminders_strip_test_marker('Zie bijlage [L084] voor details');
    $this->assertFalse($result['changed'], 'Een [L084]-patroon dat niet aan het begin staat mag niet gestript worden.');
    $this->assertEquals('Zie bijlage [L084] voor details', $result['subject']);
  }

  /**
   * Een kortere/langere numerieke reeks dan precies 3 cijfers matcht niet — voorkomt
   * dat een legitiem onderwerp als "[A12] iets" of "[A1234] iets" per ongeluk gestript wordt.
   */
  public function testAndereCijferlengteWordtNietGestript() {
    $twee    = _batchreminders_strip_test_marker('[A12] Test onderwerp');
    $vier    = _batchreminders_strip_test_marker('[A1234] Test onderwerp');
    $this->assertFalse($twee['changed'], 'Twee cijfers i.p.v. drie mag niet matchen.');
    $this->assertFalse($vier['changed'], 'Vier cijfers i.p.v. drie mag niet matchen.');
  }

  /**
   * Een lege string levert geen fout op en blijft leeg.
   */
  public function testLegeStringGeeftLegeStringTerug() {
    $result = _batchreminders_strip_test_marker('');
    $this->assertFalse($result['changed']);
    $this->assertEquals('', $result['subject']);
  }

}
