<script lang="ts">
  import type { Game } from './game.svelte';
  import { affixDescription, affixes } from './tideforge-ui';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import Materials from './Materials.svelte';
  let { game, section, onchange, onNavigate }: {
    game: Game; section: 'vault' | 'codex'; onchange: () => void;
    onNavigate: (view: 'tideforge' | 'trader', section: string) => void;
  } = $props();
  let search = $state('');
  let filter = $state('');
  const visible = $derived(affixes.filter(row => row.name.toLowerCase().includes(search.trim().toLowerCase())
    && (!filter || row.kinds.includes(filter))));
</script>
{#if section === 'vault'}
  <Materials {game} {onNavigate} />
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
  .tideforge-records{display:grid;gap:12px}.tideforge-records h3,.tideforge-records p{margin:0}.tideforge-records h3{font-family:var(--font-game)}.filters{display:flex;gap:12px;flex-wrap:wrap}.filters label{display:grid;gap:6px;min-width:160px}.rows{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:8px}.rows>article{padding:10px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.rows article p{margin-top:8px}
</style>
