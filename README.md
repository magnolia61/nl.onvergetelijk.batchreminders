# nl.onvergetelijk.batchreminders

Batchgewijze, geprioriteerde verzending van CiviCRM Scheduled Reminders — met de
render-limiter als kern: niet alleen het *verzenden* maar ook het *renderen* is
per cron-run begrensd.

Maintainer: Richard van Oosterhout <webteam@onvergetelijk.nl>

**Versie 4.0 (2-jul-2026)** — volledige herbouw na het incident van die dag
(zie "Geschiedenis" onderaan).

---

## Waarom deze extensie?

CiviCRM core rendert per schedule **alle** wachtende ontvangers in één keer
(`TokenProcessor->evaluate()` over de volledige set) vóórdat de eerste mail
verstuurd wordt. Bij OZK betekent dat: 126 ontvangers × het zware
Smarty-headerwerk (~60KB, honderden tokens) = minuten aan CPU per schedule,
terwijl de MySQL-connectie openstaat. Dat gaf historisch drie soorten ellende:

1. **"MySQL server has gone away"** midden in een run (idle connecties geoogst,
   monit-kills op CPU-verbruik, OOM);
2. **Weggegooid werk** — een naïeve verzend-limiet (alleen in `alterMailParams`)
   kapt pas af NÁ het renderen, dus al dat werk werd elke run herhaald;
3. **Duplicaten** wanneer een afgebroken run zijn action_log-administratie niet
   kon afronden.

---

## Architectuur

```
┌─ prewarm-cron (elke 5 min, eigen proces) ──────────────────────────┐
│  valideert templates (exec markup/tokens-scripts, traag bij cold   │
│  cache), synct template → schedule, schrijft state-file            │
└──────────────────────────┬─────────────────────────────────────────┘
                           │  /tmp/batchreminders_blocked_schedules.json
                           ▼
┌─ job.send_reminder (elke 2 min, via cron-runner + lock) ───────────┐
│  1. civi.actionSchedule.prepareMailingQuery-listener                │
│     → bouwt ÉÉN keer per run de prioriteitsranking + budget-        │
│       verdeling en zet LIMIT op elke schedule-query                 │
│  2. alterMailParams-hook                                            │
│     → telt verzendingen; zelfde batchsize als VANGNET               │
└─────────────────────────────────────────────────────────────────────┘
```

### 1. Render-limiter (`_batchreminders_limit_mailing_query`)

Listener op `civi.actionSchedule.prepareMailingQuery`. Zet een `LIMIT` op de
wachtrij-query zodat core nooit meer ontvangers ophaalt (en rendert) dan het
run-budget. Niet-geselecteerde rijen behouden `action_date_time = NULL` en
komen automatisch in de volgende run aan de beurt.

### 2. Prioriteitsranking (`_batchreminders_rank_and_allocate`)

Het budget wordt greedy verdeeld in deze volgorde:

1. **Oudste wachtende dag eerst** (dag-niveau; herleid uit de
   nullfix-snapshothistorie `/tmp/nullfix_max_id.history`, want
   `civicrm_action_log` heeft geen aanmaakdatum-kolom).
2. **Chronologie-emmer van het reminder-type** — de mailreeks per kamp is een
   lopend verhaal (28wk → 4wk-info → 1wk → 2dgn → 1dag). Langste vooruitloop
   eerst: emmer 1 = >2 weken, emmer 2 = ≤2 weken (ook absolute-datum-schedules),
   emmer 3 = ≤2 dagen. Grove emmers zodat een triviaal offset-verschil de
   kampvolgorde niet overruled.
3. **Kampvolgorde** KK → BK → TK → JK → TOP (herkend uit de schedule-titel;
   samenstellingen als `KKBKTKJK` tellen als hun hoogst geprioriteerde kamp).
4. **Leiding-templates** (JL/LEID in de titel) daarna.

### 3. Batchsize: één knop (`batchreminders_batchsize`, default 25)

Zelfde waarde voor render-limiet én verzend-vangnet — twee losse waarden zijn
alleen correct als ze gelijk staan. Instelbaar via `Civi::settings()`.
**Bij verhogen: de wrapper-timeout evenredig meeschalen** (zie Vangrails).

### 4. Prewarm (`_batchreminders_prewarm` / `bin/prewarm.php`)

