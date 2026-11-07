<script lang="ts">
  import raidPolicy from '../../../../../content/raids.json';
  import economy from '../../../../../content/tideforge-economy.json';
  import type { Game } from './game.svelte';
  import { affixDescription, affixes, affixName } from './tideforge-ui';
  import { itemSlot } from './raid-items';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import RaidArt from './RaidArt.svelte';
  let { game, section, onchange }: { game: Game; section: 'vault' | 'codex'; onchange: () => void } = $props();
  let search = $state('');
  let filter = $state('');
  const visible = $derived(affixes.filter(row => row.name.toLowerCase().includes(search.trim().toLowerCase())
    && (!filter || row.kinds.includes(filter))));
  function quantity(slot: number) { return game.items.find(row => itemSlot(row.key) === slot)?.quantity ?? 0n; }
</script>
{#if section === 'vault'}
  <div class="tideforge-records">
    <p>Dredge Marks: {game.tideforgeWallet?.marks ?? 0n}. Earn them through certain achievements and regular fish raid clears.</p>
    <h3>Fillets &amp; scales</h3>
    <div class="rows"><p><RaidArt kind="fillets" grade={0} size={24} /> Basic fillets <strong>{quantity(economy.processing.filletsSlot)}</strong></p><p><RaidArt kind="scales" grade={0} size={24} /> Basic scales <strong>{quantity(economy.processing.scalesSlot)}</strong></p>
      {#each raidPolicy.grades as grade}
        <p><RaidArt kind="fillets" grade={grade.grade} size={24} /> {grade.name}-grade fillets <strong>{quantity(raidPolicy.materials[0].slotBase + grade.grade)}</strong></p>
        <p><RaidArt kind="scales" grade={grade.grade} size={24} /> {grade.name}-grade scales <strong>{quantity(raidPolicy.materials[1].slotBase + grade.grade)}</strong></p>
      {/each}
    </div>
    <h3>Affix shards</h3><p>Dismantle unprotected gear to recover affix shards for the Forge.</p>
    {#if game.tideforgeVault.length}<div class="rows">{#each game.tideforgeVault as shard (shard.key)}<p>{affixName(shard.affixId)} <strong>{shard.count}</strong></p>{/each}</div>{:else}<p>No recovered shards yet.</p>{/if}
  </div>
{:else}
  <div class="tideforge-records">
    <p>Find affixes on newly acquired gear to unlock them in the Forge. Adding or changing affixes in the Forge does not count as a discovery.</p>
    <div class="filters"><label>Find affix <SearchInput label="Find affix" value={search} oninput={value => { search = value; onchange(); }} /></label>
      <label>Kind <GameSelect label="Affix kind" value={filter} options={[{ value: '', label: 'All gear' }, { value: 'rod', label: 'Rod' }, { value: 'boat', label: 'Boat' }, { value: 'net', label: 'Net' }]} onchange={value => { filter = value; onchange(); }} /></label></div>
    <div class="rows">{#each visible as affix (affix.id)}
      {@const seen = game.tideforgeCodex.find(row => row.affixId === affix.id)}
      <article><h3>{affix.name}</h3><p>{affix.kinds.join(' · ')}{affix.condition === 'primary' ? ' · Primary water only' : affix.condition === 'secondary' ? ' · Chosen secondary water only' : ''}</p>
        <p>{affixDescription(affix.id)}</p><p>{seen ? `Times found ${seen.naturalCount} · Highest tier ${seen.highestTier} · Best strength ${seen.bestFinishBp / 100}%` : 'Undiscovered · locked in Forge'}</p></article>
    {/each}</div>
    {#if !visible.length}<p>No affixes match.</p>{/if}
  </div>
{/if}
<style>
  .tideforge-records{display:grid;gap:12px}.tideforge-records h3,.tideforge-records p{margin:0}.tideforge-records h3{font-family:var(--font-game)}.filters{display:flex;gap:12px;flex-wrap:wrap}.filters label{display:grid;gap:6px;min-width:160px}.rows{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:8px}.rows>p,.rows>article{padding:10px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.rows>p{display:flex;align-items:center;gap:7px}.rows strong{margin-left:auto}.rows article p{margin-top:8px}
</style>
