# nl.onvergetelijk.batchreminders

Batchgewijze en gevalideerde verzending van CiviCRM Scheduled Reminders voor OZK.

Maintainer: Richard van Oosterhout <webteam@onvergetelijk.nl>

---

## Waarom deze extensie?

CiviCRM's standaard `job.send_reminder` verzendt alle openstaande reminders in één run, zonder limiet. Bij OZK levert dat drie problemen op:

1. **Overbelasting** — een grote batch kan de SMTP-server of het geheugen overbelasten.
2. **Ongeldige templates** — een gebroken template (Smarty-fout of wees-token) gaat er zonder waarschuwing uit.
3. **Duplicaten na deadlock** — als een run afbreekt door een MySQL-deadlock, blijven `action_log`-rijen op `action_date_time = NULL` staan; de volgende run ziet die contacten opnieuw als "nog niet verstuurd" en stuurt opnieuw.

Deze extensie pakt alle drie aan via de `alterMailParams`-hook.

---

## Wat doet de extensie?

### 1. Batch-limiet (`$batchLimit = 25`)

Per cron-run worden maximaal 25 reminders doorgelaten. De overige worden geaborteerd (`$params['abort'] = TRUE`) en worden automatisch opgepakt in de volgende run. Zo worden lange runs geknipt in beheersbare stukken.

### 2. Template-validatie bij opstart

Bij de eerste mail van een run wordt de volledige actieve cluster gevalideerd (alle `action_schedule`-records met openstaande `action_log`-entries):

- **Markup-check** — `civicrm_templates_markup.sh -q -c s {template_id}`
- **Token-check** — `civicrm_templates_tokens.sh -q {template_id}`

Templates die een van deze checks niet doorstaan worden **geblokkeerd**: hun schedules worden voor deze run overgeslagen en er gaat een alert-mail naar webteam.

#### Validatie-cache

Validatie kost ~9 seconden per template (shell-exec). Om dat bij iedere run te vermijden wordt het resultaat gecached in `/tmp/batchreminders_valid_{id}.ok`. De cache is geldig zolang:
- het bestand jonger is dan 6 uur, **en**
- de template niet is gewijzigd na de cache-aanmaak.

Bij cache-hit wordt de exec overgeslagen en is de check <1 ms.

### 3. Template-sync

Templates die de validatie halen worden automatisch gesynchroniseerd: de inhoud van `civicrm_msg_template` (de bewerkbare bron) wordt naar `civicrm_action_schedule` (de werkkopie) geschreven als ze verschillen. Zo loopt de reminder altijd op de actuele versie.

---

## De ACL-deadlock en het duplicaten-probleem

### Achtergrond

CiviCRM pre-inserteert `action_log`-rijen met `action_date_time = NULL` vóórdat een mail de deur uitgaat. Na verzending wordt de timestamp ingevuld. De eligibility-query controleert op timestamp: `NULL` = nog niet verstuurd.

Bij een nieuwe inschrijving (registratie → groep-toevoeging → ACL-cache-flush) voerde CiviCRM een `TRUNCATE TABLE civicrm_acl_contact_cache` uit. Dat is DDL: het veroorzaakt een impliciete commit en een tabel-metadata-versie-bump. Een gelijktijdige reminder-run die dezelfde tabel leest, krijgt MySQL-fout **1412** ("Table definition has changed") of **1213** (deadlock) en breekt af — vóórdat de timestamps zijn ingevuld. Alle NULL-entries van die run blijven staan.

### Hoe dit opgelost is

Drie lagen, van preventief naar reactief:

| Laag | Wat | Waar |
|------|-----|------|
| **1. Root cause** | `TRUNCATE` → `DELETE` in `CRM/ACL/BAO/Cache.php` (OZK M62) | Core-patch, geregistreerd in `patch-civicrm-core.sh` |
| **2. Nachtelijk vangnet** | Sqltask 238 — dagelijks 02:30, stempelt stale NULLs, alert als count > 0 | CiviCRM sqltasks |
| **3. Near-realtime monitor** | `cron-civicrm-reminder-nullfix.sh` — elke 30 min, checkt flock-slot van reminder-job | `/usr/local/bin/maintenance/` |

#### Optionele crontab-regel voor laag 3

```
*/30 * * * * webteam /usr/local/bin/maintenance/cron-runner.sh none /usr/local/bin/maintenance/cron-civicrm-reminder-nullfix.sh
```

De monitor gebruikt een state-file (`/tmp/nullfix_max_id.state`) om alleen entries aan te raken die er al waren vóór de vorige run, en slaat een run over als de reminder-job actief is (`flock -n /tmp/civi_reminder.lock`).

---

## Bestandsstructuur

```
batchreminders.php              Hook-implementaties + hulpfuncties
tests/phpunit/
  Batchreminders/
    StartupTest.php             7 unit-tests voor _batchreminders_startup()
```

### Centrale functies

| Functie | Doel |
|---------|------|
| `batchreminders_civicrm_alterMailParams()` | Hook-entry: telt, valideert, limiteert per run |
| `_batchreminders_get_cluster()` | Haalt actieve template+schedule-combinaties op uit de wachtrij |
| `_batchreminders_startup()` | Valideert templates, bouwt ok/blocked-lijsten, schrijft cache |
| `_batchreminders_send_alert()` | Stuurt HTML-alert bij validatiefouten |

### Debug-kanalen (`ozk.debug.config.php`)

| Kanaal | Drempel |
|--------|---------|
| `batchreminders` | 3 |

---

## Tests uitvoeren

```bash
cd nl.onvergetelijk.batchreminders
CIVICRM_UF="UnitTests" phpunit9 --configuration=phpunit.xml.dist
```

De tests gebruiken een `$execFn`-callback zodat er geen DB of shell-aanroepen nodig zijn. De validatie-cache wordt alleen op het productiepad geschreven (`$execFn === NULL`), niet in tests.

---

## Versiehistorie

| Versie | Datum | Wijziging |
|--------|-------|-----------|
| 3.0.0 | 2026-05-14 | Initiële release |
| 3.1.0 | 2026-06-27 | Batch 25 (was 10), validatie-cache (6u TTL), debug-level fix (4→3), totalRemaining filtert inactieve schedules; ACL deadlock-fix (M62) + sqltask 238 + monitor-script |