De template-validatie (exec() naar `civicrm_templates_markup.sh`/`_tokens.sh`,
~9s per template en tot minuten bij een cold cache door hun interne
`timeout 180` naar html-validate) draait NIET in het verzendpad maar op een
eigen cron. Resultaat gaat via een atomisch geschreven state-file
(`/tmp/batchreminders_blocked_schedules.json`) naar de hooks. Geblokkeerde
schedules krijgen `LIMIT 0` — die worden dus niet eens gerenderd.

**Fail-open** bij ontbrekende of corrupte state: niets wordt geblokkeerd (wel
gelogd) — liever een kapotte template versturen dan de keten stilleggen. Bij
een **verouderde** state (prewarm-cron lijkt gestopt, > 20 min oud) geldt het
omgekeerde: de laatst bekende blocked-lijst blijft juist actief (wel gelogd
als error) — beter een oude blokkade aanhouden dan bij een gestopte prewarm
blind alles doorlaten.

### 5. Vangrails (defense in depth)

| Laag | Mechanisme | Grens |
|---|---|---|
| 1 | batchsize + render-limiter | run is per ontwerp kort (~2-4 min) |
| 2 | `timeout` in cron-civicrm-reminders.sh | 600s (formule: `3 × (10 + batchsize × 7)` sec) |
| 3 | monit CPU-check | 98% / 30 cycli (~60 min) |
| 4 | nullfix-cron | stempelt pas na **24 uur** onverstuurd (history-based) |

---

## Cron-opstelling

```
*/2  * * * *  cron-runner.sh /tmp/civi_reminder.lock  cron-civicrm-reminders.sh     # verzenden
*/5  * * * *  cron-runner.sh <eigen lock>             cron-civicrm-batchreminders-prewarm.sh
*/30 * * * *  cron-runner.sh none                     cron-civicrm-reminder-nullfix.sh
```

Doorvoer: 25 per ~4-5 min ≈ 300/uur — bewust een gestage druppel
(deliverability: geen bursts; Gmail bulk-drempels beginnen pas bij ~5000/dag).

## Monitoring

`/usr/local/bin/templates/civicrm_templates_dashboard.sh` (of `-l` voor live)
toont wachtrij per schedule, cron-status, locks, prewarm-state, geheugen/DB en
een verwachting in gewone taal.

---

## Bestandsstructuur

```
batchreminders.php                      # alle hooks + pure functies
bin/prewarm.php                         # CLI-entrypoint prewarm (cv scr)
settings/Batchreminders.setting.php     # batchreminders_batchsize
tests/phpunit/Batchreminders/
  RenderBudgetTest.php                  # classificatie, chronologie, ranking, allocatie
  StartupTest.php                       # validatie-/blokkeerlogica (met exec-fake)
  BlockedStateTest.php                  # fail-open contract van de state-file
```

### Pure functies (headless testbaar, geen Civi/DB)

| Functie | Doel |
|---|---|
| `_batchreminders_classify_title()` | kamp (KK..TOP) + leiding uit schedule-titel |
| `_batchreminders_urgency_bucket()` | chronologie-emmer uit offset+unit |
| `_batchreminders_id_to_day()` | aanmaakdag van action_log-rij via snapshothistorie |
| `_batchreminders_rank_and_allocate()` | prioriteit + greedy budgetverdeling |
| `_batchreminders_parse_blocked_state()` | fail-open interpretatie prewarm-state |
| `_batchreminders_startup()` | validatie-orkestratie (exec injecteerbaar) |

## Tests uitvoeren

```bash
cd nl.onvergetelijk.batchreminders
phpunit9 --configuration=phpunit.xml.dist        # 49 tests, geen DB nodig
```

---

## Geschiedenis

- **V4.0 (2-jul-2026)** — render-limiter + prioriteitsranking + prewarm-cron +
  batchsize-setting. Aanleiding: reminders kwamen al dagen niet aan. De keten
  bleek op VIER punten tegelijk kapot: (1) core rendert alles vóór de eerste
  send en de oude cap kwam te laat; (2) monit killde "gezond drukke" runs op
  90% CPU / 5 cycli; (3) de 30-min-nullfix stempelde 442 legitieme wachtenden
  weg als "verzonden"; (4) `outBound_option` stond sinds 1-jul op 5
  (Redirect-to-Database) waardoor niets de server verliet. Alle vier gefixt;
  zie memory `reminder_keten_overhaul.md` voor het volledige verhaal.
- **V3.x (jun-2026)** — batch-cap in alterMailParams, template-validatie met
  /tmp-cache, template-sync, alert-mail bij validatiefouten.
- **V2/V1** — logging-hook; limiet in extern bash-script.
