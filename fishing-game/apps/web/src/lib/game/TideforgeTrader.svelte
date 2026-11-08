<script lang="ts">
  import economy from '../../../../../content/tideforge-economy.json';
  import type { Game } from './game.svelte';
  import type { TideforgeIntent, TideforgeKind } from './tideforge-intents';
  import { gearName, waterOptions } from './tideforge-ui';
  import { formatTraderTime, wanderingTraderSchedule } from './wandering-trader';
  import GameSelect from './GameSelect.svelte';
  import InfoButton from './InfoButton.svelte';
  let { game, onchange, onpreview, offer = 'all' }: {
    game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void; offer?: 'camp' | 'wandering' | 'all';
  } = $props();
  let originalId = $state('');
  let water = $state(0);
  const originals = $derived(game.gearInstances.filter(row => row.protectedOriginal
    && economy.families.some(family => family.familyId === row.familyId)));
  const original = $derived(originals.find(row => row.instanceId.toString() === originalId));
  const waters = $derived(original ? waterOptions(game, original.primaryBiomeId) : []);
  const price = $derived(economy.families.find(row => row.familyId === original?.familyId));
  const schedule = $derived(wanderingTraderSchedule(game.serverNowMs));
  function problem(action: 'camp_copy' | 'wandering_gamble') {
    if (!game.ready) return 'Wait for the connection before previewing this offer.';
    if (game.busy) return 'Wait for the current action to finish.';
    if (game.pendingTideforgeCommit) return 'Check the previous action before starting another.';
    if (!original) return 'Choose an owned gear model.';
    if (!waters.some(row => row.value === water)) return 'Choose a secondary water.';
    if (!price) return 'This gear model is unavailable.';
    if (action === 'camp_copy' && game.player && game.player.coins < BigInt(price.coins)) {
      const required = BigInt(price.coins), owned = game.player.coins;
      return `Need ${(required - owned).toLocaleString()} more coins (${owned.toLocaleString()} / ${required.toLocaleString()}).`;
    }
    if (action === 'wandering_gamble') {
      const current = wanderingTraderSchedule(game.serverNowMs);
      if (!current) return 'Syncing server time before the trader can offer gear.';
      if (!current.open) return 'The wandering trader is away. Check the next visit below.';
      if (!game.tideforgeWallet || game.tideforgeWallet.playerId !== game.player?.playerId) return 'Dredge Marks are still loading.';
      if (game.tideforgeWallet.marks < BigInt(price.marks)) {
        const required = BigInt(price.marks), owned = game.tideforgeWallet.marks;
        return `Need ${(required - owned).toLocaleString()} more Dredge Marks (${owned.toLocaleString()} / ${required.toLocaleString()}).`;
      }
    }
    return '';
  }
  const campProblem = $derived(problem('camp_copy'));
  const wanderingProblem = $derived(problem('wandering_gamble'));
  function chooseOriginal(value: string) { originalId = value; water = 0; onchange(); }
  function preview(action: 'camp_copy' | 'wandering_gamble') {
    if (!original || problem(action)) return;
    onpreview({ action, kind: original.kind as TideforgeKind, definition_id: original.definitionId, secondary_biome_id: water });
  }
</script>

<div class="tideforge-trader">
  <p>Choose a model you already own. This buys an additional item, not an upgrade to your original. New gear starts at Common quality with no reinforcement; matching gear shares mastery.</p>
  <div class="fields">
    <label>Gear model <GameSelect label="Gear model" value={originalId} options={[{ value: '', label: 'Choose gear' }, ...originals.map(row => ({ value: row.instanceId.toString(), label: gearName(game, row) }))]} onchange={chooseOriginal} /></label>
    <label>Secondary water <GameSelect label="Secondary water" value={water} options={[{ value: 0, label: 'Choose water' }, ...waters]} disabled={!original} onchange={value => { water = value; onchange(); }} /></label>
    <InfoButton label="About secondary water" message={'A Secondary Affinity affix uses this water. Other affixes ignore it. Selecting it does not unlock or grant access to that water.'} />
  </div>
  <div class="cards">
    {#if offer === 'all' || offer === 'camp'}<article><h3>Camp outfitter</h3><p>Random affixes, with a chance of an item signature.</p>
      {#if price}<p>Guide: {price.coins.toLocaleString()} coins</p>{/if}
      {#if campProblem}<p id="camp-offer-problem">{campProblem}</p>{/if}
      <button class="tideforge-action" disabled={!!campProblem} aria-describedby={campProblem ? 'camp-offer-problem' : undefined} onclick={() => preview('camp_copy')}>Preview gear purchase</button>
    </article>{/if}
    {#if offer === 'all' || offer === 'wandering'}<article><h3>Wandering trader</h3><p>Higher-tier random affixes and a chance of a signature.</p>
      <p>Visits four times daily for two hours. Times shown in your local timezone.</p>
      {#if !schedule}<p role="status">Syncing server time…</p>
      {:else}
        {#if schedule.current}<p role="status">Here now · Closes <time datetime={new Date(schedule.current.closesAt).toISOString()}>{formatTraderTime(schedule.current.closesAt)}</time></p>
        {:else}<p role="status">Returns <time datetime={new Date(schedule.next.opensAt).toISOString()}>{formatTraderTime(schedule.next.opensAt)}</time></p>{/if}
        <ul aria-label="Trader visit times">{#each schedule.visits as visit (visit.opensAt)}
          <li><time datetime={new Date(visit.opensAt).toISOString()}>{formatTraderTime(visit.opensAt)}</time> – <time datetime={new Date(visit.closesAt).toISOString()}>{formatTraderTime(visit.closesAt)}</time></li>
        {/each}</ul>
      {/if}
      {#if price}<p>Guide: {price.marks.toLocaleString()} Dredge Marks</p>{/if}
      {#if wanderingProblem}<p id="wandering-offer-problem">{wanderingProblem}</p>{/if}
      <button class="tideforge-action" disabled={!!wanderingProblem} aria-describedby={wanderingProblem ? 'wandering-offer-problem' : undefined} onclick={() => preview('wandering_gamble')}>Preview trader offer</button>
    </article>{/if}
  </div>
  {#if !originals.length}<p>Buy eligible gear from Camp first to unlock another of that model here.</p>{/if}
</div>
<style>
  .tideforge-trader{display:grid;gap:14px;min-width:0}.fields{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.fields label{display:grid;gap:6px;min-width:180px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:14px}.cards article{min-width:0;padding:18px;border:2px solid var(--paper-edge);background:var(--panel-inset);overflow-wrap:anywhere}.cards ul{padding-left:20px}h3{font-family:var(--font-game);margin:0}button{white-space:normal;max-width:100%}
</style>
