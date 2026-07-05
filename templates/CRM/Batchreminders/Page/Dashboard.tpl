<div class="crm-content-block batchreminders-dashboard">

  <style>
    .brd-grid          { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 16px; }
    .brd-card          { background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 10px 16px; box-sizing: border-box; }
    .brd-card h3       { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; color: #666; }
    .brd-card .val     { font-size: 22px; font-weight: bold; }
    .brd-ok            { color: #2e7d32; }
    .brd-warn          { color: #b71c1c; }
    .brd-table         { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .brd-table th, .brd-table td { border: 1px solid #ddd; padding: 4px 8px; font-size: 13px; text-align: left; }
    .brd-table th      { background: #f0f0f0; }
    .brd-blocked       { background: #fdecea; }
    .brd-inactive      { color: #999; font-style: italic; }
    .brd-pill          { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; }
    .brd-pill-kk       { background: #e3f2fd; } .brd-pill-bk { background: #e8f5e9; }
    .brd-pill-tk       { background: #fff3e0; } .brd-pill-jk { background: #f3e5f5; }
    .brd-pill-top      { background: #ffe0b2; } .brd-pill-onbekend { background: #eceff1; }
    .brd-pill-leiding  { background: #eee; border: 1px solid #ccc; }
    .brd-refresh       { font-size: 12px; color: #666; }
  </style>

  <div class="brd-grid">

    <div class="brd-card">
      <h3>{ts}Laatste verzending{/ts}</h3>
      {if $laatsteVerstuurd}
        <div class="val {if $cronWaarschuwing}brd-warn{else}brd-ok{/if}">{$minutenGeleden} min geleden</div>
        <div>{$laatsteVerstuurd}</div>
      {else}
        <div class="val brd-warn">{ts}nooit{/ts}</div>
      {/if}
    </div>

    <div class="brd-card">
      <h3>{ts}Cron-venster{/ts}</h3>
      <div class="val {if $inVenster}brd-ok{/if}">{if $inVenster}actief{else}buiten venster{/if}</div>
      <div>ma-za 07-22u · zo 12-22u (elke 2 min)</div>
    </div>

    <div class="brd-card">
      <h3>{ts}Volgende cron-run{/ts}</h3>
      <div class="val">{$volgendeRunTijd|substr:11:5}</div>
      <div>over <span id="brd-countdown-run">{$volgendeRunLabel}</span> · {$volgendeRunTijd}</div>
    </div>

    <div class="brd-card">
      <h3>{ts}Batchgrootte{/ts}</h3>
      <div class="val">{$batchsize}</div>
      <div>per cron-run (render + verzend)</div>
    </div>

    <div class="brd-card">
      <h3>{ts}Totaal in wachtrij{/ts}</h3>
      <div class="val {if $totaalPending > 0}brd-warn{else}brd-ok{/if}">{$totaalPending}</div>
      <div>over {$rijen|@count} schedule(s)</div>
    </div>

    <div class="brd-card">
      <h3>{ts}Prewarm-validatiestate{/ts}</h3>
      <div class="val {if $stateStatus eq 'ok'}brd-ok{else}brd-warn{/if}">{$stateStatus}</div>
      <div>{if $stateAgeSec ne null}{$stateAgeSec}s oud{else}geen state-file{/if}</div>
    </div>

  </div>

  {if $cronWaarschuwing}
    <div class="messages status error-alert">
      ⚠ {ts}Er is meer dan 20 minuten niets verstuurd terwijl we binnen het reminder-cronvenster zitten — check /var/log/cron/cron-runner.log en of de reminders-cron nog draait.{/ts}
    </div>
  {/if}

  <h2>{ts}Wachtrij per schedule{/ts}</h2>
  <table class="brd-table">
    <thead>
      <tr>
        <th>{ts}Reminder-ID{/ts}</th>
        <th>{ts}Schedule{/ts}</th>
        <th>{ts}Template-ID{/ts}</th>
        <th>{ts}Template-titel{/ts}</th>
        <th>{ts}Offset{/ts}</th>
        <th>{ts}Event-type ID{/ts}</th>
        <th>{ts}Kamp{/ts}</th>
        <th>{ts}Type{/ts}</th>
        <th>{ts}Wachtend sinds{/ts}</th>
        <th>{ts}Wachtend{/ts}</th>
        <th>{ts}Volgende run{/ts}</th>
        <th>{ts}Status{/ts}</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$rijen item=r}
      <tr class="{if $r.geblokkeerd}brd-blocked{/if}">
        <td><a href="{crmURL p='civicrm/admin/scheduleReminders/edit' q="action=update&id=`$r.id`&reset=1"}" target="_blank">{$r.id}</a></td>
        <td>{$r.title}{if !$r.is_active} <span class="brd-inactive">(inactief)</span>{/if}</td>
        <td>{if $r.template_id}<a href="{crmURL p='civicrm/admin/messageTemplates/add' q="action=update&id=`$r.template_id`&reset=1"}" target="_blank">{$r.template_id}</a>{else}—{/if}</td>
        <td>{$r.template_titel|default:'—'}</td>
        <td>{$r.offset_label}</td>
        <td>{$r.event_type_id}</td>
        <td>
          {if $r.kamp_prio eq 1}<span class="brd-pill brd-pill-kk">KK</span>
          {elseif $r.kamp_prio eq 2}<span class="brd-pill brd-pill-bk">BK</span>
          {elseif $r.kamp_prio eq 3}<span class="brd-pill brd-pill-tk">TK</span>
          {elseif $r.kamp_prio eq 4}<span class="brd-pill brd-pill-jk">JK</span>
          {elseif $r.kamp_prio eq 5}<span class="brd-pill brd-pill-top">TOP</span>
          {else}<span class="brd-pill brd-pill-onbekend">?</span>{/if}
        </td>
        <td>{if $r.is_leiding}<span class="brd-pill brd-pill-leiding">leiding</span>{else}deelnemer{/if}</td>
        <td>{$r.wachtdag} ({$r.wacht_dagen} d)</td>
        <td><strong>{$r.pending}</strong></td>
        <td>{if $r.geblokkeerd}—{else}{$r.volgende_run}{/if}</td>
        <td>{if $r.geblokkeerd}<span class="brd-warn">geblokkeerd (validatie){else}<span class="brd-ok">wacht{/if}</span></td>
      </tr>
      {foreachelse}
      <tr><td colspan="12">{ts}Geen reminders in de wachtrij.{/ts}</td></tr>
      {/foreach}
    </tbody>
  </table>

  <h2>{ts}Recent verstuurd (laatste 3 uur){/ts}</h2>
  <table class="brd-table">
    <thead>
      <tr>
        <th>{ts}Schedule{/ts}</th>
        <th>{ts}Aantal{/ts}</th>
        <th>{ts}Laatst verstuurd{/ts}</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$recent item=x}
      <tr>
        <td>{$x.title}</td>
        <td>{$x.aantal}</td>
        <td>{$x.laatste}</td>
      </tr>
      {foreachelse}
      <tr><td colspan="3">{ts}Niets verstuurd in de laatste 3 uur.{/ts}</td></tr>
      {/foreach}
    </tbody>
  </table>

  <p class="brd-refresh">
    {ts}Peildatum{/ts}: {$nu} — <a href="#" onclick="location.reload(); return false;">{ts}nu vernieuwen{/ts}</a> ·
    {ts}auto-refresh over{/ts} <span id="brd-autorefresh-countdown">30</span>s
  </p>

  <script>
    (function() {
      var runSeconden  = {$volgendeRunSeconden|default:0};
      var refreshSeconden = 30;

      var runEl     = document.getElementById('brd-countdown-run');
      var refreshEl = document.getElementById('brd-autorefresh-countdown');

      function formatDuur(s) {
        if (s <= 0) { return 'nu'; }
        if (s < 60) { return s + 's'; }
        return Math.round(s / 60) + ' min';
      }

      setInterval(function() {
        runSeconden -= 1;
        refreshSeconden -= 1;

        if (runEl) { runEl.textContent = formatDuur(runSeconden); }
        if (refreshEl) { refreshEl.textContent = Math.max(0, refreshSeconden); }

        if (refreshSeconden <= 0) { location.reload(); }
      }, 1000);
    })();
  </script>

</div>
