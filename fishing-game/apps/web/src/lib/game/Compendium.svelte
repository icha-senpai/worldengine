<script lang="ts">
  import manifest from '../../../../../assets/manifest.json';
  import catalog from '../../../../../content/species.json';
  import world from '../../../../../content/world.json';
  import PageTurner from './PageTurner.svelte';
  import FishDetails from './FishDetails.svelte';
  import { castChance, formatOdds } from './compendium';
  let { active = true } = $props<{ active?: boolean }>();
  const pageSize = 24;
  const tiers = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let query = $state('');
  let biome = $state(0);
  let rarity = $state('');
  let page = $state(1);
  let selected = $state<typeof catalog.species[number] | null>(null);
  $effect(() => { if (!active) selected = null; });
  const species = catalog.species.map(entry => ({ ...entry, asset: manifest.fish.find(asset => asset.key === entry.spriteKey)! }));
  const fish = $derived(species.filter(entry => (!biome || entry.biomeId === biome) && (!rarity || entry.allowedRarities.includes(rarity)) && (entry.name.toLowerCase().includes(query.trim().toLowerCase()) || entry.key.includes(query.trim().toLowerCase().replaceAll(' ', '-')))));
</script>

<section aria-labelledby="compendium-heading">
  <h2 id="compendium-heading">The fish book <span>{species.length} species</span></h2>
  <div class="filters"><div class="search"><label for="catalog-search">Find a fish</label><input id="catalog-search" type="search" placeholder="Carp, koi, trout…" bind:value={query} oninput={() => page = 1} /></div><div><label for="catalog-biome">Biome</label><select id="catalog-biome" bind:value={biome} onchange={() => page = 1}><option value={0}>All waters</option>{#each world.biomes as b}<option value={b.biomeId}>{b.name}</option>{/each}</select></div><div><label for="catalog-rank">Rank</label><select id="catalog-rank" bind:value={rarity} onchange={() => page = 1}><option value="">All ranks</option>{#each tiers as tier}<option value={tier}>{tier}</option>{/each}</select></div></div>
  <p class="count">{fish.length} matching fish. Base rates are per accepted cast in each fish's biome{rarity ? ' at the selected rank' : ', across all eligible ranks'}, with a Common Twig Rod and no bait. Better gear and bait improve these rates.</p>
  <div class="fish">
    {#each fish.slice((page - 1) * pageSize, page * pageSize) as entry (entry.speciesId)}
      <button class="fish-card" aria-label={`Open ${entry.name} details`} aria-haspopup="dialog" data-rank={rarity || (entry.allowedRarities.length === 1 ? entry.allowedRarities[0] : '')} onclick={() => selected = entry}>
        <span class="sprite-slot"><img src={entry.asset.url} alt="" loading="lazy" width={entry.asset.width} height={entry.asset.height} /></span>
        <span class="caption"><span class="fish-name">{entry.name}</span><span class="detail">{world.biomes.find(b => b.biomeId === entry.biomeId)?.name}</span><span class="rarity">{rarity || (entry.allowedRarities.length === 10 ? 'F through UUR' : entry.allowedRarities[0] + ' only')}</span><span class="detail">About {formatOdds(castChance(entry, rarity || undefined))} casts{rarity ? ' at ' + rarity : ' across ranks'}</span></span>
      </button>
    {/each}
    {#if !fish.length}<p class="count">No fish match these filters.</p>{/if}
  </div>
  <PageTurner bind:page total={fish.length} {pageSize} label="Compendium pages" />
</section>
<FishDetails bind:fish={selected} asset={selected ? manifest.fish.find(asset => asset.key === selected!.spriteKey)!.url : ''} />

<style>
  section{position:relative;padding:30px;background:repeating-linear-gradient(0deg,#896b3410 0 1px,transparent 1px 6px),var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #fff0cb,0 6px 0 #132a25}
  section::before{content:'';position:absolute;left:12px;top:12px;bottom:12px;border-left:2px dashed #c5ac7e;pointer-events:none}
  h2{font-family:var(--font-game);font-size:32px;font-weight:500;margin:0 0 22px;line-height:1.25}h2 span{font-family:var(--font-body);font-size:13px;color:var(--ink-muted);font-weight:400;margin-left:12px;white-space:nowrap}
  .filters{display:flex;gap:16px;flex-wrap:wrap;padding:16px;background:#e4d2a7;border:1px solid #b99b6c;box-shadow:inset 0 0 0 2px #f9eac4}.filters>div{display:flex;flex-direction:column;gap:6px;min-width:0}.filters .search{flex:1;min-width:180px}
  label{font-size:13px;font-weight:700;color:#685034}input,select{min-height:44px;border:2px solid #a68a60;background:#fff1cd;color:var(--ink);padding:8px 12px;box-shadow:inset 2px 2px 0 #dcc398;font:inherit}input{width:100%;min-width:0}input::placeholder{color:#8d7856}
  .count{font-size:12px;color:#79664a;margin:18px 0 22px}
  .fish{display:grid;grid-template-columns:repeat(auto-fill,minmax(156px,1fr));gap:16px}
  .fish-card{--slot-edge:#8c7650;margin:0;padding:10px;background:#fff0c9;border:2px solid var(--slot-edge);box-shadow:inset 0 0 0 2px #f9e4b6,3px 4px 0 #ccb080;text-align:center;transition:transform .15s,box-shadow .15s}.fish-card:hover{transform:translateY(-3px);border-color:#b08a41;box-shadow:inset 0 0 0 2px #f9e4b6,3px 6px 0 #ccb080}
  .sprite-slot{display:flex;align-items:center;justify-content:center;height:118px;border:2px solid #6a7657;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;box-shadow:inset 0 0 0 3px #78916a}.sprite-slot img{width:100px;height:90px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 #203b3580)}
  .caption{display:block;margin-top:12px;font-size:12px;line-height:1.5;overflow-wrap:anywhere}.fish-name{display:block;font-family:var(--font-game);font-size:20px;line-height:1.1;color:#4f3d29;min-height:44px}.detail{display:block;color:#79664a;font-size:11px;margin-top:6px}.rarity{display:inline-block;background:#e8d6a8;border:1px solid #c1a16b;color:#71592f;font-family:var(--font-game);font-size:14px;padding:2px 7px;margin:8px 0 2px}
  .fish-card[data-rank=UUR]{--slot-edge:#99753d}.fish-card[data-rank=UUR] .sprite-slot{border-color:#dbb45d;box-shadow:inset 0 0 0 3px #978653;background:#2e4c48}.fish-card[data-rank=UUR] .rarity{background:#4d4270;color:#fff0c4;border-color:#aa8bca}.fish-card[data-rank=UR] .rarity,.fish-card[data-rank=SSS] .rarity{background:#4c697c;color:#fff1cc;border-color:#819dab}
  @media(max-width:600px){section{padding:22px 18px}section::before{left:8px}h2{font-size:29px}h2 span{font-size:11px}.filters{gap:12px;padding:12px}.filters .search{flex-basis:100%;min-width:0}.filters>div{flex:1}.filters select{width:100%;min-width:0;font-size:13px}.fish{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fish-card{padding:8px}.sprite-slot{height:100px}.sprite-slot img{width:90px;height:80px}.fish-name{font-size:19px}.count{font-size:11px}.detail{font-size:10px}}
</style>
