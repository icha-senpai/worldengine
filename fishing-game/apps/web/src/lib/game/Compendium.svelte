<script lang="ts">
  import manifest from '../../../../../assets/manifest.json';
  import catalog from '../../../../../content/species.json';
  import world from '../../../../../content/world.json';
  import PageTurner from './PageTurner.svelte';
  import FishDetails from './FishDetails.svelte';
  import { baselineLoadout, castChance, formatOdds, formatPercent, perfectCatchOdds } from './compendium';
  import { bestPerfectLoadout, equippedLoadout } from './compendium-loadouts';
  import type { Game } from './game.svelte';
  let { active = true, game } = $props<{ active?: boolean; game?: Game }>();
  const pageSize = 24;
  const tiers = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let query = $state('');
  let biome = $state(0);
  let rarity = $state('');
  let page = $state(1);
  let rateMode = $state<'baseline' | 'equipped' | 'best'>('baseline');
  const equipped = $derived(equippedLoadout(game));
  const loadout = $derived(rateMode === 'best' ? bestPerfectLoadout : rateMode === 'equipped' && equipped ? equipped : baselineLoadout);
  $effect(() => { if (rateMode === 'equipped' && !equipped) rateMode = 'baseline'; });
  let selected = $state<typeof catalog.species[number] | null>(null);
  $effect(() => { if (!active) selected = null; });
  const species = $derived(catalog.species.map(entry => ({ ...entry, perfect: perfectCatchOdds(entry, loadout), asset: manifest.fish.find(asset => asset.key === entry.spriteKey)! })));
  const fish = $derived(species.filter(entry => (!biome || entry.biomeId === biome) && (!rarity || entry.allowedRarities.includes(rarity)) && (entry.name.toLowerCase().includes(query.trim().toLowerCase()) || entry.key.includes(query.trim().toLowerCase().replaceAll(' ', '-')))));
</script>

