<script lang="ts">
  import type { Game } from './game.svelte';
  import { affixName } from './tideforge-ui';
  import { materialBalances, type MaterialGroup } from './materials-ui';
  import InfoButton from './InfoButton.svelte';

  let { game, onNavigate }: { game: Game; onNavigate: (view: 'tideforge' | 'trader', section: string) => void } = $props();
  let showEmpty = $state(false);
  const balances = $derived(materialBalances(game.items.filter(row => row.playerId === game.player?.playerId), showEmpty));
  const shards = $derived(game.tideforgeVault.filter(row => row.playerId === game.player?.playerId && row.count > 0n));
  const wallet = $derived(game.tideforgeAvailability === 'active' && game.tideforgeWallet?.playerId === game.player?.playerId ? game.tideforgeWallet : null);
  const groups: { id: MaterialGroup; label: string }[] = [
    { id: 'supplies', label: 'Supplies' },
    { id: 'basic', label: 'Basic materials' },
    { id: 'graded', label: 'Raid materials' },
    { id: 'unknown', label: 'Other items' },
  ];
</script>

<div class="materials" role="region" aria-label="Materials">
  {#if !game.ready || !game.player}
    <p role="status">Loading your materials…</p>
  {:else}
    <h3>Materials</h3>
    <p>Dredge Marks: {#if wallet}<strong>{wallet.marks.toString()}</strong>{:else}<span>Unavailable</span>{/if}
      <InfoButton label="About Dredge Marks" message="Earn Dredge Marks from regular-fish raid clears and newly earned achievements. Spend them at the wandering trader." />
    </p>
    <p>Basic materials come from filleting fish. F-grade and higher materials are separate balances used for reinforcement and Forge work.</p>
    <details bind:open={showEmpty}><summary>Show empty materials</summary><p>Empty balances are included below.</p></details>
    {#each groups as group}
      {@const rows = balances.filter(row => row.group === group.id)}
      {#if group.id !== 'unknown' || rows.length}
        <div class="group">
          <h3>{group.label}</h3>
          {#if rows.length}
            <div class="rows">{#each rows as row (row.id)}
              <p>{#if row.image}<img src={row.image} alt="" width="32" height="32" loading="lazy" />{/if}<span>{row.label}</span> <strong>{row.quantity.toString()}</strong></p>
            {/each}</div>
          {/if}
          {#if !rows.some(row => row.quantity > 0n)}
            {#if group.id === 'supplies'}<p>No supplies yet. <button class="tideforge-action secondary" onclick={() => onNavigate('trader', 'camp')}>Visit shop</button></p>
            {:else if group.id === 'basic'}<p>No Basic materials yet. <button class="tideforge-action secondary" onclick={() => onNavigate('tideforge', 'processing')}>Open Processing</button></p>
            {:else if group.id === 'graded'}<p>No raid materials yet. <a href="#quests">Open Quests for raids</a></p>{/if}
          {/if}
        </div>
      {/if}
    {/each}
    <div class="group"><h3>Affix shards</h3><p>Dismantle eligible gear to recover shards for the Forge.</p>
      {#if game.tideforgeAvailability === 'inactive'}<p role="status">Affix shards are not enabled on this server.</p>
      {:else if game.tideforgeAvailability === 'unavailable'}<p role="status">Affix shards are unavailable.</p><button class="tideforge-action secondary" onclick={() => game.retryTideforge()}>Retry Tideforge</button>
      {:else if game.tideforgeDetailsUnavailable}<p role="status">Affix shard details are unavailable.</p><button class="tideforge-action secondary" onclick={() => game.retryTideforgeDetails()}>Retry details</button>
      {:else if game.tideforgeAvailability === 'loading' || game.tideforgeLoading || !game.tideforgeDetailsReady}<p role="status">Loading affix shards…</p>
      {:else if shards.length}<div class="rows">{#each shards as shard (shard.key)}<p><span>{affixName(shard.affixId)} shards</span> <strong>{shard.count.toString()}</strong></p>{/each}</div>
      {:else}<p>No recovered shards yet.</p>{/if}
    </div>
  {/if}
</div>

<style>
  .materials,.group{display:grid;gap:12px;min-width:0}.materials h3,.materials p{margin:0}.materials h3{font-family:var(--font-game)}.materials p{line-height:1.5}.materials details{padding:10px;border:1px solid var(--paper-edge);background:var(--panel-inset)}.materials summary{cursor:pointer}.materials details p{margin-top:8px}.rows{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:8px}.rows p{display:flex;align-items:center;gap:8px;padding:10px;background:var(--panel-inset);border:1px solid var(--paper-edge);min-width:0}.rows img{object-fit:contain;image-rendering:pixelated;flex:none}.rows span{overflow-wrap:anywhere}.rows strong{margin-left:auto;overflow-wrap:anywhere}.group a{color:var(--ink)}
</style>
