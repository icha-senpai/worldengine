<script lang="ts">
  import type { CatalogFish } from './compendium';
  import { castChance, formatOdds, formatPercent, rankSpreads } from './compendium';
  import { formatLength, formatWeight } from './measurements';
  import world from '../../../../../content/world.json';
  let { fish = $bindable(null), asset = '' } = $props<{fish?: CatalogFish | null; asset?: string}>();
  let dialog: HTMLDialogElement;
  const bands = $derived(fish ? rankSpreads(fish) : []);
  const ranges = $derived(bands.length ? {
    minLength: Math.min(...bands.map(row => row.minLengthMm)), maxLength: Math.max(...bands.map(row => row.maxLengthMm)),
    minWeight: Math.min(...bands.map(row => row.minWeightG)), maxWeight: Math.max(...bands.map(row => row.maxWeightG)),
  } : null);
  $effect(() => {
    if (fish && dialog && !dialog.open) dialog.showModal();
    else if (!fish && dialog?.open) dialog.close();
  });
  $effect(() => {
    if (!fish) return;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  });
  function dismissBackdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) fish = null;
  }
</script>

<dialog bind:this={dialog} aria-labelledby="fish-details-title" onclose={() => fish = null} oncancel={() => fish = null} onpointerdown={dismissBackdrop}>
  {#if fish && ranges}
    <header><div><p class="eyebrow">COMPENDIUM FIELD NOTES</p><h2 id="fish-details-title">{fish.name}</h2><p class="biome">{world.biomes.find(row => row.biomeId === fish!.biomeId)?.name} · {fish.allowedRarities.length === 10 ? 'F through UUR' : fish.allowedRarities[0] + ' only'}</p></div><button class="close" onclick={() => fish = null} aria-label="Close fish details">✕</button></header>
    <div class="pages">
      <div class="overview">
        <div class="fish-art"><img src={asset} alt={fish.name} /><span>{fish.allowedRarities.length === 1 ? fish.allowedRarities[0] + ' ONLY' : 'SPECIES PROFILE'}</span></div>
        <div class="facts"><dl><div><dt>Reference length</dt><dd>{formatLength(fish.typicalLengthMm)}</dd></div><div><dt>Reference weight</dt><dd>{formatWeight(fish.typicalWeightG)}</dd></div><div><dt>Catchable length</dt><dd>{formatLength(ranges.minLength)} – {formatLength(ranges.maxLength)}</dd></div><div><dt>Catchable weight</dt><dd>{formatWeight(ranges.minWeight)} – {formatWeight(ranges.maxWeight)}</dd></div></dl><p class="overall">About <strong>{formatOdds(castChance(fish))} casts</strong> across eligible ranks.</p></div>
      </div>
      {#if fish.lore}<p class="lore">{fish.lore}</p>{/if}
      <section aria-labelledby="size-spread-title">
        <h3 id="size-spread-title">Size &amp; rarity spread</h3>
        <p class="explain">The reference size is this species’ benchmark, not the average of your catches. The bars show the share of <strong>{fish.name}</strong> catches at each rank with a Common Twig Rod and no bait.</p>
        <div class="spread" aria-label="Rarity distribution">
          {#each bands as band (band.rarity)}
            <div class="band" data-rank={band.rarity}><span class="rank">{band.rarity}</span><div class="track"><span style:width={`${band.sharePercent}%`}></span></div><span class="share">{formatPercent(band.sharePercent)}</span></div>
          {/each}
        </div>
      </section>
      <section aria-labelledby="rank-ranges-title">
        <h3 id="rank-ranges-title">What you can pull at each rank</h3>
        <p class="explain">Length is drawn within the rank’s size band. Weight grows with the cube of length, with roughly ±12% variation and the rank’s minimum weight applied. Longer fish tend to be heavier; the ranges below aren’t independent rolls.</p>
        <div class="rank-ranges">
          {#each bands as band (band.rarity)}
            <article class="range" data-rank={band.rarity}><div class="range-heading"><h4>{band.rarity} rank</h4><span>{formatPercent(band.sharePercent)} of this species’ catches</span></div><dl><div><dt>Length range</dt><dd>{formatLength(band.minLengthMm)} – {formatLength(band.maxLengthMm)}</dd></div><div><dt>Weight range</dt><dd>{formatWeight(band.minWeightG)} – {formatWeight(band.maxWeightG)}</dd></div><div><dt>Base chance per cast</dt><dd>{formatPercent(band.castChance * 100)} <small>About {formatOdds(band.castChance)} casts</small></dd></div></dl><p class="threshold">{band.rarity === 'F' ? 'Below the D threshold in length or weight.' : `Both dimensions must reach at least ${band.minimumLengthFactor.toLocaleString(undefined, { maximumFractionDigits: 2 })}× reference length and ${band.minimumWeightFactor.toLocaleString(undefined, { maximumFractionDigits: 6 })}× reference weight.`}</p></article>
          {/each}
        </div>
      </section>
      <p class="note">Cast odds include the Common Twig Rod’s 0.5% chance of a second pull and the biome’s fish / junk / treasure mix. Better luck shifts catches toward higher size bands; more power adds chances for another pull. “1 in” is a long-run expectation, with no guarantee or pity timer.</p>
    </div>
  {/if}
</dialog>

<style>
  dialog{width:min(960px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:0;border:4px solid var(--wood-dark);color:var(--ink);background:var(--paper);box-shadow:inset 0 0 0 3px #fff0cb,0 12px 0 #132a2580;overflow:hidden}dialog[open]{display:flex;flex-direction:column}dialog::backdrop{background:#102820cf}
  header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;padding:22px 26px 18px;border-bottom:2px solid #b99b6c;background:#e4d2a7;flex-shrink:0}.eyebrow{margin:0 0 6px;font-size:10px;letter-spacing:2px;color:#79603b;font-weight:800}h2{font:34px var(--font-game);margin:0;line-height:1.2}.biome{font-size:12px;color:#79603b;margin:5px 0 0}.close{flex-shrink:0;width:42px;height:42px;border:2px solid #96794f;background:#fff0cb;color:var(--ink);font-size:20px;box-shadow:2px 3px 0 #b4996a}.close:hover{background:#f6d993}
  .pages{padding:24px 26px;overflow-y:auto;overscroll-behavior:contain}.overview{display:grid;grid-template-columns:210px 1fr;gap:24px;align-items:center}.fish-art{height:180px;border:3px solid #6a7657;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;box-shadow:inset 0 0 0 3px #78916a;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}.fish-art img{width:160px;height:110px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 #203b3580)}.fish-art>span{font:13px var(--font-game);color:#ffe5a4;margin-top:12px;letter-spacing:1px}dl{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:0}dt{font-size:11px;color:#79603b;margin-bottom:4px}dd{margin:0;font-weight:800;font-size:14px}.overall{font-size:12px;margin:18px 0 0;color:#79603b}.overall strong{color:#5d4930}.lore{padding:14px 18px;background:#e8d6ab;border-left:3px solid #b28c42;font-size:13px;margin:22px 0 0}
  section{margin-top:28px;border-top:1px dashed #b99b6c;padding-top:22px}h3{font:26px var(--font-game);margin:0 0 10px}.explain,.note{font-size:12px;color:#79664a;line-height:1.7}.explain{margin:0 0 18px}.spread{padding:14px 18px;background:#fff0c9;border:2px solid #b99b6c;display:grid;gap:8px}.band{display:flex;align-items:center;gap:12px}.rank{font:16px var(--font-game);width:36px;flex-shrink:0}.track{height:13px;background:#e2d0a6;border:1px solid #b89b6e;flex:1;overflow:hidden}.track span{display:block;height:100%;min-width:2px;background:#68825c}.band[data-rank=UUR] .track span{background:#82689c}.band[data-rank=UR] .track span,.band[data-rank=SSS] .track span{background:#658599}.share{width:75px;flex-shrink:0;text-align:right;font-size:11px;font-variant-numeric:tabular-nums;color:#79603b}
  .rank-ranges{display:grid;gap:12px}.range{padding:14px 16px;border:1px solid #b99b6c;background:#fff0c9;box-shadow:2px 3px 0 #ccb080}.range-heading{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.range-heading h4{margin:0;font:21px var(--font-game)}.range-heading>span{font-size:11px;color:#79603b}.range dl{grid-template-columns:1fr 1.3fr 1fr;gap:18px}.range dd{font-size:12px}.range dd small{display:block;font-weight:400;color:#79603b;font-size:11px;margin-top:4px}.threshold{font-size:11px;margin:12px 0 0;color:#79603b}.note{margin:22px 0 0}.range[data-rank=UUR]{border-color:#977cb0}
  @media(max-width:600px){dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px)}header{padding:17px 16px;gap:10px}h2{font-size:27px}.pages{padding:18px 16px}.overview{grid-template-columns:1fr;gap:18px}.fish-art{height:140px}.fish-art img{height:90px}.fish-art>span{margin-top:8px}.facts dl{gap:14px}dd{font-size:12px}.range{padding:12px}.range-heading{align-items:flex-start}.range-heading>span{text-align:right;font-size:10px}.range dl{grid-template-columns:1fr;gap:12px}.range dl>div{display:flex;justify-content:space-between;gap:14px}.range dt{margin:0;flex-shrink:0}.range dd{text-align:right;font-size:11px}.range .threshold{font-size:10px}.spread{padding:12px}.band{gap:8px}.share{width:65px}.rank{width:30px}.note{font-size:11px}h3{font-size:24px}}
</style>