<section aria-labelledby="compendium-heading">
  <h2 id="compendium-heading">The fish book <span>{species.length} species</span></h2>
  <div class="filters">
    <div class="search"><label for="catalog-search">Find a fish</label><input id="catalog-search" type="search" placeholder="Carp, koi, trout…" bind:value={query} oninput={() => page = 1} /></div>
    <div><label for="catalog-biome">Biome</label><select id="catalog-biome" bind:value={biome} onchange={() => page = 1}><option value={0}>All waters</option>{#each world.biomes as b}<option value={b.biomeId}>{b.name}</option>{/each}</select></div>
    <div><label for="catalog-rank">Rank</label><select id="catalog-rank" bind:value={rarity} onchange={() => page = 1}><option value="">All ranks</option>{#each tiers as tier}<option value={tier}>{tier}</option>{/each}</select></div>
    <fieldset class="rate-presets"><legend>Catch rates</legend><div>
      <button aria-pressed={rateMode === 'baseline'} onclick={() => rateMode = 'baseline'}>Baseline</button>
      <button aria-pressed={rateMode === 'equipped'} disabled={!equipped} title={!equipped ? 'Link your Discord account to see your equipped loadout.' : undefined} onclick={() => rateMode = 'equipped'}>Equipped</button>
      <button aria-pressed={rateMode === 'best'} onclick={() => rateMode = 'best'}>Best for perfects</button>
    </div>{#if !equipped}<small>Link your Discord account to compare your equipped loadout.</small>{/if}</fieldset>
  </div>
  <p class="loadout" aria-live="polite"><strong>{loadout.name}</strong><span>+{formatPercent(loadout.luckBp / 100)} luck · {formatPercent(loadout.power / 2)} bonus pull · {loadout.sizeRolls} size {loadout.sizeRolls === 1 ? 'attempt' : 'attempts'}</span></p>
  <p class="count">{fish.length} matching fish. Rates are per accepted cast in each fish's biome{rarity ? ' at the selected rank' : ', across all eligible ranks'}, using the loadout above.{rateMode === 'best' ? ' This is the strongest perfect-catching setup, whether or not you own it.' : ''}</p>
  <p class="count perfect-note">✦ Perfect rates always refer to both exact maximum measurements at UUR, regardless of the rank filter. They include natural perfects, the UUR perfect roll, and any extra size attempts.</p>
  <div class="fish">
    {#each fish.slice((page - 1) * pageSize, page * pageSize) as entry (entry.speciesId)}
      <button class="fish-card" aria-label={`Open ${entry.name} details`} aria-haspopup="dialog" data-rank={rarity || (entry.allowedRarities.length === 1 ? entry.allowedRarities[0] : '')} onclick={() => selected = entry}>
        <span class="sprite-slot"><img src={entry.asset.url} alt="" loading="lazy" width={entry.asset.width} height={entry.asset.height} /></span>
        <span class="caption"><span class="fish-name">{entry.name}</span><span class="detail">{world.biomes.find(b => b.biomeId === entry.biomeId)?.name}</span><span class="rarity">{rarity || (entry.allowedRarities.length === 10 ? 'F through UUR' : entry.allowedRarities[0] + ' only')}</span><span class="detail">About {formatOdds(castChance(entry, rarity || undefined, loadout))} casts{rarity ? ' at ' + rarity : ' across ranks'}</span></span>
        <span class="perfect-odds"><span>✦ Perfect catch</span><strong>{entry.perfect ? formatOdds(entry.perfect.chancePerCast) + ' casts' : 'Not eligible'}</strong></span>
      </button>
    {/each}
    {#if !fish.length}<p class="count">No fish match these filters.</p>{/if}
  </div>
  <PageTurner bind:page total={fish.length} {pageSize} label="Compendium pages" />
</section>
<FishDetails bind:fish={selected} {loadout} asset={selected ? manifest.fish.find(asset => asset.key === selected!.spriteKey)!.url : ''} />

<style>
  .rate-presets{flex:1 0 100%;min-width:0;margin:0;padding:12px 0 0;border:0;border-top:1px dashed #b99b6c}
  .rate-presets legend{font-size:16px;font-weight:700;color:#685034;padding:0 8px 0 0}
  .rate-presets>div{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .rate-presets button{padding:12px;border:2px solid #7e6446;background:#e4d4a9;color:var(--ink);font-family:var(--font-game);font-size:20px;overflow-wrap:anywhere}
  .rate-presets button[aria-pressed=true]{background:#385846;color:#fff0c9;border-color:#58724d}
  .rate-presets button:hover:not(:disabled){border-color:#385846}.rate-presets button:disabled{opacity:.55}
  .rate-presets small{display:block;margin-top:10px;color:#79664a;font-size:14px}
  .loadout{display:grid;gap:4px;padding:14px 16px;border-left:3px solid #68825c;background:#e8d6ab;font-size:16px;overflow-wrap:anywhere}.loadout span{color:#79664a;font-size:14px}
  @media(max-width:600px){.rate-presets>div{grid-template-columns:1fr;gap:8px}.rate-presets button{font-size:18px}.rate-presets legend{font-size:15px}.loadout{font-size:15px}}
  .perfect-note{margin-top:-12px}
  .perfect-odds{display:block;padding-top:8px;margin-top:10px;border-top:1px dashed #c1a16b;color:#6b4b78;font-size:14px;line-height:1.5;overflow-wrap:anywhere}
  .perfect-odds span,.perfect-odds strong{display:block}
  section{position:relative;padding:36px;background:repeating-linear-gradient(0deg,#896b3410 0 1px,transparent 1px 6px),var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #fff0cb,0 6px 0 #132a25}
  section::before{content:'';position:absolute;left:12px;top:12px;bottom:12px;border-left:2px dashed #c5ac7e;pointer-events:none}
  h2{font-family:var(--font-game);font-size:36px;font-weight:500;margin:0 0 22px;line-height:1.25}h2 span{font-family:var(--font-body);font-size:16px;color:var(--ink-muted);font-weight:400;margin-left:12px;white-space:nowrap}
  .filters{display:flex;gap:16px;flex-wrap:wrap;padding:16px;background:#e4d2a7;border:1px solid #b99b6c;box-shadow:inset 0 0 0 2px #f9eac4}.filters>div{display:flex;flex-direction:column;gap:6px;min-width:0}.filters .search{flex:1;min-width:180px}
  label{font-size:16px;font-weight:700;color:#685034}input,select{min-height:44px;border:2px solid #a68a60;background:#fff1cd;color:var(--ink);padding:8px 12px;box-shadow:inset 2px 2px 0 #dcc398;font:inherit}input{width:100%;min-width:0}input::placeholder{color:#8d7856}
  .count{font-size:16px;color:#79664a;margin:18px 0 22px}
  .fish{display:grid;grid-template-columns:repeat(auto-fill,minmax(244px,1fr));gap:20px}
  .fish-card{--slot-edge:#8c7650;margin:0;padding:16px;background:#fff0c9;border:2px solid var(--slot-edge);box-shadow:inset 0 0 0 2px #f9e4b6,3px 4px 0 #ccb080;text-align:center;transition:transform .15s,box-shadow .15s}.fish-card:hover{transform:translateY(-3px);border-color:#b08a41;box-shadow:inset 0 0 0 2px #f9e4b6,3px 6px 0 #ccb080}
  .sprite-slot{display:flex;align-items:center;justify-content:center;height:150px;border:2px solid #6a7657;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;box-shadow:inset 0 0 0 3px #78916a}.sprite-slot img{width:130px;height:115px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 #203b3580)}
  .caption{display:block;margin-top:12px;font-size:16px;line-height:1.5;overflow-wrap:anywhere}.fish-name{display:block;font-family:var(--font-game);font-size:24px;line-height:1.1;color:#4f3d29;min-height:54px}.detail{display:block;color:#79664a;font-size:14px;margin-top:6px}.rarity{display:inline-block;background:#e8d6a8;border:1px solid #c1a16b;color:#71592f;font-family:var(--font-game);font-size:17px;padding:2px 7px;margin:8px 0 2px}
  .fish-card[data-rank=UUR]{--slot-edge:#99753d}.fish-card[data-rank=UUR] .sprite-slot{border-color:#dbb45d;box-shadow:inset 0 0 0 3px #978653;background:#2e4c48}.fish-card[data-rank=UUR] .rarity{background:#4d4270;color:#fff0c4;border-color:#aa8bca}.fish-card[data-rank=UR] .rarity,.fish-card[data-rank=SSS] .rarity{background:#4c697c;color:#fff1cc;border-color:#819dab}
  @media(max-width:600px){section{padding:22px 18px}section::before{left:8px}h2{font-size:31px}h2 span{font-size:13px}.filters{gap:12px;padding:12px}.filters .search{flex-basis:100%;min-width:0}.filters>div{flex:1}.filters select{width:100%;min-width:0;font-size:15px}.fish{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fish-card{padding:8px}.sprite-slot{height:100px}.sprite-slot img{width:90px;height:80px}.fish-name{font-size:20px}.count{font-size:13px}.detail{font-size:12px}}
</style>
